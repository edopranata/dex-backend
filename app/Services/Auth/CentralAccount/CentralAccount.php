<?php

namespace App\Services\Auth\CentralAccount;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client of the central account at tune-in.trade (Laravel Passport): the browser goes to /oauth/authorize, and this
 * server exchanges the code and calls /api/me over the Docker `edge` network.
 */
class CentralAccount
{
    public const MODES = ['local', 'both', 'central'];

    /**
     * The mode in effect. Without a client id and secret the app always uses its own login, so a mode chosen
     * in the panel can never lock everyone out.
     */
    public function mode(): string
    {
        $mode = (string) config('central_account.mode');

        if (! in_array($mode, self::MODES, true) || ! $this->isConfigured()) {
            return 'local';
        }

        return $mode;
    }

    public function isConfigured(): bool
    {
        return filled(config('central_account.client_id'))
            && filled(config('central_account.client_secret'))
            && filled(config('central_account.url'))
            && filled(config('central_account.internal_url'));
    }

    /**
     * Where the browser goes to log in, with PKCE (S256) and the state for this login.
     */
    public function authorizeUrl(string $state, string $codeVerifier): string
    {
        return rtrim((string) config('central_account.url'), '/').'/oauth/authorize?'.http_build_query([
            'client_id' => config('central_account.client_id'),
            'redirect_uri' => config('central_account.redirect_uri'),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    public static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_at: int}
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('central_account.redirect_uri'),
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_at: int}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * Who the token belongs to and their role in this app. 401 or 403 means the token was revoked, the account
     * was disabled, or it lost access to this app.
     *
     * @return array{sub: string, name: string, email: string, role: string}
     */
    public function me(string $accessToken): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->withToken($accessToken)->get('/api/me'));
        $profile = $response->json();

        if (! is_array($profile) || ! is_string($profile['sub'] ?? null) || ! is_string($profile['email'] ?? null)
            || ! in_array($profile['role'] ?? null, ['admin', 'user'], true)) {
            throw CentralAccountException::unreachable('Jawaban /api/me tidak dikenali.');
        }

        if (($profile['app'] ?? null) !== config('central_account.app_key')) {
            throw CentralAccountException::denied('Token ini untuk aplikasi lain.');
        }

        return [
            'sub' => $profile['sub'],
            'name' => is_string($profile['name'] ?? null) && $profile['name'] !== '' ? $profile['name'] : $profile['email'],
            'email' => $profile['email'],
            'role' => $profile['role'],
        ];
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token: string|null, expires_at: int}
     */
    private function token(array $params): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post('/oauth/token', [
            ...$params,
            'client_id' => config('central_account.client_id'),
            'client_secret' => config('central_account.client_secret'),
        ]));

        if (! is_string($response->json('access_token'))) {
            throw CentralAccountException::unreachable('Jawaban /oauth/token tidak dikenali.');
        }

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            'expires_at' => now()->addSeconds(max(60, (int) $response->json('expires_in', 900)) - 30)->getTimestamp(),
        ];
    }

    /**
     * 400, 401 and 403 from Account mean "no" (bad or revoked token, no access); anything else is Account
     * being unreachable.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call($this->http());
        } catch (ConnectionException $e) {
            throw CentralAccountException::unreachable($e->getMessage());
        }

        if (in_array($response->status(), [400, 401, 403], true)) {
            throw CentralAccountException::denied((string) ($response->json('error') ?? $response->status()));
        }

        if (! $response->successful()) {
            throw CentralAccountException::unreachable('HTTP '.$response->status());
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('central_account.internal_url'), '/'))
            ->withHeaders(['Host' => (string) parse_url((string) config('central_account.url'), PHP_URL_HOST)])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout((int) config('central_account.timeout_seconds', 5));
    }
}
