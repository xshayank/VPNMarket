<?php

namespace Modules\TelegramBot\Services;

use App\Models\User;
use App\Services\ResellerUpgradeService;
use Telegram\Bot\Keyboard\Keyboard;

class BotRenderer
{
    protected ResellerUpgradeService $upgradeService;

    public function __construct(ResellerUpgradeService $upgradeService)
    {
        $this->upgradeService = $upgradeService;
    }

    /**
     * Get main menu message for user
     */
    public function getMainMenuMessage(User $user): string
    {
        $name = $user->name ?? 'کاربر';
        $message = "سلام *{$name}* عزیز! 👋\n\n";

        // Show balance info
        $reseller = $user->reseller;
        if ($reseller && $reseller->isWalletBased()) {
            $balance = number_format($reseller->wallet_balance ?? 0);
            $message .= "💰 موجودی کیف پول: *{$balance} تومان*\n";
            $message .= "🎖 نوع حساب: *نمایندگی (کیف پولی)*\n\n";
        } else {
            $balance = number_format($user->balance ?? 0);
            $message .= "💰 موجودی کیف پول: *{$balance} تومان*\n";

            if (! $user->reseller) {
                $needed = $this->upgradeService->getAmountNeeded($user);
                if ($needed > 0) {
                    $neededFormatted = number_format($needed);
                    $message .= "📊 برای ارتقا به نمایندگی: *{$neededFormatted} تومان* دیگر نیاز است\n\n";
                } else {
                    $message .= "🎉 شما آماده ارتقا به نمایندگی هستید!\n\n";
                }
            }
        }

        $message .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:";

        return $message;
    }

    /**
     * Get main menu keyboard for user
     */
    public function getMainMenuKeyboard(User $user): Keyboard
    {
        $keyboard = Keyboard::make()->inline();

        $reseller = $user->reseller;

        // Account info button
        $keyboard->row([
            Keyboard::inlineButton(['text' => '👤 حساب کاربری من', 'callback_data' => '/my_account']),
        ]);

        // Wallet button
        $keyboard->row([
            Keyboard::inlineButton(['text' => '💰 کیف پول', 'callback_data' => '/wallet']),
        ]);

        // Reseller section
        if ($reseller) {
            if ($reseller->isWalletBased()) {
                $keyboard->row([
                    Keyboard::inlineButton(['text' => '🎖 داشبورد نمایندگی', 'callback_data' => '/reseller_dashboard']),
                ]);
                $keyboard->row([
                    Keyboard::inlineButton(['text' => '⚙️ کانفیگ‌های من', 'callback_data' => '/my_configs']),
                ]);
            }
        } else {
            // Show become reseller if not a reseller yet
            $needed = $this->upgradeService->getAmountNeeded($user);
            if ($needed > 0) {
                $keyboard->row([
                    Keyboard::inlineButton(['text' => '🎖 ارتقا به نمایندگی', 'callback_data' => '/become_reseller']),
                ]);
            }
        }

        // Help & Support
        $keyboard->row([
            Keyboard::inlineButton(['text' => '❓ راهنما', 'callback_data' => '/help']),
            Keyboard::inlineButton(['text' => '💬 پشتیبانی', 'callback_data' => '/support']),
        ]);

        return $keyboard;
    }

    /**
     * Get wallet menu message
     */
    public function getWalletMenuMessage(User $user): string
    {
        $reseller = $user->reseller;

        if ($reseller && $reseller->isWalletBased()) {
            $balance = number_format($reseller->wallet_balance ?? 0);
            $message = "💰 *کیف پول نمایندگی*\n\n";
            $message .= "موجودی فعلی: *{$balance} تومان*\n\n";
        } else {
            $balance = number_format($user->balance ?? 0);
            $message = "💰 *کیف پول*\n\n";
            $message .= "موجودی فعلی: *{$balance} تومان*\n\n";
        }

        $message .= 'لطفاً یکی از گزینه‌های زیر را انتخاب کنید:';

        return $message;
    }

