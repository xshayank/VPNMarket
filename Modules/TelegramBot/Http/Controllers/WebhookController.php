<?php

namespace Modules\TelegramBot\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\TelegramBot\Services\BotRouter;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\Update;

class WebhookController extends Controller
{
    public function __construct(private BotRouter $router)
    {
    }

    public function handle(Request $request)
    {
        try {
            $settings = Setting::all()->pluck('value', 'key');
            $botToken = $settings->get('telegram_bot_token');

            if (! $botToken) {
                Log::warning('Telegram webhook received without configured bot token');

                return 'ok';
            }

            Telegram::setAccessToken($botToken);
            $update = Telegram::getWebhookUpdate();

            if (! $update instanceof Update) {
                return 'ok';
            }

            $chatId = $this->extractChatId($update);
            if ($chatId && $this->isRateLimited($chatId)) {
                Log::warning('Telegram bot rate limit triggered', ['action' => 'tg_rate_limited', 'chat_id' => $chatId]);

                return 'ok';
            }

            $this->router->handle($update, $settings, $botToken);
        } catch (\Throwable $exception) {
            Log::error('Telegram bot webhook error', [
                'action' => 'tg_webhook_error',
                'message' => $exception->getMessage(),
            ]);
        }

        return 'ok';
    }

    protected function extractChatId(Update $update): ?int
    {
        if ($update->isType('callback_query')) {
            return $update->getCallbackQuery()?->getMessage()?->getChat()?->getId();
        }

        if ($update->has('message')) {
            return $update->getMessage()?->getChat()?->getId();
        }

        return null;
    }

    protected function isRateLimited(int $chatId): bool
    {
        $key = 'telegram:chat:'.$chatId;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return true;
        }

        RateLimiter::hit($key, 10);

        return false;
    }
}
