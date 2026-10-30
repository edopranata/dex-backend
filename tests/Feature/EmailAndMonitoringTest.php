<?php

use App\Console\Commands\HeartbeatCommand;
use App\Http\Controllers\HealthController;
use App\Models\ServiceNote;
use App\Models\TaskRun;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Notifications\ResetPassword;
use App\Notifications\TemporaryPassword;
use App\Notifications\TestEmail;
use App\Notifications\TwoFactorDisabled;
use App\Notifications\TwoFactorResetLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Admin']);
});

function enableMail(): void
{
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.resend.com', 'mail.from.address' => 'noreply@example.com']);
}

test('forgot password needs email to be set up and never reveals which emails exist', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'budi@example.com']);
    $system = User::autoFollower();

    $this->postJson('/api/auth/forgot-password', ['email' => 'budi@example.com'])->assertStatus(503);
    $this->getJson('/api/auth/options')->assertJsonPath('password_reset_enabled', false);

    enableMail();
    $this->getJson('/api/auth/options')->assertJsonPath('password_reset_enabled', true);

    $known = $this->postJson('/api/auth/forgot-password', ['email' => 'budi@example.com'])->assertOk()->json('message');
    $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');
    $this->postJson('/api/auth/forgot-password', ['email' => $system->email])->assertOk();

    expect($known)->toBe($unknown);
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertNotSentTo($system, ResetPassword::class);
    Notification::assertCount(1);
});

