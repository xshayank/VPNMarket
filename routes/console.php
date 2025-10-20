<?php

use App\Jobs\SendRenewalWalletRemindersJob;
use App\Jobs\SendResellerTrafficTimeRemindersJob;
use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
use Modules\Reseller\Jobs\SyncResellerUsageJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule email reminders based on settings
Schedule::call(function () {
    $autoRemindEnabled = Setting::get('email.auto_remind_renewal_wallet') === 'true';
    if ($autoRemindEnabled) {
        SendRenewalWalletRemindersJob::dispatch();
    }
})->daily()->at('09:00');

Schedule::call(function () {
    $autoRemindEnabled = Setting::get('email.auto_remind_reseller_traffic_time') === 'true';
    if ($autoRemindEnabled) {
        SendResellerTrafficTimeRemindersJob::dispatch();
    }
})->hourly();

// Schedule reseller usage sync job
// Minimum guaranteed interval: 15 minutes
// Supports dynamic interval via 'reseller.usage_sync_interval_minutes' setting
// The interval is read at runtime to support changes without redeployment
Schedule::call(function () {
    $intervalMinutes = Setting::getInt('reseller.usage_sync_interval_minutes', 15);

    // Ensure minimum of 15 minutes
    if ($intervalMinutes < 15) {
        $intervalMinutes = 15;
    }

    // Check if current minute is a multiple of the interval
    $currentMinute = now()->minute;
    if ($currentMinute % $intervalMinutes === 0) {
        Log::info("Scheduling SyncResellerUsageJob (interval: {$intervalMinutes} minutes)");
        SyncResellerUsageJob::dispatch();
    }
})->everyMinute();

// Schedule reseller config re-enable job
// Runs every minute to quickly re-enable configs when reseller recovers
Schedule::call(function () {
    ReenableResellerConfigsJob::dispatch();
})->everyMinute();
