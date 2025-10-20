<?php

namespace Modules\Reseller\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Panel;
use App\Models\Plan;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Reseller\Services\ResellerProvisioner;

class ConfigController extends Controller
{
    public function index(Request $request)
    {
        $reseller = $request->user()->reseller;

        if (! $reseller->isTrafficBased()) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'This feature is only available for traffic-based resellers.');
        }

        $configs = $reseller->configs()->latest()->paginate(20);

        return view('reseller::configs.index', [
            'reseller' => $reseller,
            'configs' => $configs,
        ]);
    }

    public function create(Request $request)
    {
        $reseller = $request->user()->reseller;

        if (! $reseller->isTrafficBased()) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'This feature is only available for traffic-based resellers.');
        }

        // If reseller has a specific panel assigned, use only that panel
        if ($reseller->panel_id) {
            $panels = Panel::where('id', $reseller->panel_id)->where('is_active', true)->get();
        } else {
            $panels = Panel::where('is_active', true)->get();
        }

        $marzneshinServices = [];

        // If reseller has Marzneshin service whitelist, fetch available services
        if ($reseller->marzneshin_allowed_service_ids) {
            // For simplicity, we'll pass the IDs and let the view handle it
            $marzneshinServices = $reseller->marzneshin_allowed_service_ids;
        }

        return view('reseller::configs.create', [
            'reseller' => $reseller,
            'panels' => $panels,
            'marzneshin_services' => $marzneshinServices,
        ]);
    }

    public function store(Request $request)
    {
        $reseller = $request->user()->reseller;

        if (! $reseller->isTrafficBased()) {
            return back()->with('error', 'This feature is only available for traffic-based resellers.');
        }

        // Check config_limit enforcement
        if ($reseller->config_limit !== null && $reseller->config_limit > 0) {
            $totalConfigsCount = $reseller->configs()->count();
            if ($totalConfigsCount >= $reseller->config_limit) {
                return back()->with('error', "Config creation limit reached. Maximum allowed: {$reseller->config_limit}");
            }
        }

        $validator = Validator::make($request->all(), [
            'panel_id' => 'required|exists:panels,id',
            'traffic_limit_gb' => 'required|numeric|min:0.1',
            'expires_days' => 'required|integer|min:1',
            'comment' => 'nullable|string|max:200',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Validate reseller can use the selected panel
        if ($reseller->panel_id && $request->panel_id != $reseller->panel_id) {
            return back()->with('error', 'You can only use the panel assigned to your account.');
        }

        $panel = Panel::findOrFail($request->panel_id);
        $expiresDays = $request->integer('expires_days');
        $trafficLimitBytes = (float) $request->input('traffic_limit_gb') * 1024 * 1024 * 1024;
        $expiresAt = now()->addDays($expiresDays);

        // Validate expiry is within reseller window (only if window_ends_at is set)
        if ($reseller->window_ends_at && $expiresAt->gt($reseller->window_ends_at)) {
            return back()->with('error', 'Config expiry cannot exceed your reseller window end date.');
        }

        // Validate traffic limit doesn't exceed remaining traffic
        $remainingTraffic = $reseller->traffic_total_bytes - $reseller->traffic_used_bytes;
        if ($trafficLimitBytes > $remainingTraffic) {
            return back()->with('error', 'Config traffic limit exceeds your remaining traffic quota.');
        }

        // Validate Marzneshin service whitelist
        if ($panel->panel_type === 'marzneshin' && $reseller->marzneshin_allowed_service_ids) {
            $serviceIds = $request->service_ids ?? [];
            $allowedServiceIds = $reseller->marzneshin_allowed_service_ids;

            foreach ($serviceIds as $serviceId) {
                if (! in_array($serviceId, $allowedServiceIds)) {
                    return back()->with('error', 'One or more selected services are not allowed for your account.');
                }
            }
        }

        DB::transaction(function () use ($request, $reseller, $panel, $trafficLimitBytes, $expiresAt, $expiresDays) {
            $provisioner = new ResellerProvisioner;

            // Create config record first
            $config = ResellerConfig::create([
                'reseller_id' => $reseller->id,
                'external_username' => '', // Will be set after provisioning
                'comment' => $request->input('comment'),
                'traffic_limit_bytes' => $trafficLimitBytes,
                'usage_bytes' => 0,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'panel_type' => $panel->panel_type,
                'panel_id' => $panel->id,
                'created_by' => $request->user()->id,
            ]);

            $username = $provisioner->generateUsername($reseller, 'config', $config->id);
            $config->update(['external_username' => $username]);

            // Provision on panel - use a non-persisted Plan model instance
            $plan = new Plan;
            $plan->volume_gb = (float) $request->input('traffic_limit_gb');
            $plan->duration_days = $expiresDays;
            $plan->marzneshin_service_ids = $request->input('service_ids', []);

            $result = $provisioner->provisionUser($panel, $plan, $username, [
                'traffic_limit_bytes' => $trafficLimitBytes,
                'expires_at' => $expiresAt,
                'service_ids' => $plan->marzneshin_service_ids,
            ]);

            if ($result) {
                $config->update([
                    'panel_user_id' => $result['panel_user_id'],
                    'subscription_url' => $result['subscription_url'] ?? null,
                ]);

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'created',
                    'meta' => $result,
                ]);

                session()->flash('success', 'Config created successfully.');
                session()->flash('subscription_url', $result['subscription_url'] ?? null);
            } else {
                $config->delete();
                session()->flash('error', 'Failed to provision config on the panel.');
            }
        });

        return redirect()->route('reseller.configs.index');
    }

    public function disable(Request $request, ResellerConfig $config)
    {
        $reseller = $request->user()->reseller;

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        if (! $config->isActive()) {
            return back()->with('error', 'Config is not active.');
        }

        // Update local state first
        $config->update([
            'status' => 'disabled',
            'disabled_at' => now(),
        ]);

        // Try to disable on remote panel
        $remoteFailed = false;
        if ($config->panel_id) {
            try {
                $panel = Panel::findOrFail($config->panel_id);
                $provisioner = new ResellerProvisioner;
                $success = $provisioner->disableUser($config->panel_type, $panel->getCredentials(), $config->panel_user_id);
                
                if (!$success) {
                    $remoteFailed = true;
                    Log::warning("Failed to disable config {$config->id} on remote panel {$panel->id}");
                }
            } catch (\Exception $e) {
                $remoteFailed = true;
                Log::error("Exception disabling config {$config->id} on panel: " . $e->getMessage());
            }
        }

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'manual_disabled',
            'meta' => [
                'user_id' => $request->user()->id,
                'remote_failed' => $remoteFailed,
            ],
        ]);

        if ($remoteFailed) {
            return back()->with('warning', 'Config disabled locally, but remote panel update failed.');
        }

        return back()->with('success', 'Config disabled successfully.');
    }

    public function enable(Request $request, ResellerConfig $config)
    {
        $reseller = $request->user()->reseller;

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        if (! $config->isDisabled()) {
            return back()->with('error', 'Config is not disabled.');
        }

        // Validate reseller can enable configs
        if (! $reseller->hasTrafficRemaining() || ! $reseller->isWindowValid()) {
            return back()->with('error', 'Cannot enable config: reseller quota exceeded or window expired.');
        }

        // Try to enable on remote panel first
        $remoteFailed = false;
        if ($config->panel_id) {
            try {
                $panel = Panel::findOrFail($config->panel_id);
                $provisioner = new ResellerProvisioner;
                $success = $provisioner->enableUser($config->panel_type, $panel->getCredentials(), $config->panel_user_id);
                
                if (!$success) {
                    $remoteFailed = true;
                    Log::warning("Failed to enable config {$config->id} on remote panel {$panel->id}");
                }
            } catch (\Exception $e) {
                $remoteFailed = true;
                Log::error("Exception enabling config {$config->id} on panel: " . $e->getMessage());
            }
        }

        // Update local state
        $config->update([
            'status' => 'active',
            'disabled_at' => null,
        ]);

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'manual_enabled',
            'meta' => [
                'user_id' => $request->user()->id,
                'remote_failed' => $remoteFailed,
            ],
        ]);

        if ($remoteFailed) {
            return back()->with('warning', 'Config enabled locally, but remote panel update failed.');
        }

        return back()->with('success', 'Config enabled successfully.');
    }

    public function destroy(Request $request, ResellerConfig $config)
    {
        $reseller = $request->user()->reseller;

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        // Try to delete on remote panel
        $remoteFailed = false;
        if ($config->panel_id) {
            try {
                $panel = Panel::findOrFail($config->panel_id);
                $provisioner = new ResellerProvisioner;
                $success = $provisioner->deleteUser($config->panel_type, $panel->getCredentials(), $config->panel_user_id);
                
                if (!$success) {
                    $remoteFailed = true;
                    Log::warning("Failed to delete config {$config->id} on remote panel {$panel->id}");
                }
            } catch (\Exception $e) {
                $remoteFailed = true;
                Log::error("Exception deleting config {$config->id} on panel: " . $e->getMessage());
            }
        }

        // Update local state regardless of remote result
        $config->update(['status' => 'deleted']);
        $config->delete();

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'deleted',
            'meta' => [
                'user_id' => $request->user()->id,
                'remote_failed' => $remoteFailed,
            ],
        ]);

        if ($remoteFailed) {
            return back()->with('warning', 'Config deleted locally, but remote panel deletion failed.');
        }

        return back()->with('success', 'Config deleted successfully.');
    }
}
