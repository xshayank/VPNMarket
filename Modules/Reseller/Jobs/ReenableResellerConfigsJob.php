<?php

namespace Modules\Reseller\Jobs;

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

    public function handle(ResellerProvisioner $provisioner): void
    {
        Log::info('Starting reseller config re-enable job');

        // Get suspended traffic-based resellers that now have quota and valid window
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
                
                return $hasTrafficRemaining && $isWindowValid;
            });

        if ($resellers->isEmpty()) {
            Log::info('No eligible resellers for re-enable');

            return;
        }

        Log::info("Found {$resellers->count()} eligible resellers for re-enable");

        foreach ($resellers as $reseller) {
            // Reactivate the reseller
            $reseller->update(['status' => 'active']);
            Log::info("Reseller {$reseller->id} reactivated after recovery");

            $this->reenableResellerConfigs($reseller, $provisioner);
        }

        Log::info('Reseller config re-enable job completed');
    }

    protected function reenableResellerConfigs(Reseller $reseller, ResellerProvisioner $provisioner): void
    {
        // Find disabled configs that were auto-disabled by the system
        $configs = ResellerConfig::where('reseller_id', $reseller->id)
            ->where('status', 'disabled')
            ->whereHas('events', function ($query) {
                $query->where('type', 'auto_disabled')
                    ->whereJsonContains('meta->reason', 'reseller_quota_exhausted')
                    ->orWhereJsonContains('meta->reason', 'reseller_window_expired');
            })
            ->get();

        // Filter configs to only those whose last event was auto_disabled with the right reason
        $configs = $configs->filter(function ($config) {
            $lastEvent = $config->events()
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $lastEvent) {
                return false;
            }

            // Only re-enable if the last event was auto_disabled with reseller-level reason
            return $lastEvent->type === 'auto_disabled'
                && isset($lastEvent->meta['reason'])
                && in_array($lastEvent->meta['reason'], ['reseller_quota_exhausted', 'reseller_window_expired']);
        });

        if ($configs->isEmpty()) {
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

                // Update local status regardless of remote result
                $config->update([
                    'status' => 'active',
                    'disabled_at' => null,
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
