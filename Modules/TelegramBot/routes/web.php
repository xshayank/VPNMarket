<?php

use Illuminate\Support\Facades\Route;
use Modules\TelegramBot\Http\Controllers\NewWebhookController;
use Modules\TelegramBot\Http\Controllers\WebhookController;

// New webhook controller with onboarding and wallet features
// To enable: change telegram_bot_webhook environment variable to use /webhooks/telegram-new
Route::post('/webhooks/telegram-new', [NewWebhookController::class, 'handle'])->name('telegram.webhook.new');

// Old webhook controller (kept for backward compatibility)
Route::post('/webhooks/telegram', [WebhookController::class, 'handle'])->name('telegram.webhook');

