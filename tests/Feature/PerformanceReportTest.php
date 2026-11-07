<?php

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Analysis;
use App\Models\EquitySnapshot;
use App\Models\Order;
use App\Models\Position;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Analysis\StrategyVersion;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = Token::factory()->create(['symbol' => 'PEPE']);
});

function reportSignal(Token $token, float $netR, string $closedAt, array $attributes = []): Signal
{
    return Signal::withoutEvents(fn () => Signal::factory()->for($token)->create([
        'status' => $netR > 0 ? 'take_profit' : 'stop_loss',
        'recommendation' => 'BUY',
        'entry_price' => 1,
        'exit_price' => 1,
        'net_r' => $netR,
        'result_r' => $netR + 0.1,
        'cost_r' => 0.1,
        'strategy_version' => 'v2-abc',
        'closed_at' => $closedAt,
        ...$attributes,
    ]));
}

test('the report needs a login', function () {
    $this->getJson('/api/reports/performance')->assertUnauthorized();
});

test('results are grouped per local week, recommendation and token, for one strategy version', function () {
    reportSignal($this->token, 2.0, '2026-10-26 02:00:00');
    reportSignal($this->token, -1.0, '2026-10-27 02:00:00', ['recommendation' => 'STRONG_BUY']);
    // Sunday 18:00 UTC is already Monday in Jakarta, so it belongs to the next week.
    reportSignal(Token::factory()->create(['symbol' => 'BONK']), 1.0, '2026-11-01 18:00:00');
    reportSignal($this->token, 5.0, '2026-10-26 02:00:00', ['strategy_version' => 'v0-old']);
    Signal::withoutEvents(fn () => Signal::factory()->for($this->token)->create(['status' => 'expired', 'strategy_version' => 'v2-abc', 'closed_at' => now()]));

    $response = $this->actingAs($this->user)->getJson('/api/reports/performance?version=v2-abc')
        ->assertOk()
        ->assertJsonPath('summary.trades', 3)
        ->assertJsonPath('summary.profit_factor', 3)
        ->assertJsonCount(2, 'weeks')
        ->assertJsonPath('weeks.0.week_start', '2026-11-02')
        ->assertJsonPath('weeks.1.week_start', '2026-10-26')
        ->assertJsonPath('tokens.0.symbol', 'PEPE')
        ->assertJsonPath('tokens.1.symbol', 'BONK');

    expect($response->json('weeks.0.cumulative_r'))->toEqual(2.0)
        ->and(collect($response->json('recommendations'))->pluck('recommendation')->sort()->values()->all())->toBe(['BUY', 'STRONG_BUY'])
        ->and($response->json('versions'))->toContain('v2-abc', 'v0-old');

    $this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertJsonPath('summary.trades', 4);
});

test('the fase 2 criteria do not pass on a good average from too few signals', function () {
    foreach (range(1, 5) as $day) {
        reportSignal($this->token, 2.0, "2026-10-0{$day} 02:00:00");
    }

    $criteria = $this->actingAs($this->user)->getJson('/api/reports/performance?version=v2-abc')->json('criteria');

    expect(collect($criteria)->pluck('passed')->unique()->all())->toBe([false]);
});

test('the fase 2 criteria pass when every target is met', function () {
    foreach (range(1, 30) as $i) {
        reportSignal($this->token, $i % 3 === 0 ? -1.0 : 1.0, now()->subDays($i)->toDateTimeString());
    }
    config(['market.simulator.starting_balance' => 10_000]);
    EquitySnapshot::query()->create(['user_id' => User::autoFollower()->id, 'equity_usd' => 9500, 'balance_usd' => 9500, 'holdings_usd' => 0, 'open_positions' => 0, 'sampled_at' => now()]);

    $criteria = $this->actingAs($this->user)->getJson('/api/reports/performance?version=v2-abc')->json('criteria');

    expect(collect($criteria)->pluck('passed')->unique()->all())->toBe([true]);
});

