<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use App\Observers\ResellerConfigObserver;
use App\Observers\ResellerObserver;
use App\Policies\AuditLogPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register policies
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Reseller::class, \App\Policies\ResellerPolicy::class);
        Gate::policy(ResellerConfig::class, \App\Policies\ResellerConfigPolicy::class);
        Gate::policy(\App\Models\Panel::class, \App\Policies\PanelPolicy::class);

        // Register observers for audit safety net - wrap in try/catch for safety
        try {
            ResellerConfig::observe(ResellerConfigObserver::class);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to register ResellerConfigObserver', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            Reseller::observe(ResellerObserver::class);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to register ResellerObserver', [
                'error' => $e->getMessage(),
            ]);
        }

        User::creating(function ($user) {
            do {

                $code = 'REF-' . strtoupper(Str::random(6));

            } while (User::where('referral_code', $code)->exists());

            $user->referral_code = $code;
        });
        // ==========================================================
    }
}
