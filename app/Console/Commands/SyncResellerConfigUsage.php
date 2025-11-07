<?php

namespace App\Console\Commands;

use App\Models\Panel;
use App\Models\ResellerConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\SyncResellerUsageJob;

class SyncResellerConfigUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reseller:usage:sync-one {--config= : The ID of the config to sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually sync usage for a specific reseller config (diagnostic tool)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $configId = $this->option('config');

        if (! $configId) {
            $this->error('Please provide a config ID using --config={id}');

            return 1;
        }

        $config = ResellerConfig::find($configId);

        if (! $config) {
            $this->error("Config with ID {$configId} not found");

            return 1;
        }

        $this->info("=== Syncing Usage for Config #{$configId} ===");
        $this->newLine();

        $this->displayConfigInfo($config);
        $this->newLine();

        $this->info('📡 Fetching usage from panel...');
        $this->newLine();

        try {
            // Create instance of the job and call the public method
            $job = new SyncResellerUsageJob;
            $usage = $job->fetchConfigUsage($config);

            if ($usage === null) {
                $this->error('❌ Failed to fetch usage (returned null - hard failure)');
                $this->warn('Check logs for details. Common causes:');
                $this->line('  - Panel not found or credentials invalid');
                $this->line('  - HTTP error or network issue');
                $this->line('  - User not found on panel');

                return 1;
            }

            $this->info('✅ Successfully fetched usage from panel');
            $this->line("📊 Usage: {$usage} bytes ({$this->formatBytes($usage)})");
            $this->newLine();

            // Update the config
            $oldUsage = $config->usage_bytes;
            $config->update(['usage_bytes' => $usage]);

            $this->info('💾 Updated config usage_bytes in database');
            $this->line("  Previous: {$oldUsage} bytes ({$this->formatBytes($oldUsage)})");
            $this->line("  Current:  {$usage} bytes ({$this->formatBytes($usage)})");
            $this->line('  Delta:    '.($usage - $oldUsage).' bytes ('.$this->formatBytes($usage - $oldUsage).')');
            $this->newLine();

            // Recalculate and persist reseller aggregate immediately
            // Include settled_usage_bytes to prevent abuse
            // CRITICAL: Use withTrashed() to include soft-deleted configs in usage calculation
            // This prevents accounting bug where deleting a config would erase its historical usage
            $reseller = $config->reseller;
            $oldResellerUsage = $reseller->traffic_used_bytes;
            $totalUsageBytesFromDB = $reseller->configs()
                ->withTrashed()
                ->get()
                ->sum(function ($c) {
                    return $c->usage_bytes + (int) data_get($c->meta, 'settled_usage_bytes', 0);
                });
            $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);

            $this->info('💾 Updated reseller aggregate traffic_used_bytes');
            $this->line("  Reseller ID: {$reseller->id}");
            $this->line("  Previous: {$oldResellerUsage} bytes ({$this->formatBytes($oldResellerUsage)})");
            $this->line("  Current:  {$totalUsageBytesFromDB} bytes ({$this->formatBytes($totalUsageBytesFromDB)})");
            $this->line('  Delta:    '.($totalUsageBytesFromDB - $oldResellerUsage).' bytes ('.$this->formatBytes($totalUsageBytesFromDB - $oldResellerUsage).')');
            $this->newLine();

            Log::info('Manual config sync updated reseller aggregate', [
                'reseller_id' => $reseller->id,
                'config_id' => $config->id,
                'old_reseller_usage_bytes' => $oldResellerUsage,
                'new_reseller_usage_bytes' => $totalUsageBytesFromDB,
            ]);

            $this->info('✅ Sync completed successfully');

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Exception occurred: '.$e->getMessage());
            $this->line('Stack trace:');
            $this->line($e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Display config information
     */
    protected function displayConfigInfo(ResellerConfig $config): void
    {
        $this->line('📋 Config Information:');
        $this->line("  ID: {$config->id}");
        $this->line("  Reseller ID: {$config->reseller_id}");
        $this->line("  Status: {$config->status}");
        $this->line("  Panel Type: {$config->panel_type}");
        $this->line('  Panel ID: '.($config->panel_id ?? 'N/A'));
        $this->line("  Panel User ID: {$config->panel_user_id}");
        $this->line('  External Username: '.($config->external_username ?? 'N/A'));
        $this->line("  Current Usage: {$config->usage_bytes} bytes ({$this->formatBytes($config->usage_bytes)})");
        $this->line("  Traffic Limit: {$config->traffic_limit_bytes} bytes ({$this->formatBytes($config->traffic_limit_bytes)})");

        // Display panel info if available
        if ($config->panel_id) {
            $panel = Panel::find($config->panel_id);
            if ($panel) {
                $this->newLine();
                $this->line('🔧 Panel Information:');
                $this->line("  Panel Name: {$panel->name}");
                $this->line("  Panel Type: {$panel->panel_type}");
                $this->line("  URL: {$panel->url}");
            }
        }
    }

    /**
     * Format bytes to human-readable format
     */
    protected function formatBytes(int $bytes): string
    {
        $sign = $bytes < 0 ? '-' : '';
        $abs = abs($bytes);

        return $sign.number_format($abs / (1024 * 1024), 2).' MB';
    }
}
