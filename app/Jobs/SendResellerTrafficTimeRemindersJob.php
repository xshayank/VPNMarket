<?php

namespace App\Jobs;

use App\Mail\ResellerTrafficTimeReminderMail;
use App\Models\Reseller;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendResellerTrafficTimeRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public function __construct()
    {
    }

    public function handle(): void
    {
        $autoRemindEnabled = Setting::where('key', 'email.auto_remind_reseller_traffic_time')->first()?->value === 'true';
        
        if (!$autoRemindEnabled) {
            Log::info('SendResellerTrafficTimeRemindersJob skipped: auto_remind_reseller_traffic_time is disabled');
            return;
        }

        $resellerDaysBefore = (int) (Setting::where('key', 'email.reseller_days_before_end')->first()?->value ?? 3);
        $trafficThresholdPercent = (int) (Setting::where('key', 'email.reseller_traffic_threshold_percent')->first()?->value ?? 10);

        Log::info("Starting SendResellerTrafficTimeRemindersJob with daysBefore={$resellerDaysBefore}, trafficThreshold={$trafficThresholdPercent}%");

        $count = 0;
        Reseller::where('type', 'traffic')
            ->where('status', 'active')
            ->with('user')
            ->chunkById(100, function ($resellers) use (&$count, $resellerDaysBefore, $trafficThresholdPercent) {
                foreach ($resellers as $reseller) {
                    if (!$reseller->user || !$reseller->user->email) {
                        continue;
                    }

                    $shouldSendEmail = false;
                    $daysRemaining = null;
                    $trafficRemainingPercent = null;

                    if ($reseller->window_ends_at) {
                        $daysRemaining = now()->diffInDays($reseller->window_ends_at, false);
                        if ($daysRemaining >= 0 && $daysRemaining <= $resellerDaysBefore) {
                            $shouldSendEmail = true;
                        }
                    }

                    if ($reseller->traffic_total_bytes > 0) {
                        $trafficUsedPercent = ($reseller->traffic_used_bytes / $reseller->traffic_total_bytes) * 100;
                        $trafficRemainingPercent = 100 - $trafficUsedPercent;
                        
                        if ($trafficRemainingPercent <= $trafficThresholdPercent && $trafficRemainingPercent >= 0) {
                            $shouldSendEmail = true;
                        }
                    }

                    if ($shouldSendEmail) {
                        Mail::to($reseller->user->email)->queue(new ResellerTrafficTimeReminderMail(
                            $reseller,
                            $daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= $resellerDaysBefore ? (int) $daysRemaining : null,
                            $trafficRemainingPercent !== null && $trafficRemainingPercent <= $trafficThresholdPercent ? $trafficRemainingPercent : null
                        ));
                        $count++;
                    }
                }
            });

        Log::info("SendResellerTrafficTimeRemindersJob completed. Queued {$count} emails.");
    }
}
