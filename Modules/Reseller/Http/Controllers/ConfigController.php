<?php

namespace Modules\Reseller\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
        $eylandooNodes = [];
        $showNodesSelector = false;

        // If reseller has Marzneshin service whitelist, fetch available services
        if ($reseller->marzneshin_allowed_service_ids) {
            // For simplicity, we'll pass the IDs and let the view handle it
            $marzneshinServices = $reseller->marzneshin_allowed_service_ids;
        }

        // Fetch Eylandoo nodes for each Eylandoo panel, filtered by reseller's allowed nodes
        foreach ($panels as $panel) {
            if ($panel->panel_type === 'eylandoo') {
                $showNodesSelector = true;
                
                // Use cached method (5 minute cache)
                $allNodes = $panel->getCachedEylandooNodes();
                
                // If reseller has node whitelist, filter nodes
                if ($reseller->eylandoo_allowed_node_ids && !empty($reseller->eylandoo_allowed_node_ids)) {
                    $allowedNodeIds = $reseller->eylandoo_allowed_node_ids;
                    $nodes = array_filter($allNodes, function($node) use ($allowedNodeIds) {
                        // Note: PHP's in_array() with loose comparison handles string/int matching
                        // so '1' == 1 automatically
                        return in_array($node['id'], $allowedNodeIds);
                    });
                } else {
                    $nodes = $allNodes;
                }
                
                // Always set nodes array for Eylandoo panels
                // If no nodes available, provide default nodes [1, 2] as fallback
                if (!empty($nodes)) {
                    $eylandooNodes[$panel->id] = array_values($nodes);
                } else {
                    // No nodes found - use default IDs 1 and 2
                    $eylandooNodes[$panel->id] = [
                        ['id' => '1', 'name' => 'Node 1 (default)'],
                        ['id' => '2', 'name' => 'Node 2 (default)'],
                    ];
                }
                
                // Log node selection data for debugging (only if app.debug is true)
                if (config('app.debug')) {
                    Log::debug('Eylandoo nodes loaded for config creation', [
                        'reseller_id' => $reseller->id,
                        'panel_id' => $panel->id,
                        'panel_type' => $panel->panel_type,
                        'all_nodes_count' => count($allNodes),
                        'filtered_nodes_count' => count($eylandooNodes[$panel->id]),
                        'has_node_whitelist' => !empty($reseller->eylandoo_allowed_node_ids),
                        'allowed_node_ids' => $reseller->eylandoo_allowed_node_ids ?? [],
                        'showNodesSelector' => $showNodesSelector,
                        'using_defaults' => empty($nodes),
                    ]);
                }
            }
        }

        return view('reseller::configs.create', [
            'reseller' => $reseller,
            'panels' => $panels,
            'marzneshin_services' => $marzneshinServices,
            'eylandoo_nodes' => $eylandooNodes,
            'showNodesSelector' => $showNodesSelector,
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
            'connections' => 'nullable|integer|min:1|max:10',
            'comment' => 'nullable|string|max:200',
            'prefix' => 'nullable|string|max:50|regex:/^[a-zA-Z0-9_-]+$/',
            'custom_name' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_-]+$/',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'integer',
            'node_ids' => 'nullable|array',
            'node_ids.*' => 'integer',
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
        // Normalize to start of day for calendar-day boundaries
        $expiresAt = now()->addDays($expiresDays)->startOfDay();

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

        // Validate Eylandoo node whitelist
        $nodeIds = $request->node_ids ?? [];
        $filteredOutCount = 0;
        
        if ($panel->panel_type === 'eylandoo' && $reseller->eylandoo_allowed_node_ids) {
            $allowedNodeIds = $reseller->eylandoo_allowed_node_ids;

            foreach ($nodeIds as $nodeId) {
                // Note: PHP's in_array() with loose comparison handles string/int matching
                // so '1' == 1 automatically
                if (! in_array($nodeId, $allowedNodeIds)) {
                    $filteredOutCount++;
                    Log::warning('Node selection rejected - not in whitelist', [
                        'reseller_id' => $reseller->id,
                        'panel_id' => $panel->id,
                        'rejected_node_id' => $nodeId,
                        'allowed_node_ids' => $allowedNodeIds,
                    ]);
                }
            }
            
            if ($filteredOutCount > 0) {
                return back()->with('error', 'One or more selected nodes are not allowed for your account.');
            }
        }
        
        // Log node selection for Eylandoo configs
        if ($panel->panel_type === 'eylandoo') {
            Log::info('Config creation with Eylandoo nodes', [
                'reseller_id' => $reseller->id,
                'panel_id' => $panel->id,
                'selected_nodes_count' => count($nodeIds),
                'selected_node_ids' => $nodeIds,
                'filtered_out_count' => $filteredOutCount,
                'has_whitelist' => !empty($reseller->eylandoo_allowed_node_ids),
            ]);
        }

        DB::transaction(function () use ($request, $reseller, $panel, $trafficLimitBytes, $expiresAt, $expiresDays) {
            $provisioner = new ResellerProvisioner;

            // Get prefix and custom_name from request (with permission checks)
            $prefix = null;
            $customName = null;

            // Only allow prefix if user has permission
            if ($request->user()->can('configs.set_prefix') && $request->filled('prefix')) {
                $prefix = $request->input('prefix');
            }

            // Only allow custom_name if user has permission
            if ($request->user()->can('configs.set_custom_name') && $request->filled('custom_name')) {
                $customName = $request->input('custom_name');
            }

            // Create config record first
            $config = ResellerConfig::create([
                'reseller_id' => $reseller->id,
                'external_username' => '', // Will be set after provisioning
                'comment' => $request->input('comment'),
                'prefix' => $prefix,
                'custom_name' => $customName,
                'traffic_limit_bytes' => $trafficLimitBytes,
                'connections' => $request->input('connections'),
                'usage_bytes' => 0,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'panel_type' => $panel->panel_type,
                'panel_id' => $panel->id,
                'created_by' => $request->user()->id,
                'meta' => [
                    'node_ids' => $request->input('node_ids', []),
                ],
            ]);

            $username = $provisioner->generateUsername($reseller, 'config', $config->id, null, $prefix, $customName);
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
                'connections' => $request->input('connections', 1),
                'max_clients' => $request->input('connections', 1),
                'nodes' => $request->input('node_ids', []),
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

        // Try to disable on remote panel first
        $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

        if ($config->panel_id) {
            try {
                $panel = Panel::findOrFail($config->panel_id);
                $provisioner = new ResellerProvisioner;

                // Use panel->panel_type instead of config->panel_type
                $remoteResult = $provisioner->disableUser(
                    $panel->panel_type,
                    $panel->getCredentials(),
                    $config->panel_user_id
                );

                if (! $remoteResult['success']) {
                    Log::warning("Failed to disable config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                }
            } catch (\Exception $e) {
                Log::error("Exception disabling config {$config->id} on panel: ".$e->getMessage());
                $remoteResult['last_error'] = $e->getMessage();
            }
        }

        // Update local state after remote attempt
        $config->update([
            'status' => 'disabled',
            'disabled_at' => now(),
        ]);

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'manual_disabled',
            'meta' => [
                'user_id' => $request->user()->id,
                'remote_success' => $remoteResult['success'],
                'attempts' => $remoteResult['attempts'],
                'last_error' => $remoteResult['last_error'],
                'panel_id' => $config->panel_id,
                'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
            ],
        ]);

        // Create audit log entry
        AuditLog::log(
            action: 'config_manual_disabled',
            targetType: 'config',
            targetId: $config->id,
            reason: 'admin_action',
            meta: [
                'remote_success' => $remoteResult['success'],
                'attempts' => $remoteResult['attempts'],
                'last_error' => $remoteResult['last_error'],
                'panel_id' => $config->panel_id,
                'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
            ]
        );

        if (! $remoteResult['success']) {
            return back()->with('warning', 'Config disabled locally, but remote panel update failed after '.$remoteResult['attempts'].' attempts.');
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
        $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

        if ($config->panel_id) {
            try {
                $panel = Panel::findOrFail($config->panel_id);
                $provisioner = new ResellerProvisioner;

                // Use panel->panel_type instead of config->panel_type
                $remoteResult = $provisioner->enableUser(
                    $panel->panel_type,
                    $panel->getCredentials(),
                    $config->panel_user_id
                );

                if (! $remoteResult['success']) {
                    Log::warning("Failed to enable config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                }
            } catch (\Exception $e) {
                Log::error("Exception enabling config {$config->id} on panel: ".$e->getMessage());
                $remoteResult['last_error'] = $e->getMessage();
            }
        }

        // Update local state after remote attempt
        $config->update([
            'status' => 'active',
            'disabled_at' => null,
        ]);

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'manual_enabled',
            'meta' => [
                'user_id' => $request->user()->id,
                'remote_success' => $remoteResult['success'],
                'attempts' => $remoteResult['attempts'],
                'last_error' => $remoteResult['last_error'],
                'panel_id' => $config->panel_id,
                'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
            ],
        ]);

        // Create audit log entry
        AuditLog::log(
            action: 'config_manual_enabled',
            targetType: 'config',
            targetId: $config->id,
            reason: 'admin_action',
            meta: [
                'remote_success' => $remoteResult['success'],
                'attempts' => $remoteResult['attempts'],
                'last_error' => $remoteResult['last_error'],
                'panel_id' => $config->panel_id,
                'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
            ]
        );

        if (! $remoteResult['success']) {
            return back()->with('warning', 'Config enabled locally, but remote panel update failed after '.$remoteResult['attempts'].' attempts.');
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

                if (! $success) {
                    $remoteFailed = true;
                    Log::warning("Failed to delete config {$config->id} on remote panel {$panel->id}");
                }
            } catch (\Exception $e) {
                $remoteFailed = true;
                Log::error("Exception deleting config {$config->id} on panel: ".$e->getMessage());
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

        // Create audit log entry
        AuditLog::log(
            action: 'config_deleted',
            targetType: 'config',
            targetId: $config->id,
            reason: 'admin_action',
            meta: [
                'remote_failed' => $remoteFailed,
                'panel_id' => $config->panel_id,
            ]
        );

        if ($remoteFailed) {
            return back()->with('warning', 'Config deleted locally, but remote panel deletion failed.');
        }

        return back()->with('success', 'Config deleted successfully.');
    }

    public function edit(Request $request, ResellerConfig $config)
    {
        // Use policy authorization
        $this->authorize('update', $config);

        $reseller = $request->user()->reseller;

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        return view('reseller::configs.edit', [
            'reseller' => $reseller,
            'config' => $config,
        ]);
    }

    public function update(Request $request, ResellerConfig $config)
    {
        // Use policy authorization
        $this->authorize('update', $config);

        $reseller = $request->user()->reseller;

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'traffic_limit_gb' => 'required|numeric|min:0.1',
            'expires_at' => 'required|date|after_or_equal:today',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $trafficLimitBytes = (float) $request->input('traffic_limit_gb') * 1024 * 1024 * 1024;
        $expiresAt = \Carbon\Carbon::parse($request->input('expires_at'))->startOfDay();

        // Validation: traffic limit cannot be below current usage
        if ($trafficLimitBytes < $config->usage_bytes) {
            return back()->with('error', 'Traffic limit cannot be set below current usage ('.round($config->usage_bytes / (1024 * 1024 * 1024), 2).' GB).');
        }

        // Store old values for audit
        $oldTrafficLimit = $config->traffic_limit_bytes;
        $oldExpiresAt = $config->expires_at;

        $remoteResultFinal = null;

        DB::transaction(function () use ($config, $trafficLimitBytes, $expiresAt, $oldTrafficLimit, $oldExpiresAt, $request, &$remoteResultFinal) {
            // Update local config
            $config->update([
                'traffic_limit_bytes' => $trafficLimitBytes,
                'expires_at' => $expiresAt,
            ]);

            // Try to update on remote panel
            $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

            if ($config->panel_id) {
                try {
                    $panel = Panel::findOrFail($config->panel_id);
                    $provisioner = new ResellerProvisioner;

                    $remoteResult = $provisioner->updateUserLimits(
                        $panel->panel_type,
                        $panel->getCredentials(),
                        $config->panel_user_id,
                        $trafficLimitBytes,
                        $expiresAt
                    );

                    if (! $remoteResult['success']) {
                        Log::warning("Failed to update config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                    }
                } catch (\Exception $e) {
                    Log::error("Exception updating config {$config->id} on panel: ".$e->getMessage());
                    $remoteResult['last_error'] = $e->getMessage();
                }
            }

            $remoteResultFinal = $remoteResult;

            ResellerConfigEvent::create([
                'reseller_config_id' => $config->id,
                'type' => 'edited',
                'meta' => [
                    'user_id' => $request->user()->id,
                    'old_traffic_limit_bytes' => $oldTrafficLimit,
                    'new_traffic_limit_bytes' => $trafficLimitBytes,
                    'old_expires_at' => $oldExpiresAt?->toDateTimeString(),
                    'new_expires_at' => $expiresAt->toDateTimeString(),
                    'remote_success' => $remoteResult['success'],
                    'attempts' => $remoteResult['attempts'],
                    'last_error' => $remoteResult['last_error'],
                ],
            ]);

            // Create audit log entry
            AuditLog::log(
                action: 'reseller_config_edited',
                targetType: 'config',
                targetId: $config->id,
                reason: 'reseller_action',
                meta: [
                    'old_traffic_limit_gb' => round($oldTrafficLimit / (1024 * 1024 * 1024), 2),
                    'new_traffic_limit_gb' => round($trafficLimitBytes / (1024 * 1024 * 1024), 2),
                    'old_expires_at' => $oldExpiresAt?->format('Y-m-d'),
                    'new_expires_at' => $expiresAt->format('Y-m-d'),
                    'remote_success' => $remoteResult['success'],
                    'reseller_id' => $config->reseller_id,
                ]
            );
        });

        if ($remoteResultFinal && ! $remoteResultFinal['success']) {
            return redirect()->route('reseller.configs.index')
                ->with('warning', 'Config updated locally, but remote panel update failed after '.$remoteResultFinal['attempts'].' attempts.');
        }

        return redirect()->route('reseller.configs.index')
            ->with('success', 'Config updated successfully.');
    }

    public function resetUsage(Request $request, ResellerConfig $config)
    {
        // Use policy authorization
        $this->authorize('resetUsage', $config);

        $reseller = $request->user()->reseller;

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        $toSettle = $config->usage_bytes;

        $remoteResultFinal = null;
        $toSettleFinal = $toSettle;

        DB::transaction(function () use ($config, $toSettle, $request, &$remoteResultFinal) {
            // Settle current usage
            $meta = $config->meta ?? [];
            $currentSettled = (int) data_get($meta, 'settled_usage_bytes', 0);
            $newSettled = $currentSettled + $toSettle;

            $meta['settled_usage_bytes'] = $newSettled;
            $meta['last_reset_at'] = now()->toDateTimeString();

            // For Eylandoo configs, also zero the meta usage fields
            if ($config->panel_type === 'eylandoo') {
                $meta['used_traffic'] = 0;
                $meta['data_used'] = 0;
            }

            // Reset local usage
            $config->update([
                'usage_bytes' => 0,
                'meta' => $meta,
            ]);

            // Try to reset on remote panel
            $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

            if ($config->panel_id) {
                try {
                    $panel = Panel::findOrFail($config->panel_id);
                    $provisioner = new ResellerProvisioner;

                    $remoteResult = $provisioner->resetUserUsage(
                        $panel->panel_type,
                        $panel->getCredentials(),
                        $config->panel_user_id
                    );

                    if (! $remoteResult['success']) {
                        Log::warning("Failed to reset usage for config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                    }
                } catch (\Exception $e) {
                    Log::error("Exception resetting usage for config {$config->id} on panel: ".$e->getMessage());
                    $remoteResult['last_error'] = $e->getMessage();
                }
            }

            $remoteResultFinal = $remoteResult;

            // Recalculate and persist reseller aggregate after reset
            // Include settled_usage_bytes to prevent abuse
            $reseller = $config->reseller;
            $totalUsageBytesFromDB = $reseller->configs()
                ->get()
                ->sum(function ($c) {
                    return $c->usage_bytes + (int) data_get($c->meta, 'settled_usage_bytes', 0);
                });
            $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);

            Log::info('Config reset updated reseller aggregate', [
                'reseller_id' => $reseller->id,
                'config_id' => $config->id,
                'new_reseller_usage_bytes' => $totalUsageBytesFromDB,
            ]);

            ResellerConfigEvent::create([
                'reseller_config_id' => $config->id,
                'type' => 'usage_reset',
                'meta' => [
                    'user_id' => $request->user()->id,
                    'bytes_settled' => $toSettle,
                    'new_settled_total' => $newSettled,
                    'last_reset_at' => $meta['last_reset_at'],
                    'remote_success' => $remoteResult['success'],
                    'attempts' => $remoteResult['attempts'],
                    'last_error' => $remoteResult['last_error'],
                ],
            ]);

            // Create audit log entry
            AuditLog::log(
                action: 'config_usage_reset',
                targetType: 'config',
                targetId: $config->id,
                reason: 'reseller_action',
                meta: [
                    'bytes_settled' => $toSettle,
                    'bytes_settled_gb' => round($toSettle / (1024 * 1024 * 1024), 2),
                    'new_settled_total' => $newSettled,
                    'last_reset_at' => $meta['last_reset_at'],
                    'remote_success' => $remoteResult['success'],
                    'reseller_id' => $config->reseller_id,
                ]
            );
        });

        if ($remoteResultFinal && ! $remoteResultFinal['success']) {
            return back()->with('warning', 'Usage reset locally (settled '.round($toSettleFinal / (1024 * 1024 * 1024), 2).' GB), but remote panel reset failed after '.$remoteResultFinal['attempts'].' attempts.');
        }

        return back()->with('success', 'Usage reset successfully. Settled '.round($toSettleFinal / (1024 * 1024 * 1024), 2).' GB to your account.');
    }
}
