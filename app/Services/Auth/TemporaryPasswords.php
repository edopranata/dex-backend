<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\TemporaryPassword;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Accounts are created by admins: the user gets a temporary password by email and must replace it
 * at the first login, within VALID_HOURS.
 */
class TemporaryPasswords
{
    public const VALID_HOURS = 72;

    /** No 0/O, 1/l/I: the password is typed over from an email. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    /**
     * Twelve random characters in three groups, e.g. "Hq7k-Rm2x-Tb9w".
     */
    public function generate(): string
    {
        $characters = '';

        for ($i = 0; $i < 12; $i++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($characters, 4));
    }

    /**
     * Give the user this password and require a new one at the next login.
     */
    public function assign(User $user, string $password): Carbon
    {
        $expiresAt = now()->addHours(self::VALID_HOURS);

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'temporary_password_expires_at' => $expiresAt,
        ])->save();

        return $expiresAt;
    }

    /**
     * Generate a temporary password and email it. The password itself is only returned when the
     * email failed, so the admin can pass it on another way.
     *
     * @return array{sent: bool, error: string|null, password: string|null}
     */
    public function issue(User $user, User $admin, bool $isNew): array
    {
        $password = $this->generate();
        $expiresAt = $this->assign($user, $password);

        try {
            $user->notifyNow(new TemporaryPassword($password, $expiresAt, $admin->name, $isNew));
        } catch (Throwable $e) {
            report($e);

            return ['sent' => false, 'error' => mb_substr($e->getMessage(), 0, 300), 'password' => $password];
        }

        return ['sent' => true, 'error' => null, 'password' => null];
    }

    /**
     * The user chose their own password: the temporary-password state ends.
     */
    public function clear(User $user): void
    {
        $user->forceFill(['must_change_password' => false, 'temporary_password_expires_at' => null])->save();
    }
}
