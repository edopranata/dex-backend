<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One-time codes that prove a Telegram chat belongs to a logged-in user: the code is only
 * shown inside the app, and whoever sends it to the bot links that chat to the account.
 */
class TelegramLinker
{
    /**
     * No 0/O or 1/I, so a code typed by hand is not misread.
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * A fresh code for the user; any previous code stops working.
     *
     * @return array{code: string, expires_at: Carbon}
     */
    public function issue(User $user): array
    {
        if ($old = Cache::pull("telegram:link-user:{$user->id}")) {
            Cache::forget("telegram:link:{$old}");
        }

        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $expiresAt = now()->addMinutes((int) config('market.telegram.link_code_ttl_minutes'));
        Cache::put("telegram:link:{$code}", $user->id, $expiresAt);
        Cache::put("telegram:link-user:{$user->id}", $code, $expiresAt);

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * Link the chat to the user who owns the code. Returns null for unknown or expired codes.
     */
    public function redeem(string $code, string $chatId, ?string $username): ?User
    {
        $code = strtoupper(trim($code));
        $user = ($userId = Cache::pull("telegram:link:{$code}")) ? User::find($userId) : null;

        if ($user === null) {
            return null;
        }

        Cache::forget("telegram:link-user:{$user->id}");

        $user->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_username' => $username,
            'telegram_linked_at' => now(),
        ])->save();

        return $user;
    }

    public function unlink(User $user): void
    {
        $user->forceFill(['telegram_chat_id' => null, 'telegram_username' => null, 'telegram_linked_at' => null])->save();
    }
}
