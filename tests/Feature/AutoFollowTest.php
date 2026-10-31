<?php

use App\Enums\OrderStatus;
use App\Jobs\FollowSignalAutomatically;
use App\Models\Order;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Admin\SettingsStore;
use App\Services\Trading\AutoFollowRisk;
use App\Services\Trading\TradingService;

beforeEach(function () {
    app(SettingsStore::class)->set(['market.autofollow.enabled' => true, 'market.autofollow.amount_usd' => 100, 'market.autofollow.risk_pct' => 0, 'market.autofollow.max_open_positions' => 0, 'market.simulator.starting_balance' => 10_000]);
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

test('the position limit stops new buys, counting limit buys still waiting', function () {
    app(SettingsStore::class)->set(['market.autofollow.max_open_positions' => 2]);

    // Fills right away.
    Signal::factory()->for($this->token)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);
    // Waits for a lower entry.
    $other = Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now()]);
    Signal::factory()->for($other)->create(['entry_low' => 1.85, 'entry_high' => 1.9, 'stop_loss' => 1.7]);
    // No room left.
    $third = Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now()]);
    $skipped = Signal::factory()->for($third)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);

    $bot = User::autoFollower();

    expect($bot->orders()->where('side', 'buy')->count())->toBe(2)
        ->and($bot->orders()->where('signal_id', $skipped->id)->exists())->toBeFalse();
});

test('the position is sized from the risk, and capped by equity and pool liquidity', function () {
    app(SettingsStore::class)->set(['market.autofollow.risk_pct' => 1, 'market.simulator.starting_balance' => 10_000]);
    $bot = User::autoFollower();
    $risk = app(AutoFollowRisk::class);
    $signal = fn (Token $token, float $stop): Signal => Signal::withoutEvents(fn () => Signal::factory()->for($token)->create(['entry_low' => 1.9, 'entry_high' => 2.0, 'stop_loss' => $stop]));
    $deep = Token::factory()->create(['liquidity_usd' => 50_000_000, 'price_usd' => 2.0]);
    $thin = Token::factory()->create(['liquidity_usd' => 100_000, 'price_usd' => 2.0]);

    // 1% of $10,000 at a 10% stop is $1,000.
    expect($risk->amountFor($bot, $signal($deep, 1.8)))->toEqual(1000.0)
        // A 2% stop would be $5,000: capped at 25% of equity.
        ->and($risk->amountFor($bot, $signal($deep, 1.96)))->toEqual(2500.0)
        // 0.25% of a $100,000 pool.
        ->and($risk->amountFor($bot, $signal($thin, 1.8)))->toEqual(250.0);
});

test('the daily loss limit stops new buys until the next local day, and only realized losses count', function () {
    app(SettingsStore::class)->set(['market.autofollow.max_daily_loss_pct' => 3, 'market.simulator.starting_balance' => 10_000]);
    $bot = User::autoFollower();
    $risk = app(AutoFollowRisk::class);
    $this->travelTo(now('Asia/Jakarta')->setTime(10, 0));
    $loss = fn (float $usd) => Order::factory()->for($bot)->for($this->token)->create(['side' => 'sell', 'status' => OrderStatus::Filled, 'realized_pnl' => $usd, 'filled_at' => now()]);

    $loss(-200);
    expect($risk->dailyLossReached($bot))->toBeFalse();

    $loss(-150);
    expect($risk->realizedToday($bot))->toEqual(-350.0)
        ->and($risk->dailyLossReached($bot))->toBeTrue();

    $signal = Signal::factory()->for($this->token)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);
    expect(Order::where('signal_id', $signal->id)->exists())->toBeFalse();

    $this->travelTo(now('Asia/Jakarta')->addDay()->setTime(0, 5));
    expect($risk->dailyLossReached($bot))->toBeFalse();
});
