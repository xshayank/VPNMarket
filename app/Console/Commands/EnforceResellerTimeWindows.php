<?php

namespace App\Console\Commands;

use App\Models\Reseller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerTimeWindowEnforcer;

class EnforceResellerTimeWindows extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reseller:enforce-time-windows';

    /**
     * The console description of the command.
     *
     * @var string
     */
    protected $description = 'Enforce time window limits on traffic-based resellers (suspend expired, reactivate extended)';

    /**
     * Execute the console command.
     */
    public function handle(ResellerTimeWindowEnforcer $enforcer): int
    {
        $lockKey = 'reseller:enforce-time-windows:lock';
        $lockTimeout = 600; // 10 minutes

        // Try to acquire a lock to prevent concurrent execution
        $lock = Cache::lock($lockKey, $lockTimeout);

        if (! $lock->get()) {
            $this->warn('Another enforcement process is already running. Skipping.');
            Log::info('Reseller time window enforcement skipped - another process is running');

            return self::SUCCESS;
        }

        try {
            $startTime = microtime(true);
            $this->info('Starting reseller time window enforcement...');
            Log::info('Starting reseller time window enforcement command');

            $now = now()->timezone(config('app.timezone', 'Asia/Tehran'))->startOfMinute();

            // Find active resellers whose window has expired
            $expiredResellers = Reseller::where('type', 'traffic')
                ->where('status', 'active')
                ->whereNotNull('window_ends_at')
                ->where('window_ends_at', '<=', $now)
                ->get();

            $this->info("Found {$expiredResellers->count()} resellers with expired windows");
            Log::info("Found {$expiredResellers->count()} resellers with expired windows");

            $suspendedCount = 0;
            foreach ($expiredResellers as $reseller) {
                try {
                    if ($enforcer->suspendIfExpired($reseller)) {
                        $suspendedCount++;
                        $this->line("  - Suspended reseller #{$reseller->id}");
                    }
                } catch (\Exception $e) {
                    $this->error("  - Failed to suspend reseller #{$reseller->id}: {$e->getMessage()}");
                    Log::error("Failed to suspend reseller {$reseller->id}: ".$e->getMessage());
                }
            }

            // Find suspended resellers whose window has been extended
            $eligibleResellers = Reseller::where('type', 'traffic')
                ->where('status', 'suspended')
                ->whereNotNull('window_ends_at')
                ->where('window_ends_at', '>', $now)
                ->get();

            $this->info("Found {$eligibleResellers->count()} resellers eligible for reactivation");
            Log::info("Found {$eligibleResellers->count()} resellers eligible for reactivation");

            $reactivatedCount = 0;
            foreach ($eligibleResellers as $reseller) {
                try {
                    if ($enforcer->reactivateIfEligible($reseller)) {
                        $reactivatedCount++;
                        $this->line("  - Reactivated reseller #{$reseller->id}");
                    }
                } catch (\Exception $e) {
                    $this->error("  - Failed to reactivate reseller #{$reseller->id}: {$e->getMessage()}");
                    Log::error("Failed to reactivate reseller {$reseller->id}: ".$e->getMessage());
                }
            }

            $duration = round(microtime(true) - $startTime, 2);
            $this->info("Enforcement completed in {$duration}s: {$suspendedCount} suspended, {$reactivatedCount} reactivated");
            Log::info("Reseller time window enforcement completed in {$duration}s: {$suspendedCount} suspended, {$reactivatedCount} reactivated");

            return self::SUCCESS;
        } finally {
            // Always release the lock
            $lock->release();
        }
    }
}
