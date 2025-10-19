<?php

namespace Modules\Reseller\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Modules\Reseller\Models\Reseller;
use Modules\Reseller\Models\ResellerConfig;
use Modules\Reseller\Models\ResellerConfigEvent;

class ConfigController
{
    public function index(): View
    {
        $reseller = $this->getTrafficReseller();

        $configs = $reseller->configs()->latest()->get();

        return view('reseller::configs.index', [
            'reseller' => $reseller,
            'configs' => $configs,
            'maxActive' => config('reseller.configs_max_active'),
        ]);
    }

    public function create(): View
    {
        $reseller = $this->getTrafficReseller();

        return view('reseller::configs.create', [
            'reseller' => $reseller,
            'maxActive' => config('reseller.configs_max_active'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $reseller = $this->getTrafficReseller();

        $validated = $request->validate([
            'traffic_limit_gb' => ['required', 'numeric', 'min:1'],
            'expires_at' => ['required', 'date'],
            'panel_type' => ['required', 'in:marzban,marzneshin,xui'],
        ]);

        $activeCount = $reseller->configs()
            ->where('status', 'active')
            ->count();

        $maxActive = (int) config('reseller.configs_max_active', 50);

        if ($activeCount >= $maxActive) {
            return Redirect::back()->withErrors([
                'traffic_limit_gb' => __('Maximum number of active configs reached.'),
            ]);
        }

        $trafficLimitBytes = (int) ($validated['traffic_limit_gb'] * 1024 * 1024 * 1024);

        $remainingTraffic = $this->remainingTraffic($reseller);
        if ($reseller->traffic_total_bytes !== null && $trafficLimitBytes > $remainingTraffic) {
            return Redirect::back()->withErrors([
                'traffic_limit_gb' => __('Requested traffic exceeds remaining quota.'),
            ]);
        }

        $expiresAt = Carbon::parse($validated['expires_at']);
        if ($reseller->window_ends_at && $expiresAt->greaterThan($reseller->window_ends_at)) {
            return Redirect::back()->withErrors([
                'expires_at' => __('Expiration must be within the allocated window.'),
            ]);
        }

        if ($reseller->window_starts_at && $expiresAt->lessThan($reseller->window_starts_at)) {
            return Redirect::back()->withErrors([
                'expires_at' => __('Expiration must be after the window start.'),
            ]);
        }

        $config = ResellerConfig::create([
            'reseller_id' => $reseller->getKey(),
            'external_username' => '',
            'traffic_limit_bytes' => $trafficLimitBytes,
            'usage_bytes' => 0,
            'expires_at' => $expiresAt,
            'status' => 'active',
            'panel_type' => $validated['panel_type'],
            'created_by' => Auth::id(),
        ]);

        $config->external_username = sprintf('%s_%dcfg%d',
            $reseller->getEffectiveUsernamePrefix(),
            $reseller->getKey(),
            $config->getKey()
        );
        $config->save();

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->getKey(),
            'type' => 'created',
            'meta' => [
                'traffic_limit_bytes' => $trafficLimitBytes,
                'expires_at' => $expiresAt->toAtomString(),
            ],
        ]);

        return Redirect::route('reseller.configs.index')
            ->with('status', __('Config created successfully.'));
    }

    public function disable(ResellerConfig $config): RedirectResponse
    {
        $reseller = $this->getTrafficReseller();
        $this->assertOwner($reseller, $config);

        if ($config->status !== 'disabled') {
            $config->update([
                'status' => 'disabled',
                'disabled_at' => now(),
            ]);

            ResellerConfigEvent::create([
                'reseller_config_id' => $config->getKey(),
                'type' => 'disabled',
                'meta' => [],
            ]);
        }

        return Redirect::route('reseller.configs.index')
            ->with('status', __('Config disabled.'));
    }

    public function enable(ResellerConfig $config): RedirectResponse
    {
        $reseller = $this->getTrafficReseller();
        $this->assertOwner($reseller, $config);

        if ($config->status === 'disabled') {
            $activeCount = $reseller->configs()
                ->where('status', 'active')
                ->count();
            $maxActive = (int) config('reseller.configs_max_active', 50);
            if ($activeCount >= $maxActive) {
                return Redirect::back()->withErrors([
                    'config' => __('Maximum number of active configs reached.'),
                ]);
            }

            $remainingTraffic = $this->remainingTraffic($reseller);
            if ($reseller->traffic_total_bytes !== null && $config->traffic_limit_bytes > $remainingTraffic) {
                return Redirect::back()->withErrors([
                    'config' => __('Not enough traffic remaining to enable config.'),
                ]);
            }

            $config->update([
                'status' => 'active',
                'disabled_at' => null,
            ]);

            ResellerConfigEvent::create([
                'reseller_config_id' => $config->getKey(),
                'type' => 'enabled',
                'meta' => [],
            ]);
        }

        return Redirect::route('reseller.configs.index')
            ->with('status', __('Config enabled.'));
    }

    public function destroy(ResellerConfig $config): RedirectResponse
    {
        $reseller = $this->getTrafficReseller();
        $this->assertOwner($reseller, $config);

        if ($config->status !== 'deleted') {
            $config->update([
                'status' => 'deleted',
                'deleted_at' => now(),
            ]);

            ResellerConfigEvent::create([
                'reseller_config_id' => $config->getKey(),
                'type' => 'deleted',
                'meta' => [],
            ]);
        }

        return Redirect::route('reseller.configs.index')
            ->with('status', __('Config deleted.'));
    }

    protected function getTrafficReseller(): Reseller
    {
        $user = Auth::user();
        $reseller = $user?->reseller;

        abort_unless($reseller && $reseller->type === 'traffic', 404);

        return $reseller;
    }

    protected function assertOwner(Reseller $reseller, ResellerConfig $config): void
    {
        abort_if($config->reseller_id !== $reseller->getKey(), 404);
    }

    protected function remainingTraffic(Reseller $reseller): int
    {
        if ($reseller->traffic_total_bytes === null) {
            return PHP_INT_MAX;
        }

        return max(0, $reseller->traffic_total_bytes - $reseller->traffic_used_bytes);
    }
}
