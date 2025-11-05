<?php

namespace Modules\Reseller\Jobs;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerProvisioner;

class ReenableResellerConfigsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 600;

    public ?int $resellerId;

    /**
     * Create a new job instance.
     *
     * @param  int|null  $resellerId  Specific reseller ID to process, or null to process all eligible
     */
    public function __construct(?int $resellerId = null)
    {
        $this->resellerId = $resellerId;
    }

    public function handle(ResellerProvisioner $provisioner): void
    {
        Log::info('Starting reseller config re-enable job', ['reseller_id' => $this->resellerId]);

        // If specific reseller ID provided, load that reseller directly
        // and check conditions (don't filter by suspended status)
        if ($this->resellerId !== null) {
            Log::info("Processing specific reseller", ['reseller_id' => $this->resellerId]);
            
            $reseller = Reseller::where('id', $this->resellerId)
                ->where('type', 'traffic')
                ->first();
                
            if (!$reseller) {
                Log::info('Reseller not found or not traffic-based', ['reseller_id' => $this->resellerId]);
                return;
            }
            
            // Apply grace thresholds for consistency with disable logic
            $resellerGrace = $this->getResellerGraceSettings();
            $effectiveResellerLimit = $this->applyGrace(
                $reseller->traffic_total_bytes,
                $resellerGrace['percent'],
                $resellerGrace['bytes']
            );
            
            $hasTrafficRemaining = $reseller->traffic_used_bytes < $effectiveResellerLimit;
            $isWindowValid = $reseller->isWindowValid();
            
            // Log decision for debugging
            if (!$hasTrafficRemaining || !$isWindowValid) {
                Log::info("Skipping reseller {$reseller->id} - not eligible for re-enable", [
                    'has_traffic_remaining' => $hasTrafficRemaining,
                    'is_window_valid' => $isWindowValid,
                    'traffic_used_bytes' => $reseller->traffic_used_bytes,
                    'traffic_total_bytes' => $reseller->traffic_total_bytes,
                    'effective_limit' => $effectiveResellerLimit,
                    'window_ends_at' => $reseller->window_ends_at?->toDateTimeString(),
                ]);
                return;
            }
            
            $resellers = collect([$reseller]);
        } else {
            // When no specific reseller ID, find all suspended traffic-based resellers
            $resellers = Reseller::where('status', 'suspended')
                ->where('type', 'traffic')
                ->get()
                ->filter(function ($reseller) {
                    // Apply grace thresholds for consistency with disable logic
                    $resellerGrace = $this->getResellerGraceSettings();
                    $effectiveResellerLimit = $this->applyGrace(
                        $reseller->traffic_total_bytes,
                        $resellerGrace['percent'],
                        $resellerGrace['bytes']
                    );
                    
                    $hasTrafficRemaining = $reseller->traffic_used_bytes < $effectiveResellerLimit;
                    $isWindowValid = $reseller->isWindowValid();
                    
                    // Log decision for debugging
                    if (!$hasTrafficRemaining || !$isWindowValid) {
                        Log::info("Skipping reseller {$reseller->id} - not eligible for re-enable", [
                            'has_traffic_remaining' => $hasTrafficRemaining,
                            'is_window_valid' => $isWindowValid,
                            'traffic_used_bytes' => $reseller->traffic_used_bytes,
                            'traffic_total_bytes' => $reseller->traffic_total_bytes,
                            'effective_limit' => $effectiveResellerLimit,
                            'window_ends_at' => $reseller->window_ends_at?->toDateTimeString(),
                        ]);
                    }
                    
                    return $hasTrafficRemaining && $isWindowValid;
                });
        }

        if ($resellers->isEmpty()) {
            Log::info('No eligible resellers for re-enable', ['reseller_id' => $this->resellerId]);

            return;
        }

        Log::info("Found {$resellers->count()} eligible resellers for re-enable");

        foreach ($resellers as $reseller) {
            // Reactivate the reseller if still suspended
            if ($reseller->status === 'suspended') {
                $reseller->update(['status' => 'active']);
                Log::info("Reseller {$reseller->id} reactivated after recovery");

                // Create audit log for reseller activation
                AuditLog::log(
                    action: 'reseller_activated',
                    targetType: 'reseller',
                    targetId: $reseller->id,
                    reason: 'reseller_recovered',
                    meta: [
                        'traffic_used_bytes' => $reseller->traffic_used_bytes,
                        'traffic_total_bytes' => $reseller->traffic_total_bytes,
                        'window_ends_at' => $reseller->window_ends_at?->toDateTimeString(),
                    ],
                    actorType: null,
                    actorId: null  // System action
                );
            } else {
                Log::info("Reseller {$reseller->id} already active, skipping reactivation");
            }

            $this->reenableResellerConfigs($reseller, $provisioner);
        }

        Log::info('Reseller config re-enable job completed');
    }

    protected function reenableResellerConfigs(Reseller $reseller, ResellerProvisioner $provisioner): void
    {
        // Find disabled configs that were auto-disabled by reseller suspension
        // This includes both quota exhaustion and time window expiration markers
        $configs = ResellerConfig::where('reseller_id', $reseller->id)
            ->where('status', 'disabled')
            ->get()
            ->filter(function ($config) {
                // Re-enable configs with either marker:
                // 1. disabled_by_reseller_suspension (quota exhaustion)
                // 2. suspended_by_time_window (time window expiration)
                // Explicitly check for true to avoid re-enabling configs with marker set to false
                $hasQuotaMarker = isset($config->meta['disabled_by_reseller_suspension']) 
                    && $config->meta['disabled_by_reseller_suspension'] === true;
                $hasTimeWindowMarker = isset($config->meta['suspended_by_time_window']) 
                    && $config->meta['suspended_by_time_window'] === true;
                    
                return $hasQuotaMarker || $hasTimeWindowMarker;
            });

        if ($configs->isEmpty()) {
            Log::info("No configs marked for re-enable for reseller {$reseller->id}");
            return;
        }

        Log::info("Re-enabling {$configs->count()} configs for reseller {$reseller->id}");

        $enabledCount = 0;
        $failedCount = 0;

        foreach ($configs as $config) {
            try {
                // Apply micro-sleep rate limiting: 3 ops/sec evenly distributed
                $provisioner->applyRateLimit($enabledCount);

                // Enable on remote panel using enableConfig method
                $remoteResult = $provisioner->enableConfig($config);

                if (! $remoteResult['success']) {
                    Log::warning("Failed to enable config {$config->id} on remote panel after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                    $failedCount++;
                }

                // Update local status and clear both suspension markers
                $meta = $config->meta ?? [];
                unset($meta['disabled_by_reseller_suspension']);
                unset($meta['disabled_by_reseller_suspension_reason']);
                unset($meta['disabled_by_reseller_suspension_at']);
                unset($meta['suspended_by_time_window']);
                
                $config->update([
                    'status' => 'active',
                    'disabled_at' => null,
                    'meta' => $meta,
                ]);

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'auto_enabled',
                    'meta' => [
                        'reason' => 'reseller_recovered',
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                        'panel_id' => $config->panel_id,
                        'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
                    ],
                ]);

                // Create audit log entry
                AuditLog::log(
                    action: 'config_auto_enabled',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: 'reseller_recovered',
                    meta: [
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                        'panel_id' => $config->panel_id,
                        'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
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

    /**
     * Calculate the effective limit with grace threshold applied
     * 
     * @param int $limit The base limit in bytes
     * @param float $gracePercent Grace percentage (e.g., 2.0 for 2%)
     * @param int $graceBytes Grace in bytes (e.g., 50MB)
     * @return int The limit plus maximum grace
     */
    protected function applyGrace(int $limit, float $gracePercent, int $graceBytes): int
    {
        $percentGrace = (int) ($limit * ($gracePercent / 100));
        $maxGrace = max($percentGrace, $graceBytes);
        
        return $limit + $maxGrace;
    }

    /**
     * Get grace settings for reseller-level checks
     * 
     * @return array ['percent' => float, 'bytes' => int]
     */
    protected function getResellerGraceSettings(): array
    {
        return [
            'percent' => (float) \App\Models\Setting::get('reseller.auto_disable_grace_percent', 2.0),
            'bytes' => (int) \App\Models\Setting::get('reseller.auto_disable_grace_bytes', 50 * 1024 * 1024), // 50MB
        ];
    }
}
