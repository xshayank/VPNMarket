<?php

namespace Modules\Reseller\Services;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use Illuminate\Support\Facades\Log;

class ResellerTimeWindowEnforcer
{
    public function __construct(
        protected ResellerProvisioner $provisioner
    ) {
    }

    /**
     * Get the current time in app timezone with minute precision
     */
    private function getAppTimezoneNow(): \Illuminate\Support\Carbon
    {
        return now()->timezone(config('app.timezone', 'Asia/Tehran'))->startOfMinute();
    }

    /**
     * Get panel for config with caching to avoid duplicate queries
     */
    private function getPanel(int $panelId, array &$panelCache): ?Panel
    {
        if (!isset($panelCache[$panelId])) {
            $panelCache[$panelId] = Panel::find($panelId);
        }
        
        return $panelCache[$panelId];
    }

    /**
     * Suspend reseller if their time window has expired
     */
    public function suspendIfExpired(Reseller $reseller): bool
    {
        // Only check traffic-based resellers
        if (!$reseller->isTrafficBased()) {
            return false;
        }

        // Check if already suspended
        if ($reseller->isSuspended()) {
            return false;
        }

        // Check if window has expired
        $now = $this->getAppTimezoneNow();
        
        if (!$reseller->window_ends_at || $reseller->window_ends_at->startOfMinute()->gt($now)) {
            return false;
        }

        Log::info("Suspending reseller {$reseller->id} due to expired time window");
        
        if (!$reseller->window_ends_at || $reseller->window_ends_at->startOfMinute()->gt($now)) {
            return false;
        }

        Log::info("Suspending reseller {$reseller->id} due to expired time window");

        // Suspend the reseller
        $reseller->update(['status' => 'suspended']);

        // Create audit log
        AuditLog::log(
            action: 'reseller_time_window_suspended',
            targetType: 'reseller',
            targetId: $reseller->id,
            reason: 'time_window_expired',
            meta: [
                'window_ends_at' => $reseller->window_ends_at->toDateTimeString(),
                'suspended_at' => now()->toDateTimeString(),
            ],
            actorType: null,
            actorId: null  // System action
        );

        // Disable all active configs
        $this->disableResellerConfigs($reseller);

        return true;
    }

    /**
     * Reactivate reseller if their time window is valid again
     */
    public function reactivateIfEligible(Reseller $reseller): bool
    {
        // Only check traffic-based resellers
        if (!$reseller->isTrafficBased()) {
            return false;
        }

        // Only process suspended resellers
        if (!$reseller->isSuspended()) {
            return false;
        }

        // Check if window is now valid
        $now = $this->getAppTimezoneNow();
        
        if (!$reseller->window_ends_at || $reseller->window_ends_at->startOfMinute()->lte($now)) {
            return false;
        }

        Log::info("Reactivating reseller {$reseller->id} after time window extended");

        // Reactivate the reseller
        $reseller->update(['status' => 'active']);

        // Create audit log
        AuditLog::log(
            action: 'reseller_time_window_reactivated',
            targetType: 'reseller',
            targetId: $reseller->id,
            reason: 'time_window_extended',
            meta: [
                'window_ends_at' => $reseller->window_ends_at->toDateTimeString(),
                'reactivated_at' => now()->toDateTimeString(),
            ],
            actorType: null,
            actorId: null  // System action
        );

        // Re-enable configs that were disabled by time window enforcement
        $this->reenableResellerConfigs($reseller);

        return true;
    }