test('snapshots build the equity curve and the drawdown from the peak', function () {
    config(['market.simulator.starting_balance' => 10_000]);
    $this->artisan('market:equity-snapshot')->assertSuccessful();
    $bot = User::autoFollower();

    expect(EquitySnapshot::query()->where('user_id', $bot->id)->sole()->equity_usd)->toEqual(10000.0)
        ->and(EquitySnapshot::query()->count())->toBe(count(User::AUTO_FOLLOW_ACCOUNTS));

    foreach ([11000, 8800, 9500] as $i => $equity) {
        EquitySnapshot::query()->create(['user_id' => $bot->id, 'equity_usd' => $equity, 'balance_usd' => $equity, 'holdings_usd' => 0, 'open_positions' => 0, 'sampled_at' => now()->addHours($i + 1)]);
    }

    $this->actingAs($this->user)->getJson('/api/reports/performance')
        ->assertJsonCount(4, 'accounts.0.curve')
        // From the peak of 11,000 down to 8,800.
        ->assertJsonPath('accounts.0.max_drawdown_pct', 20)
        ->assertJsonPath('accounts.0.curve.3.equity', 9500);
});

test('a long history is thinned for the chart but keeps the latest point', function () {
    $bot = User::autoFollower();
    foreach (range(1, 700) as $i) {
        EquitySnapshot::query()->create(['user_id' => $bot->id, 'equity_usd' => 10000 + $i, 'balance_usd' => 0, 'holdings_usd' => 0, 'open_positions' => 0, 'sampled_at' => now()->subHours(701 - $i)]);
    }

    $curve = $this->actingAs($this->user)->getJson('/api/reports/performance')->json('accounts.0.curve');

    expect(count($curve))->toBeLessThanOrEqual(301)
        ->and(end($curve)['equity'])->toEqual(10700);
});

test('the snapshot runs on schedule', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('market:equity-snapshot');
});

test('the current strategy version can be chosen before it has a signal', function () {
    $this->actingAs($this->user)->getJson('/api/reports/performance')
        ->assertOk()
        ->assertJsonPath('versions.0', StrategyVersion::current())
        ->assertJsonPath('version', StrategyVersion::current())
        ->assertJsonPath('summary.trades', 0);
});

test('the accounts are compared by what they bought and earned on finished signals', function () {
    $token = $this->token;
    $trade = function (string $key, Signal $signal, float $pnl) use ($token): void {
        $account = User::autoFollower($key);
        Order::factory()->for($account)->for($token)->create(['signal_id' => $signal->id, 'side' => OrderSide::Buy, 'status' => OrderStatus::Filled, 'filled_at' => now()]);
        Order::factory()->for($account)->for($token)->create(['signal_id' => $signal->id, 'side' => OrderSide::Sell, 'status' => OrderStatus::Filled, 'realized_pnl' => $pnl, 'filled_at' => now()]);
    };
    $won = reportSignal($token, 1.5, now()->subDay()->toDateTimeString(), ['recommendation' => 'STRONG_BUY']);
    $lost = reportSignal($token, -1.0, now()->subDay()->toDateTimeString());
    $open = Signal::withoutEvents(fn () => Signal::factory()->for($token)->create(['status' => 'active']));
    $trade('strong_buy', $won, 80);
    $trade('buy', $lost, -50);
    $trade('buy', $open, 5);

    $accounts = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('accounts'))->keyBy('key');

    expect($accounts->keys()->all())->toBe(['all', 'strong_buy', 'buy'])
        ->and($accounts['strong_buy']['closed_signals'])->toBe(1)
        ->and($accounts['strong_buy']['win_rate'])->toEqual(100)
        ->and($accounts['strong_buy']['total_r'])->toEqual(1.5)
        ->and($accounts['buy']['closed_signals'])->toBe(1)
        ->and($accounts['buy']['win_rate'])->toEqual(0)
        ->and($accounts['all']['closed_signals'])->toBe(0)
        ->and($accounts['all']['win_rate'])->toBeNull()
        ->and($accounts['strong_buy']['recommendations'])->toBe(['STRONG_BUY']);
});

