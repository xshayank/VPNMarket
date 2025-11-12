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
        }
        // Additional callback handlers will be added in subsequent commits
    }

    /**
     * Handle photo messages
     */
    protected function handlePhotoMessage($update, $link, $chatId): void
    {
        if (! $link) {
            return;
        }

        // Photo handling will be implemented for proof uploads
    }

    /**
     * Handle session-based flows
     */
    protected function handleSessionFlow($session, $user, $text, $update): void
    {
        $session->touch();

        // Session flow handlers will be added in subsequent commits
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
