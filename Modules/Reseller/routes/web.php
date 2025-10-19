<?php

use Illuminate\Support\Facades\Route;
use Modules\Reseller\Http\Controllers\DashboardController;
use Modules\Reseller\Http\Controllers\PlanPurchaseController;
use Modules\Reseller\Http\Controllers\ConfigController;
use Modules\Reseller\Http\Controllers\SyncController;

/*
|--------------------------------------------------------------------------
| Reseller Routes
|--------------------------------------------------------------------------
|
| All routes for the reseller panel
|
*/

Route::prefix('reseller')
    ->middleware(['web', 'auth', 'reseller'])
    ->name('reseller.')
    ->group(function () {
        // Dashboard
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Plan-based resellers
        Route::get('/plans', [PlanPurchaseController::class, 'index'])->name('plans.index');
        Route::post('/bulk', [PlanPurchaseController::class, 'store'])->name('bulk.store');
        Route::get('/orders/{order}', [PlanPurchaseController::class, 'show'])->name('orders.show');

        // Traffic-based resellers
        Route::get('/configs', [ConfigController::class, 'index'])->name('configs.index');
        Route::get('/configs/create', [ConfigController::class, 'create'])->name('configs.create');
        Route::post('/configs', [ConfigController::class, 'store'])->name('configs.store');
        Route::post('/configs/{config}/disable', [ConfigController::class, 'disable'])->name('configs.disable');
        Route::post('/configs/{config}/enable', [ConfigController::class, 'enable'])->name('configs.enable');
        Route::delete('/configs/{config}', [ConfigController::class, 'destroy'])->name('configs.destroy');

        // Manual sync
        Route::post('/sync', [SyncController::class, 'sync'])->name('sync');
    });
