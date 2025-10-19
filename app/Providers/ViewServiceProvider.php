<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\Schema;
class ViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Skip in testing environment to avoid database connection issues
        if (app()->environment('testing')) {
            return;
        }

        try {
            if (Schema::hasTable('settings')) {
                $settings = Setting::all()->pluck('value', 'key');

                View::share('settings', $settings);
            }
        } catch (\Exception $e) {
            // Silently fail when database is not available
        }
    }
}
