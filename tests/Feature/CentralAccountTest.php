<?php

use App\Models\User;
use App\Services\Auth\CentralAccount\CentralAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Login through the central account at tune-in.trade. Account itself is faked: /oauth/token and /api/me on
 * http://account (the Docker edge network).
 */

beforeEach(function () {
    config([
        'central_account.mode' => 'both',
        'central_account.client_id' => 'client-123',
        'central_account.client_secret' => 'secret-456',
        'central_account.url' => 'https://tune-in.trade',
        'central_account.internal_url' => 'http://account',
        'central_account.redirect_uri' => 'https://app.test/auth/callback',
    ]);
});

/**
 * @param  array<string, mixed>  $profile
 */
function accountProfile(array $profile = []): array
{
    return ['sub' => '7', 'name' => 'Pemilik', 'email' => 'pemilik@contoh.test', 'role' => 'admin', 'app' => config('central_account.app_key'), ...$profile];
}

function tokenResponse(string $access = 'access-1', string $refresh = 'refresh-1'): array
{
    return ['token_type' => 'Bearer', 'expires_in' => 900, 'access_token' => $access, 'refresh_token' => $refresh];
}

/**
 * Goes through /auth/redirect and /auth/callback with the state Account would send back.
 */
function centralLogin(string $to = ''): TestResponse
{
    $location = test()->get('/auth/redirect'.($to !== '' ? '?to='.urlencode($to) : ''))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return test()->get('/auth/callback?'.http_build_query(['code' => 'the-code', 'state' => $query['state']]));
}

function fromSpa(): TestCase
{
    return test()->withHeader('Origin', 'http://localhost:5173');
}

test('the redirect sends the browser to Account with PKCE S256 and a state', function () {
    $location = $this->get('/auth/redirect')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://tune-in.trade/oauth/authorize?')
        ->and($query)->toMatchArray([
            'client_id' => 'client-123',
            'redirect_uri' => 'https://app.test/auth/callback',
            'response_type' => 'code',
            'code_challenge_method' => 'S256',
        ])
        ->and(strlen($query['state']))->toBe(40)
        ->and($query['code_challenge'])->toBe(CentralAccount::codeChallenge(session('central_account.pending')['verifier']));
});

test('without a client id and secret, or in local mode, the old login stays', function (array $config) {
    config($config);

    $this->get('/auth/redirect')->assertRedirect('/login');
    $this->getJson('/api/auth/options')->assertJsonPath('auth_mode', 'local');
})->with([
    'local mode' => [['central_account.mode' => 'local']],
    'central without secret' => [['central_account.mode' => 'central', 'central_account.client_secret' => null]],
]);

test('the callback links the local user with the same email, takes the role, and logs in', function () {
    Http::fake(['http://account/oauth/token' => Http::response(tokenResponse()), 'http://account/api/me' => Http::response(accountProfile())]);
    $user = User::factory()->create(['email' => 'pemilik@contoh.test', 'is_admin' => false, 'must_change_password' => true]);

    centralLogin('/market')->assertRedirect('/market');

    expect(Auth::guard('web')->id())->toBe($user->id)
        ->and($user->refresh()->account_id)->toBe('7')
        ->and($user->is_admin)->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and(User::count())->toBe(1);

    Http::assertSent(function (HttpRequest $request): bool {
        if ($request->url() !== 'http://account/oauth/token') {
            return false;
        }

        return $request->header('Host') === ['tune-in.trade']
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'the-code'
            && $request['client_secret'] === 'secret-456'
            && $request['redirect_uri'] === 'https://app.test/auth/callback'
            && strlen($request['code_verifier']) === 96;
    });
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'http://account/api/me' && $request->header('Authorization') === ['Bearer access-1']);
});

test('a new account gets a new local user, found again by account_id after an email change', function () {
    Http::fake([
        'http://account/oauth/token' => Http::response(tokenResponse()),
        'http://account/api/me' => Http::sequence()
            ->push(accountProfile(['sub' => '9', 'email' => 'baru@contoh.test', 'role' => 'user']))
            ->push(accountProfile(['sub' => '9', 'email' => 'ganti@contoh.test', 'role' => 'user'])),
    ]);

    centralLogin()->assertRedirect('/');
    $user = User::query()->where('account_id', '9')->sole();
    expect($user->email)->toBe('baru@contoh.test')->and($user->is_admin)->toBeFalse();

    Auth::guard('web')->logout();
    centralLogin()->assertRedirect('/');

    expect(Auth::guard('web')->id())->toBe($user->id)->and(User::count())->toBe(1);
});

