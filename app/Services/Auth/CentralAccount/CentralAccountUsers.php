<?php

namespace App\Services\Auth\CentralAccount;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Finds the local user for a central account: by account_id, else the user with exactly the same email and no
 * account_id yet (linked once, logged), else a new user with the same start data as `app:create-user`.
 * The admin role follows the role from Account on every login.
 */
class CentralAccountUsers
{
    /**
     * @param  array{sub: string, name: string, email: string, role: string}  $profile
     */
    public function resolve(array $profile): User
    {
        return DB::transaction(function () use ($profile): User {
            $user = User::query()->where('account_id', $profile['sub'])->lockForUpdate()->first();

            if ($user === null) {
                $user = $this->localUsers()->whereNull('account_id')->where('email', $profile['email'])->lockForUpdate()->first();

                if ($user !== null) {
                    $user->forceFill(['account_id' => $profile['sub']]);
                    Log::info('Akun tune-in.trade diikat ke user lokal lewat email', ['user_id' => $user->id, 'account_id' => $profile['sub']]);
                }
            }

            if ($user === null) {
                if (User::query()->where('email', $profile['email'])->exists()) {
                    throw new CentralAccountException(CentralAccountException::CONFLICT, 'Email sudah dipakai user lokal yang terikat ke akun lain.');
                }

                $user = new User($this->newUserAttributes($profile));
                $user->forceFill(['account_id' => $profile['sub']]);
                Log::info('User baru dibuat dari akun tune-in.trade', ['email' => $profile['email'], 'account_id' => $profile['sub']]);
            }

            // Account checked the password and 2FA, so a temporary password from the old login no longer blocks.
            $user->forceFill([
                'is_admin' => $profile['role'] === 'admin',
                'must_change_password' => false,
                'temporary_password_expires_at' => null,
            ])->save();

            return $user;
        });
    }

    /**
     * Users that may be matched by email. DEX: never the strategy accounts (is_system), which cannot log in.
     *
     * @return Builder<User>
     */
    private function localUsers(): Builder
    {
        return User::query()->where('is_system', false);
    }

    /**
     * The start data of a new user, as `app:create-user` gives it. The password is random: this user logs in
     * through Account.
     *
     * @param  array{sub: string, name: string, email: string, role: string}  $profile
     * @return array<string, mixed>
     */
    private function newUserAttributes(array $profile): array
    {
        return [
            'name' => $profile['name'],
            'email' => $profile['email'],
            'password' => Str::random(64),
            'balance_usd' => config('market.simulator.starting_balance'),
        ];
    }
}
