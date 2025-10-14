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
    protected $description = 'Set the Telegram bot webhook URL based on the .env configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Attempting to set Telegram webhook...');

        // ۱. خواندن تنظیمات از دیتابیس
        $settings = Setting::all()->pluck('value', 'key');
        $appUrl = $settings->get('app_url');
        $botToken = $settings->get('telegram_bot_token');

        // ۲. بررسی وجود تنظیمات لازم
        if (!$appUrl || !$botToken) {
            $this->error('Error: APP_URL or TELEGRAM_BOT_TOKEN is not set in your site settings.');
            $this->warn('Please make sure you have configured these values in your Filament admin panel.');
            return 1;
        }

        // ۳. ساخت URL وب‌هوک
        $webhookUrl = rtrim($appUrl, '/') . '/webhooks/telegram';
        $telegramApiUrl = "https://api.telegram.org/bot{$botToken}/setWebhook?url={$webhookUrl}";

        $this->line("Webhook URL: " . $webhookUrl);

        // ۴. ارسال درخواست به تلگرام
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


