<?php

namespace Modules\Reseller\Routes;

use Illuminate\Support\Facades\Route;

class ResellerRouteRegistrar
{
    public static function register(): void
    {
        Route::middleware(['auth', 'reseller'])
            ->prefix('reseller')
            ->name('reseller.')
            ->group(function () {
                Route::get('/', [\Modules\Reseller\Http\Controllers\DashboardController::class, '__invoke'])
                    ->name('dashboard');

                Route::get('/plans', [\Modules\Reseller\Http\Controllers\PlanPurchaseController::class, 'index'])
                    ->name('plans.index');
                Route::post('/bulk', [\Modules\Reseller\Http\Controllers\PlanPurchaseController::class, 'store'])
                    ->name('plans.store');
                Route::get('/orders/{order}', [\Modules\Reseller\Http\Controllers\BulkOrderController::class, 'show'])
                    ->name('orders.show');

                Route::get('/configs', [\Modules\Reseller\Http\Controllers\ConfigController::class, 'index'])
                    ->name('configs.index');
                Route::get('/configs/create', [\Modules\Reseller\Http\Controllers\ConfigController::class, 'create'])
                    ->name('configs.create');
                Route::post('/configs', [\Modules\Reseller\Http\Controllers\ConfigController::class, 'store'])
                    ->name('configs.store');
                Route::post('/configs/{config}/disable', [\Modules\Reseller\Http\Controllers\ConfigController::class, 'disable'])
                    ->name('configs.disable');
                Route::post('/configs/{config}/enable', [\Modules\Reseller\Http\Controllers\ConfigController::class, 'enable'])
                    ->name('configs.enable');
                Route::delete('/configs/{config}', [\Modules\Reseller\Http\Controllers\ConfigController::class, 'destroy'])
                    ->name('configs.destroy');

                Route::post('/sync', [\Modules\Reseller\Http\Controllers\SyncController::class, '__invoke'])
                    ->name('sync');
            });
    }
}