test('a reset link sets a new password once, ends a pending temporary password and sends a notice', function () {
    Notification::fake();
    $user = User::factory()->create(['must_change_password' => true, 'temporary_password_expires_at' => now()->addDay()]);
    $token = Password::broker('users')->createToken($user);

    $this->postJson('/api/auth/reset-password', ['token' => 'wrong', 'email' => $user->email, 'password' => 'Password-baru-123', 'password_confirmation' => 'Password-baru-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('token');

    $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Password-baru-123', 'password_confirmation' => 'Password-baru-123'])
        ->assertOk();
    $user->refresh();
    expect(Hash::check('Password-baru-123', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse();
    Notification::assertSentTo($user, PasswordChanged::class);

    $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Lagi-lagi-1234', 'password_confirmation' => 'Lagi-lagi-1234'])
        ->assertUnprocessable();
});

test('admins create accounts whose temporary password is emailed and must be replaced at the first login', function () {
    Notification::fake();
    enableMail();

    $this->actingAs($this->admin)->postJson('/api/admin/users', ['name' => 'Sari', 'email' => 'sari@example.com'])
        ->assertCreated()
        ->assertJsonPath('delivery.sent', true)
        ->assertJsonPath('delivery.password', null);

    $sari = User::query()->where('email', 'sari@example.com')->firstOrFail();
    $password = null;
    Notification::assertSentTo($sari, TemporaryPassword::class, function (TemporaryPassword $notification) use (&$password): bool {
        $password = $notification->password;

        return $notification->isNew && $notification->admin === 'Admin';
    });

    expect($password)->toMatch('/^[A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}$/')
        ->and($sari->must_change_password)->toBeTrue()
        ->and($sari->temporary_password_expires_at->diffInHours(now(), true))->toBeGreaterThan(71);

    auth()->forgetGuards();
    $spa = fn () => $this->withHeader('Origin', 'http://localhost:5173');
    $spa()->postJson('/api/auth/login', ['email' => 'sari@example.com', 'password' => $password])->assertOk()->assertJsonPath('data.must_change_password', true);
    $spa()->getJson('/api/portfolio')->assertForbidden()->assertJsonPath('code', 'password_change_required');
    $spa()->putJson('/api/profile/password', ['current_password' => $password, 'password' => $password, 'password_confirmation' => $password])
        ->assertJsonValidationErrors('password');
    $spa()->putJson('/api/profile/password', ['current_password' => $password, 'password' => 'Pilihan-sendiri-9', 'password_confirmation' => 'Pilihan-sendiri-9'])
        ->assertOk()->assertJsonPath('data.must_change_password', false);
    $spa()->getJson('/api/portfolio')->assertOk();
    Notification::assertSentTo($sari, PasswordChanged::class);
});

test('an expired temporary password no longer logs in, and admins can email a new one', function () {
    Notification::fake();
    enableMail();
    $user = User::factory()->create(['must_change_password' => true, 'temporary_password_expires_at' => now()->subMinute()]);

    $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertJsonValidationErrors(['email' => 'kedaluwarsa']);

    $this->actingAs($this->admin)->postJson("/api/admin/users/{$user->id}/temporary-password")->assertOk()->assertJsonPath('delivery.sent', true);
    $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->admin->id}/temporary-password")->assertUnprocessable();

    Notification::assertSentTo($user, TemporaryPassword::class, fn (TemporaryPassword $notification): bool => ! $notification->isNew);
    expect($user->refresh()->temporaryPasswordExpired())->toBeFalse();
});

test('without email the admin types the temporary password, which still has to be replaced', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/users', ['name' => 'Budi', 'email' => 'budi@example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->actingAs($this->admin)->postJson('/api/admin/users', ['name' => 'Budi', 'email' => 'budi@example.com', 'password' => 'Sementara-123'])
        ->assertCreated()->assertJsonPath('delivery', null);

    expect(User::query()->where('email', 'budi@example.com')->first()->must_change_password)->toBeTrue();
});

test('admins can send a test email with the saved smtp settings', function () {
    Notification::fake();

    $this->actingAs($this->admin)->postJson('/api/admin/settings/test-mail')->assertOk()->assertJsonPath('ok', false);

    enableMail();
    $this->actingAs($this->admin)->postJson('/api/admin/settings/test-mail')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('to', $this->admin->email)
        ->assertJsonPath('provider', 'Resend');

    Notification::assertSentOnDemand(TestEmail::class);
});

test('every email renders on the app theme', function () {
    $user = new User(['name' => 'Sari', 'email' => 'sari@example.com']);
    $emails = [
        new TemporaryPassword('Hq7k-Rm2x-Tb9w', now()->addDays(3), 'Admin', true),
        new TemporaryPassword('Hq7k-Rm2x-Tb9w', now()->addDays(3), 'Admin', false),
        new ResetPassword('token123'),
        new PasswordChanged(now()),
        new TwoFactorResetLink('token456', 30),
        new TwoFactorDisabled(now(), true),
        new TestEmail('Resend'),
    ];

    foreach ($emails as $email) {
        $html = (string) $email->toMail($user)->render();

        expect($html)->toContain('#121925', '/mail/logo.png', 'jangan dibalas')->not->toContain('Laravel');
    }

    expect((string) $emails[0]->toMail($user)->render())->toContain('Hq7k-Rm2x-Tb9w', 'Akun Anda sudah dibuat');
});

test('the health endpoint fails when the scheduler stops and remembers uptime robot checks', function () {
    Redis::shouldReceive('connection->ping')->andReturn(true);

    $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.scheduler', false)->assertJsonPath('checks.database', true);

    TaskRun::factory()->create();
    $this->getJson('/health', ['User-Agent' => 'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'])
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    expect(Cache::get(HealthController::LAST_MONITOR_CHECK))->toMatchArray(['ok' => true]);
});

test('the heartbeat pings healthchecks and reports unhealthy services on the fail endpoint', function () {
    Http::fake();
    Redis::shouldReceive('connection->ping')->andReturn(true);

    $this->artisan('app:heartbeat')->assertSuccessful();
    Http::assertNothingSent();

    config(['market.monitoring.heartbeat_url' => 'https://hc-ping.com/abc']);
    $this->artisan('app:heartbeat')->assertSuccessful();
    Http::assertSent(fn ($request): bool => $request->url() === 'https://hc-ping.com/abc/fail' && str_contains($request->body(), 'scheduler'));

    expect(Cache::get(HeartbeatCommand::LAST_PING))->toMatchArray(['sent' => true, 'healthy' => false]);
});

test('general information lists third-party services with their status and admin notes', function () {
    enableMail();

    $this->actingAs(User::factory()->create())->getJson('/api/admin/info')->assertForbidden();

    $this->actingAs($this->admin)->putJson('/api/admin/info/r2/note', ['note' => 'Akun Cloudflare: owner@example.com'])->assertOk();
    $this->actingAs($this->admin)->putJson('/api/admin/info/unknown/note', ['note' => 'x'])->assertNotFound();

    $services = collect($this->actingAs($this->admin)->getJson('/api/admin/info')->assertOk()->json('groups'))
        ->flatMap(fn (array $group): array => $group['services'])
        ->keyBy('key');

    expect($services->keys()->all())->toContain('r2', 'uptimerobot', 'healthchecks', 'email', 'gemini', 'dexscreener')
        ->and($services['r2']['note'])->toBe('Akun Cloudflare: owner@example.com')
        ->and($services['email']['status'])->toMatchArray(['state' => 'ok', 'label' => 'Aktif via Resend'])
        ->and($services['healthchecks']['status']['state'])->toBe('off');

    $this->actingAs($this->admin)->putJson('/api/admin/info/r2/note', ['note' => ''])->assertOk();
    expect(ServiceNote::query()->count())->toBe(0);
});

test('the heartbeat url must be an https url', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/admin/settings', ['values' => ['market.monitoring.heartbeat_url' => 'not-a-url']])
        ->assertUnprocessable();
});

test('general information shows uptime robot status from its read-only api', function () {
    Http::fake(['api.uptimerobot.com/*' => Http::response([
        'stat' => 'ok',
        'monitors' => [[
            'id' => 801234567,
            'friendly_name' => 'DEX health',
            'url' => 'https://dexsim.tune-in.trade/health',
            'status' => 2,
            'custom_uptime_ratio' => '100.000-99.950-99.800',
            'average_response_time' => '231.4',
            'logs' => [
                ['type' => 2, 'datetime' => now()->subDays(2)->timestamp, 'duration' => 172800],
                ['type' => 1, 'datetime' => now()->subDays(2)->subMinutes(6)->timestamp, 'duration' => 360, 'reason' => ['code' => '503', 'detail' => 'Service Unavailable']],
            ],
        ]],
    ])]);
    config(['market.monitoring.uptimerobot_key' => 'ur123-readonly']);

    $uptime = collect($this->actingAs($this->admin)->getJson('/api/admin/info')->assertOk()->json('groups'))
        ->flatMap(fn (array $group): array => $group['services'])
        ->firstWhere('key', 'uptimerobot');

    expect($uptime['status']['state'])->toBe('ok')
        ->and($uptime['status']['label'])->toBe('Up')
        ->and($uptime['status']['detail'])->toContain('24 jam 100%', '7 hari 99,95%', '30 hari 99,8%', 'respons 231 ms', 'down terakhir 2 hari yang lalu (6 menit)')
        ->and(collect($uptime['links'])->pluck('url'))->toContain('https://dashboard.uptimerobot.com/monitors/801234567');

    Http::assertSent(fn ($request): bool => $request['api_key'] === 'ur123-readonly' && $request['custom_uptime_ratios'] === '1-7-30');
});

test('a rejected uptime robot key is shown instead of failing the page', function () {
    Http::fake(['api.uptimerobot.com/*' => Http::response(['stat' => 'fail', 'error' => ['message' => 'api_key not found.']])]);
    config(['market.monitoring.uptimerobot_key' => 'wrong']);

    $uptime = collect($this->actingAs($this->admin)->getJson('/api/admin/info')->assertOk()->json('groups'))
        ->flatMap(fn (array $group): array => $group['services'])
        ->firstWhere('key', 'uptimerobot');

    expect($uptime['status'])->toMatchArray(['state' => 'warn', 'label' => 'API gagal', 'detail' => 'api_key not found.']);
});
