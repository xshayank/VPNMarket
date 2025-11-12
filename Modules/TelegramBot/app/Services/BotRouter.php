<?php

namespace Modules\\TelegramBot\\Services;

use App\\Models\\PaymentGatewayTransaction;
use App\\Models\\Reseller;
use App\\Models\\ResellerConfig;
use App\\Models\\Transaction;
use App\\Models\\User;
use App\\Models\\UserTelegramLink;
use App\\Provisioners\\ProvisionerFactory;
use App\\Support\\PaymentMethodConfig;
use App\\Support\\StarsefarConfig;
use App\\Services\\Payments\\StarsEfarClient;
use Carbon\\CarbonInterval;
use Illuminate\\Support\\Collection;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Support\\Facades\\Storage;
use Illuminate\\Support\\Facades\\URL;
use Illuminate\\Support\\Str;
use Modules\\Reseller\\Services\\ResellerProvisioner;
use Telegram\\Bot\\Keyboard\\Keyboard;
use Telegram\\Bot\\Laravel\\Facades\\Telegram;
use Telegram\\Bot\\Objects\\Update;

class BotRouter
{
    public const STATE_ONBOARDING_EMAIL = 'onboarding_email';
    public const STATE_ONBOARDING_PASSWORD = 'onboarding_password';
    public const STATE_ONBOARDING_CONFIRM = 'onboarding_password_confirm';
    public const STATE_ONBOARDING_EXISTING_PASSWORD = 'onboarding_existing_password';
    public const STATE_WALLET_AMOUNT = 'wallet_amount';
    public const STATE_WALLET_RECEIPT = 'wallet_receipt';

    protected Collection $settings;
    protected string $botToken;

    public function __construct(
        protected TelegramSessionManager $sessions,
        protected ResellerProvisioner $resellerProvisioner
    ) {
    }

    public function handle(Update $update, Collection $settings, string $botToken): void
    {
        $this->settings = $settings;
        $this->botToken = $botToken;

        if ($update->isType('callback_query')) {
            $callback = $update->getCallbackQuery();
            $message = $callback->getMessage();
            $chatId = $message?->getChat()?->getId();
            if (! $chatId) {
                return;
            }

            $session = $this->prepareSession($chatId);
            $userContext = $this->resolveUser(
                $chatId,
                $callback->getFrom()?->getUsername(),
                $callback->getFrom()?->getFirstName(),
                $callback->getFrom()?->getLastName()
            );

            Telegram::answerCallbackQuery(['callback_query_id' => $callback->getId()]);
            $this->handleCallback($chatId, (string) $callback->getData(), $session, $userContext['user'], $userContext['link']);

            return;
        }

        if (! $update->has('message')) {
            return;
        }

        $message = $update->getMessage();
        $chatId = $message->getChat()?->getId();
        if (! $chatId) {
            return;
        }

        $session = $this->prepareSession($chatId);
        $userContext = $this->resolveUser(
            $chatId,
            $message->getFrom()?->getUsername(),
            $message->getFrom()?->getFirstName(),
            $message->getFrom()?->getLastName()
        );

        if ($message->has('text')) {
            $this->handleText($chatId, $session, $userContext['user'], $userContext['link'], $message->getText());

            return;
        }

        if ($message->has('photo')) {
            $this->handlePhoto($chatId, $session, $userContext['user'], $message->getPhoto());
        }
    }

    protected function prepareSession(int $chatId)
    {
        $session = $this->sessions->touch($chatId);

        return $this->sessions->resetIfExpired($session, CarbonInterval::minutes(15));
    }

    protected function resolveUser(int $chatId, ?string $username, ?string $firstName, ?string $lastName): array
    {
        $link = UserTelegramLink::with('user')->where('chat_id', $chatId)->first();
        $user = $link?->user;

        if (! $user) {
            $user = User::where('telegram_chat_id', $chatId)->first();
            if ($user) {
                $link = UserTelegramLink::updateOrCreate(
                    ['chat_id' => $chatId],
                    [
                        'user_id' => $user->id,
                        'username' => $username,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'verified_at' => now(),
                    ]
                );
            }
        } elseif ($link) {
            $link->fill([
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'verified_at' => $link->verified_at ?? now(),
            ])->save();
        }

        return ['user' => $user, 'link' => $link];
    }

