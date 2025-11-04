<?php

namespace App\Console\Commands;

use App\Models\Panel;
use App\Models\ResellerConfig;
use Illuminate\Console\Command;
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
            // Create a temporary instance of the job to use its method
            $job = new SyncResellerUsageJob;
            
            // Use reflection to call the protected method
            $reflection = new \ReflectionClass($job);
            $method = $reflection->getMethod('fetchConfigUsage');
            $method->setAccessible(true);
            
            $usage = $method->invoke($job, $config);

            if ($usage === null) {
                $this->error('❌ Failed to fetch usage (returned null - hard failure)');
                $this->warn('Check logs for details. Common causes:');
                $this->line('  - Panel not found or credentials invalid');
                $this->line('  - HTTP error or network issue');
                $this->line('  - User not found on panel');

                return 1;
            }

            $this->info('✅ Successfully fetched usage from panel');
            $this->line("📊 Usage: {$usage} bytes (".number_format($usage / (1024 * 1024), 2).' MB)');
            $this->newLine();

            // Update the config
            $oldUsage = $config->usage_bytes;
            $config->update(['usage_bytes' => $usage]);

            $this->info('💾 Updated config usage_bytes in database');
            $this->line("  Previous: {$oldUsage} bytes (".number_format($oldUsage / (1024 * 1024), 2).' MB)');
            $this->line("  Current:  {$usage} bytes (".number_format($usage / (1024 * 1024), 2).' MB)');
            $this->line('  Delta:    '.($usage - $oldUsage).' bytes ('.number_format(($usage - $oldUsage) / (1024 * 1024), 2).' MB)');
            $this->newLine();

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
        $this->line("  Panel ID: ".($config->panel_id ?? 'N/A'));
        $this->line("  Panel User ID: {$config->panel_user_id}");
        $this->line("  External Username: ".($config->external_username ?? 'N/A'));
        $this->line("  Current Usage: {$config->usage_bytes} bytes (".number_format($config->usage_bytes / (1024 * 1024), 2).' MB)');
        $this->line("  Traffic Limit: {$config->traffic_limit_bytes} bytes (".number_format($config->traffic_limit_bytes / (1024 * 1024), 2).' MB)');

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
}
