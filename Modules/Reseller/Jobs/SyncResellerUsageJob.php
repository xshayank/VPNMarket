<?php

namespace Modules\Reseller\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Models\Reseller;

class SyncResellerUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Reseller $reseller)
    {
    }

    public function handle(): void
    {
        $this->reseller->refresh();
        Log::info('SyncResellerUsageJob placeholder for reseller '.$this->reseller->getKey());
    }
}
