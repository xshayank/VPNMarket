<?php

namespace App\Observers;

use App\Models\Setting;
use App\Models\Transaction;
use App\Services\ResellerAutoUpgradeService;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class TransactionObserver
{
    public bool $afterCommit = true;

    public function __construct(private ResellerAutoUpgradeService $autoUpgradeService)
    {
    }

    public function created(Transaction $transaction): void
    {
        if ($this->isCompletedDeposit($transaction)) {
            $this->handleCompletedDeposit($transaction);
        }
    }

    public function updated(Transaction $transaction): void
    {
        if ($transaction->type === Transaction::TYPE_DEPOSIT
            && $transaction->status === Transaction::STATUS_COMPLETED
            && $transaction->getOriginal('status') !== Transaction::STATUS_COMPLETED) {
            $this->handleCompletedDeposit($transaction);
        }
    }

    protected function isCompletedDeposit(Transaction $transaction): bool
    {
        return $transaction->type === Transaction::TYPE_DEPOSIT
            && $transaction->status === Transaction::STATUS_COMPLETED;
    }

    protected function handleCompletedDeposit(Transaction $transaction): void
    {
        $user = $transaction->user()->with(['reseller', 'telegramLink'])->first();

        if (! $user) {
            return;
        }

        $autoResult = $this->autoUpgradeService->upgradeIfEligible($user);
        $user->refresh();
        $user->loadMissing(['reseller', 'telegramLink']);

        $chatId = $user->telegram_chat_id ?: $user->telegramLink?->chat_id;
        if (! $chatId) {
            return;
        }

        $token = Setting::getValue('telegram_bot_token');
        if (! $token) {
            return;
        }

        $balance = $user->reseller && $user->reseller->isWalletBased()
            ? $user->reseller->wallet_balance
            : $user->balance;

        $message = '✅ کیف پول شما به مبلغ *'.number_format($transaction->amount)." تومان* شارژ شد.";
        $message .= "\nموجودی جدید: *".number_format($balance)." تومان*";

        if (($autoResult['upgraded'] ?? false) && $autoResult['reseller']) {
            $message .= "\n\n🎉 حساب شما به ریسلر کیف‌پولی ارتقا یافت. از منوی ربات می‌توانید به امکانات ریسلر دسترسی داشته باشید.";
        } elseif ($autoResult['reactivated'] ?? false) {
            $message .= "\n\n✅ حساب ریسلر شما مجدداً فعال شد.";
        }

        try {
            Telegram::setAccessToken($token);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to send Telegram wallet notification', [
                'action' => 'tg_wallet_notification_failed',
                'transaction_id' => $transaction->id,
                'chat_id' => $chatId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