    /**
     * Get wallet menu keyboard
     */
    public function getWalletMenuKeyboard(): Keyboard
    {
        return Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '💳 شارژ کیف پول', 'callback_data' => '/topup']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '📜 تاریخچه تراکنش‌ها', 'callback_data' => '/transactions']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/main_menu']),
            ]);
    }

    /**
     * Get my account message
     */
    public function getMyAccountMessage(User $user): string
    {
        $message = "👤 *اطلاعات حساب کاربری*\n\n";
        $message .= "📧 ایمیل: `{$user->email}`\n";
        $message .= "👋 نام: *{$user->name}*\n";

        $link = $user->telegramLink;
        if ($link) {
            $message .= "✅ تلگرام: متصل\n";
            if ($link->username) {
                $message .= "   @{$link->username}\n";
            }
        }

        $reseller = $user->reseller;
        if ($reseller) {
            $message .= "\n🎖 *وضعیت نمایندگی*\n";
            $message .= 'نوع: *'.($reseller->isWalletBased() ? 'کیف پولی' : 'پلنی')."*\n";
            $message .= 'وضعیت: *'.($reseller->isActive() ? 'فعال ✅' : 'غیرفعال ❌')."*\n";

            if ($reseller->isWalletBased()) {
                $balance = number_format($reseller->wallet_balance ?? 0);
                $message .= "موجودی: *{$balance} تومان*\n";
            }
        } else {
            $balance = number_format($user->balance ?? 0);
            $message .= "\n💰 موجودی: *{$balance} تومان*\n";
        }

        return $message;
    }

    /**
     * Get my account keyboard
     */
    public function getMyAccountKeyboard(): Keyboard
    {
        return Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/main_menu']),
            ]);
    }

    /**
     * Get become reseller message
     */
    public function getBecomeResellerMessage(User $user): string
    {
        $minAmount = config('billing.reseller.min_wallet_upgrade', 100000);
        $minFormatted = number_format($minAmount);
        $currentBalance = number_format($user->balance ?? 0);
        $needed = $this->upgradeService->getAmountNeeded($user);
        $neededFormatted = number_format($needed);

        $message = "🎖 *ارتقا به نمایندگی*\n\n";
        $message .= "با شارژ کیف پول به مبلغ *{$minFormatted} تومان* یا بیشتر، به صورت خودکار به نمایندگی کیف پولی ارتقا می‌یابید.\n\n";
        $message .= "📊 *وضعیت فعلی:*\n";
        $message .= "موجودی شما: *{$currentBalance} تومان*\n";

        if ($needed > 0) {
            $message .= "مبلغ مورد نیاز: *{$neededFormatted} تومان*\n\n";
            $message .= '💡 برای ارتقا، کیف پول خود را شارژ کنید.';
        } else {
            $message .= "\n✅ شما آماده ارتقا هستید! شارژ بعدی شما باعث فعال شدن حساب نمایندگی خواهد شد.";
        }

        return $message;
    }

    /**
     * Get become reseller keyboard
     */
    public function getBecomeResellerKeyboard(): Keyboard
    {
        return Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '💳 شارژ کیف پول', 'callback_data' => '/topup']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/main_menu']),
            ]);
    }

    /**
     * Get help message
     */
    public function getHelpMessage(): string
    {
        return "❓ *راهنمای استفاده از ربات*\n\n"
            ."🔹 با ثبت‌نام در ربات، یک حساب کاربری کامل ایجاد می‌شود\n"
            ."🔹 می‌توانید کیف پول خود را شارژ کنید\n"
            ."🔹 با شارژ بالای 100,000 تومان، به نمایندگی ارتقا می‌یابید\n"
            ."🔹 نمایندگان می‌توانند کانفیگ‌های خود را مدیریت کنند\n\n"
            .'برای پشتیبانی، روی دکمه پشتیبانی کلیک کنید.';
    }

    /**
     * Get help keyboard
     */
    public function getHelpKeyboard(): Keyboard
    {
        return Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/main_menu']),
            ]);
    }
}
