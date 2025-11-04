<?php

use App\Jobs\SendRenewalWalletRemindersJob;
use App\Jobs\SendResellerTrafficTimeRemindersJob;
use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Modules\Reseller\Jobs\EnforceResellerTimeWindowsJob;
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
// Configurable interval: 1-5 minutes (default: 5 minutes)
// Supports dynamic interval via 'reseller.usage_sync_interval_minutes' setting
// The interval is read at runtime to support changes without redeployment
Schedule::call(function () {
    $intervalMinutes = Setting::getInt('reseller.usage_sync_interval_minutes', 5);

    // Clamp interval to [1, 5] minutes range
    if ($intervalMinutes < 1) {
        $intervalMinutes = 1;
    }
    if ($intervalMinutes > 5) {
        $intervalMinutes = 5;
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

// Schedule reseller time window enforcement job
// Runs every 5 minutes to enforce time limits on resellers
// Suspends resellers whose window_ends_at has passed
// Reactivates resellers whose window_ends_at has been extended beyond now
Schedule::call(function () {
    EnforceResellerTimeWindowsJob::dispatch();
})->everyFiveMinutes();
