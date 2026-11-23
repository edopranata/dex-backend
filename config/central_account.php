<?php

/*
 * Login through the central account at tune-in.trade (OAuth2 authorization code + PKCE).
 * The same file is in all six apps; only app_key differs. See docs/ARCHITECTURE.md, "Akun tune-in.trade".
 */
return [
    // The key of this app in Account (`account:connect-app <key>`); /api/me must answer for this app.
    'app_key' => 'dex',

    // local: old login only; both: old login and tune-in.trade; central: tune-in.trade only.
    // Changed from Admin → Pengaturan or `php artisan app:auth-mode`; without a client id and secret it acts as local.
    'mode' => env('ACCOUNT_AUTH_MODE', 'local'),

    // Where the browser goes to log in.
    'url' => env('ACCOUNT_URL', 'https://tune-in.trade'),

    // Where this server exchanges the code and calls /api/me: Account's container on the Docker `edge` network,
    // so the request does not go out through Cloudflare. The Host header is taken from `url`.
    'internal_url' => env('ACCOUNT_INTERNAL_URL', 'http://account'),

    // Made on the Account server with `php artisan account:connect-app <key>`; only in .env, never in git.
    'client_id' => env('ACCOUNT_CLIENT_ID'),
    'client_secret' => env('ACCOUNT_CLIENT_SECRET'),

    // Must be exactly the redirect URI registered in Account.
    'redirect_uri' => env('ACCOUNT_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/auth/callback'),

    // How often a logged-in session asks Account again whether the account still has access.
    'recheck_minutes' => (int) env('ACCOUNT_RECHECK_MINUTES', 10),

    'timeout_seconds' => 5,
];
