<?php

namespace Modules\TelegramBot\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\TelegramBot\Services\BotRenderer;
use Modules\TelegramBot\Services\BotRouter;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * New webhook controller with onboarding and wallet top-up support
 */
class NewWebhookController extends Controller
{
    protected BotRouter $router;

    protected BotRenderer $renderer;

    public function __construct(BotRouter $router, BotRenderer $renderer)
    {
        $this->router = $router;
        $this->renderer = $renderer;
    }

    public function handle(Request $request)
    {
        Log::info('Telegram Webhook Received (New)', $request->all());

        try {
            $settings = Setting::all()->pluck('value', 'key');
            $botToken = $settings->get('telegram_bot_token');

            if (! $botToken) {
                return 'ok';
            }

            Telegram::setAccessToken($botToken);
            $update = Telegram::getWebhookUpdate();

            // Rate limiting check
            $chatId = $this->getChatId($update);
            if ($chatId && $this->isRateLimited($chatId)) {
                Log::warning('Rate limit exceeded', [
                    'action' => 'tg_rate_limit_exceeded',
                    'chat_id' => $chatId,
                ]);

                return 'ok';
            }

            // Route to appropriate handler
            $this->router->route($update);
        } catch (\Exception $e) {
            Log::error('Telegram Bot Error (New): '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return 'ok';
    }

    /**
     * Get chat ID from update
     */
    protected function getChatId($update): ?int
    {
        if ($update->isType('callback_query')) {
            return $update->getCallbackQuery()->getMessage()->getChat()->getId();
        } elseif ($update->has('message')) {
            return $update->getMessage()->getChat()->getId();
        }

        return null;
    }

    /**
     * Basic rate limiting check
     * Allows 5 requests per 10 seconds per chat
     */
    protected function isRateLimited($chatId): bool
    {
        $key = "telegram_rate_limit:{$chatId}";
        $limit = 5;
        $window = 10; // seconds

        $count = cache()->get($key, 0);

        if ($count >= $limit) {
            return true;
        }

        cache()->put($key, $count + 1, $window);

        return false;
    }
}
