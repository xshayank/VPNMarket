<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResellerEnforcementHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reseller:enforcement:health';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display health check for reseller enforcement system including settings, queue status, and recent audit events';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Reseller Enforcement System Health Check ===');
        $this->newLine();

        $this->displaySettings();
        $this->newLine();

        $this->displayQueueConnection();
        $this->newLine();

        $this->displaySchedulerStatus();
        $this->newLine();

        $this->displayResellerStats();
        $this->newLine();

        $this->displayRecentAuditEvents();
        $this->newLine();

        $this->info('Health check complete.');

        return 0;
    }

    protected function displaySettings(): void
    {
        $this->info('📋 Current Enforcement Settings:');
        $this->newLine();

        $settings = [
            'reseller.allow_config_overrun' => Setting::getBool('reseller.allow_config_overrun', true),
            'reseller.auto_disable_grace_percent' => Setting::get('reseller.auto_disable_grace_percent', 2.0),
            'reseller.auto_disable_grace_bytes' => Setting::get('reseller.auto_disable_grace_bytes', 50 * 1024 * 1024),
            'reseller.time_expiry_grace_minutes' => Setting::get('reseller.time_expiry_grace_minutes', 0),
            'reseller.usage_sync_interval_minutes' => Setting::get('reseller.usage_sync_interval_minutes', 3),
        ];

        foreach ($settings as $key => $value) {
            if ($key === 'reseller.allow_config_overrun') {
                $displayValue = $value ? '✓ Enabled' : '✗ Disabled';
            } elseif ($key === 'reseller.auto_disable_grace_bytes') {
                $mb = round($value / (1024 * 1024), 2);
                $displayValue = "{$value} bytes ({$mb} MB)";
            } else {
                $displayValue = $value;
            }

            $this->line("  <fg=cyan>{$key}:</> {$displayValue}");
        }
    }

    protected function displayQueueConnection(): void
    {
        $this->info('🔗 Queue Configuration:');
        $this->newLine();

        $connection = config('queue.default');
        $this->line("  <fg=cyan>Default connection:</> {$connection}");

        if ($connection === 'database') {
            try {
                $jobsCount = DB::table('jobs')->count();
                $failedJobsCount = DB::table('failed_jobs')->count();

                $this->line("  <fg=cyan>Pending jobs:</> {$jobsCount}");
                $this->line("  <fg=cyan>Failed jobs:</> {$failedJobsCount}");

                if ($failedJobsCount > 0) {
                    $this->warn("  ⚠️  There are {$failedJobsCount} failed jobs that may need attention");
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Could not read job tables: " . $e->getMessage());
            }
        } else {
            $this->line("  <fg=yellow>Note:</> Queue status check only available for 'database' connection");
        }
    }

    protected function displaySchedulerStatus(): void
    {
        $this->info('⏰ Scheduler Status:');
        $this->newLine();

        // Check if there's a schedule:run command in the cron or recent activity
        $this->line("  <fg=yellow>Note:</> To verify the scheduler is running, check:");
        $this->line("    - Crontab entry: * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1");
        $this->line("    - Recent audit logs (shown below) should have entries if jobs are running");
        
        // Try to get last schedule run from cache if available
        try {
            $lastRun = cache('schedule:last_run');
            if ($lastRun) {
                $this->line("  <fg=cyan>Last detected run:</> {$lastRun}");
            } else {
                $this->line("  <fg=yellow>Last run:</> Unknown (cache not set)");
            }
        } catch (\Exception $e) {
            $this->line("  <fg=yellow>Last run:</> Unable to determine");
        }
    }

    protected function displayResellerStats(): void
    {
        $this->info('📊 Reseller Statistics:');
        $this->newLine();

        $totalResellers = Reseller::where('type', 'traffic')->count();
        $activeResellers = Reseller::where('type', 'traffic')->where('status', 'active')->count();
        $suspendedResellers = Reseller::where('type', 'traffic')->where('status', 'suspended')->count();

        $this->line("  <fg=cyan>Total traffic-based resellers:</> {$totalResellers}");
        $this->line("  <fg=green>Active:</> {$activeResellers}");
        $this->line("  <fg=red>Suspended:</> {$suspendedResellers}");

        $totalConfigs = ResellerConfig::count();
        $activeConfigs = ResellerConfig::where('status', 'active')->count();
        $disabledConfigs = ResellerConfig::where('status', 'disabled')->count();
        $expiredConfigs = ResellerConfig::where('status', 'expired')->count();

        $this->newLine();
        $this->line("  <fg=cyan>Total configs:</> {$totalConfigs}");
        $this->line("  <fg=green>Active:</> {$activeConfigs}");
        $this->line("  <fg=yellow>Disabled:</> {$disabledConfigs}");
        $this->line("  <fg=red>Expired:</> {$expiredConfigs}");
    }

    protected function displayRecentAuditEvents(): void
    {
        $this->info('📝 Recent Audit Events (last 24 hours):');
        $this->newLine();

        $actions = [
            'reseller_suspended',
            'reseller_activated',
            'config_auto_disabled',
            'config_auto_enabled',
            'config_manual_disabled',
            'config_manual_enabled',
        ];

        $since = now()->subDay();

        foreach ($actions as $action) {
            $count = AuditLog::where('action', $action)
                ->where('created_at', '>=', $since)
                ->count();

            $icon = $count > 0 ? '✓' : '-';
            $color = $count > 0 ? 'green' : 'gray';

            $this->line("  <fg={$color}>{$icon} {$action}:</> {$count}");
        }

        $this->newLine();
        $totalAuditLogs = AuditLog::where('created_at', '>=', $since)->count();
        $this->line("  <fg=cyan>Total audit logs in last 24h:</> {$totalAuditLogs}");

        // Show most recent entries
        $recentLogs = AuditLog::whereIn('action', $actions)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        if ($recentLogs->isNotEmpty()) {
            $this->newLine();
            $this->line("  <fg=cyan>Most recent enforcement events:</>");
            
            foreach ($recentLogs as $log) {
                $time = $log->created_at->diffForHumans();
                $reason = $log->reason ?? 'N/A';
                $this->line("    • [{$time}] {$log->action} (reason: {$reason})");
            }
        } else {
            $this->newLine();
            $this->warn("  ⚠️  No enforcement events in the last 24 hours. This may indicate:");
            $this->line("    - Scheduler is not running");
            $this->line("    - Jobs are not being processed");
            $this->line("    - No enforcement actions were needed (all resellers within limits)");
        }
    }
}
