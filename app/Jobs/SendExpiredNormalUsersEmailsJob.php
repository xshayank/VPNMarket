<?php

namespace App\Jobs;

use App\Mail\NormalUserExpiredMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendExpiredNormalUsersEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public function __construct()
    {
    }

    public function handle(): void
    {
        Log::info('Starting SendExpiredNormalUsersEmailsJob');

        $expiredUserIds = Order::where('status', 'paid')
            ->whereNotNull('plan_id')
            ->where('expires_at', '<=', now())
            ->pluck('user_id')
            ->unique();

        $activeUserIds = Order::where('status', 'paid')
            ->whereNotNull('plan_id')
            ->where('expires_at', '>', now())
            ->pluck('user_id')
            ->unique();

        $expiredOnlyUserIds = $expiredUserIds->diff($activeUserIds);

        $count = 0;
        User::whereIn('id', $expiredOnlyUserIds)
            ->whereNotNull('email')
            ->chunkById(100, function ($users) use (&$count) {
                foreach ($users as $user) {
                    $lastExpiredOrder = Order::where('user_id', $user->id)
                        ->where('status', 'paid')
                        ->whereNotNull('plan_id')
                        ->where('expires_at', '<=', now())
                        ->with('plan')
                        ->orderBy('expires_at', 'desc')
                        ->first();

                    if ($lastExpiredOrder && $lastExpiredOrder->plan) {
                        Mail::to($user->email)->queue(new NormalUserExpiredMail(
                            $user,
                            $lastExpiredOrder->plan->name ?? 'VPN Plan',
                            $lastExpiredOrder->expires_at->format('Y-m-d H:i')
                        ));
                        $count++;
                    }
                }
            });

        Log::info("SendExpiredNormalUsersEmailsJob completed. Queued {$count} emails.");
    }
}
