<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetTelegramWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:set-webhook';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set the Telegram bot webhook URL based on your configuration.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Attempting to set Telegram webhook...');

        // ۱. خواندن APP_URL از کانفیگ اصلی لاراول (که از .env می‌خواند)
        $appUrl = config('app.url');

        // ۲. خواندن توکن ربات از دیتابیس (که در پنل ادمین تنظیم می‌شود)
        $botToken = Setting::where('key', 'telegram_bot_token')->value('value');

        // ۳. بررسی وجود تنظیمات لازم
        if (!$appUrl || $appUrl === 'http://localhost') {
            $this->error('Error: APP_URL is not set correctly in your .env file. It should be your public domain (e.g., https://yourdomain.com).');
            return 1;
        }

        if (!$botToken) {
            $this->error('Error: TELEGRAM_BOT_TOKEN is not set in your site settings.');
            $this->warn('Please configure the bot token in your Filament admin panel first.');
            return 1;
        }

        // ۴. ساخت URL وب‌هوک
        $webhookUrl = rtrim($appUrl, '/') . '/webhooks/telegram';
        $telegramApiUrl = "https://api.telegram.org/bot{$botToken}/setWebhook?url={$webhookUrl}";

        $this->line("Setting webhook to: " . $webhookUrl);

        // ۵. ارسال درخواست به تلگرام
        try {
            $response = Http::get($telegramApiUrl);

            if ($response->successful() && $response->json('ok') === true) {
                $this->info('✅ Webhook set successfully!');
                $this->line($response->json('description'));
            } else {
                $this->error('❌ Failed to set webhook.');
                $this->error('Reason: ' . ($response->json('description') ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            $this->error('An exception occurred while trying to connect to the Telegram API.');
            $this->error($e->getMessage());
        }

        return 0;
    }
}
