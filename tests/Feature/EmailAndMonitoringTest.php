<?php

use App\Console\Commands\HeartbeatCommand;
use App\Http\Controllers\HealthController;
use App\Models\ServiceNote;
use App\Models\TaskRun;
use App\Models\User;
use App\Notifications\InviteUser;
use App\Notifications\ResetPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

test('a reset link sets a new password once; invitation links use their own longer-lived broker', function () {
    $user = User::factory()->create();
    $token = Password::broker('users')->createToken($user);

    $this->postJson('/api/auth/reset-password', ['token' => 'wrong', 'email' => $user->email, 'password' => 'Password-baru-123', 'password_confirmation' => 'Password-baru-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('token');

    $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Password-baru-123', 'password_confirmation' => 'Password-baru-123'])
        ->assertOk();
    expect(Hash::check('Password-baru-123', $user->fresh()->password))->toBeTrue();

    $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Lagi-lagi-1234', 'password_confirmation' => 'Lagi-lagi-1234'])
        ->assertUnprocessable();

    $invite = Password::broker('invites')->createToken($user);
    $this->travel(2)->days();
    $this->postJson('/api/auth/reset-password', ['token' => $invite, 'email' => $user->email, 'invite' => true, 'password' => 'Dari-undangan-123', 'password_confirmation' => 'Dari-undangan-123'])
        ->assertOk();
});

test('admins invite new users by email instead of choosing their password', function () {
    Notification::fake();

    $this->actingAs($this->admin)->postJson('/api/admin/users', ['name' => 'Sari', 'email' => 'sari@example.com', 'invite' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('invite');

    enableMail();
    $this->actingAs($this->admin)->postJson('/api/admin/users', ['name' => 'Sari', 'email' => 'sari@example.com', 'invite' => true])
        ->assertCreated()
        ->assertJsonPath('invite.sent', true);

    $sari = User::query()->where('email', 'sari@example.com')->firstOrFail();
    Notification::assertSentTo($sari, InviteUser::class, function (InviteUser $notification) use ($sari): bool {
        $mail = $notification->toMail($sari);

        return $notification->invitedBy === 'Admin' && str_contains($mail->actionUrl, 'invite=1') && str_contains($mail->actionUrl, urlencode('sari@example.com'));
    });

    $this->actingAs($this->admin)->postJson("/api/admin/users/{$sari->id}/invite")->assertOk()->assertJsonPath('invite.sent', true);
    $this->actingAs($this->admin)->postJson('/api/admin/users', ['name' => 'Tanpa', 'email' => 'tanpa@example.com'])->assertUnprocessable()->assertJsonValidationErrors('password');
    Notification::assertSentToTimes($sari, InviteUser::class, 2);
});

test('admins can send a test email with the saved smtp settings', function () {
    Mail::fake();

    $this->actingAs($this->admin)->postJson('/api/admin/settings/test-mail')->assertOk()->assertJsonPath('ok', false);

    enableMail();
    $this->actingAs($this->admin)->postJson('/api/admin/settings/test-mail')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('to', $this->admin->email)
        ->assertJsonPath('provider', 'Resend');
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