    protected function handleText(int $chatId, $session, ?User $user, ?UserTelegramLink $link, string $text): void
    {
        $text = trim($text);

        if ($text === '/cancel') {
            $this->sessions->clear($session);
            $this->sendMessage($chatId, '❎ عملیات لغو شد. برای شروع دوباره می‌توانید /start را ارسال کنید.');

            return;
        }

        if ($text === '/start') {
            $this->sessions->clear($session);
            if ($user) {
                $this->showMainMenu($chatId, $user);
            } else {
                $this->startOnboarding($chatId, $session);
            }

            return;
        }

        switch ($session->state) {
            case self::STATE_ONBOARDING_EMAIL:
                $this->handleOnboardingEmail($chatId, $session, $text);

                return;
            case self::STATE_ONBOARDING_PASSWORD:
                $this->handleOnboardingPassword($chatId, $session, $text);

                return;
            case self::STATE_ONBOARDING_CONFIRM:
                $this->handleOnboardingPasswordConfirm($chatId, $session, $text, $link);

                return;
            case self::STATE_ONBOARDING_EXISTING_PASSWORD:
                $this->handleExistingAccountPassword($chatId, $session, $text, $link);

                return;
            case self::STATE_WALLET_AMOUNT:
                if ($user) {
                    $this->handleWalletAmountInput($chatId, $session, $user, $text);
                } else {
                    $this->sendMessage($chatId, 'لطفاً ابتدا ثبت‌نام را تکمیل کنید و دوباره تلاش نمایید.');
                }

                return;
            case self::STATE_WALLET_RECEIPT:
                $this->sendMessage($chatId, 'لطفاً رسید خود را به صورت عکس ارسال کنید.');

                return;
        }

        if ($user) {
            $this->sendMessage($chatId, 'دستور نامعتبر است. لطفاً از منوی ربات استفاده کنید یا /start را ارسال نمایید.');
        } else {
            $this->sendMessage($chatId, 'برای شروع ثبت‌نام لطفاً /start را ارسال کنید.');
        }
    }

    protected function handleCallback(int $chatId, string $data, $session, ?User $user, ?UserTelegramLink $link): void
    {
        if ($data === 'main') {
            $this->sessions->clear($session);
            if ($user) {
                $this->showMainMenu($chatId, $user);
            } else {
                $this->startOnboarding($chatId, $session);
            }

            return;
        }

        if (! $user) {
            $this->startOnboarding($chatId, $session);

            return;
        }

        $parts = explode(':', $data);
        $action = $parts[0] ?? '';

        switch ($action) {
            case 'wallet':
                $this->showWalletOverview($chatId, $user);

                return;
            case 'wallet_topup':
                $this->showWalletTopUpMethods($chatId, $session, $user);

                return;
            case 'wallet_method':
                $method = $parts[1] ?? '';
                $this->startWalletTopUp($chatId, $session, $user, $method);

                return;
            case 'reseller_upgrade':
                $this->showResellerUpgradeStatus($chatId, $user);

                return;
            case 'reseller_dashboard':
                $this->showResellerDashboard($chatId, $user);

                return;
            case 'configs':
                $this->showConfigList($chatId, $user);

                return;
            case 'config':
                $subAction = $parts[1] ?? '';
                $id = isset($parts[2]) ? (int) $parts[2] : null;
                $this->handleConfigAction($chatId, $user, $subAction, $id);

                return;
            case 'support':
                $this->showSupport($chatId);

                return;
        }

        $this->sendMessage($chatId, 'دستور انتخاب‌شده نامعتبر است.');
    }
    protected function startOnboarding(int $chatId, $session): void
    {
        $this->sessions->setState($session, self::STATE_ONBOARDING_EMAIL);
        $this->sendMessage($chatId, "👋 به ربات VPNMarket خوش آمدید!\n\nبرای شروع، لطفاً ایمیل خود را ارسال کنید.");
        Log::info('Telegram onboarding started', ['action' => 'tg_onboarding_start', 'chat_id' => $chatId]);
    }

    protected function handleOnboardingEmail(int $chatId, $session, string $email): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->sendMessage($chatId, 'ایمیل وارد شده معتبر نیست. لطفاً مجدداً تلاش کنید.');

