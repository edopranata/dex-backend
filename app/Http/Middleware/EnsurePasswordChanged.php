<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user still on the temporary password from an admin may only replace it; every other API call
 * is refused until they do.
 */
class EnsurePasswordChanged
{
    /** Routes needed to change the password (the SPA's forced change-password screen). */
    private const ALLOWED = ['profile.show', 'profile.password'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return response()->json(['message' => 'Ganti password sementara Anda terlebih dahulu.', 'code' => 'password_change_required'], 403);
        }

        return $next($request);
    }
}
