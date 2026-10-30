<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\TwoFactorDisabled;
use App\Notifications\TwoFactorResetLink;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Reset 2FA" for users who lost their authenticator and recovery codes: only after the password
 * was accepted on the login screen, a single-use link goes to the account's email. Switching 2FA
 * off (by link or by an admin) always sends the user a notice and alerts the admins on Telegram.
 */
class TwoFactorReset
{
    public const LINK_MINUTES = 30;

    public function sendLink(User $user): void
    {
        $token = Str::random(64);
        Cache::put($this->key($user), hash('sha256', $token), now()->addMinutes(self::LINK_MINUTES));

        $user->notifyNow(new TwoFactorResetLink($token, self::LINK_MINUTES));
    }

    /**
     * Switch 2FA off when the link is valid; the link works once.
     */
    public function useLink(string $email, string $token): ?User
    {
        $user = User::query()->where('email', $email)->where('is_system', false)->first();
        $expected = $user ? Cache::get($this->key($user)) : null;

        if ($user === null || ! is_string($expected) || ! hash_equals($expected, hash('sha256', $token))) {
            return null;
        }

        Cache::forget($this->key($user));
        $this->disable($user, byAdmin: false);

        return $user;
    }

    public function disable(User $user, bool $byAdmin): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        rescue(fn () => $user->notify(new TwoFactorDisabled(now(), $byAdmin)));
        app(TelegramNotifier::class)->system('2FA dimatikan', "{$user->email}: ".($byAdmin ? 'oleh admin' : 'lewat link reset di email').'.');
    }

    private function key(User $user): string
    {
        return "two-factor-reset:{$user->id}";
    }
}
