<?php

namespace Modules\Reseller\Jobs;

use App\Models\Reseller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerTimeWindowEnforcer;

class EnforceResellerTimeWindowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 600;

    public function handle(ResellerTimeWindowEnforcer $enforcer): void
    {
        Log::info('Starting reseller time window enforcement job');

        $now = now()->timezone(config('app.timezone', 'Asia/Tehran'))->startOfMinute();

        // Find active resellers whose window has expired
        // Use DB query to filter instead of loading all into memory
        $expiredResellers = Reseller::where('type', 'traffic')
            ->where('status', 'active')
            ->whereNotNull('window_ends_at')
            ->where('window_ends_at', '<=', $now)
            ->get();

        Log::info("Found {$expiredResellers->count()} resellers with expired windows");

        foreach ($expiredResellers as $reseller) {
            try {
                $enforcer->suspendIfExpired($reseller);
            } catch (\Exception $e) {
                Log::error("Failed to suspend reseller {$reseller->id}: " . $e->getMessage());
            }
        }

        // Find suspended resellers whose window has been extended
        // Use DB query to filter instead of loading all into memory
        $eligibleResellers = Reseller::where('type', 'traffic')
            ->where('status', 'suspended')
            ->whereNotNull('window_ends_at')
            ->where('window_ends_at', '>', $now)
            ->get();

        Log::info("Found {$eligibleResellers->count()} resellers eligible for reactivation");

        foreach ($eligibleResellers as $reseller) {
            try {
                $enforcer->reactivateIfEligible($reseller);
            } catch (\Exception $e) {
                Log::error("Failed to reactivate reseller {$reseller->id}: " . $e->getMessage());
            }
        }

        Log::info('Reseller time window enforcement job completed');
    }
}
