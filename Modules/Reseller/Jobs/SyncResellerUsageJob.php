<?php

namespace Modules\Reseller\Jobs;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\Setting;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use App\Services\XUIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncResellerUsageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 600;
    public $uniqueFor = 300; // 5 minutes

    public function handle(): void
    {
        Log::info("Starting reseller usage sync");

        // Get all active resellers with traffic-based configs
        $resellers = Reseller::where('status', 'active')
            ->where('type', 'traffic')
            ->get();

        foreach ($resellers as $reseller) {
            $this->syncResellerUsage($reseller);
        }

        Log::info("Reseller usage sync completed");
    }

    protected function syncResellerUsage(Reseller $reseller): void
    {
        Log::info("Syncing usage for reseller {$reseller->id}");

        $configs = $reseller->configs()
            ->where('status', 'active')
            ->get();

        if ($configs->isEmpty()) {
            return;
        }

        $totalUsageBytes = 0;
        $allowConfigOverrun = Setting::getBool('reseller.allow_config_overrun', true);

        foreach ($configs as $config) {
            try {
                $usage = $this->fetchConfigUsage($config);
                
                if ($usage !== null) {
                    $config->update(['usage_bytes' => $usage]);
                    $totalUsageBytes += $usage;

                    // Only check per-config limits if config overrun is NOT allowed
                    if (!$allowConfigOverrun) {
                        // Check if config exceeded its own limits
                        if ($config->usage_bytes >= $config->traffic_limit_bytes) {
                            $this->disableConfig($config, 'traffic_exceeded');
                        } elseif ($config->isExpiredByTime()) {
                            $this->disableConfig($config, 'time_expired');
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("Error syncing config {$config->id}: " . $e->getMessage());
            }
        }

        // Update reseller's total traffic usage
        $reseller->update(['traffic_used_bytes' => $totalUsageBytes]);

        // Check reseller-level limits
        if (!$reseller->hasTrafficRemaining() || !$reseller->isWindowValid()) {
            $this->disableResellerConfigs($reseller);
        }
    }

    protected function fetchConfigUsage(ResellerConfig $config): ?int
    {
        try {
            // Find the panel for this config - use exact panel_id if available, otherwise fallback to type
            if ($config->panel_id) {
                $panel = Panel::find($config->panel_id);
            } else {
                $panel = Panel::where('panel_type', $config->panel_type)->first();
            }
            
            if (!$panel) {
                Log::warning("No panel found for config {$config->id} (panel_id: {$config->panel_id}, type: {$config->panel_type})");
                return null;
            }

            $credentials = $panel->getCredentials();

            switch ($config->panel_type) {
                case 'marzban':
                    return $this->fetchMarzbanUsage($credentials, $config->panel_user_id);
                    
                case 'marzneshin':
                    return $this->fetchMarzneshinUsage($credentials, $config->panel_user_id);
                    
                case 'xui':
                    return $this->fetchXUIUsage($credentials, $config->panel_user_id);
                    
                default:
                    return null;
            }
        } catch (\Exception $e) {
            Log::error("Failed to fetch usage for config {$config->id}: " . $e->getMessage());
            return null;
        }
    }

    protected function fetchMarzbanUsage(array $credentials, string $username): ?int
    {
        $service = new MarzbanService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password']
        );

        if (!$service->login()) {
            return null;
        }

        $user = $service->getUser($username);
        return $user['used_traffic'] ?? null;
    }

    protected function fetchMarzneshinUsage(array $credentials, string $username): ?int
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
        
        $service = new MarzneshinService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );

        if (!$service->login()) {
            return null;
        }

        $user = $service->getUser($username);
        return $user['used_traffic'] ?? null;
    }

    protected function fetchXUIUsage(array $credentials, string $username): ?int
    {
        $service = new XUIService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password']
        );

        if (!$service->login()) {
            return null;
        }

        $user = $service->getUser($username);
        return ($user['up'] + $user['down']) ?? null;
    }

    protected function disableConfig(ResellerConfig $config, string $reason): void
    {
        $config->update([
            'status' => $reason === 'time_expired' ? 'expired' : 'disabled',
            'disabled_at' => now(),
        ]);

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'auto_disabled',
            'meta' => ['reason' => $reason],
        ]);

        Log::info("Config {$config->id} disabled due to: {$reason}");
    }

    protected function disableResellerConfigs(Reseller $reseller): void
    {
        $reason = !$reseller->hasTrafficRemaining() ? 'reseller_quota_exhausted' : 'reseller_window_expired';

        $configs = $reseller->configs()->where('status', 'active')->get();

        if ($configs->isEmpty()) {
            return;
        }

        Log::info("Starting auto-disable for reseller {$reseller->id}: {$configs->count()} configs, reason: {$reason}");

        $disabledCount = 0;
        $failedCount = 0;
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();

        foreach ($configs as $config) {
            try {
                // Rate-limit: 3 configs per second
                if ($disabledCount > 0 && $disabledCount % 3 === 0) {
                    sleep(1);
                }

                // Disable on remote panel using the stored panel_id/panel_type
                $remoteSuccess = false;
                if ($config->panel_id) {
                    $panel = Panel::find($config->panel_id);
                    if ($panel) {
                        $remoteSuccess = $provisioner->disableUser(
                            $config->panel_type, 
                            $panel->getCredentials(), 
                            $config->panel_user_id
                        );
                    }
                }

                if (!$remoteSuccess) {
                    Log::warning("Failed to disable config {$config->id} on remote panel");
                    $failedCount++;
                }

                // Update local status regardless of remote result
                $config->update([
                    'status' => 'disabled',
                    'disabled_at' => now(),
                ]);

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'auto_disabled',
                    'meta' => [
                        'reason' => $reason,
                        'remote_success' => $remoteSuccess,
                    ],
                ]);

                $disabledCount++;
            } catch (\Exception $e) {
                Log::error("Exception disabling config {$config->id}: " . $e->getMessage());
                $failedCount++;
            }
        }

        Log::info("Auto-disable completed for reseller {$reseller->id}: {$disabledCount} disabled, {$failedCount} failed");
    }
}
