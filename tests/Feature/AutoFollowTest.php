<?php

use App\Enums\OrderStatus;
use App\Enums\SignalStatus;
use App\Jobs\FollowSignalAutomatically;
use App\Models\AutoFollowDecision;
use App\Models\Order;
use App\Models\Position;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Admin\SettingsStore;
use App\Services\Analysis\SignalTracker;
use App\Services\Trading\AutoFollowRisk;
use App\Services\Trading\TradingService;

beforeEach(function () {
    app(SettingsStore::class)->set(['market.autofollow.enabled' => true, 'market.autofollow.amount_usd' => 100, 'market.autofollow.risk_pct' => 0, 'market.autofollow.max_open_positions' => 0, 'market.simulator.starting_balance' => 10_000]);
    $this->token = Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now()]);
});

test('every signal is bought by the auto-follow account with the signal exits once it enters', function () {
    $signal = Signal::factory()->for($this->token)->active(2.0)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);

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

    Signal::factory()->for($this->token)->active(2.0)->create(['entry_high' => 2.05, 'stop_loss' => 1.8]);

    expect(Order::count())->toBe(0);
});

test('the auto-follow account cannot log in', function () {
    User::autoFollower()->forceFill(['password' => 'password'])->save();

    $this->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/auth/login', ['email' => 'auto-follow@system.invalid', 'password' => 'password'])
        ->assertJsonValidationErrors('email');
});

test('a limit buy still waiting is cancelled when its signal closes', function () {
    // The signal entered at 1.9, but the price was back above the zone when the account bought.
    $signal = Signal::factory()->for($this->token)->active(1.9)->create(['entry_low' => 1.85, 'entry_high' => 1.9, 'stop_loss' => 1.7, 'take_profit_1' => 2.1, 'take_profit_2' => 2.3, 'take_profit_3' => 2.6]);
    $buy = User::autoFollower()->orders()->where('signal_id', $signal->id)->where('side', 'buy')->sole();

    expect($buy->status)->toBe(OrderStatus::Pending);

    $signal->update(['status' => 'expired', 'closed_at' => now()]);

    expect($buy->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($buy->note)->toContain('sudah ditutup');
});

test('a retried auto-follow job does not buy the same signal twice', function () {
    $signal = Signal::factory()->for($this->token)->active(2.0)->create(['entry_high' => 2.05, 'stop_loss' => 1.8]);

    (new FollowSignalAutomatically($signal))->handle(app(TradingService::class));

    expect(User::autoFollower()->orders()->where('signal_id', $signal->id)->where('side', 'buy')->count())->toBe(1);
});

test('the position limit stops new buys, counting limit buys still waiting', function () {
    app(SettingsStore::class)->set(['market.autofollow.max_open_positions' => 2]);

    // Fills right away.
    Signal::factory()->for($this->token)->active(2.0)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);
    // Waits for a lower entry.
    $other = Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now()]);
    Signal::factory()->for($other)->active(1.9)->create(['entry_low' => 1.85, 'entry_high' => 1.9, 'stop_loss' => 1.7]);
    // No room left.
    $third = Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now()]);
    $skipped = Signal::factory()->for($third)->active(2.0)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);

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

    $signal = Signal::factory()->for($this->token)->active(2.0)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);
    expect($bot->orders()->where('signal_id', $signal->id)->exists())->toBeFalse();

    $this->travelTo(now('Asia/Jakarta')->addDay()->setTime(0, 5));
    expect($risk->dailyLossReached($bot))->toBeFalse();
});