test('the callback refuses a wrong state, a refusal from Account, and an unreachable Account', function (Closure $fake, string $reason, bool $tamperState) {
    $fake();
    $location = $this->get('/auth/redirect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $state = $tamperState ? 'wrong' : $query['state'];

    $this->get('/auth/callback?'.http_build_query(['code' => 'the-code', 'state' => $state]))->assertRedirect('/login?account_error='.$reason);

    expect(Auth::guard('web')->check())->toBeFalse();
})->with([
    'wrong state' => [fn () => Http::fake(), 'state', true],
    'no access' => [fn () => Http::fake(['http://account/oauth/token' => Http::response(tokenResponse()), 'http://account/api/me' => Http::response(['error' => 'access_denied'], 403)]), 'denied', false],
    'code refused' => [fn () => Http::fake(['http://account/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]), 'denied', false],
    'token for another app' => [fn () => Http::fake(['http://account/oauth/token' => Http::response(tokenResponse()), 'http://account/api/me' => Http::response(accountProfile(['app' => 'dex-other']))]), 'denied', false],
    'Account down' => [fn () => Http::fake(['http://account/*' => fn () => throw new ConnectionException('timeout')]), 'unreachable', false],
]);

test('an email already linked to another account is not taken over', function () {
    User::factory()->create(['email' => 'pemilik@contoh.test', 'account_id' => '99']);
    Http::fake(['http://account/oauth/token' => Http::response(tokenResponse()), 'http://account/api/me' => Http::response(accountProfile())]);

    centralLogin()->assertRedirect('/login?account_error=conflict');
    expect(Auth::guard('web')->check())->toBeFalse();
});

test('a session from Account is checked again after 10 minutes and ends when access is gone', function () {
    Http::fake([
        'http://account/oauth/token' => Http::response(tokenResponse()),
        'http://account/api/me' => Http::sequence()->push(accountProfile())->push(['error' => 'access_denied'], 403),
    ]);
    User::factory()->create(['email' => 'pemilik@contoh.test']);
    centralLogin();

    fromSpa()->getJson('/api/auth/me')->assertOk();
    Http::assertSentCount(2);

    $this->travel(11)->minutes();
    fromSpa()->getJson('/api/auth/me')->assertUnauthorized()->assertJsonPath('code', 'account_session_ended');

    expect(Auth::guard('web')->check())->toBeFalse();
});

test('an expired access token is refreshed before the check, and the new refresh token is kept', function () {
    Http::fake([
        'http://account/oauth/token' => Http::sequence()->push(tokenResponse())->push(tokenResponse('access-2', 'refresh-2'))->push(tokenResponse('access-3', 'refresh-3')),
        'http://account/api/me' => Http::response(accountProfile()),
    ]);
    User::factory()->create(['email' => 'pemilik@contoh.test']);
    centralLogin();

    $this->travel(20)->minutes();
    fromSpa()->getJson('/api/auth/me')->assertOk();
    $this->travel(20)->minutes();
    fromSpa()->getJson('/api/auth/me')->assertOk();

    $refreshes = Http::recorded(fn (HttpRequest $request): bool => $request->url() === 'http://account/oauth/token' && $request['grant_type'] === 'refresh_token');
    expect($refreshes->map(fn (array $pair): string => $pair[0]['refresh_token'])->values()->all())->toBe(['refresh-1', 'refresh-2']);
});

test('when Account is unreachable the session continues', function () {
    $down = false;
    Http::fake([
        'http://account/oauth/token' => Http::response(tokenResponse()),
        'http://account/api/me' => function () use (&$down) {
            if ($down) {
                throw new ConnectionException('timeout');
            }

            return Http::response(accountProfile());
        },
    ]);
    User::factory()->create(['email' => 'pemilik@contoh.test']);
    centralLogin();

    $down = true;
    $this->travel(11)->minutes();
    fromSpa()->getJson('/api/auth/me')->assertOk();
    expect(Auth::guard('web')->check())->toBeTrue();
});

test('central mode closes the old login and ends sessions from it; both mode keeps them', function () {
    $user = User::factory()->create();

    fromSpa()->actingAs($user, 'web')->getJson('/api/auth/me')->assertOk();

    config(['central_account.mode' => 'central']);
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden()->assertJsonPath('code', 'central_login_only');
    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertForbidden();
    fromSpa()->getJson('/api/auth/me')->assertUnauthorized();
});

test('the auth mode command refuses tune-in.trade without a client, and sets it with one', function () {
    config(['central_account.client_secret' => null, 'central_account.mode' => 'local']);
    $this->artisan('app:auth-mode central')->assertFailed();
    expect(config('central_account.mode'))->toBe('local');

    config(['central_account.client_secret' => 'secret-456']);
    $this->artisan('app:auth-mode central')->assertSuccessful();
    expect(config('central_account.mode'))->toBe('central');

    $this->artisan('app:auth-mode local')->assertSuccessful();
    expect(config('central_account.mode'))->toBe('local');
});

test('DEX: a new user gets the starting virtual balance, and a strategy account is never linked', function () {
    config(['market.simulator.starting_balance' => 10000]);
    Http::fake(['http://account/oauth/token' => Http::response(tokenResponse()), 'http://account/api/me' => Http::sequence()
        ->push(accountProfile(['sub' => '5', 'email' => 'baru@contoh.test', 'role' => 'user']))
        ->push(accountProfile(['sub' => '6', 'email' => 'sistem@contoh.test', 'role' => 'user']))]);

    centralLogin()->assertRedirect('/');
    expect((float) User::query()->where('account_id', '5')->sole()->balance_usd)->toBe(10000.0);

    Auth::guard('web')->logout();
    $system = User::factory()->create(['email' => 'sistem@contoh.test']);
    $system->forceFill(['is_system' => true])->save();

    centralLogin()->assertRedirect('/login?account_error=conflict');
    expect($system->refresh()->account_id)->toBeNull();
});
