<?php

namespace App\Services\Auth\CentralAccount;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Account tokens of one logged-in session, used to ask Account again at most every 10 minutes whether the
 * account still has access. The session only holds a random link id; the tokens live encrypted in the cache under
 * that id, so parallel API requests never overwrite a freshly rotated refresh token with an old session copy.
 */
class CentralAccountSessions
{
    public const SESSION_KEY = 'central_account.link';

    private const TTL_DAYS = 30;

    public function __construct(private CentralAccount $account) {}

    /**
     * @param  array{access_token: string, refresh_token: string|null, expires_at: int}  $tokens
     */
    public function start(Session $session, User $user, string $sub, array $tokens): void
    {
        $link = Str::random(40);
        $session->put(self::SESSION_KEY, $link);
        $this->store($link, ['user_id' => $user->id, 'sub' => $sub, ...$tokens, 'checked_at' => now()->getTimestamp()]);
    }

    public function linked(Session $session): bool
    {
        return is_string($session->get(self::SESSION_KEY));
    }

    public function forget(Session $session): void
    {
        $link = $session->pull(self::SESSION_KEY);

        if (is_string($link)) {
            Cache::forget($this->key($link));
        }
    }

    /**
     * False when the session must end: the tokens are gone, Account refused them, or the account lost access.
     * True when access is confirmed, checked recently, being checked by another request, or Account is unreachable.
     */
    public function stillAllowed(Session $session, User $user): bool
    {
        $link = $session->get(self::SESSION_KEY);
        $data = is_string($link) ? $this->load($link) : null;

        if ($data === null || $data['user_id'] !== $user->id) {
            return false;
        }

        if ($data['checked_at'] > now()->subMinutes((int) config('central_account.recheck_minutes', 10))->getTimestamp()) {
            return true;
        }

        $lock = Cache::lock($this->key($link).':check', 15);

        if (! $lock->get()) {
            return true;
        }

        try {
            // Read again under the lock: another request may have just refreshed the tokens.
            $data = $this->load($link) ?? $data;

            return $this->check($link, $data, $user);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function check(string $link, array $data, User $user): bool
    {
        try {
            if ($data['expires_at'] <= now()->getTimestamp()) {
                if ($data['refresh_token'] === null) {
                    return false;
                }

                $data = [...$data, ...$this->account->refresh($data['refresh_token'])];
                // Store the rotated refresh token at once: the old one no longer works.
                $this->store($link, $data);
            }

            $profile = $this->account->me($data['access_token']);
        } catch (CentralAccountException $e) {
            if ($e->reason !== CentralAccountException::UNREACHABLE) {
                Log::info('Akun tune-in.trade: sesi diakhiri', ['user_id' => $user->id, 'reason' => $e->getMessage()]);

                return false;
            }

            // Account is down: keep the session and try again in a minute instead of on every request.
            Log::warning('Akun tune-in.trade tidak bisa dihubungi saat pemeriksaan ulang', ['error' => $e->getMessage()]);
            $retryAt = now()->subMinutes((int) config('central_account.recheck_minutes', 10))->addMinute()->getTimestamp();
            $this->store($link, [...$data, 'checked_at' => $retryAt]);

            return true;
        }

        if ($profile['sub'] !== $data['sub']) {
            return false;
        }

        $user->forceFill(['is_admin' => $profile['role'] === 'admin'])->save();
        $this->store($link, [...$data, 'checked_at' => now()->getTimestamp()]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function store(string $link, array $data): void
    {
        Cache::put($this->key($link), Crypt::encryptString((string) json_encode($data)), now()->addDays(self::TTL_DAYS));
    }

    /**
     * @return array{user_id: int, sub: string, access_token: string, refresh_token: string|null, expires_at: int, checked_at: int}|null
     */
    private function load(string $link): ?array
    {
        try {
            $value = Cache::get($this->key($link));
            $data = is_string($value) ? json_decode(Crypt::decryptString($value), true) : null;
        } catch (Throwable) {
            return null;
        }

        return is_array($data) && isset($data['user_id'], $data['sub'], $data['access_token'], $data['expires_at'], $data['checked_at']) ? $data : null;
    }

    private function key(string $link): string
    {
        return 'central-account:'.$link;
    }
}
