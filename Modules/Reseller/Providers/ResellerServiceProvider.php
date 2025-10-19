<?php

namespace Modules\Reseller\Providers;

use Illuminate\Support\ServiceProvider;

class ResellerServiceProvider extends ServiceProvider
{
    protected string $name = 'Reseller';
    protected string $nameLower = 'reseller';

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'reseller');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }
}