test('the portfolio of an auto-follow account is only its own, and only for looking at', function () {
    $account = User::autoFollower('buy');
    Position::factory()->for($account)->for($this->token)->create(['quantity' => 5]);
    Position::factory()->for($account)->for(Token::factory()->create())->create(['quantity' => 0]);
    Position::factory()->for(User::autoFollower('strong_buy'))->for(Token::factory()->create())->create(['quantity' => 7]);
    Order::factory()->for($account, 'user')->for($this->token)->create(['status' => OrderStatus::Pending]);
    Order::factory()->count(21)->for($account, 'user')->for($this->token)->create(['status' => OrderStatus::Filled]);
    Order::factory()->for($this->user, 'user')->for($this->token)->create(['status' => OrderStatus::Filled]);

    $this->getJson('/api/reports/accounts/buy/portfolio')->assertUnauthorized();

    $this->actingAs($this->user);

    $this->getJson('/api/reports/accounts/buy/portfolio')->assertOk()
        ->assertJsonCount(1, 'positions')
        ->assertJsonPath('positions.0.token.symbol', 'PEPE')
        ->assertJsonCount(1, 'pending')
        ->assertJsonPath('pending.0.status', 'pending')
        ->assertJsonCount(20, 'orders.data')
        ->assertJsonPath('orders.meta.total', 21)
        ->assertJsonPath('orders.meta.last_page', 2);

    $this->getJson('/api/reports/accounts/buy/portfolio?page=2')->assertOk()->assertJsonCount(1, 'orders.data');
    $this->getJson('/api/reports/accounts/strong_buy/portfolio')->assertOk()->assertJsonCount(1, 'positions')->assertJsonCount(0, 'pending');

    // Nothing but the three system accounts, so a person's portfolio can never be reached this way.
    $this->getJson('/api/reports/accounts/'.$this->user->id.'/portfolio')->assertNotFound();
    $this->getJson('/api/reports/accounts/nope/portfolio')->assertNotFound();

    // Only ever read.
    $this->postJson('/api/reports/accounts/buy/portfolio')->assertStatus(405);
    $this->deleteJson('/api/reports/accounts/buy/portfolio')->assertStatus(405);
    expect($account->positions()->count())->toBe(2)
        ->and($account->orders()->count())->toBe(22);
});

test('order history reads by the time it shows, not by when the order was placed', function () {
    $account = User::autoFollower('buy');
    $placed = '2026-11-01 02:00:00';
    $early = Order::factory()->for($account, 'user')->for($this->token)->create(['status' => OrderStatus::Filled, 'created_at' => $placed, 'filled_at' => '2026-11-01 03:00:00']);
    $late = Order::factory()->for($account, 'user')->for($this->token)->create(['status' => OrderStatus::Filled, 'created_at' => $placed, 'filled_at' => '2026-11-01 08:00:00']);
    $cancelled = Order::factory()->for($account, 'user')->for($this->token)->create(['status' => OrderStatus::Cancelled, 'created_at' => '2026-11-01 05:00:00', 'filled_at' => null]);

    $this->actingAs($this->user)->getJson('/api/reports/accounts/buy/portfolio')->assertOk()
        ->assertJsonPath('orders.data.0.id', $late->id)
        ->assertJsonPath('orders.data.1.id', $cancelled->id)
        ->assertJsonPath('orders.data.2.id', $early->id);
});

