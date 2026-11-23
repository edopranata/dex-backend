<?php

namespace App\Http\Controllers;

use App\Services\Auth\CentralAccount\CentralAccount;
use App\Services\Auth\CentralAccount\CentralAccountException;
use App\Services\Auth\CentralAccount\CentralAccountSessions;
use App\Services\Auth\CentralAccount\CentralAccountUsers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Masuk lewat tune-in.trade": /auth/redirect sends the browser to Account, /auth/callback takes the code back,
 * exchanges it server to server, finds or creates the local user, and starts a normal session.
 */
class CentralAuthController extends Controller
{
    private const PENDING_KEY = 'central_account.pending';

    public function __construct(private CentralAccount $account) {}

    public function redirect(Request $request): RedirectResponse
    {
        if ($this->account->mode() === 'local') {
            return redirect('/login');
        }

        $state = Str::random(40);
        $verifier = Str::random(96);
        $request->session()->put(self::PENDING_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'to' => $this->safePath($request->query('to')),
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        return redirect()->away($this->account->authorizeUrl($state, $verifier));
    }

    public function callback(Request $request, CentralAccountUsers $users, CentralAccountSessions $sessions): RedirectResponse
    {
        $pending = $request->session()->pull(self::PENDING_KEY);

        if ($this->account->mode() === 'local') {
            return redirect('/login');
        }

        if (! is_array($pending) || $pending['expires_at'] < now()->getTimestamp()
            || ! hash_equals((string) $pending['state'], (string) $request->query('state'))) {
            return $this->fail('state');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->fail('denied');
        }

        try {
            $tokens = $this->account->exchangeCode((string) $request->query('code'), (string) $pending['verifier']);
            $profile = $this->account->me($tokens['access_token']);
            $user = $users->resolve($profile);
        } catch (CentralAccountException $e) {
            Log::warning('Login lewat tune-in.trade gagal', ['reason' => $e->reason, 'error' => $e->getMessage()]);

            return $this->fail($e->reason);
        }

        // Not "remember me": when this session ends, the next login goes through Account again (and its checks).
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $sessions->start($request->session(), $user, $profile['sub'], $tokens);

        return redirect($pending['to'] ?? '/');
    }

    private function fail(string $reason): RedirectResponse
    {
        return redirect('/login?'.http_build_query(['account_error' => $reason]));
    }

    /**
     * Only a path on this site, so the login cannot be used to send someone elsewhere.
     */
    private function safePath(mixed $path): ?string
    {
        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\')
            ? $path
            : null;
    }
}
