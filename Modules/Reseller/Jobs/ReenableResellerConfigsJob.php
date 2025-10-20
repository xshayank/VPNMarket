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

    public function handle(): void
    {
        Log::info("Starting reseller config re-enable job");

        // Get suspended traffic-based resellers that now have quota and valid window
        $resellers = Reseller::where('status', 'suspended')
            ->where('type', 'traffic')
            ->get()
            ->filter(function ($reseller) {
                return $reseller->hasTrafficRemaining() && $reseller->isWindowValid();
            });

        if ($resellers->isEmpty()) {
            Log::info("No eligible resellers for re-enable");
            return;
        }

        Log::info("Found {$resellers->count()} eligible resellers for re-enable");

        foreach ($resellers as $reseller) {
            // Reactivate the reseller
            $reseller->update(['status' => 'active']);
            Log::info("Reseller {$reseller->id} reactivated after recovery");
            
            $this->reenableResellerConfigs($reseller);
        }

        Log::info("Reseller config re-enable job completed");
    }

    protected function reenableResellerConfigs(Reseller $reseller): void
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

            if (!$lastEvent) {
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
        $provisioner = new ResellerProvisioner();

        foreach ($configs as $config) {
            try {
                // Rate-limit: 3 configs per second
                if ($enabledCount > 0 && $enabledCount % 3 === 0) {
                    sleep(1);
                }

                // Enable on remote panel using the stored panel_id/panel_type
                $remoteSuccess = false;
                if ($config->panel_id) {
                    $panel = Panel::find($config->panel_id);
                    if ($panel) {
                        $remoteSuccess = $provisioner->enableUser(
                            $config->panel_type, 
                            $panel->getCredentials(), 
                            $config->panel_user_id
                        );
                    }
                }

                if (!$remoteSuccess) {
                    Log::warning("Failed to enable config {$config->id} on remote panel");
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
                        'remote_success' => $remoteSuccess,
                    ],
                ]);

                $enabledCount++;
            } catch (\Exception $e) {
                Log::error("Exception enabling config {$config->id}: " . $e->getMessage());
                $failedCount++;
            }
        }

        Log::info("Auto-enable completed for reseller {$reseller->id}: {$enabledCount} enabled, {$failedCount} failed");
    }
}
