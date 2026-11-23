<?php

namespace App\Http\Middleware;

use App\Services\Auth\CentralAccount\CentralAccount;
use App\Services\Auth\CentralAccount\CentralAccountSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * On every API request (appended to the `api` group):
 * - in `central` mode the old login, password reset and 2FA reset are closed, and sessions from the old login end;
 * - a session that came through Account is checked with Account again every 10 minutes, and ends when the account
 *   lost access or "Keluar dari semua" revoked its tokens. If Account is unreachable the session continues.
 * In `local` mode nothing is checked, so switching back to local is the way out when Account has a problem.
 */
class CentralAccountGuard
{
    /** Routes of the old login, closed in central mode. */
    private const LOCAL_LOGIN_ROUTES = [
        'auth.login',
        'auth.two-factor',
        'auth.password.forgot',
        'auth.password.reset',
        'auth.two-factor.reset',
        'auth.two-factor.reset-request',
    ];

    public function __construct(private CentralAccount $account, private CentralAccountSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $mode = $this->account->mode();

        if ($mode === 'central' && in_array($request->route()?->getName(), self::LOCAL_LOGIN_ROUTES, true)) {
            return response()->json(['message' => 'Login hanya lewat tune-in.trade.', 'code' => 'central_login_only'], 403);
        }

        if ($mode === 'local' || ! $request->hasSession()) {
            return $next($request);
        }

        $user = Auth::guard('web')->user();

        if ($user === null) {
            return $next($request);
        }

        $allowed = $this->sessions->linked($request->session())
            ? $this->sessions->stillAllowed($request->session(), $user)
            : $mode !== 'central';

        if (! $allowed) {
            $this->sessions->forget($request->session());
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Sesi berakhir. Silakan masuk lagi lewat tune-in.trade.', 'code' => 'account_session_ended'], 401);
        }

        return $next($request);
    }
}
