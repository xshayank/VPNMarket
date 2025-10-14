<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebhookController;
use App\Models\Plan;
use Modules\TelegramBot\Http\Controllers\WebhookController as TelegramWebhookController;
use App\Http\Controllers\WebhookController as NowPaymentsWebhookController;

use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;



Route::get('/', function () {
    $settings = Setting::all()->pluck('value', 'key');
    $activeTheme = $settings->get('active_theme', 'welcome');
    $viewData = ['settings' => $settings];


    $viewData = [
        'settings' => $settings,
        'plans' => Plan::where('is_active', true)->orderBy('price')->get(),
    ];

    if (!view()->exists("themes.{$activeTheme}")) {
        abort(404, "قالب '{$activeTheme}' یافت نشد.");
    }

    return view("themes.{$activeTheme}", $viewData);
})->name('home');


Route::middleware(['auth'])->group(function () {

    // Dashboard
    Route::get('/dashboard', function () {
        $user = Auth::user();

        if ($user->show_renewal_notification) {
            session()->flash('renewal_success', 'سرویس شما با موفقیت تمدید شد. لینک اشتراک شما تغییر کرده است، لطفاً لینک جدید را کپی و در نرم‌افزار خود آپدیت کنید.');
            $user->update(['show_renewal_notification' => false]);
        }



        $orders = $user->orders()->with('plan')
            ->whereNotNull('plan_id') // فقط سفارشاتی که پلن دارند
            ->whereNull('renews_order_id') // سفارشات تمدیدی را نشان نده
            ->latest()->get();


        $transactions = $user->orders()->with('plan')->latest()->get();

        $plans = Plan::where('is_active', true)->orderBy('price')->get();
        $tickets = $user->tickets()->latest()->get();


        return view('dashboard', compact('orders', 'plans', 'tickets', 'transactions'));
    })->name('dashboard');


    Route::post('/order/{order}/renew', [OrderController::class, 'renew'])->name('order.renew');

    Route::post('/payment/wallet/{order}', [OrderController::class, 'processWalletPayment'])->name('payment.wallet.process');


    // in routes/web.php


    Route::get('/wallet/charge', [OrderController::class, 'showChargeForm'])->name('wallet.charge.form');

    Route::post('/wallet/charge', [OrderController::class, 'createChargeOrder'])->name('wallet.charge.create');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');




    Route::post('/webhooks/nowpayments', [NowPaymentsWebhookController::class, 'handle'])->name('webhooks.nowpayments');
    Route::post('/webhooks/telegram', [TelegramWebhookController::class, 'handle'])->name('webhooks.telegram');

    // Order & Payment Process
    Route::post('/order/{order}/renew', [OrderController::class, 'renew'])->name('order.renew');
    Route::post('/order/{plan}', [OrderController::class, 'store'])->name('order.store'); // Step 1: Create Order
    Route::get('/order/{order}', [OrderController::class, 'show'])->name('order.show'); // Step 2: Show Invoice / Choose Payment
    Route::post('/payment/card/{order}', [OrderController::class, 'processCardPayment'])->name('payment.card.process'); // Step 3a: Go to Receipt Upload
    Route::post('/payment/crypto/{order}', [OrderController::class, 'processCryptoPayment'])->name('payment.crypto.process'); // Step 3b: Go to NOWPayments
    Route::post('/payment/card/{order}/submit', [OrderController::class, 'submitCardReceipt'])->name('payment.card.submit'); // Step 4: Submit Receipt
});

/* WEBHOOKS */
Route::post('/webhooks/nowpayments', [WebhookController::class, 'handle'])->name('webhooks.nowpayments');

/* BREEZE AUTHENTICATION */
require __DIR__.'/auth.php';