test('each account only buys the recommendations it follows, within its own limits', function () {
    app(SettingsStore::class)->set(['market.autofollow.max_open_positions' => 1]);
    $make = fn (string $recommendation) => Signal::factory()->for(Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now()]))
        ->active(2.0)->create(['recommendation' => $recommendation, 'entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);
    $bought = fn (string $key) => User::autoFollower($key)->orders()->where('side', 'buy')->pluck('signal_id')->all();

    $buy = $make('BUY');

    expect($bought('all'))->toBe([$buy->id])
        ->and($bought('buy'))->toBe([$buy->id])
        ->and($bought('strong_buy'))->toBe([]);

    // The combined account is full now, but the Strong Buy account still has room.
    $strong = $make('STRONG_BUY');

    expect($bought('all'))->toBe([$buy->id])
        ->and($bought('strong_buy'))->toBe([$strong->id])
        ->and($bought('buy'))->toBe([$buy->id]);
});

test('every auto-follow account is a system account that cannot log in', function () {
    foreach (array_keys(User::AUTO_FOLLOW_ACCOUNTS) as $key) {
        $account = User::autoFollower($key);
        $account->forceFill(['password' => 'password'])->save();

        expect($account->is_system)->toBeTrue();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/auth/login', ['email' => $account->email, 'password' => 'password'])
            ->assertJsonValidationErrors('email');
    }

    expect(User::autoFollower('all')->email)->toBe('auto-follow@system.invalid');
});

