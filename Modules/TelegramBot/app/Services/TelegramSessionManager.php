<?php

namespace Modules\TelegramBot\Services;

use App\Models\TelegramSession;
use Carbon\CarbonInterval;

class TelegramSessionManager
{
    public function touch(int $chatId): TelegramSession
    {
        $session = TelegramSession::firstOrCreate(['chat_id' => $chatId]);
        $session->last_activity_at = now();
        $session->save();

        return $session->fresh();
    }

    public function resetIfExpired(TelegramSession $session, CarbonInterval $ttl): TelegramSession
    {
        $expiry = $session->last_activity_at;

        if ($expiry && $expiry->lt(now()->sub($ttl))) {
            $session->state = null;
            $session->data = null;
            $session->save();
        }

        return $session->fresh();
    }

    public function setState(TelegramSession $session, ?string $state, array $data = []): TelegramSession
    {
        $session->state = $state;
        $session->data = $data;
        $session->last_activity_at = now();
        $session->save();

        return $session->fresh();
    }

    public function clear(TelegramSession $session): void
    {
        $session->update([
            'state' => null,
            'data' => null,
            'last_activity_at' => now(),
        ]);
    }
}
