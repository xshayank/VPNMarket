<?php

namespace Modules\Reseller\Jobs;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfigEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerProvisioner;

class ResetResellerUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 600;

    public $backoff = [60, 180]; // Retry after 60s, then 180s

    protected Reseller $reseller;

    /**
     * Create a new job instance.
     */
    public function __construct(Reseller $reseller)
    {
        $this->reseller = $reseller;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Starting reseller usage reset for reseller {$this->reseller->id}");

        $provisioner = new ResellerProvisioner;
        $successCount = 0;
        $failCount = 0;
        $remoteSuccessCount = 0;
        $remoteFailCount = 0;
        $totalBytesSettled = 0;

        // Get all configs for this reseller (active and inactive)
        // Eager load panels to avoid N+1 queries
        $configs = $this->reseller->configs()->with('panel')->get();

        if ($configs->isEmpty()) {
            Log::info("Reseller {$this->reseller->id} has no configs to reset");
            return;
        }

        Log::info("Processing {$configs->count()} configs for reseller {$this->reseller->id}");

        foreach ($configs as $config) {
            try {
                $bytesToSettle = $config->usage_bytes;

                DB::transaction(function () use ($config, $bytesToSettle, $provisioner, &$successCount, &$remoteSuccessCount, &$remoteFailCount, &$totalBytesSettled) {
                    // Move current usage to settled
                    $meta = $config->meta ?? [];
                    $currentSettled = (int) data_get($meta, 'settled_usage_bytes', 0);
                    $newSettled = $currentSettled + $bytesToSettle;

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

                    if ($config->panel_id && $config->panel) {
                        try {
                            $remoteResult = $provisioner->resetUserUsage(
                                $config->panel->panel_type,
                                $config->panel->getCredentials(),
                                $config->panel_user_id
                            );

                            if ($remoteResult['success']) {
                                $remoteSuccessCount++;
                            } else {
                                $remoteFailCount++;
                                Log::warning("Failed to reset usage for config {$config->id} on remote panel {$config->panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                            }
                        } catch (\Exception $e) {
                            $remoteFailCount++;
                            Log::error("Exception resetting usage for config {$config->id} on panel: ".$e->getMessage());
                            $remoteResult['last_error'] = $e->getMessage();
                        }
                    }

                    // Emit event
                    ResellerConfigEvent::create([
                        'reseller_config_id' => $config->id,
                        'type' => 'usage_reset',
                        'meta' => [
                            'bytes_settled' => $bytesToSettle,
                            'new_settled_total' => $newSettled,
                            'last_reset_at' => $meta['last_reset_at'],
                            'remote_success' => $remoteResult['success'],
                            'attempts' => $remoteResult['attempts'],
                            'last_error' => $remoteResult['last_error'],
                            'triggered_by' => 'admin_reset',
                        ],
                    ]);

                    $successCount++;
                    $totalBytesSettled += $bytesToSettle;
                });
            } catch (\Exception $e) {
                $failCount++;
                Log::error("Failed to reset config {$config->id}: ".$e->getMessage());
            }
        }

        // Recompute reseller aggregate - sum only current usage_bytes (not settled)
        $currentUsageBytes = $this->reseller->configs()
            ->get()
            ->sum(function ($config) {
                return $config->usage_bytes;
            });

        $this->reseller->update(['traffic_used_bytes' => $currentUsageBytes]);

        Log::info("Reseller {$this->reseller->id} usage reset completed", [
            'configs_processed' => $successCount,
            'configs_failed' => $failCount,
            'remote_success' => $remoteSuccessCount,
            'remote_failed' => $remoteFailCount,
            'total_bytes_settled' => $totalBytesSettled,
            'new_traffic_used_bytes' => $currentUsageBytes,
        ]);

        // Create audit log entry
        AuditLog::log(
            action: 'reseller_usage_reset_completed',
            targetType: 'reseller',
            targetId: $this->reseller->id,
            reason: 'admin_action',
            meta: [
                'configs_processed' => $successCount,
                'configs_failed' => $failCount,
                'remote_success_count' => $remoteSuccessCount,
                'remote_fail_count' => $remoteFailCount,
                'total_bytes_settled' => $totalBytesSettled,
                'total_bytes_settled_gb' => round($totalBytesSettled / (1024 * 1024 * 1024), 2),
                'new_traffic_used_bytes' => $currentUsageBytes,
            ]
        );
    }
}
