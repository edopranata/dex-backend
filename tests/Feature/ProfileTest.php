<?php

use App\Models\Position;
use App\Models\Token;
use App\Models\User;

test('profile shows security, telegram and notification settings with defaults', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('two_factor.enabled', false)
        ->assertJsonPath('telegram.linked', false)
        ->assertJsonPath('notifications.signals', true)
        ->assertJsonPath('notifications.daily_summary_hour', 21);
});

test('users update their name and email but not to an email that is taken', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->putJson('/api/profile', ['name' => 'Edo P', 'email' => $other->email])->assertJsonValidationErrors('email');
    $this->actingAs($user)->putJson('/api/profile', ['name' => 'Edo P', 'email' => 'edo@example.com'])->assertOk()->assertJsonPath('data.name', 'Edo P');
});

test('changing the password needs the current one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/profile/password', ['current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
        ->assertJsonValidationErrors('current_password');
    $this->actingAs($user)->putJson('/api/profile/password', ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
        ->assertOk();

    expect(Hash::check('new-password-1', $user->refresh()->password))->toBeTrue();
});

test('notification preferences are saved per user', function () {
    $user = User::factory()->create();
    $preferences = [
        'signals' => true, 'signal_updates' => false, 'risk' => true, 'watchlist' => true, 'system' => false,
        'daily_summary' => true, 'signal_min' => 'STRONG_BUY', 'daily_summary_hour' => 7, 'quiet_start_hour' => 22, 'quiet_end_hour' => 5,
    ];

    $this->actingAs($user)->putJson('/api/profile/notifications', [...$preferences, 'signal_min' => 'ANY'])->assertJsonValidationErrors('signal_min');
    $this->actingAs($user)->putJson('/api/profile/notifications', $preferences)->assertOk()->assertJsonPath('notifications', $preferences);

    expect($user->refresh()->wantsTelegram('watchlist'))->toBeFalse()
        ->and($user->notificationSettings()['daily_summary_hour'])->toBe(7);
});

test('the portfolio summary feeds the sidebar', function () {
    config(['market.simulator.starting_balance' => 10_000]);
    $user = User::factory()->create(['balance_usd' => 9_000]);
    $token = Token::factory()->create(['price_usd' => 12]);
    Position::factory()->for($user)->for($token)->create(['quantity' => 100, 'avg_price' => 10, 'realized_pnl' => 50]);

    $this->actingAs($user)->getJson('/api/portfolio/summary')
        ->assertOk()
        ->assertJsonPath('equity_usd', 10200)
        ->assertJsonPath('total_pnl', 200)
        ->assertJsonPath('unrealized_pnl', 200)
        ->assertJsonPath('realized_pnl', 50)
        ->assertJsonPath('open_positions', 1);
});