    /**
     * Disable all active configs for a reseller
     */
    protected function disableResellerConfigs(Reseller $reseller): void
    {
        $configs = ResellerConfig::where('reseller_id', $reseller->id)
            ->where('status', 'active')
            ->get();

        if ($configs->isEmpty()) {
            return;
        }

        Log::info("Disabling {$configs->count()} configs for reseller {$reseller->id}");

        $disabledCount = 0;
        $failedCount = 0;
        $panelCache = [];

        foreach ($configs as $config) {
            try {
                // Apply rate limiting: 3 ops/sec
                $this->provisioner->applyRateLimit($disabledCount);

                // Disable on remote panel
                $remoteResult = $this->provisioner->disableConfig($config);

                if (!$remoteResult['success']) {
                    Log::warning("Failed to disable config {$config->id} on remote panel after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                    $failedCount++;
                }

                // Update local status regardless of remote result
                $meta = $config->meta ?? [];
                $meta['suspended_by_time_window'] = true;
                
                $config->update([
                    'status' => 'disabled',
                    'disabled_at' => now(),
                    'meta' => $meta,
                ]);

                // Get panel type with caching
                $panelType = null;
                if ($config->panel_id) {
                    $panel = $this->getPanel($config->panel_id, $panelCache);
                    $panelType = $panel?->panel_type;
                }

                // Create config event
                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'auto_disabled',
                    'meta' => [
                        'reason' => 'reseller_time_window_expired',
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                        'panel_id' => $config->panel_id,
                        'panel_type_used' => $panelType,
                    ],
                ]);

                // Create audit log
                AuditLog::log(
                    action: 'reseller_config_disabled_by_time_window',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: 'reseller_time_window_expired',
                    meta: [
                        'reseller_id' => $reseller->id,
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                        'panel_id' => $config->panel_id,
                        'panel_type_used' => $panelType,
                    ],
                    actorType: null,
                    actorId: null  // System action
                );

                $disabledCount++;
            } catch (\Exception $e) {
                Log::error("Exception disabling config {$config->id}: " . $e->getMessage());
                $failedCount++;
            }
        }

        Log::info("Auto-disable completed for reseller {$reseller->id}: {$disabledCount} disabled, {$failedCount} failed");
    }

    /**
     * Re-enable configs that were disabled by time window enforcement
     */
    protected function reenableResellerConfigs(Reseller $reseller): void
    {
        // Find configs disabled by time window enforcement
        $configs = ResellerConfig::where('reseller_id', $reseller->id)
            ->where('status', 'disabled')
            ->get()
            ->filter(function ($config) {
                // Only re-enable if marked as suspended by time window
                return isset($config->meta['suspended_by_time_window']) && $config->meta['suspended_by_time_window'] === true;
            });

        if ($configs->isEmpty()) {
            return;
        }

        Log::info("Re-enabling {$configs->count()} configs for reseller {$reseller->id}");

        $enabledCount = 0;
        $failedCount = 0;
        $panelCache = [];

        foreach ($configs as $config) {
            try {
                // Apply rate limiting: 3 ops/sec
                $this->provisioner->applyRateLimit($enabledCount);

                // Enable on remote panel
                $remoteResult = $this->provisioner->enableConfig($config);

                if (!$remoteResult['success']) {
                    Log::warning("Failed to enable config {$config->id} on remote panel after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                    $failedCount++;
                }

                // Update local status - remove the time window flag
                $meta = $config->meta ?? [];
                unset($meta['suspended_by_time_window']);
                
                $config->update([
                    'status' => 'active',
                    'disabled_at' => null,
                    'meta' => $meta,
                ]);

                // Get panel type with caching
                $panelType = null;
                if ($config->panel_id) {
                    $panel = $this->getPanel($config->panel_id, $panelCache);
                    $panelType = $panel?->panel_type;
                }

                // Create config event
                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'auto_enabled',
                    'meta' => [
                        'reason' => 'reseller_time_window_extended',
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                        'panel_id' => $config->panel_id,
                        'panel_type_used' => $panelType,
                    ],
                ]);

                // Create audit log
                AuditLog::log(
                    action: 'reseller_config_enabled_by_time_window',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: 'reseller_time_window_extended',
                    meta: [
                        'reseller_id' => $reseller->id,
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                        'panel_id' => $config->panel_id,
                        'panel_type_used' => $panelType,
                    ],
                    actorType: null,
                    actorId: null  // System action
                );

                $enabledCount++;
            } catch (\Exception $e) {
                Log::error("Exception enabling config {$config->id}: " . $e->getMessage());
                $failedCount++;
            }
        }

        Log::info("Auto-enable completed for reseller {$reseller->id}: {$enabledCount} enabled, {$failedCount} failed");
    }
}