test('a retried job does not buy twice in any account', function () {
    $signal = Signal::factory()->for($this->token)->active(2.0)->create(['recommendation' => 'STRONG_BUY', 'entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);

    (new FollowSignalAutomatically($signal))->handle(app(TradingService::class));

    // Gabungan, Strong Buy, and Solana (the token's chain).
    expect(Order::where('signal_id', $signal->id)->where('side', 'buy')->count())->toBe(3);
});

test('the accounts wait for the signal to enter its zone, and buy at the moment it does', function () {
    $signal = Signal::factory()->for($this->token)->create(['recommendation' => 'STRONG_BUY', 'entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);
    $tracker = app(SignalTracker::class);

    // In the zone, but a single price does not enter the signal yet: nothing is bought.
    $tracker->track($signal, 2.0, 2.2);

    expect($signal->refresh()->status)->toBe(SignalStatus::Pending)
        ->and(Order::count())->toBe(0)
        ->and(AutoFollowDecision::count())->toBe(0);

    $tracker->track($signal, 2.0, 2.0);
    $buys = Order::where('signal_id', $signal->id)->where('side', 'buy')->get();

    expect($signal->refresh()->status)->toBe(SignalStatus::Active)
        ->and($buys)->toHaveCount(3)
        ->and($buys->every(fn (Order $buy): bool => $buy->status === OrderStatus::Filled && $buy->filled_at->equalTo($signal->entered_at)))->toBeTrue()
        ->and(AutoFollowDecision::where('decision', AutoFollowDecision::BOUGHT)->count())->toBe(3);
});

test('a signal that never enters its zone is never bought', function () {
    $signal = Signal::factory()->for($this->token)->create(['entry_low' => 1.85, 'entry_high' => 1.9, 'stop_loss' => 1.7]);

    $signal->update(['status' => 'expired', 'closed_at' => now()]);

    expect(Order::count())->toBe(0)
        ->and(AutoFollowDecision::count())->toBe(0);
});

test('a job queued for a waiting signal before an update buys nothing until the signal enters', function () {
    $signal = Signal::withoutEvents(fn () => Signal::factory()->for($this->token)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]));

    (new FollowSignalAutomatically($signal))->handle(app(TradingService::class));

    expect(Order::count())->toBe(0);

    $signal->update(['status' => 'active', 'entry_price' => 2.0, 'entered_at' => now()]);

    expect(User::autoFollower()->orders()->where('signal_id', $signal->id)->where('side', 'buy')->count())->toBe(1);
});

test('rounding left over from selling in thirds closes the position and frees its slot', function () {
    $user = User::factory()->create(['balance_usd' => 10000]);
    $token = Token::factory()->create(['price_usd' => 0.008, 'liquidity_usd' => 10_000_000]);
    $position = Position::factory()->for($user)->for($token)->create(['quantity' => 115923.44243394, 'avg_price' => 0.008]);
    $sell = fn (float $quantity) => Order::factory()->for($user, 'user')->for($token)->create(['side' => 'sell', 'type' => 'market', 'status' => 'pending', 'quantity' => $quantity]);

    $trading = app(TradingService::class);
    $trading->execute($sell(38641.147477979), 0.008);
    $trading->execute($sell(38641.147477979), 0.008);

    // A real remainder stays open.
    expect((float) $position->refresh()->quantity)->toBeGreaterThan(38000.0);

    // What the last third leaves is the 0.000000003 that blocked a slot on production.
    $trading->execute($sell(38641.147477979), 0.008);

    expect((float) $position->refresh()->quantity)->toEqual(0.0)
        ->and((float) $position->avg_price)->toEqual(0.0)
        ->and($user->positions()->where('quantity', '>', 0)->count())->toBe(0);
});

test('a small but real sale does not close what is left of a big position', function () {
    $user = User::factory()->create(['balance_usd' => 10000]);
    $token = Token::factory()->create(['price_usd' => 1, 'liquidity_usd' => 10_000_000]);
    $position = Position::factory()->for($user)->for($token)->create(['quantity' => 1000, 'avg_price' => 1]);

    app(TradingService::class)->execute(Order::factory()->for($user, 'user')->for($token)->create(['side' => 'sell', 'type' => 'market', 'status' => 'pending', 'quantity' => 1]), 1);

    expect((float) $position->refresh()->quantity)->toEqual(999.0);
});

/**
 * A signal the auto-follow account bought straight away (it entered at 2.0, inside its zone).
 */
function boughtSignal(Token $token): array
{
    $signal = Signal::factory()->for($token)->active(2.0)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);
    $buy = User::autoFollower()->orders()->where('signal_id', $signal->id)->where('side', 'buy')->sole();

    return [$signal, $buy];
}

test('an auto-follow lot is sold at market once its signal closes, and its waiting exits are cancelled', function () {
    [$signal, $buy] = boughtSignal($this->token);

    expect($buy->status)->toBe(OrderStatus::Filled);

    $signal->update(['status' => 'expired', 'closed_at' => now()]);
    app(TradingService::class)->closeFinishedSignalLots();

    $sell = Order::where('parent_id', $buy->id)->where('type', 'market')->sole();

    expect($sell->status)->toBe(OrderStatus::Filled)
        ->and((float) $sell->filled_quantity)->toEqualWithDelta((float) $buy->filled_quantity, 1e-9)
        ->and($sell->signal_id)->toBe($signal->id)
        ->and($sell->note)->toContain('kedaluwarsa')
        ->and(Order::where('parent_id', $buy->id)->where('status', 'pending')->count())->toBe(0)
        ->and((float) Position::where('user_id', $buy->user_id)->where('token_id', $this->token->id)->value('quantity'))->toEqual(0.0);
});

test('after the first target only what the lot still holds is sold', function () {
    [$signal, $buy] = boughtSignal($this->token);
    $trading = app(TradingService::class);

    $trading->execute(Order::where('parent_id', $buy->id)->where('type', 'take_profit')->orderBy('id')->first(), 2.3);
    $signal->update(['status' => 'expired', 'closed_at' => now()]);
    $trading->closeFinishedSignalLots();

    $sell = Order::where('parent_id', $buy->id)->where('type', 'market')->sole();

    expect((float) $sell->filled_quantity)->toEqualWithDelta((float) $buy->filled_quantity * 2 / 3, 1e-6)
        ->and(Order::where('parent_id', $buy->id)->where('status', 'pending')->count())->toBe(0);
});

test('a person following a signal keeps the position when the signal closes', function () {
    $signal = Signal::factory()->for($this->token)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);
    $person = User::factory()->create(['balance_usd' => 10_000]);
    [$buy] = app(TradingService::class)->followSignal($person, $signal, 100);

    $signal->update(['status' => 'expired', 'closed_at' => now()]);
    app(TradingService::class)->closeFinishedSignalLots();

    expect(Order::where('parent_id', $buy->id)->where('type', 'market')->exists())->toBeFalse()
        ->and(Order::where('parent_id', $buy->id)->where('status', 'pending')->count())->toBe(4);
});

