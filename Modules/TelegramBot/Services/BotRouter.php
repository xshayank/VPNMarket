<?php

namespace Modules\TelegramBot\Services;

use App\Models\TelegramSession;
use App\Models\User;
use App\Models\UserTelegramLink;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Telegram\Bot\Laravel\Facades\Telegram;

class BotRouter
{
    protected BotRenderer $renderer;

    public function __construct(BotRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    /**
     * Route incoming update to appropriate handler
     */
    public function route($update): void
    {
        $chatId = $this->getChatId($update);

        if (! $chatId) {
            return;
        }

        // Check if user is linked
        $link = UserTelegramLink::where('chat_id', $chatId)->first();

        if ($update->isType('callback_query')) {
            $this->handleCallbackQuery($update, $link);
        } elseif ($update->has('message')) {
            $message = $update->getMessage();
            if ($message->has('text')) {
                $this->handleTextMessage($update, $link, $chatId);
            } elseif ($message->has('photo')) {
                $this->handlePhotoMessage($update, $link, $chatId);
            }
        }
    }

    /**
     * Handle text messages
     */
    protected function handleTextMessage($update, $link, $chatId): void
    {
        $text = $update->getMessage()->getText();

        // If not linked, start onboarding
        if (! $link) {
            if ($text === '/start') {
                $this->startOnboarding($chatId, $update);
            } else {
                $this->handleOnboardingFlow($chatId, $text, $update);
            }

            return;
        }

        // User is linked, handle commands
        $user = $link->user;

        if ($text === '/start') {
            $this->showMainMenu($user, $chatId);

            return;
        }

        // Handle session-based flows
        $session = TelegramSession::where('chat_id', $chatId)->first();

        if ($session) {
            $this->handleSessionFlow($session, $user, $text, $update);
        } else {
            // No session, show help
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 'لطفاً از منوی زیر استفاده کنید یا /start را ارسال کنید.',
                'reply_markup' => $this->renderer->getMainMenuKeyboard($user),
            ]);
        }
    }

    /**
     * Start onboarding process
     */
    protected function startOnboarding($chatId, $update): void
    {
        $from = $update->getMessage()->getFrom();

        Log::info('Starting onboarding', [
            'action' => 'tg_onboarding_start',
            'chat_id' => $chatId,
            'username' => $from->getUsername(),
        ]);

        // Create or update session
        TelegramSession::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'state' => 'awaiting_email',
                'data' => [
                    'first_name' => $from->getFirstName(),
                    'last_name' => $from->getLastName(),
                    'username' => $from->getUsername(),
                ],
                'last_activity_at' => now(),
            ]
        );

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "سلام! به ربات ما خوش آمدید.\n\nبرای شروع، لطفاً ایمیل خود را وارد کنید:",
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Handle onboarding flow steps
     */
    protected function handleOnboardingFlow($chatId, $text, $update): void
    {
        $session = TelegramSession::where('chat_id', $chatId)->first();

        if (! $session) {
            $this->startOnboarding($chatId, $update);

            return;
        }

        $session->touch();

        switch ($session->state) {
            case 'awaiting_email':
                $this->processEmail($chatId, $text, $session);
                break;

            case 'awaiting_password':
                $this->processPassword($chatId, $text, $session);
                break;

            case 'awaiting_password_confirm':
                $this->processPasswordConfirm($chatId, $text, $session);
                break;
        }
    }

    /**
     * Process email input
     */
    protected function processEmail($chatId, $email, $session): void
    {
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ ایمیل نامعتبر است. لطفاً یک ایمیل صحیح وارد کنید:',
            ]);

            return;
        }

        // Check if email already exists
        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ این ایمیل قبلاً ثبت شده است. لطفاً با ایمیل دیگری امتحان کنید یا با پشتیبانی تماس بگیرید.',
            ]);

            return;
        }

        $session->setData('email', $email);
        $session->state = 'awaiting_password';
        $session->save();

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ ایمیل ذخیره شد: {$email}\n\nحالا لطفاً یک رمز عبور انتخاب کنید (حداقل 8 کاراکتر):",
        ]);

        Log::info('Email collected', [
            'action' => 'tg_email_collected',
            'chat_id' => $chatId,
        ]);
    }

    /**
     * Process password input
     */
    protected function processPassword($chatId, $password, $session): void
    {
        if (strlen($password) < 8) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ رمز عبور باید حداقل 8 کاراکتر باشد. لطفاً دوباره امتحان کنید:',
            ]);

            return;
        }

        $session->setData('password', $password);
        $session->state = 'awaiting_password_confirm';
        $session->save();

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => '✅ رمز عبور دریافت شد.\n\nلطفاً رمز عبور را دوباره وارد کنید تا تأیید شود:',
        ]);
    }

    /**
     * Process password confirmation and create user
     */
    protected function processPasswordConfirm($chatId, $confirmPassword, $session): void
    {
        $password = $session->getData('password');

        if ($password !== $confirmPassword) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ رمز عبور مطابقت ندارد. لطفاً دوباره رمز عبور را وارد کنید:',
            ]);
            $session->state = 'awaiting_password';
            $session->save();

            return;
        }

        // Create user and link
        try {
            $email = $session->getData('email');
            $firstName = $session->getData('first_name', 'کاربر');

            $user = User::create([
                'name' => $firstName,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);

            UserTelegramLink::create([
                'user_id' => $user->id,
                'chat_id' => $chatId,
                'username' => $session->getData('username'),
                'first_name' => $session->getData('first_name'),
                'last_name' => $session->getData('last_name'),
                'verified_at' => now(),
            ]);

            // Clear session
            $session->delete();

            Log::info('User created and linked', [
                'action' => 'tg_link_complete',
                'user_id' => $user->id,
                'chat_id' => $chatId,
            ]);

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "✅ حساب کاربری شما با موفقیت ایجاد شد!\n\n📧 ایمیل: {$email}\n\nشما می‌توانید با این اطلاعات وارد پنل وب نیز شوید.",
                'parse_mode' => 'Markdown',
            ]);

            // Show main menu
            $this->showMainMenu($user, $chatId);
        } catch (\Exception $e) {
            Log::error('Failed to create user', [
                'action' => 'tg_user_creation_failed',
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ خطا در ایجاد حساب کاربری. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
            ]);
        }
    }

    /**
     * Show main menu to user
     */
    protected function showMainMenu(User $user, $chatId): void
    {
        $message = $this->renderer->getMainMenuMessage($user);
        $keyboard = $this->renderer->getMainMenuKeyboard($user);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * Handle callback queries
     */
    protected function handleCallbackQuery($update, $link): void
    {
        $callbackQuery = $update->getCallbackQuery();
        $data = $callbackQuery->getData();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();

        try {
            Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
        } catch (\Exception $e) {
            Log::warning('Could not answer callback query: '.$e->getMessage());
        }

        if (! $link) {
            return;
        }

        $user = $link->user;

        // Route callback to appropriate handler
        if ($data === '/start' || $data === '/main_menu') {
            $this->showMainMenu($user, $chatId);
        } elseif ($data === '/my_account') {
            $this->showMyAccount($user, $chatId);
        } elseif ($data === '/wallet') {
            $this->showWallet($user, $chatId);
        } elseif ($data === '/become_reseller') {
            $this->showBecomeReseller($user, $chatId);
        } elseif ($data === '/help') {
            $this->showHelp($user, $chatId);
        } elseif ($data === '/topup') {
            $this->showTopupOptions($user, $chatId);
        } elseif ($data === '/transactions') {
            $this->showTransactions($user, $chatId);
        } elseif (str_starts_with($data, 'topup_method_')) {
            $method = str_replace('topup_method_', '', $data);
            $this->handleTopupMethod($user, $chatId, $method);
        } elseif ($data === '/reseller_dashboard') {
            $this->showResellerDashboard($user, $chatId);
        } elseif ($data === '/my_configs') {
            $this->showMyConfigs($user, $chatId);
        }
    }

    /**
     * Handle photo messages
     */
    protected function handlePhotoMessage($update, $link, $chatId): void
    {
        if (! $link) {
            return;
        }

        $user = $link->user;
        $session = TelegramSession::where('chat_id', $chatId)->first();

        if (! $session || $session->state !== 'awaiting_card_proof') {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ لطفاً ابتدا فرآیند پرداخت را شروع کنید.',
            ]);

            return;
        }

        $transactionId = $session->getData('transaction_id');
        $transaction = \App\Models\Transaction::find($transactionId);

        if (! $transaction || $transaction->user_id !== $user->id) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ تراکنش یافت نشد.',
            ]);

            return;
        }

        try {
            // Get photo file
            $message = $update->getMessage();
            $photo = collect($message->getPhoto())->last();
            $settings = \App\Models\Setting::all()->pluck('value', 'key');
            $botToken = $settings->get('telegram_bot_token');

            $file = Telegram::getFile(['file_id' => $photo->getFileId()]);
            $fileContents = file_get_contents("https://api.telegram.org/file/bot{$botToken}/{$file->getFilePath()}");

            if ($fileContents === false) {
                throw new \Exception('Failed to download file from Telegram.');
            }

            // Store proof image
            $year = now()->format('Y');
            $month = now()->format('m');
            $uuid = \Illuminate\Support\Str::uuid();
            $fileName = "wallet-topups/{$year}/{$month}/{$uuid}.jpg";

            \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $fileContents);

            // Update transaction with proof
            $transaction->update(['proof_image_path' => $fileName]);

            // Clear session
            $session->delete();

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "✅ رسید دریافت شد!\n\nتراکنش شما در انتظار تأیید توسط ادمین است.\nپس از تأیید، موجودی شما به‌روزرسانی خواهد شد.",
            ]);

            Log::info('Payment proof uploaded', [
                'action' => 'tg_proof_uploaded',
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'proof_path' => $fileName,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process payment proof', [
                'action' => 'tg_proof_upload_failed',
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ خطا در پردازش رسید. لطفاً دوباره تلاش کنید.',
            ]);
        }
    }

    /**
     * Handle session-based flows
     */
    protected function handleSessionFlow($session, $user, $text, $update): void
    {
        $session->touch();

        switch ($session->state) {
            case 'awaiting_topup_amount':
                $this->processTopupAmount($user, $session, $text, $update);
                break;

            case 'awaiting_card_proof':
                // Will be handled in photo message handler
                Telegram::sendMessage([
                    'chat_id' => $user->telegramLink->chat_id,
                    'text' => 'لطفاً رسید پرداخت را به صورت عکس ارسال کنید.',
                ]);
                break;
        }
    }

    /**
     * Process topup amount input
     */
    protected function processTopupAmount(User $user, TelegramSession $session, string $text, $update): void
    {
        // Clean and validate amount
        $amount = (int) str_replace(',', '', trim($text));

        if ($amount < 10000) {
            Telegram::sendMessage([
                'chat_id' => $session->chat_id,
                'text' => '❌ مبلغ باید حداقل 10,000 تومان باشد. لطفاً دوباره وارد کنید:',
            ]);

            return;
        }

        $method = $session->getData('payment_method');

        if ($method === 'card_to_card') {
            $this->initiateCardToCardPayment($user, $session, $amount);
        } elseif ($method === 'starsefar') {
            $this->initiateStarsefarPayment($user, $session, $amount);
        }
    }

    /**
     * Initiate card-to-card payment
     */
    protected function initiateCardToCardPayment(User $user, TelegramSession $session, int $amount): void
    {
        $settings = \App\Models\Setting::all()->pluck('value', 'key');

        // Create pending transaction
        $transaction = \App\Models\Transaction::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'type' => \App\Models\Transaction::TYPE_DEPOSIT,
            'status' => \App\Models\Transaction::STATUS_PENDING,
            'description' => 'شارژ کیف پول - کارت به کارت (تلگرام)',
            'metadata' => ['source' => 'telegram_bot', 'method' => 'card_to_card'],
        ]);

        // Update session
        $session->state = 'awaiting_card_proof';
        $session->setData('transaction_id', $transaction->id);
        $session->save();

        // Send card details
        $cardNumber = $settings->get('payment_card_number', 'شماره کارتی یافت نشد');
        $cardHolder = $settings->get('payment_card_holder_name', 'نامشخص');

        $message = "💳 *پرداخت کارت به کارت*\n\n";
        $message .= "مبلغ: *".number_format($amount)." تومان*\n\n";
        $message .= "لطفاً مبلغ را به کارت زیر واریز کنید:\n\n";
        $message .= "شماره کارت: `{$cardNumber}`\n";
        $message .= "نام صاحب حساب: {$cardHolder}\n\n";
        $message .= "پس از واریز، عکس رسید را ارسال کنید.";

        Telegram::sendMessage([
            'chat_id' => $session->chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);

        Log::info('Card-to-card payment initiated', [
            'action' => 'tg_card_payment_initiated',
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'amount' => $amount,
        ]);
    }

    /**
     * Initiate StarsEfar payment
     */
    protected function initiateStarsefarPayment(User $user, TelegramSession $session, int $amount): void
    {
        // Clear session
        $session->delete();

        // Note: StarsEfar integration would need proper gateway setup
        // For now, we'll create a pending transaction
        $transaction = \App\Models\Transaction::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'type' => \App\Models\Transaction::TYPE_DEPOSIT,
            'status' => \App\Models\Transaction::STATUS_PENDING,
            'description' => 'شارژ کیف پول - استارز ایفار (تلگرام)',
            'metadata' => ['source' => 'telegram_bot', 'method' => 'starsefar'],
        ]);

        $message = "💰 *پرداخت استارز ایفار*\n\n";
        $message .= "مبلغ: *".number_format($amount)." تومان*\n\n";
        $message .= "⚠️ در حال حاضر پرداخت آنلاین از طریق ربات در حال توسعه است.\n";
        $message .= 'لطفاً از پنل وب برای پرداخت آنلاین استفاده کنید.';

        Telegram::sendMessage([
            'chat_id' => $session->chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);

        Log::info('StarsEfar payment requested', [
            'action' => 'tg_starsefar_payment_requested',
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'amount' => $amount,
        ]);
    }

    /**
     * Show my account info
     */
    protected function showMyAccount(User $user, $chatId): void
    {
        $message = $this->renderer->getMyAccountMessage($user);
        $keyboard = $this->renderer->getMyAccountKeyboard();

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * Show wallet menu
     */
    protected function showWallet(User $user, $chatId): void
    {
        $message = $this->renderer->getWalletMenuMessage($user);
        $keyboard = $this->renderer->getWalletMenuKeyboard();

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * Show become reseller info
     */
    protected function showBecomeReseller(User $user, $chatId): void
    {
        $message = $this->renderer->getBecomeResellerMessage($user);
        $keyboard = $this->renderer->getBecomeResellerKeyboard();

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * Show help
     */
    protected function showHelp(User $user, $chatId): void
    {
        $message = $this->renderer->getHelpMessage();
        $keyboard = $this->renderer->getHelpKeyboard();

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * Show top-up options
     */
    protected function showTopupOptions(User $user, $chatId): void
    {
        $paymentService = app(\App\Services\PaymentMethodService::class);
        $methods = $paymentService->getEnabledMethods();

        if (empty($methods)) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ در حال حاضر هیچ روش پرداختی فعال نیست. لطفاً بعداً دوباره تلاش کنید.',
            ]);

            return;
        }

        $message = "💳 *روش‌های شارژ کیف پول*\n\nلطفاً یک روش پرداخت انتخاب کنید:";

        $keyboard = \Telegram\Bot\Keyboard\Keyboard::make()->inline();

        foreach ($methods as $method) {
            $keyboard->row([
                \Telegram\Bot\Keyboard\Keyboard::inlineButton([
                    'text' => $method['name'],
                    'callback_data' => 'topup_method_'.$method['id'],
                ]),
            ]);
        }

        $keyboard->row([
            \Telegram\Bot\Keyboard\Keyboard::inlineButton([
                'text' => '⬅️ بازگشت',
                'callback_data' => '/wallet',
            ]),
        ]);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard,
        ]);

        Log::info('Top-up options shown', [
            'action' => 'tg_topup_options_shown',
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'available_methods' => array_keys($methods),
        ]);
    }

    /**
     * Handle top-up method selection
     */
    protected function handleTopupMethod(User $user, $chatId, string $method): void
    {
        $paymentService = app(\App\Services\PaymentMethodService::class);

        if (! $paymentService->isMethodEnabled($method)) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ این روش پرداخت در حال حاضر غیرفعال است.',
            ]);

            return;
        }

        // Create session for amount input
        TelegramSession::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'state' => 'awaiting_topup_amount',
                'data' => ['payment_method' => $method],
                'last_activity_at' => now(),
            ]
        );

        $message = "لطفاً مبلغ مورد نظر را به تومان وارد کنید:\n\n";
        $message .= "💡 حداقل مبلغ: 10,000 تومان";

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
        ]);

        Log::info('Awaiting topup amount', [
            'action' => 'tg_awaiting_topup_amount',
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'method' => $method,
        ]);
    }

    /**
     * Show transactions
     */
    protected function showTransactions(User $user, $chatId): void
    {
        $transactions = \App\Models\Transaction::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        if ($transactions->isEmpty()) {
            $message = '📜 تاریخچه تراکنش‌های شما خالی است.';
        } else {
            $message = "📜 *۵ تراکنش اخیر:*\n\n";
            foreach ($transactions as $transaction) {
                $status = $transaction->status === 'completed' ? '✅' : '⏳';
                $type = $transaction->type === 'deposit' ? '💰 شارژ' : '🛒 خرید';
                $amount = number_format($transaction->amount);
                $date = $transaction->created_at->format('Y/m/d H:i');

                $message .= "{$status} {$type} - {$amount} تومان\n";
                $message .= "   {$date}\n\n";
            }
        }

        $keyboard = \Telegram\Bot\Keyboard\Keyboard::make()->inline()
            ->row([
                \Telegram\Bot\Keyboard\Keyboard::inlineButton([
                    'text' => '⬅️ بازگشت',
                    'callback_data' => '/wallet',
                ]),
            ]);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * Show reseller dashboard
     */
    protected function showResellerDashboard(User $user, $chatId): void
    {
        $reseller = $user->reseller;

        if (! $reseller) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ شما هنوز نمایندگی ندارید.',
            ]);

            return;
        }

        $balance = number_format($reseller->wallet_balance ?? 0);
        $configCount = $reseller->configs()->count();
        $activeConfigs = $reseller->configs()->where('status', 'active')->count();

        $message = "🎖 *داشبورد نمایندگی*\n\n";
        $message .= "💰 موجودی: *{$balance} تومان*\n";
        $message .= "⚙️ تعداد کانفیگ‌ها: *{$configCount}*\n";
        $message .= "✅ کانفیگ‌های فعال: *{$activeConfigs}*\n";
        $message .= "📊 وضعیت: *".($reseller->isActive() ? 'فعال ✅' : 'غیرفعال ❌')."*\n";

        $keyboard = \Telegram\Bot\Keyboard\Keyboard::make()->inline()
            ->row([
                \Telegram\Bot\Keyboard\Keyboard::inlineButton([
                    'text' => '⚙️ کانفیگ‌های من',
                    'callback_data' => '/my_configs',
                ]),
            ])
            ->row([
                \Telegram\Bot\Keyboard\Keyboard::inlineButton([
                    'text' => '💰 کیف پول',
                    'callback_data' => '/wallet',
                ]),
            ])
            ->row([
                \Telegram\Bot\Keyboard\Keyboard::inlineButton([
                    'text' => '⬅️ بازگشت',
                    'callback_data' => '/main_menu',
                ]),
            ]);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * Show my configs list
     */
    protected function showMyConfigs(User $user, $chatId): void
    {
        $reseller = $user->reseller;

        if (! $reseller) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ شما هنوز نمایندگی ندارید.',
            ]);

            return;
        }

        $configs = $reseller->configs()->orderBy('created_at', 'desc')->take(10)->get();

        if ($configs->isEmpty()) {
            $message = '⚙️ شما هنوز کانفیگی ندارید.';

            $keyboard = \Telegram\Bot\Keyboard\Keyboard::make()->inline()
                ->row([
                    \Telegram\Bot\Keyboard\Keyboard::inlineButton([
                        'text' => '⬅️ بازگشت',
                        'callback_data' => '/reseller_dashboard',
                    ]),
                ]);
        } else {
            $message = "⚙️ *کانفیگ‌های شما:*\n\n";

            foreach ($configs as $config) {
                $status = $config->status === 'active' ? '✅' : '❌';
                $name = $config->custom_name ?? $config->username;
                $panel = $config->panel ? $config->panel->name : 'نامشخص';

                $message .= "{$status} {$name}\n";
                $message .= "   پنل: {$panel}\n";
                $message .= "   ID: {$config->id}\n\n";
            }

            $keyboard = \Telegram\Bot\Keyboard\Keyboard::make()->inline()
                ->row([
                    \Telegram\Bot\Keyboard\Keyboard::inlineButton([
                        'text' => '⬅️ بازگشت',
                        'callback_data' => '/reseller_dashboard',
                    ]),
                ]);
        }

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard,
        ]);
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
}
