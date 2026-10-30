<?php

use App\Models\User;
use App\Notifications\TwoFactorDisabled;
use App\Notifications\TwoFactorResetLink;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

function enableTwoFactor(User $user): array
{
    $secret = test()->actingAs($user)->postJson('/api/profile/two-factor', ['password' => 'password'])->assertOk()->json('secret');
    $codes = test()->actingAs($user)->postJson('/api/profile/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk()->json('recovery_codes');

    return [$secret, $codes];
}

function spa()
{
    return test()->withHeader('Origin', 'http://localhost:5173');
}

test('users enable 2fa with a qr code and a confirming code, and get recovery codes', function () {
    $user = User::factory()->create();

    $setup = $this->actingAs($user)->postJson('/api/profile/two-factor', ['password' => 'password'])->assertOk();
    expect($setup->json('qr_svg'))->toContain('<svg')
        ->and($setup->json('otpauth_url'))->toStartWith('otpauth://totp/');

    $this->actingAs($user)->postJson('/api/profile/two-factor/confirm', ['code' => '000000'])->assertJsonValidationErrors('code');
    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();

    $codes = $this->actingAs($user)->postJson('/api/profile/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($setup->json('secret'))])
        ->assertOk()->json('recovery_codes');

    expect($codes)->toHaveCount(8)
        ->and($user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('enabling 2fa needs the current password', function () {
    $this->actingAs(User::factory()->create())->postJson('/api/profile/two-factor', ['password' => 'wrong'])->assertJsonValidationErrors('password');
});

test('login asks for a code after the password and each code works only once', function () {
    $user = User::factory()->create();
    [$secret] = enableTwoFactor($user);
    auth()->forgetGuards();

    spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertExactJson(['two_factor' => true]);
    spa()->getJson('/api/auth/me')->assertUnauthorized();

    $google2fa = app(Google2FA::class);
    spa()->postJson('/api/auth/two-factor-challenge', ['code' => $google2fa->getCurrentOtp($secret)])->assertJsonValidationErrors('code');

    // The current code was spent confirming the setup; the next 30-second window is still accepted.
    $code = $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1);
    spa()->postJson('/api/auth/two-factor-challenge', ['code' => $code])->assertOk()->assertJsonPath('data.email', $user->email);
    spa()->getJson('/api/auth/me')->assertOk();

    spa()->postJson('/api/auth/logout')->assertNoContent();
    spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    spa()->postJson('/api/auth/two-factor-challenge', ['code' => $code])->assertJsonValidationErrors('code');
});

test('a recovery code logs in once', function () {
    $user = User::factory()->create();
    [, $codes] = enableTwoFactor($user);
    auth()->forgetGuards();

    spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
    spa()->postJson('/api/auth/two-factor-challenge', ['recovery_code' => strtolower($codes[0])])->assertOk();

    expect($user->refresh()->two_factor_recovery_codes)->toHaveCount(7)->not->toContain($codes[0]);
});

test('the challenge expires after five minutes', function () {
    $user = User::factory()->create();
    [$secret] = enableTwoFactor($user);
    auth()->forgetGuards();

    spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
    $this->travel(6)->minutes();

    spa()->postJson('/api/auth/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertJsonValidationErrors(['code' => 'kedaluwarsa']);
});

test('users disable 2fa with their password and admins can reset it for a locked-out user', function () {
    $user = User::factory()->create();
    enableTwoFactor($user);

    $this->actingAs($user)->deleteJson('/api/profile/two-factor', ['password' => 'wrong'])->assertJsonValidationErrors('password');
    $this->actingAs($user)->deleteJson('/api/profile/two-factor', ['password' => 'password'])->assertOk();
    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();

    enableTwoFactor($user);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->patchJson("/api/admin/users/{$user->id}", ['disable_two_factor' => true])->assertOk();
    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('users without 2fa log in directly', function () {
    $user = User::factory()->create();

    spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.two_factor_enabled', false);
});

test('a user who lost the authenticator resets 2fa with an emailed link, only after the password', function () {
    Notification::fake();
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.resend.com', 'mail.from.address' => 'noreply@example.com']);
    $user = User::factory()->create();
    enableTwoFactor($user);
    auth()->forgetGuards();

    spa()->postJson('/api/auth/two-factor/reset-request')->assertJsonValidationErrors(['code' => 'kedaluwarsa']);

    spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertExactJson(['two_factor' => true]);
    spa()->postJson('/api/auth/two-factor/reset-request')->assertOk()->assertJsonPath('message', fn (string $message): bool => str_contains($message, '***@'));

    $token = null;
    Notification::assertSentTo($user, TwoFactorResetLink::class, function (TwoFactorResetLink $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    spa()->postJson('/api/auth/two-factor/reset', ['email' => $user->email, 'token' => 'wrong'])->assertJsonValidationErrors('token');
    spa()->postJson('/api/auth/two-factor/reset', ['email' => $user->email, 'token' => $token])->assertOk();
    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
    Notification::assertSentTo($user, TwoFactorDisabled::class, fn (TwoFactorDisabled $notification): bool => ! $notification->byAdmin);

    spa()->postJson('/api/auth/two-factor/reset', ['email' => $user->email, 'token' => $token])->assertJsonValidationErrors('token');
    spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJsonPath('data.email', $user->email);
});

test('an admin resetting 2fa emails the user a notice', function () {
    Notification::fake();
    $user = User::factory()->create();
    enableTwoFactor($user);

    $this->actingAs(User::factory()->create(['is_admin' => true]))->patchJson("/api/admin/users/{$user->id}", ['disable_two_factor' => true])->assertOk();

    Notification::assertSentTo($user, TwoFactorDisabled::class, fn (TwoFactorDisabled $notification): bool => $notification->byAdmin);
});
