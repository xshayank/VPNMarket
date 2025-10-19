<?php

namespace Modules\Reseller\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Models\ResellerOrder;

class ProvisionResellerOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ResellerOrder $order)
    {
    }

    public function handle(): void
    {
        $this->order->refresh();

        if ($this->order->status !== 'paid') {
            return;
        }

        $this->order->update([
            'status' => 'fulfilled',
            'fulfilled_at' => now(),
            'artifacts' => [
                'message' => 'Provisioning not yet implemented.',
            ],
        ]);

        Log::info('ProvisionResellerOrderJob fulfilled placeholder for order '.$this->order->getKey());
    }
}
