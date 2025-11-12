<?php

namespace App\Listeners;

use App\Events\TransactionCompleted;
use App\Models\UserTelegramLink;
use App\Services\ResellerUpgradeService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class NotifyUserViaT

elegram
{
    protected ResellerUpgradeService $upgradeService;

    protected WalletService $walletService;

    public function __construct(
        ResellerUpgradeService $upgradeService,
        WalletService $walletService
    ) {
        $this->upgradeService = $upgradeService;
        $this->walletService = $walletService;
    }

    /**
     * Handle the event.
     */
    public function handle(TransactionCompleted $event): void
    {
        $transaction = $event->transaction;
        $user = $transaction->user;

        // Only process completed deposit transactions
        if ($transaction->status !== 'completed' || $transaction->type !== 'deposit') {
            return;
        }

        // Check if user has telegram link
        $link = UserTelegramLink::where('user_id', $user->id)->first();

        if (! $link) {
            return;
        }

        try {
            // Get bot token
            $settings = \App\Models\Setting::all()->pluck('value', 'key');
            $botToken = $settings->get('telegram_bot_token');

            if (! $botToken) {
                return;
            }

            Telegram::setAccessToken($botToken);

            // Credit the wallet
            $reseller = $user->reseller;
            $amount = $transaction->amount;
            $amountFormatted = number_format($amount);

            // Check if this should trigger reseller upgrade
            $upgradeResult = $this->upgradeService->checkAndUpgrade($user->fresh());

            // Send notification
            $message = "✅ *کیف پول شما شارژ شد!*\n\n";
            $message .= "مبلغ واریزی: *{$amountFormatted} تومان*\n";

            if ($upgradeResult['upgraded']) {
                $newBalance = number_format($upgradeResult['reseller']->wallet_balance);
                $message .= "موجودی جدید: *{$newBalance} تومان*\n\n";
                $message .= "🎉 *تبریک!*\n";
                $message .= "شما به نمایندگی کیف پولی ارتقا یافتید!\n";
                $message .= "اکنون می‌توانید کانفیگ‌های خود را مدیریت کنید.";
            } else {
                $reseller = $user->fresh()->reseller;
                if ($reseller && $reseller->isWalletBased()) {
                    $newBalance = number_format($reseller->wallet_balance);
                    $message .= "موجودی نمایندگی: *{$newBalance} تومان*";
                } else {
                    $newBalance = number_format($user->fresh()->balance);
                    $message .= "موجودی جدید: *{$newBalance} تومان*";
                }
            }

            Telegram::sendMessage([
                'chat_id' => $link->chat_id,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ]);

            Log::info('Telegram notification sent', [
                'action' => 'tg_wallet_credited',
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'upgraded' => $upgradeResult['upgraded'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram notification', [
                'action' => 'tg_notification_failed',
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
