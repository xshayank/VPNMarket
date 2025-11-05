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

            // Get all active traffic-based resellers and check both window and quota
            $activeResellers = Reseller::where('type', 'traffic')
                ->where('status', 'active')
                ->get();

            $this->info("Checking {$activeResellers->count()} active traffic resellers");
            Log::info("Checking {$activeResellers->count()} active traffic resellers for suspension");

            $suspendedCount = 0;
            $skippedCount = 0;

            foreach ($activeResellers as $reseller) {
                try {
                    // Check both conditions: window validity and traffic quota
                    $windowValid = $reseller->isWindowValid();
                    $hasTraffic = $reseller->hasTrafficRemaining();

                    // Suspend if EITHER condition fails
                    if (! $windowValid || ! $hasTraffic) {
                        // Prioritize quota exhaustion over window expiry (more critical issue)
                        $reason = ! $windowValid && $hasTraffic ? 'window_expired' : 'quota_exhausted';

                        if ($enforcer->suspendDueToLimits($reseller, $reason)) {
                            $suspendedCount++;
                            $this->line("  - Suspended reseller #{$reseller->id} (reason: {$reason})");
                            Log::info("Suspended reseller {$reseller->id}", [
                                'reason' => $reason,
                                'window_valid' => $windowValid,
                                'has_traffic' => $hasTraffic,
                            ]);
                        }
                    } else {
                        $skippedCount++;
                    }
                } catch (\Exception $e) {
                    $this->error("  - Failed to process reseller #{$reseller->id}: {$e->getMessage()}");
                    Log::error("Failed to process reseller {$reseller->id}: ".$e->getMessage());
                }
            }

            Log::info("Suspension check completed: {$suspendedCount} suspended, {$skippedCount} remain active");

            // Check suspended resellers for reactivation eligibility
            $suspendedResellers = Reseller::where('type', 'traffic')
                ->where('status', 'suspended')
                ->get();

            $this->info("Checking {$suspendedResellers->count()} suspended resellers for reactivation");
            Log::info("Checking {$suspendedResellers->count()} suspended resellers for reactivation eligibility");

            $reactivatedCount = 0;
            $skippedReactivationCount = 0;

            foreach ($suspendedResellers as $reseller) {
                try {
                    // Check both conditions for reactivation
                    $windowValid = $reseller->isWindowValid();
                    $hasTraffic = $reseller->hasTrafficRemaining();

                    $result = $enforcer->reactivateIfEligible($reseller);
                    if ($result) {
                        $reactivatedCount++;
                        $this->line("  - Reactivated reseller #{$reseller->id}");
                        
                        // Log reactivation and dispatch of re-enable job
                        Log::info("Reseller {$reseller->id} reactivated, ReenableResellerConfigsJob dispatched", [
                            'reseller_id' => $reseller->id,
                            'window_valid' => $windowValid,
                            'has_traffic' => $hasTraffic,
                            'job_dispatched' => true,
                        ]);
                    } else {
                        $skippedReactivationCount++;
                        // Log why reactivation was skipped
                        if (! $windowValid && ! $hasTraffic) {
                            $this->line("  - Skipped reseller #{$reseller->id}: window invalid AND no traffic");
                            Log::debug("Skipped reactivation for reseller {$reseller->id}: both conditions fail");
                        } elseif (! $windowValid) {
                            $this->line("  - Skipped reseller #{$reseller->id}: window invalid");
                            Log::debug("Skipped reactivation for reseller {$reseller->id}: window invalid");
                        } elseif (! $hasTraffic) {
                            $this->line("  - Skipped reseller #{$reseller->id}: no traffic remaining");
                            Log::debug("Skipped reactivation for reseller {$reseller->id}: no traffic remaining");
                        }
                    }
                } catch (\Exception $e) {
                    $this->error("  - Failed to reactivate reseller #{$reseller->id}: {$e->getMessage()}");
                    Log::error("Failed to reactivate reseller {$reseller->id}: ".$e->getMessage());
                }
            }

            Log::info("Reactivation check completed: {$reactivatedCount} reactivated, {$skippedReactivationCount} remain suspended");

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
