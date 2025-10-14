<?php

namespace Modules\TelegramBot\Listeners;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Ticketing\Events\TicketReplied;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\FileUpload\InputFile;

class SendTelegramReplyNotification
{
    public function handle(TicketReplied $event): void
    {
        $reply = $event->reply;
        $ticket = $reply->ticket;
        $user = $ticket->user;

        if (!$user->telegram_chat_id || !$reply->user->is_admin) {
            return;
        }

        try {
            // تنظیم توکن
            $settings = Setting::all()->pluck('value', 'key');
            $botToken = $settings->get('telegram_bot_token');
            if (!$botToken) {
                Log::error('Telegram bot token not found.');
                return;
            }
            Telegram::setAccessToken($botToken);

            // کیبورد و متن پیام
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✍️ پاسخ به تیکت', 'callback_data' => "reply_ticket_{$ticket->id}"],
                        ['text' => '❌ بستن تیکت', 'callback_data' => "close_ticket_{$ticket->id}"],
                    ]
                ]
            ];
            $message = "📩 پاسخ جدید به تیکت شما:\n\n*موضوع:* {$ticket->subject}\n*پاسخ:* {$reply->message}";

            Log::info('Processing reply ID: ' . $reply->id . ', Attachment path: ' . ($reply->attachment_path ?? 'null'));

            // ارسال فایل ضمیمه (اگر وجود داشت)
            if ($reply->attachment_path && Storage::disk('public')->exists($reply->attachment_path)) {
                $filePath = Storage::disk('public')->path($reply->attachment_path);
                $mimeType = Storage::disk('public')->mimeType($reply->attachment_path);

                $telegramData = [
                    'chat_id' => $user->telegram_chat_id,
                    'caption' => $message,
                    'reply_markup' => json_encode($keyboard),
                    'parse_mode' => 'Markdown',
                ];

                if (str_starts_with($mimeType, 'image/')) {
                    $telegramData['photo'] = InputFile::create($filePath);
                    Telegram::sendPhoto($telegramData);
                } else {
                    $telegramData['document'] = InputFile::create($filePath);
                    Telegram::sendDocument($telegramData);
                }

                Log::info('Attachment sent successfully for reply ID: ' . $reply->id);
                return; // بعد از ارسال فایل، خروج
            }

            // اگر فایل نبود، فقط متن ارسال شود
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $message,
                'reply_markup' => json_encode($keyboard),
                'parse_mode' => 'Markdown',
            ]);

            Log::info('Text-only message sent for reply ID: ' . $reply->id);

        } catch (\Exception $e) {
            Log::error('Failed to send Telegram ticket reply notification for reply ID ' . $reply->id . ': ' . $e->getMessage());
        }
    }
}
