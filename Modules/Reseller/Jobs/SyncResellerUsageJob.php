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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncResellerUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 600;

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

        foreach ($configs as $config) {
            $usage = $this->fetchConfigUsage($config);
            
            if ($usage !== null) {
                $config->update(['usage_bytes' => $usage]);
                $totalUsageBytes += $usage;

                // Check if config exceeded its own limits
                if ($config->usage_bytes >= $config->traffic_limit_bytes) {
                    $this->disableConfig($config, 'traffic_exceeded');
                } elseif ($config->isExpiredByTime()) {
                    $this->disableConfig($config, 'time_expired');
                }
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
            // Find the panel for this config
            // Since configs don't have a direct panel_id, we need to determine it
            // For now, we'll use the first active panel of the same type
            // In a production scenario, you'd want to store panel_id in reseller_configs
            $panel = Panel::where('panel_type', $config->panel_type)->first();
            
            if (!$panel) {
                Log::warning("No panel found for config {$config->id} with type {$config->panel_type}");
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
        return $user['up'] + $user['down'] ?? null;
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
        $reason = !$reseller->hasTrafficRemaining() ? 'reseller_traffic_exceeded' : 'reseller_window_expired';

        $configs = $reseller->configs()->where('status', 'active')->get();

        foreach ($configs as $config) {
            $this->disableConfig($config, $reason);
        }

        Log::info("All active configs disabled for reseller {$reseller->id} due to: {$reason}");
    }
}
