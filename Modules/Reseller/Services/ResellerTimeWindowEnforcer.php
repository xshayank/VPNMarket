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
    ) {}

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
        if (! isset($panelCache[$panelId])) {
            $panelCache[$panelId] = Panel::find($panelId);
        }

        return $panelCache[$panelId];
    }

    /**
     * Suspend reseller if their time window has expired OR they have no traffic remaining
     *
     * @param  Reseller  $reseller  The reseller to potentially suspend
     * @param  string  $reason  The reason for suspension: 'window_expired' or 'quota_exhausted'
     * @return bool True if reseller was suspended, false otherwise
     */
    public function suspendDueToLimits(Reseller $reseller, string $reason): bool
    {
        // Only check traffic-based resellers
        if (! $reseller->isTrafficBased()) {
            return false;
        }

        // Check if already suspended
        if ($reseller->isSuspended()) {
            return false;
        }

        Log::info("Suspending reseller {$reseller->id} due to {$reason}");

        // Suspend the reseller
        $reseller->update(['status' => 'suspended']);

        // Create audit log
        AuditLog::log(
            action: 'reseller_suspended_by_enforcement',
            targetType: 'reseller',
            targetId: $reseller->id,
            reason: $reason,
            meta: [
                'window_ends_at' => $reseller->window_ends_at?->toDateTimeString(),
                'suspended_at' => now()->toDateTimeString(),
                'traffic_used_bytes' => $reseller->traffic_used_bytes,
                'traffic_total_bytes' => $reseller->traffic_total_bytes,
            ],
            actorType: null,
            actorId: null  // System action
        );

        // Disable all active configs
        $this->disableResellerConfigs($reseller, $reason);

        return true;
    }

    /**
     * Suspend reseller if their time window has expired
     */
    public function suspendIfExpired(Reseller $reseller): bool
    {
        // Only check traffic-based resellers
        if (! $reseller->isTrafficBased()) {
            return false;
        }

        // Check if already suspended
        if ($reseller->isSuspended()) {
            return false;
        }

        // Check if window has expired
        $now = $this->getAppTimezoneNow();

        if (! $reseller->window_ends_at || $reseller->window_ends_at->startOfMinute()->gt($now)) {
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
     * Reactivate reseller if their time window is valid again AND they have traffic remaining
     */
    public function reactivateIfEligible(Reseller $reseller): bool
    {
        // Only check traffic-based resellers
        if (! $reseller->isTrafficBased()) {
            return false;
        }

        // Only process suspended resellers
        if (! $reseller->isSuspended()) {
            return false;
        }

        // Check if window is now valid
        $now = $this->getAppTimezoneNow();

        if (! $reseller->window_ends_at || $reseller->window_ends_at->startOfMinute()->lte($now)) {
            Log::debug("Skipping reactivation for reseller {$reseller->id}: window invalid or expired");

            return false;
        }

        // CRITICAL FIX: Check if reseller has traffic remaining before reactivation
        // This prevents reactivating a reseller who still has exhausted quota
        if (! $reseller->hasTrafficRemaining()) {
            Log::info("Skipping reactivation for reseller {$reseller->id}: window valid but no traffic remaining", [
                'traffic_used_bytes' => $reseller->traffic_used_bytes,
                'traffic_total_bytes' => $reseller->traffic_total_bytes,
            ]);

            return false;
        }

        Log::info("Reactivating reseller {$reseller->id} after time window extended and traffic available", [
            'traffic_used_bytes' => $reseller->traffic_used_bytes,
            'traffic_total_bytes' => $reseller->traffic_total_bytes,
            'window_ends_at' => $reseller->window_ends_at->toDateTimeString(),
        ]);

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
                'traffic_used_bytes' => $reseller->traffic_used_bytes,
                'traffic_total_bytes' => $reseller->traffic_total_bytes,
            ],
            actorType: null,
            actorId: null  // System action
        );

        // Re-enable configs synchronously with fallback logic
        $this->reenableConfigsWithFallback($reseller);

        return true;
    }

    /**
     * Re-enable configs for a reseller, using synchronous job dispatch with inline fallback
     *
     * @param  Reseller  $reseller  The reseller whose configs should be re-enabled
     */
    protected function reenableConfigsWithFallback(Reseller $reseller): void
    {
        Log::info("Starting synchronous config re-enable for reseller {$reseller->id}");

        try {
            // Attempt to run the job synchronously
            if (class_exists('\Modules\Reseller\Jobs\ReenableResellerConfigsJob')) {
                Log::info("Dispatching ReenableResellerConfigsJob (sync) for reseller {$reseller->id}");
                \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatchSync($reseller->id);
                Log::info("ReenableResellerConfigsJob completed synchronously for reseller {$reseller->id}");
            } else {
                Log::warning("ReenableResellerConfigsJob class not found, using inline fallback for reseller {$reseller->id}");
                $this->inlineReenableConfigs($reseller);
            }
        } catch (\Exception $e) {
            Log::error("ReenableResellerConfigsJob failed for reseller {$reseller->id}: {$e->getMessage()}", [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            Log::info("Falling back to inline config re-enable for reseller {$reseller->id}");

            try {
                $this->inlineReenableConfigs($reseller);
            } catch (\Exception $fallbackException) {
                Log::error("Inline fallback also failed for reseller {$reseller->id}: {$fallbackException->getMessage()}");
            }
        }
    }

    /**
     * Inline fallback to re-enable configs when job system is unavailable
     *
     * @param  Reseller  $reseller  The reseller whose configs should be re-enabled
     */
    protected function inlineReenableConfigs(Reseller $reseller): void
    {
        Log::info("Starting inline config re-enable for reseller {$reseller->id}");

        // Find configs with suspension markers
        $configs = ResellerConfig::where('reseller_id', $reseller->id)
            ->where('status', 'disabled')
            ->get()
            ->filter(function ($config) use ($reseller) {
                $meta = $config->meta ?? [];

                // Check for various suspension markers
                $disabledByReseller = $meta['disabled_by_reseller_suspension'] ?? null;
                $suspendedByWindow = $meta['suspended_by_time_window'] ?? null;
                $disabledByResellerId = $meta['disabled_by_reseller_id'] ?? null;

                // Consider truthy if: true, 1, '1', 'true' or if disabled_by_reseller_id matches
                $isMarkedByReseller = $disabledByReseller === true
                    || $disabledByReseller === 1
                    || $disabledByReseller === '1'
                    || $disabledByReseller === 'true';

                $isMarkedByWindow = $suspendedByWindow === true
                    || $suspendedByWindow === 1
                    || $suspendedByWindow === '1'
                    || $suspendedByWindow === 'true';

                return $isMarkedByReseller || $isMarkedByWindow || ($disabledByResellerId === $reseller->id);
            });

        if ($configs->isEmpty()) {
            Log::info("No configs found for inline re-enable for reseller {$reseller->id}");

            return;
        }

        Log::info("Found {$configs->count()} configs for inline re-enable for reseller {$reseller->id}");

        $enabledCount = 0;
        $failedCount = 0;
        $panelCache = [];

        foreach ($configs as $config) {
            try {
                // Apply rate limiting
                $this->provisioner->applyRateLimit($enabledCount);

                Log::info("Inline re-enabling config {$config->id} for reseller {$reseller->id}", [
                    'panel_id' => $config->panel_id,
                    'panel_user_id' => $config->panel_user_id,
                ]);

                // Best-effort remote enable
                $remoteResult = $this->provisioner->enableConfig($config);

                if (! $remoteResult['success']) {
                    Log::warning("Remote enable failed for config {$config->id}, proceeding with DB update", [
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                    ]);
                }

                // Update local status and clear markers
                $meta = $config->meta ?? [];
                unset($meta['disabled_by_reseller_suspension']);
                unset($meta['disabled_by_reseller_suspension_reason']);
                unset($meta['disabled_by_reseller_suspension_at']);
                unset($meta['disabled_by_reseller_id']);
                unset($meta['disabled_at']);
                unset($meta['suspended_by_time_window']);

                $config->update([
                    'status' => 'active',
                    'disabled_at' => null,
                    'meta' => $meta,
                ]);

                Log::info("Config {$config->id} re-enabled (inline) for reseller {$reseller->id}", [
                    'remote_success' => $remoteResult['success'],
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
                        'reason' => 'reseller_recovered_inline',
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                        'panel_id' => $config->panel_id,
                        'panel_type_used' => $panelType,
                    ],
                ]);

                // Create audit log
                AuditLog::log(
                    action: 'config_auto_enabled',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: 'reseller_recovered_inline',
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
                Log::error("Failed to inline re-enable config {$config->id}: {$e->getMessage()}");
                $failedCount++;
            }
        }

        Log::info("Inline config re-enable completed for reseller {$reseller->id}: {$enabledCount} enabled, {$failedCount} failed");
    }

    /**
     * Disable all active configs for a reseller
     *
     * @param  Reseller  $reseller  The reseller whose configs should be disabled
     * @param  string  $reason  The reason for disabling: 'window_expired', 'quota_exhausted', etc.
     */
    protected function disableResellerConfigs(Reseller $reseller, string $reason = 'reseller_time_window_expired'): void
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

                if (! $remoteResult['success']) {
                    Log::warning("Failed to disable config {$config->id} on remote panel after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                    $failedCount++;
                }

                // Update local status regardless of remote result
                $meta = $config->meta ?? [];
                $meta['suspended_by_time_window'] = true;
                $meta['disabled_by_reseller_suspension'] = true;  // Boolean true for consistency
                $meta['disabled_by_reseller_id'] = $reseller->id;
                $meta['disabled_by_reseller_suspension_reason'] = $reason;
                $meta['disabled_at'] = now()->toIso8601String();
                $meta['disabled_by_reseller_suspension_at'] = now()->toIso8601String();

                $config->update([
                    'status' => 'disabled',
                    'disabled_at' => now(),
                    'meta' => $meta,
                ]);

                // Log per-config disable
                Log::info("Config {$config->id} auto-disabled by reseller time window enforcement", [
                    'reseller_id' => $reseller->id,
                    'config_id' => $config->id,
                    'reason' => $reason,
                    'panel_id' => $config->panel_id,
                    'remote_success' => $remoteResult['success'],
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
                        'reason' => $reason,
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
                    reason: $reason,
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
                Log::error("Exception disabling config {$config->id}: ".$e->getMessage());
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

                if (! $remoteResult['success']) {
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
                Log::error("Exception enabling config {$config->id}: ".$e->getMessage());
                $failedCount++;
            }
        }

        Log::info("Auto-enable completed for reseller {$reseller->id}: {$enabledCount} enabled, {$failedCount} failed");
    }
}