test('each shadow variant counts signals entered since it started, and only changes what its rule touches', function () {
    config(['market.analysis.shadow_exit_since' => '2026-11-07 13:00:00', 'market.analysis.shadow_second_since' => '2026-11-07 13:00:00']);
    $levels = ['entry_price' => 1.0, 'stop_loss' => 0.9, 'take_profit_1' => 1.15, 'take_profit_2' => 1.25, 'take_profit_3' => 1.4, 'cost_r' => 0.1];
    $analysis = fn (Token $token, string $at, string $recommendation, float $price = 1.0) => Analysis::factory()->for($token)->create(['recommendation' => $recommendation, 'price' => $price, 'created_at' => $at]);

    // Opened without a Buy before it; the first check after entry is a Hold at 1.05 (+0.4R), the second one in a
    // row at 1.02 (+0.1R).
    $one = Token::factory()->create();
    $analysis($one, '2026-11-07 13:30:00', 'HOLD');
    $created = $analysis($one, '2026-11-07 13:45:00', 'BUY');
    $analysis($one, '2026-11-07 14:15:00', 'HOLD', 1.05);
    $analysis($one, '2026-11-07 14:30:00', 'HOLD', 1.02);
    reportSignal($one, -1.1, '2026-11-08 05:00:00', [...$levels, 'analysis_id' => $created->id, 'created_at' => '2026-11-07 13:45:00', 'entered_at' => '2026-11-07 14:00:00',
        'downgraded_at' => '2026-11-07 14:15:00', 'downgrade_price' => 1.05, 'downgrade_tp_hit' => 0,
        'second_downgraded_at' => '2026-11-07 14:30:00', 'second_downgrade_price' => 1.02, 'second_downgrade_tp_hit' => 0]);

    // Confirmed entry that holds at first, then one Hold at the entry after target 1 (1/3 of 1.5R = +0.4R net).
    $two = Token::factory()->create();
    $analysis($two, '2026-11-07 14:30:00', 'BUY');
    $created = $analysis($two, '2026-11-07 14:45:00', 'BUY');
    $analysis($two, '2026-11-07 15:15:00', 'BUY');
    $analysis($two, '2026-11-07 16:00:00', 'HOLD');
    $analysis($two, '2026-11-07 16:15:00', 'BUY');
    reportSignal($two, 0.4, '2026-11-08 06:00:00', [...$levels, 'analysis_id' => $created->id, 'created_at' => '2026-11-07 14:45:00', 'entered_at' => '2026-11-07 15:00:00',
        'downgraded_at' => '2026-11-07 16:00:00', 'downgrade_price' => 1.0, 'downgrade_tp_hit' => 1]);

    // Confirmed, never downgraded: its real result stands everywhere.
    $three = Token::factory()->create();
    $analysis($three, '2026-11-07 15:30:00', 'STRONG_BUY');
    $created = $analysis($three, '2026-11-07 15:45:00', 'BUY');
    $analysis($three, '2026-11-07 16:15:00', 'BUY');
    reportSignal($three, -1.0, '2026-11-08 07:00:00', [...$levels, 'analysis_id' => $created->id, 'created_at' => '2026-11-07 15:45:00', 'entered_at' => '2026-11-07 16:00:00']);

    // Entered before the shadow started: left out.
    reportSignal($this->token, 2.0, '2026-11-08 08:00:00', [...$levels, 'entered_at' => '2026-11-07 10:00:00', 'downgraded_at' => '2026-11-07 11:00:00', 'downgrade_price' => 0.95, 'downgrade_tp_hit' => 0]);

    $variants = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('shadow_exit'))->keyBy('key');

    expect($variants->keys()->all())->toBe(['first_downgrade', 'first_check', 'second_downgrade', 'confirmed_entry'])
        ->and($variants->pluck('signals')->unique()->all())->toBe([3])
        ->and($variants->pluck('actual_r')->unique()->all())->toEqual([-1.7])
        ->and($variants['first_downgrade'])->toMatchArray(['affected' => 2, 'shadow_r' => -0.2])
        ->and($variants['first_check'])->toMatchArray(['affected' => 1, 'shadow_r' => -0.2])
        ->and($variants['second_downgrade'])->toMatchArray(['affected' => 1, 'shadow_r' => -0.5])
        ->and($variants['confirmed_entry'])->toMatchArray(['affected' => 1, 'shadow_r' => -0.6]);
});