            return;
        }

        $existing = User::where('email', $email)->first();
        $data = ['email' => strtolower($email)];

        if ($existing) {
            $this->sessions->setState($session, self::STATE_ONBOARDING_EXISTING_PASSWORD, $data);
            $this->sendMessage($chatId, 'این ایمیل قبلاً ثبت شده است. لطفاً رمز عبور خود را برای ورود ارسال کنید.');
        } else {
            $this->sessions->setState($session, self::STATE_ONBOARDING_PASSWORD, $data);
            $this->sendMessage($chatId, 'لطفاً یک رمز عبور انتخاب کنید (حداقل ۸ کاراکتر).');
        }
    }

    protected function handleOnboardingPassword(int $chatId, $session, string $password): void
    {
        $password = trim($password);

        if (Str::length($password) < 8) {
            $this->sendMessage($chatId, 'رمز عبور باید حداقل ۸ کاراکتر باشد.');

            return;
        }

        $data = $session->data ?? [];
        $data['password_hash'] = Hash::make($password);
        $this->sessions->setState($session, self::STATE_ONBOARDING_CONFIRM, $data);
        $this->sendMessage($chatId, 'لطفاً جهت تایید، رمز عبور را مجدداً ارسال کنید.');
    }

    protected function handleOnboardingPasswordConfirm(int $chatId, $session, string $confirmation, ?UserTelegramLink $link): void
    {
        $data = $session->data ?? [];
        $passwordHash = $data['password_hash'] ?? null;
        $email = $data['email'] ?? null;

        if (! $passwordHash || ! $email) {
            $this->sessions->clear($session);
            $this->sendMessage($chatId, 'خطای غیرمنتظره‌ای رخ داد. لطفاً دوباره /start را ارسال کنید.');

            return;
        }

        if (! Hash::check($confirmation, $passwordHash)) {
            $this->sendMessage($chatId, 'رمز عبور با مقدار قبلی مطابقت ندارد. لطفاً مجدداً رمز عبور را وارد کنید.');
            $this->sessions->setState($session, self::STATE_ONBOARDING_PASSWORD, ['email' => $email]);

            return;
        }

        $user = $this->createUserAndLink($chatId, $email, $passwordHash);
        $this->sessions->clear($session);
        if ($user) {
            $this->showMainMenu($chatId, $user);
        }
    }

    protected function handleExistingAccountPassword(int $chatId, $session, string $password, ?UserTelegramLink $link): void
    {
        $data = $session->data ?? [];
        $email = $data['email'] ?? null;

        if (! $email) {
            $this->sessions->clear($session);
            $this->sendMessage($chatId, 'خطای غیرمنتظره‌ای رخ داد. لطفاً دوباره /start را ارسال کنید.');

            return;
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->sendMessage($chatId, 'رمز عبور نادرست است. لطفاً دوباره تلاش کنید یا /cancel را ارسال نمایید.');

            return;
        }

        $user->telegram_chat_id = $chatId;
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->save();

        UserTelegramLink::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'user_id' => $user->id,
                'username' => $link?->username,
                'first_name' => $link?->first_name,
                'last_name' => $link?->last_name,
                'verified_at' => now(),
            ]
        );

        $this->sessions->clear($session);
        $this->sendMessage($chatId, '✅ حساب شما با موفقیت به تلگرام متصل شد.');
        Log::info('Telegram account linked to existing user', ['action' => 'tg_link_complete', 'chat_id' => $chatId, 'user_id' => $user->id]);
        $this->showMainMenu($chatId, $user);
    }

    protected function createUserAndLink(int $chatId, string $email, string $passwordHash): ?User
    {
        try {
            $name = Str::before($email, '@');
            $user = new User([
                'name' => $name,
                'email' => $email,
                'password' => $passwordHash,
                'telegram_chat_id' => $chatId,
            ]);
            $user->email_verified_at = now();
            $user->balance = $user->balance ?? 0;
            $user->save();

            UserTelegramLink::updateOrCreate(
                ['chat_id' => $chatId],
                [
                    'user_id' => $user->id,
                    'verified_at' => now(),
                ]
            );

            Log::info('Telegram onboarding completed', ['action' => 'tg_link_complete', 'chat_id' => $chatId, 'user_id' => $user->id]);
            $this->sendMessage($chatId, '✅ حساب کاربری شما با موفقیت ایجاد و به تلگرام متصل شد.');

            return $user;
        } catch (\Throwable $exception) {
            Log::error('Telegram onboarding failed', ['action' => 'tg_onboarding_failed', 'chat_id' => $chatId, 'message' => $exception->getMessage()]);
            $this->sendMessage($chatId, 'در ایجاد حساب مشکلی رخ داد. لطفاً بعداً دوباره تلاش کنید.');

            return null;
        }
    }
    protected function showMainMenu(int $chatId, User $user): void
    {
        $reseller = $user->reseller;
        $balance = $this->resolveWalletBalance($user);

        $message = "سلام {$this->escapeMarkdown($user->name ?? 'کاربر')}!\n";
        $message .= "\n💰 موجودی کیف پول: *".number_format($balance)." تومان*";

        if ($reseller instanceof Reseller && $reseller->isWalletBased()) {
            $message .= "\n🏷️ وضعیت ریسلر: ".($reseller->status === 'active' ? 'فعال ✅' : 'غیرفعال ⚠️');
        } else {
            $threshold = (int) config('billing.reseller.min_wallet_upgrade', 100000);
            if ($balance >= $threshold) {
                $message .= "\n🚀 شما واجد شرایط تبدیل به ریسلر هستید و به زودی حساب شما ارتقا خواهد یافت.";
            } else {
                $message .= "\n🚀 برای تبدیل به ریسلر، حداقل موجودی مورد نیاز " . number_format($threshold) . ' تومان است.';
                $message .= "\n💡 مبلغ مورد نیاز: " . number_format(max($threshold - $balance, 0)) . ' تومان.';
            }
        }

        $keyboard = Keyboard::make()->inline();
        $keyboard->row([Keyboard::inlineButton(['text' => '💰 کیف پول', 'callback_data' => 'wallet'])]);

        if ($reseller instanceof Reseller && $reseller->isWalletBased()) {
            $keyboard->row([Keyboard::inlineButton(['text' => '⚙️ کانفیگ‌های من', 'callback_data' => 'configs:list'])]);
            $keyboard->row([Keyboard::inlineButton(['text' => '📊 داشبورد ریسلر', 'callback_data' => 'reseller_dashboard'])]);
        } else {
            $keyboard->row([Keyboard::inlineButton(['text' => '🚀 تبدیل به ریسلر', 'callback_data' => 'reseller_upgrade'])]);
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '🆘 پشتیبانی', 'callback_data' => 'support'])]);

        $this->sendMessage($chatId, $message, $keyboard);
    }

    protected function resolveWalletBalance(User $user): int
    {
        $reseller = $user->reseller;

        if ($reseller instanceof Reseller && $reseller->isWalletBased()) {
            return (int) $reseller->wallet_balance;
        }

        return (int) $user->balance;
    }
    protected function showWalletOverview(int $chatId, User $user): void
    {
        $balance = $this->resolveWalletBalance($user);
        $message = "💼 مدیریت کیف پول\n\nموجودی فعلی: *".number_format($balance)." تومان*";

        $transactions = Transaction::where('user_id', $user->id)
            ->where('type', Transaction::TYPE_DEPOSIT)
            ->latest()
            ->limit(5)
            ->get();

        if ($transactions->isEmpty()) {
            $message .= "\n\nتراکنش اخیر یافت نشد.";
        } else {
            $message .= "\n\n🧾 آخرین تراکنش‌ها:";
            foreach ($transactions as $transaction) {
                $statusMap = [
                    Transaction::STATUS_COMPLETED => 'تایید شده ✅',
                    Transaction::STATUS_PENDING => 'در انتظار ⏳',
                    Transaction::STATUS_FAILED => 'رد شده ❌',
                ];
                $message .= "\n- " . number_format($transaction->amount) . ' تومان (' . ($statusMap[$transaction->status] ?? $transaction->status) . ')';
            }
        }

        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '💳 شارژ کیف پول', 'callback_data' => 'wallet_topup'])])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'main'])]);

        $this->sendMessage($chatId, $message, $keyboard);
    }

    protected function showWalletTopUpMethods(int $chatId, $session, User $user): void
    {
        $methods = PaymentMethodConfig::availableWalletChargeMethods();

        if (empty($methods)) {
            $this->sendMessage($chatId, 'در حال حاضر هیچ روش پرداختی فعال نیست. لطفاً بعداً تلاش کنید.');

            return;
        }

        $message = 'لطفاً روش پرداخت مورد نظر خود را انتخاب کنید:';
        $keyboard = Keyboard::make()->inline();

        foreach ($methods as $method) {
            if ($method === 'card') {
                $keyboard->row([Keyboard::inlineButton(['text' => '💳 کارت به کارت', 'callback_data' => 'wallet_method:card'])]);
            }
            if ($method === 'starsefar') {
                $keyboard->row([Keyboard::inlineButton(['text' => '⭐️ پرداخت استارز تلگرام', 'callback_data' => 'wallet_method:starsefar'])]);
            }
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'wallet'])]);

        $this->sendMessage($chatId, $message, $keyboard);
    }

    protected function startWalletTopUp(int $chatId, $session, User $user, string $method): void
    {
        if ($method === 'card' && ! PaymentMethodConfig::cardToCardEnabled()) {
            $this->sendMessage($chatId, 'روش کارت به کارت غیرفعال است.');

            return;
        }

        if ($method === 'starsefar' && ! StarsefarConfig::isEnabled()) {
            $this->sendMessage($chatId, 'درگاه استارز در حال حاضر فعال نیست.');

            return;
        }

        $this->sessions->setState($session, self::STATE_WALLET_AMOUNT, [
            'method' => $method,
        ]);

        if ($method === 'card') {
            $this->sendMessage($chatId, 'مبلغ مورد نظر برای شارژ (به تومان) را ارسال کنید. حداقل مبلغ ۱٬۰۰۰ تومان است.');
        } else {
            $min = StarsefarConfig::getMinAmountToman();
            $this->sendMessage($chatId, 'مبلغ مورد نظر برای شارژ استارز (به تومان) را ارسال کنید. حداقل مبلغ '.number_format($min).' تومان است.');
        }
    }

    protected function handleWalletAmountInput(int $chatId, $session, User $user, string $text): void
    {
        $data = $session->data ?? [];
        $method = $data['method'] ?? null;

        if (! $method) {
            $this->sessions->clear($session);
            $this->sendMessage($chatId, 'خطایی رخ داد. لطفاً دوباره تلاش کنید.');

            return;
        }

        $amount = (int) str_replace([',', '٫'], '', trim($text));

        if ($amount <= 0) {
            $this->sendMessage($chatId, 'مبلغ وارد شده معتبر نیست.');

            return;
        }

        if ($method === 'card') {
            if ($amount < 1000) {
                $this->sendMessage($chatId, 'حداقل مبلغ کارت به کارت ۱٬۰۰۰ تومان است.');

                return;
            }

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => Transaction::TYPE_DEPOSIT,
                'status' => Transaction::STATUS_PENDING,
                'description' => 'شارژ کیف پول (تلگرام - کارت به کارت)',
                'metadata' => [
                    'source' => 'telegram_bot',
                    'method' => 'card_to_card',
                ],
            ]);

            $this->sessions->setState($session, self::STATE_WALLET_RECEIPT, [
                'transaction_id' => $transaction->id,
            ]);

            $cardNumber = $this->settings->get('payment_card_number');
            $holder = $this->settings->get('payment_card_holder_name');
            $instructions = $this->settings->get('payment_card_instructions');

            $message = "✅ درخواست شارژ ثبت شد.\n\nلطفاً مبلغ *".number_format($amount)." تومان* را به شماره کارت زیر واریز کرده و رسید را ارسال کنید:\n";
            if ($cardNumber) {
                $message .= "\n💳 شماره کارت: `{$cardNumber}`";
            }
            if ($holder) {
                $message .= "\n👤 نام صاحب حساب: {$this->escapeMarkdown($holder)}";
            }
            if ($instructions) {
                $message .= "\n\n📌 توضیحات: {$this->escapeMarkdown($instructions)}";
            }
            $message .= "\n\nپس از ارسال رسید، تیم پشتیبانی آن را بررسی خواهد کرد.";

            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منو', 'callback_data' => 'main'])]);

            $this->sendMessage($chatId, $message, $keyboard);
            Log::info('Telegram wallet top-up initiated', [
                'action' => 'tg_wallet_topup_initiated',
                'chat_id' => $chatId,
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'method' => 'card_to_card',
            ]);

            return;
        }

        $min = StarsefarConfig::getMinAmountToman();
        if ($amount < $min) {
            $this->sendMessage($chatId, 'حداقل مبلغ استارز '.number_format($min).' تومان است.');

            return;
        }

        $this->sessions->clear($session);
        $this->startStarsEfarPayment($chatId, $user, $amount);
    }

    protected function startStarsEfarPayment(int $chatId, User $user, int $amount): void
    {
        if (! StarsefarConfig::isEnabled()) {
            $this->sendMessage($chatId, 'درگاه استارز در حال حاضر فعال نیست.');

            return;
        }

        try {
            $client = new StarsEfarClient(StarsefarConfig::getBaseUrl(), StarsefarConfig::getApiKey());
        } catch (\Throwable $exception) {
            Log::error('StarsEfar client init failed', ['action' => 'tg_wallet_topup_starsefar_init_failed', 'message' => $exception->getMessage()]);
            $this->sendMessage($chatId, 'امکان ایجاد لینک پرداخت استارز وجود ندارد. لطفاً بعداً تلاش کنید.');

            return;
        }

        $callbackUrl = URL::to(StarsefarConfig::getCallbackPath());
        $targetAccount = StarsefarConfig::getDefaultTargetAccount() ?: '@xShayank';

        try {
            $response = $client->createGiftLink($amount, $targetAccount, $callbackUrl);
        } catch (\Throwable $exception) {
            Log::error('StarsEfar createGiftLink exception', ['action' => 'tg_wallet_topup_starsefar_link_failed', 'message' => $exception->getMessage()]);
            $this->sendMessage($chatId, 'خطا در ایجاد لینک پرداخت استارز. لطفاً بعداً دوباره تلاش کنید.');

            return;
        }

        if (! ($response['success'] ?? false)) {
            Log::warning('StarsEfar createGiftLink unsuccessful', ['action' => 'tg_wallet_topup_starsefar_link_unsuccessful', 'response' => $response]);
            $this->sendMessage($chatId, 'خطا در ایجاد لینک پرداخت استارز.');

            return;
        }

        $orderId = $response['orderId'] ?? null;
        $link = $response['link'] ?? null;

        if (! $orderId || ! $link) {
            Log::warning('StarsEfar createGiftLink missing orderId or link', ['action' => 'tg_wallet_topup_starsefar_link_invalid', 'response' => $response]);
            $this->sendMessage($chatId, 'پاسخ نامعتبر از درگاه استارز دریافت شد.');

            return;
        }

        PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => $orderId,
            'user_id' => $user->id,
            'amount_toman' => $amount,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
            'target_account' => $targetAccount,
            'meta' => [
                'source' => 'telegram_bot',
                'response' => $response,
                'callback_url' => $callbackUrl,
            ],
        ]);

        Log::info('StarsEfar payment initiated via Telegram bot', [
            'action' => 'tg_wallet_topup_starsefar_created',
            'user_id' => $user->id,
            'order_id' => $orderId,
            'amount' => $amount,
        ]);

        $message = "✅ لینک پرداخت استارز ایجاد شد.\n\nلطفاً با کلیک روی لینک زیر پرداخت را تکمیل کنید:\n{$link}\n\nپس از تکمیل پرداخت، کیف پول شما به صورت خودکار شارژ خواهد شد.";
        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => 'پرداخت استارز', 'url' => $link])])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'wallet'])]);

        $this->sendMessage($chatId, $message, $keyboard, false);
    }
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => Transaction::TYPE_DEPOSIT,
                'status' => Transaction::STATUS_PENDING,
                'description' => 'شارژ کیف پول (تلگرام - کارت به کارت)',
                'metadata' => [
                    'source' => 'telegram_bot',
                    'method' => 'card_to_card',
                ],
            ]);

            $this->sessions->setState($session, self::STATE_WALLET_RECEIPT, [
                'transaction_id' => $transaction->id,
            ]);

            $cardNumber = $this->settings->get('payment_card_number');
            $holder = $this->settings->get('payment_card_holder_name');
            $instructions = $this->settings->get('payment_card_instructions');

            $message = "✅ درخواست شارژ ثبت شد.\n\nلطفاً مبلغ *".number_format($amount)." تومان* را به شماره کارت زیر واریز کرده و رسید را ارسال کنید:";
            if ($cardNumber) {
                $message .= "\n💳 شماره کارت: `{$cardNumber}`";
            }
            if ($holder) {
                $message .= "\n👤 نام صاحب حساب: {$this->escapeMarkdown($holder)}";
            }
            if ($instructions) {
                $message .= "\n\n📌 توضیحات: {$this->escapeMarkdown($instructions)}";
            }
            $message .= "\n\nپس از ارسال رسید، تیم پشتیبانی آن را بررسی خواهد کرد.";

            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منو', 'callback_data' => 'main'])]);

            $this->sendMessage($chatId, $message, $keyboard);
            Log::info('Telegram wallet top-up initiated', [
                'action' => 'tg_wallet_topup_initiated',
                'chat_id' => $chatId,
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'method' => 'card_to_card',
            ]);

            return;
        }

        $min = StarsefarConfig::getMinAmountToman();
        if ($amount < $min) {
            $this->sendMessage($chatId, 'حداقل مبلغ استارز '.number_format($min).' تومان است.');

            return;
        }

        $this->sessions->clear($session);
        $this->startStarsEfarPayment($chatId, $user, $amount);
    }

    protected function handlePhoto(int $chatId, $session, ?User $user, $photos): void
    {
        if (! $user) {
            $this->sendMessage($chatId, 'ابتدا باید حساب خود را متصل کنید. لطفاً /start را ارسال نمایید.');

            return;
        }

        if ($session->state !== self::STATE_WALLET_RECEIPT) {
            $this->sendMessage($chatId, 'در حال حاضر به تصویر نیازی نیست. برای شروع عملیات جدید /start را ارسال کنید.');

            return;
        }

        $transactionId = $session->data['transaction_id'] ?? null;
        if (! $transactionId) {
            $this->sessions->clear($session);
            $this->sendMessage($chatId, 'خطایی رخ داد. لطفاً دوباره درخواست شارژ ارسال کنید.');

            return;
        }

        $transaction = Transaction::where('id', $transactionId)
            ->where('user_id', $user->id)
            ->where('status', Transaction::STATUS_PENDING)
            ->first();

        if (! $transaction) {
            $this->sessions->clear($session);
            $this->sendMessage($chatId, 'تراکنش معتبر یافت نشد یا قبلاً بررسی شده است.');

            return;
        }

        try {
            $photo = collect($photos)->last();
            $file = Telegram::getFile(['file_id' => $photo->getFileId()]);
            $contents = file_get_contents("https://api.telegram.org/file/bot{$this->botToken}/{$file->getFilePath()}");
            if ($contents === false) {
                throw new \RuntimeException('Failed to download photo from Telegram');
            }

            $path = 'wallet-receipts/'.Str::random(40).'.jpg';
            Storage::disk('public')->put($path, $contents);

            $meta = $transaction->metadata ?? [];
            $meta['receipt_uploaded_at'] = now()->toDateTimeString();
            $meta['source'] = 'telegram_bot';

            $transaction->update([
                'proof_image_path' => $path,
                'metadata' => $meta,
            ]);

            $this->sessions->clear($session);
            $this->sendMessage($chatId, '✅ رسید شما با موفقیت ثبت شد. پس از تایید تیم پشتیبانی، مبلغ به کیف پول شما افزوده می‌شود.');

            $adminChatId = $this->settings->get('telegram_admin_chat_id');
            if ($adminChatId) {
                $url = Storage::disk('public')->url($path);
                $adminMessage = "رسید جدید برای تراکنش #{$transaction->id} ثبت شد. مبلغ: ".number_format($transaction->amount).' تومان.';
                $adminMessage .= "\nکاربر: {$user->email}";
                $adminMessage .= "\nلینک رسید: {$url}";
                Telegram::sendMessage([
                    'chat_id' => $adminChatId,
                    'text' => $adminMessage,
                ]);
            }

            Log::info('Telegram wallet top-up receipt uploaded', [
                'action' => 'tg_wallet_topup_receipt_uploaded',
                'transaction_id' => $transaction->id,
                'user_id' => $user->id,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to process Telegram receipt', [
                'action' => 'tg_wallet_topup_receipt_failed',
                'transaction_id' => $transaction->id ?? null,
                'message' => $exception->getMessage(),
            ]);
            $this->sendMessage($chatId, '❌ در ذخیره‌سازی رسید مشکلی پیش آمد. لطفاً مجدداً تلاش کنید.');
        }
    }

    protected function showResellerUpgradeStatus(int $chatId, User $user): void
    {
        $reseller = $user->reseller;
        $balance = $this->resolveWalletBalance($user);
        $threshold = (int) config('billing.reseller.min_wallet_upgrade', 100000);

        if ($reseller instanceof Reseller && $reseller->isWalletBased()) {
            $this->sendMessage($chatId, 'شما در حال حاضر یک ریسلر فعال هستید.');

            return;
        }

        $remaining = max($threshold - $balance, 0);
        $message = "برای تبدیل به ریسلر، حداقل موجودی مورد نیاز *".number_format($threshold)." تومان* است.";
        $message .= "\nموجودی فعلی شما: *".number_format($balance)." تومان*.";
        if ($remaining > 0) {
            $message .= "\nمبلغ باقیمانده: *".number_format($remaining)." تومان*.";
            $message .= "\nبه محض رسیدن موجودی به مقدار لازم، حساب شما به صورت خودکار به ریسلر تبدیل خواهد شد.";
        } else {
            $message .= "\nشما واجد شرایط ارتقا هستید و به‌زودی فرآیند تکمیل خواهد شد.";
        }

        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '💳 شارژ کیف پول', 'callback_data' => 'wallet_topup'])])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'main'])]);

        $this->sendMessage($chatId, $message, $keyboard);
    }

    protected function showResellerDashboard(int $chatId, User $user): void
    {
        $reseller = $user->reseller;

        if (! ($reseller instanceof Reseller) || ! $reseller->isWalletBased()) {
            $this->sendMessage($chatId, 'شما هنوز ریسلر نیستید.');

            return;
        }

        $message = "📊 داشبورد ریسلر\n\nوضعیت: ".($reseller->status === 'active' ? 'فعال ✅' : 'غیرفعال ⚠️');
        $message .= "\nموجودی کیف پول ریسلر: *".number_format($reseller->wallet_balance)." تومان*";
        $message .= "\nقیمت هر گیگ: ".number_format($reseller->getWalletPricePerGb()).' تومان';

        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '⚙️ کانفیگ‌های من', 'callback_data' => 'configs:list'])])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'main'])]);

        $this->sendMessage($chatId, $message, $keyboard);
    }
    protected function showConfigList(int $chatId, User $user): void
    {
        $reseller = $user->reseller;
        if (! ($reseller instanceof Reseller) || ! $reseller->isWalletBased()) {
            $this->sendMessage($chatId, 'دسترسی به مدیریت کانفیگ ندارید.');

            return;
        }

        $configs = $reseller->configs()->latest()->limit(5)->get();

        if ($configs->isEmpty()) {
            $this->sendMessage($chatId, 'هیچ کانفیگ فعالی برای شما ثبت نشده است.');

            return;
        }

        $message = "🔧 کانفیگ‌های اخیر شما:";
        $keyboard = Keyboard::make()->inline();

        foreach ($configs as $config) {
            $label = '#'.$config->id.' - '.($config->custom_name ?: $config->external_username ?: 'بدون نام');
            $status = match ($config->status) {
                'active' => 'فعال ✅',
                'disabled' => 'غیرفعال ⛔️',
                'expired' => 'منقضی شده ⚠️',
                default => $config->status,
            };
            $message .= "\n- {$label} ({$status})";
            $keyboard->row([Keyboard::inlineButton(['text' => "مشاهده {$config->id}", 'callback_data' => 'config:show:'.$config->id])]);
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'main'])]);

        $this->sendMessage($chatId, $message, $keyboard);
    }

    protected function handleConfigAction(int $chatId, User $user, string $subAction, ?int $configId): void
    {
        if (! $configId) {
            $this->sendMessage($chatId, 'کانفیگ انتخاب‌شده معتبر نیست.');

            return;
        }

        $reseller = $user->reseller;
        if (! ($reseller instanceof Reseller) || ! $reseller->isWalletBased()) {
            $this->sendMessage($chatId, 'دسترسی به مدیریت کانفیگ ندارید.');

            return;
        }

        $config = $reseller->configs()->find($configId);
        if (! $config) {
            $this->sendMessage($chatId, 'کانفیگ یافت نشد.');

            return;
        }

        switch ($subAction) {
            case 'show':
                $this->showConfigDetail($chatId, $config);

                return;
            case 'enable':
                $this->toggleConfig($chatId, $config, true);

                return;
            case 'disable':
                $this->toggleConfig($chatId, $config, false);

                return;
            case 'reset':
                $this->resetConfigUsage($chatId, $config);

                return;
            case 'link':
                $this->sendConfigLink($chatId, $config);

                return;
        }

        $this->sendMessage($chatId, 'عملیات انتخاب‌شده پشتیبانی نمی‌شود.');
    }

    protected function showConfigDetail(int $chatId, ResellerConfig $config): void
    {
        $usagePercent = null;
        if ($config->traffic_limit_bytes > 0) {
            $usagePercent = round(($config->getTotalUsageBytes() / $config->traffic_limit_bytes) * 100, 1);
        }

        $message = "کانفیگ #{$config->id}\n";
        $message .= 'نام: '.($config->custom_name ?: $config->external_username ?: '—');
        $message .= "\nوضعیت: {$config->status}";
        if ($config->expires_at) {
            $message .= "\nانقضا: {$config->expires_at->format('Y-m-d')}";
        }
        if ($usagePercent !== null) {
            $message .= "\nمصرف: {$usagePercent}%";
        }

        $keyboard = Keyboard::make()->inline();
        if ($config->status === 'active') {
            $keyboard->row([Keyboard::inlineButton(['text' => '⛔️ غیرفعال‌سازی', 'callback_data' => 'config:disable:'.$config->id])]);
        } else {
            $keyboard->row([Keyboard::inlineButton(['text' => '✅ فعال‌سازی', 'callback_data' => 'config:enable:'.$config->id])]);
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '🔄 ریست ترافیک', 'callback_data' => 'config:reset:'.$config->id])]);
        $keyboard->row([Keyboard::inlineButton(['text' => '🔗 لینک اشتراک', 'callback_data' => 'config:link:'.$config->id])]);
        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'configs:list'])]);

        $this->sendMessage($chatId, $message, $keyboard);
    }

    protected function toggleConfig(int $chatId, ResellerConfig $config, bool $enable): void
    {
        Log::info('Telegram config toggle requested', [
            'action' => $enable ? 'tg_config_enable_attempt' : 'tg_config_disable_attempt',
            'config_id' => $config->id,
            'reseller_id' => $config->reseller_id,
        ]);

        $provisioner = ProvisionerFactory::forConfig($config);
        $result = $enable
            ? $provisioner->enableConfig($config)
            : $provisioner->disableConfig($config);

        if (! ($result['success'] ?? false)) {
            Log::warning('Telegram config toggle failed', [
                'action' => $enable ? 'tg_config_enable_failed' : 'tg_config_disable_failed',
                'config_id' => $config->id,
                'attempts' => $result['attempts'] ?? null,
                'error' => $result['last_error'] ?? null,
            ]);
            $this->sendMessage($chatId, 'اجرای عملیات در پنل ریموت با خطا مواجه شد. لطفاً بعداً دوباره تلاش کنید.');

            return;
        }

        if ($enable) {
            $config->update(['status' => 'active', 'disabled_at' => null]);
        } else {
            $config->update(['status' => 'disabled', 'disabled_at' => now()]);
        }

        Log::info('Telegram config toggle succeeded', [
            'action' => $enable ? 'tg_config_enable_success' : 'tg_config_disable_success',
            'config_id' => $config->id,
        ]);

        $this->sendMessage($chatId, $enable ? 'کانفیگ با موفقیت فعال شد.' : 'کانفیگ با موفقیت غیرفعال شد.');
    }

    protected function resetConfigUsage(int $chatId, ResellerConfig $config): void
    {
        if (! $config->canResetUsage()) {
            $this->sendMessage($chatId, 'ریست ترافیک در حال حاضر امکان‌پذیر نیست. لطفاً بعداً تلاش کنید.');

            return;
        }

        try {
            DB::transaction(function () use ($config) {
                $usageBytes = $config->usage_bytes;
                $meta = $config->meta ?? [];
                $meta['settled_usage_bytes'] = (int) ($meta['settled_usage_bytes'] ?? 0) + $usageBytes;
                $meta['last_reset_at'] = now()->toDateTimeString();
                if ($config->panel_type === 'eylandoo') {
                    $meta['used_traffic'] = 0;
                    $meta['data_used'] = 0;
                }

                $config->update([
                    'usage_bytes' => 0,
                    'meta' => $meta,
                ]);

                $reseller = $config->reseller;
                $totalUsage = $reseller->configs()->get()->sum(function ($cfg) {
                    return $cfg->usage_bytes + (int) data_get($cfg->meta, 'settled_usage_bytes', 0);
                });

                $reseller->update([
                    'traffic_used_bytes' => $totalUsage - (int) ($reseller->admin_forgiven_bytes ?? 0),
                ]);
            });
        } catch (\Throwable $exception) {
            Log::error('Telegram config reset local update failed', [
                'action' => 'tg_config_reset_failed_local',
                'config_id' => $config->id,
                'message' => $exception->getMessage(),
            ]);
            $this->sendMessage($chatId, 'خطا در به‌روزرسانی محلی کانفیگ.');

            return;
        }

        $panelResult = ['success' => false];
        try {
            $panel = $config->panel;
            if ($panel) {
                $panelResult = $this->resellerProvisioner->resetUserUsage(
                    $panel->panel_type,
                    $panel->getCredentials(),
                    $config->panel_user_id
                );
            }
        } catch (\Throwable $exception) {
            $panelResult['last_error'] = $exception->getMessage();
        }

        if (! ($panelResult['success'] ?? false)) {
            Log::warning('Telegram config reset remote failed', [
                'action' => 'tg_config_reset_failed_remote',
                'config_id' => $config->id,
                'error' => $panelResult['last_error'] ?? null,
            ]);
            $this->sendMessage($chatId, 'ترافیک محلی ریست شد اما پنل ریموت پاسخی نداد. لطفاً وضعیت را بررسی کنید.');

            return;
        }

        Log::info('Telegram config reset succeeded', [
            'action' => 'tg_config_reset_success',
            'config_id' => $config->id,
        ]);

        $this->sendMessage($chatId, 'ترافیک کانفیگ با موفقیت ریست شد.');
    }

    protected function sendConfigLink(int $chatId, ResellerConfig $config): void
    {
        $link = $config->subscription_url;
        if (! $link) {
            $this->sendMessage($chatId, 'این کانفیگ لینک اشتراک فعالی ندارد.');

            return;
        }

        $this->sendMessage($chatId, "🔗 لینک اشتراک:\n{$link}", null, false);
    }

    protected function showSupport(int $chatId): void
    {
        $supportUrl = $this->settings->get('support_telegram_link') ?? 'https://t.me/VPNMarket_OfficialSupport';
        $this->sendMessage($chatId, "برای ارتباط با پشتیبانی می‌توانید به لینک زیر مراجعه کنید:\n{$supportUrl}", null, false);
    }

    protected function sendMessage(int $chatId, string $message, ?Keyboard $keyboard = null, bool $markdown = true): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $message,
        ];

        if ($markdown) {
            $payload['parse_mode'] = 'Markdown';
        }

        if ($keyboard) {
            $payload['reply_markup'] = $keyboard;
        }

        Telegram::sendMessage($payload);
    }

    protected function escapeMarkdown(string $text): string
    {
        return str_replace(['*', '_', '`', '[', ']'], ['\\*', '\\_', '\\`', '\\[', '\\]'], $text);
    }
}
