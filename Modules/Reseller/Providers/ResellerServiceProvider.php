<?php

namespace Modules\Reseller\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Reseller\Routes\ResellerRouteRegistrar;

class ResellerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'reseller');
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'reseller');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::middleware('web')
            ->group(function () {
                ResellerRouteRegistrar::register();
            });
    }
}
