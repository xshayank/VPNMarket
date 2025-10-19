<?php

namespace App\Jobs;

use App\Mail\ResellerExpiredMail;
use App\Models\Reseller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendExpiredResellerUsersEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public function __construct()
    {
    }

    public function handle(): void
    {
        Log::info('Starting SendExpiredResellerUsersEmailsJob');

        $count = 0;
        Reseller::where('type', 'traffic')
            ->where(function ($query) {
                $query->where('window_ends_at', '<=', now())
                    ->orWhereRaw('traffic_used_bytes >= traffic_total_bytes');
            })
            ->with('user')
            ->chunkById(100, function ($resellers) use (&$count) {
                foreach ($resellers as $reseller) {
                    if ($reseller->user && $reseller->user->email) {
                        Mail::to($reseller->user->email)->queue(new ResellerExpiredMail($reseller));
                        $count++;
                    }
                }
            });

        Log::info("SendExpiredResellerUsersEmailsJob completed. Queued {$count} emails.");
    }
}
