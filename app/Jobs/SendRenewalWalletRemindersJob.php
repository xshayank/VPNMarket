<?php

namespace App\Jobs;

use App\Mail\RenewalReminderMail;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendRenewalWalletRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public function __construct() {}

    public function handle(): void
    {
        $autoRemindEnabled = Setting::where('key', 'email.auto_remind_renewal_wallet')->first()?->value === 'true';

        if (! $autoRemindEnabled) {
            Log::info('SendRenewalWalletRemindersJob skipped: auto_remind_renewal_wallet is disabled');

            return;
        }

        $renewalDaysBefore = (int) (Setting::where('key', 'email.renewal_days_before')->first()?->value ?? 3);
        $minWalletThreshold = (int) (Setting::where('key', 'email.min_wallet_threshold')->first()?->value ?? 10000);

        Log::info("Starting SendRenewalWalletRemindersJob with renewalDaysBefore={$renewalDaysBefore}, minWalletThreshold={$minWalletThreshold}");

        $targetDate = now()->addDays($renewalDaysBefore);
        $startOfDay = $targetDate->copy()->startOfDay();
        $endOfDay = $targetDate->copy()->endOfDay();

        $expiringOrderUserIds = Order::where('status', 'paid')
            ->whereNotNull('plan_id')
            ->whereBetween('expires_at', [$startOfDay, $endOfDay])
            ->pluck('user_id')
            ->unique();

        $count = 0;
        User::whereIn('id', $expiringOrderUserIds)
            ->whereNotNull('email')
            ->where('balance', '<', $minWalletThreshold)
            ->chunkById(100, function ($users) use (&$count, $renewalDaysBefore, $startOfDay, $endOfDay) {
                foreach ($users as $user) {
                    $expiringOrder = Order::where('user_id', $user->id)
                        ->where('status', 'paid')
                        ->whereNotNull('plan_id')
                        ->whereBetween('expires_at', [$startOfDay, $endOfDay])
                        ->with('plan')
                        ->orderBy('expires_at', 'asc')
                        ->first();

                    if ($expiringOrder && $expiringOrder->plan) {
                        $hasLongerOrder = Order::where('user_id', $user->id)
                            ->where('status', 'paid')
                            ->whereNotNull('plan_id')
                            ->where('expires_at', '>', $expiringOrder->expires_at)
                            ->exists();

                        if (! $hasLongerOrder) {
                            Mail::to($user->email)->queue(new RenewalReminderMail(
                                $user,
                                $expiringOrder->plan->name ?? 'VPN Plan',
                                $expiringOrder->expires_at->format('Y-m-d H:i'),
                                $renewalDaysBefore
                            ));
                            $count++;
                        }
                    }
                }
            });

        Log::info("SendRenewalWalletRemindersJob completed. Queued {$count} emails.");
    }
}
