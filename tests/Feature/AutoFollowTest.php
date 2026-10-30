<?php

use App\Enums\OrderStatus;
use App\Jobs\FollowSignalAutomatically;
use App\Models\Order;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Admin\SettingsStore;
use App\Services\Trading\TradingService;

beforeEach(function () {
    app(SettingsStore::class)->set(['market.autofollow.enabled' => true, 'market.autofollow.amount_usd' => 100, 'market.simulator.starting_balance' => 10_000]);
    $this->token = Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now()]);
});

test('every new signal is bought by the auto-follow account with the signal exits', function () {
    $signal = Signal::factory()->for($this->token)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);

    $bot = User::firstWhere('email', 'auto-follow@system.invalid');
    $buy = Order::where('user_id', $bot->id)->where('side', 'buy')->sole();

    expect($bot->is_system)->toBeTrue()
        ->and($buy->signal_id)->toBe($signal->id)
        ->and($buy->amount_usd)->toEqual(100.0)
        ->and($buy->status)->toBe(OrderStatus::Filled)
        ->and(Order::where('parent_id', $buy->id)->count())->toBe(4);
});

test('nothing is bought when auto-follow is switched off', function () {
    app(SettingsStore::class)->set(['market.autofollow.enabled' => false]);

    Signal::factory()->for($this->token)->create();

    expect(Order::count())->toBe(0);
});

test('the auto-follow account cannot log in', function () {
    User::autoFollower()->forceFill(['password' => 'password'])->save();

    $this->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/auth/login', ['email' => 'auto-follow@system.invalid', 'password' => 'password'])
        ->assertJsonValidationErrors('email');
});

test('waiting signal buys are cancelled when the signal closes before entry', function () {
    $signal = Signal::factory()->for($this->token)->create(['entry_low' => 1.85, 'entry_high' => 1.9, 'stop_loss' => 1.7, 'take_profit_1' => 2.1, 'take_profit_2' => 2.3, 'take_profit_3' => 2.6]);
    $buy = Order::where('signal_id', $signal->id)->where('side', 'buy')->sole();

    expect($buy->status)->toBe(OrderStatus::Pending);

    $signal->update(['status' => 'expired', 'closed_at' => now()]);

    expect($buy->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($buy->note)->toContain('sudah ditutup');
});

test('a retried auto-follow job does not buy the same signal twice', function () {
    $signal = Signal::factory()->for($this->token)->create();

    (new FollowSignalAutomatically($signal))->handle(app(TradingService::class));

    expect(Order::where('signal_id', $signal->id)->where('side', 'buy')->count())->toBe(1);
});