test('only the closed signal lot is sold when the account holds the token from two signals', function () {
    [$first, $firstBuy] = boughtSignal($this->token);
    [, $secondBuy] = boughtSignal($this->token);

    $first->update(['status' => 'expired', 'closed_at' => now()]);
    app(TradingService::class)->closeFinishedSignalLots();

    expect(Order::where('parent_id', $firstBuy->id)->where('type', 'market')->sole()->status)->toBe(OrderStatus::Filled)
        ->and(Order::where('parent_id', $secondBuy->id)->where('status', 'pending')->count())->toBe(4)
        ->and((float) Position::where('user_id', $secondBuy->user_id)->where('token_id', $this->token->id)->value('quantity'))
        ->toEqualWithDelta((float) $secondBuy->filled_quantity, 1e-6);
});

test('a lot waits for a fresh price before it is sold', function () {
    [$signal, $buy] = boughtSignal($this->token);
    $this->token->update(['price_updated_at' => now()->subMinutes(5)]);

    $signal->update(['status' => 'expired', 'closed_at' => now()]);

    expect(app(TradingService::class)->closeFinishedSignalLots())->toBe(0)
        ->and(Order::where('parent_id', $buy->id)->where('status', 'pending')->count())->toBe(4);

    $this->token->update(['price_updated_at' => now()]);

    // Every auto-follow account that bought the signal gets its lot closed.
    expect(app(TradingService::class)->closeFinishedSignalLots())->toBe(Order::where('signal_id', $signal->id)->where('side', 'buy')->count())
        ->and(Order::where('parent_id', $buy->id)->where('status', 'pending')->count())->toBe(0);
});

test('a chain account only buys signals on its own chain', function () {
    $bsc = Token::factory()->create(['chain_id' => 'bsc', 'price_usd' => 2.0, 'price_updated_at' => now()]);
    $signal = Signal::factory()->for($bsc)->active(2.0)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);
    $bought = fn (string $key) => User::autoFollower($key)->orders()->where('signal_id', $signal->id)->where('side', 'buy')->exists();

    expect($bought('bsc'))->toBeTrue()
        ->and($bought('all'))->toBeTrue()
        ->and($bought('solana'))->toBeFalse()
        ->and($bought('ethereum'))->toBeFalse()
        ->and(AutoFollowDecision::where('user_id', User::autoFollower('solana')->id)->exists())->toBeFalse();
});

test('a chain account pays its network gas on every swap, the other accounts do not', function () {
    app(SettingsStore::class)->set(['market.autofollow.gas_usd.ethereum' => 0.5]);
    $eth = Token::factory()->create(['chain_id' => 'ethereum', 'price_usd' => 2.0, 'price_updated_at' => now(), 'liquidity_usd' => 0]);
    $signal = Signal::factory()->for($eth)->active(2.0)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);
    $buyOf = fn (string $key) => User::autoFollower($key)->orders()->where('signal_id', $signal->id)->where('side', 'buy')->sole();
    $chainBuy = $buyOf('ethereum');

    // $100 at 0.3% is $0.30, plus $0.50 gas.
    expect((float) $chainBuy->fee_usd)->toEqualWithDelta(0.8, 1e-9)
        ->and((float) $buyOf('all')->fee_usd)->toEqualWithDelta(0.3, 1e-9);

    $sell = Order::where('parent_id', $chainBuy->id)->where('type', 'take_profit')->orderBy('id')->first();
    app(TradingService::class)->execute($sell, 2.3);

    expect((float) $sell->refresh()->fee_usd)->toEqualWithDelta((float) $sell->filled_quantity * 2.3 * 0.003 + 0.5, 1e-6);
});
