<?php

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Analysis;
use App\Models\Candle;
use App\Models\EquitySnapshot;
use App\Models\Order;
use App\Models\Position;
use App\Models\ShadowSignal;
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

    expect($accounts->keys()->all())->toBe(['all', 'strong_buy', 'buy', 'solana', 'bsc', 'ethereum'])
        ->and($accounts['solana']['chains'])->toBe(['solana'])
        ->and($accounts['all']['chains'])->toBeNull()
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
    config(['market.analysis.shadow_exit_since' => '2026-11-07 13:00:00', 'market.analysis.shadow_second_since' => '2026-11-07 13:00:00', 'market.analysis.shadow_young_pair_since' => '2026-11-07 13:00:00', 'market.analysis.shadow_btc_since' => '2026-11-07 13:00:00']);
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

    expect($variants->keys()->all())->toBe(['first_downgrade', 'first_check', 'second_downgrade', 'confirmed_entry', 'young_pair', 'btc_follower_down_30', 'btc_follower_down_10', 'btc_follower_up', 'candle_confirmed', 'candle_confirmed_volume', 'atr_trail_4h'])
        ->and($variants->pluck('signals')->unique()->all())->toBe([3])
        ->and($variants->pluck('actual_r')->unique()->all())->toEqual([-1.7])
        ->and($variants['first_downgrade'])->toMatchArray(['affected' => 2, 'shadow_r' => -0.2])
        ->and($variants['first_check'])->toMatchArray(['affected' => 1, 'shadow_r' => -0.2])
        ->and($variants['second_downgrade'])->toMatchArray(['affected' => 1, 'shadow_r' => -0.5])
        ->and($variants['confirmed_entry'])->toMatchArray(['affected' => 1, 'shadow_r' => -0.6]);
});

test('the young pair shadow skips signals on pairs younger than the limit, entered since it started', function () {
    config(['market.analysis.shadow_young_pair_since' => '2026-11-13 16:00:00', 'market.analysis.shadow_young_pair_days' => 14]);
    $signal = function (?float $ageDays, float $netR, string $enteredAt): void {
        $token = Token::factory()->create();
        $analysis = Analysis::factory()->for($token)->create(['indicators' => $ageDays === null ? [] : ['fundamental' => ['age_days' => $ageDays]]]);
        reportSignal($token, $netR, '2026-11-15 00:00:00', ['analysis_id' => $analysis->id, 'stop_loss' => 0.9, 'entered_at' => $enteredAt]);
    };

    $signal(5.2, -1.0, '2026-11-13 17:00:00');   // young: skipped, counts 0R
    $signal(13.9, 2.0, '2026-11-13 18:00:00');   // young: skipped
    $signal(40.0, -1.0, '2026-11-13 19:00:00');  // old enough: kept
    $signal(null, 0.5, '2026-11-13 20:00:00');   // no age data: kept
    $signal(3.0, -1.0, '2026-11-13 10:00:00');   // entered before the shadow started: left out

    $young = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('shadow_exit'))->firstWhere('key', 'young_pair');

    expect($young)->toMatchArray(['label' => 'Lewati pair berumur di bawah 14 hari', 'signals' => 4, 'affected' => 2, 'actual_r' => 0.5, 'shadow_r' => -0.5]);
});

test('the BTC shadows skip BTC followers while BTC is not rising, and keep only them while it rises', function () {
    config(['market.analysis.shadow_btc_since' => '2026-11-15 12:00:00', 'market.analysis.shadow_btc_corr' => [0.3, 0.1]]);
    $signal = function (?float $corr, ?string $btcTrend, float $netR, string $enteredAt): void {
        $token = Token::factory()->create();
        $analysis = Analysis::factory()->for($token)->create([
            'indicators' => $corr === null ? [] : ['btc' => ['corr' => $corr]],
            'market_context' => ['benchmarks' => ['BTC' => ['trend_4h' => $btcTrend]]],
        ]);
        reportSignal($token, $netR, '2026-11-17 00:00:00', ['analysis_id' => $analysis->id, 'stop_loss' => 0.9, 'entered_at' => $enteredAt]);
    };

    $signal(0.5, 'bearish', -1.0, '2026-11-15 13:00:00');   // follower, BTC falling: skipped by both thresholds
    $signal(0.2, 'sideways', -1.0, '2026-11-15 14:00:00');  // follows only at 0.1: skipped by the 0.1 rule
    $signal(0.05, 'bearish', 2.0, '2026-11-15 15:00:00');   // moves on its own: kept
    $signal(0.6, 'bullish', 1.5, '2026-11-15 16:00:00');    // follower, BTC rising: kept, and the only one in B
    $signal(null, 'bearish', 0.5, '2026-11-15 17:00:00');   // no BTC data: kept by A, outside B
    $signal(0.9, 'bearish', -1.0, '2026-11-15 10:00:00');   // entered before the shadows started: left out

    $variants = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('shadow_exit'))->keyBy('key');

    expect($variants['btc_follower_down_30'])->toMatchArray([
        'label' => 'Lewati token yang ikut BTC (korelasi ≥ 0,3) saat tren 4 jam BTC tidak naik',
        'signals' => 5, 'affected' => 1, 'actual_r' => 2.0, 'shadow_r' => 3.0,
    ])
        ->and($variants['btc_follower_down_10'])->toMatchArray(['affected' => 2, 'shadow_r' => 4.0])
        ->and($variants['btc_follower_up'])->toMatchArray([
            'label' => 'Hanya token yang ikut BTC (korelasi ≥ 0,3) saat tren 4 jam BTC naik',
            'affected' => 4, 'shadow_r' => 1.5,
        ]);
});

test('the candle shadows keep only entries after a confirmation candle, with and without the volume rule', function () {
    config(['market.analysis.shadow_candle_since' => '2026-11-07 13:00:00', 'market.analysis.shadow_candle_volume' => 1.5]);
    $hour = strtotime('2026-11-10 11:00:00');
    // 20 quiet hours, then the last closed hour (11:00-12:00) as given.
    $signal = function (?array $last, float $netR, string $enteredAt = '2026-11-10 12:05:00') use ($hour): void {
        $token = Token::factory()->create();
        if ($last !== null) {
            foreach (range(20, 1) as $back) {
                Candle::query()->create(['token_id' => $token->id, 'timeframe' => '1h', 'open_time' => $hour - $back * 3600,
                    'open' => 1, 'high' => 1.01, 'low' => 0.99, 'close' => 1, 'volume' => 100]);
            }
            Candle::query()->create(['token_id' => $token->id, 'timeframe' => '1h', 'open_time' => $hour, ...$last]);
        }
        reportSignal($token, $netR, '2026-11-12 00:00:00', ['stop_loss' => 0.9, 'entered_at' => $enteredAt]);
    };

    $signal(['open' => 1, 'high' => 1.06, 'low' => 0.995, 'close' => 1.05, 'volume' => 200], 2.0);   // solid, above previous high, volume 2x: kept by both
    $signal(['open' => 1, 'high' => 1.06, 'low' => 0.995, 'close' => 1.05, 'volume' => 120], -1.0);  // confirmed, volume too low: skipped only with volume
    $signal(['open' => 1, 'high' => 1.06, 'low' => 0.99, 'close' => 1.005, 'volume' => 300], -1.0); // long upper wick: skipped by both
    $signal(['open' => 1.0, 'high' => 1.009, 'low' => 0.999, 'close' => 1.008, 'volume' => 300], 1.0); // solid but below previous high: skipped by both
    $signal(null, 0.5);                                                                                 // no candles: kept by both
    $signal(['open' => 1, 'high' => 1.06, 'low' => 0.995, 'close' => 1.05, 'volume' => 200], -1.0, '2026-11-05 12:05:00'); // before the shadow: left out

    $variants = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('shadow_exit'))->keyBy('key');

    expect($variants['candle_confirmed'])->toMatchArray([
        'label' => 'Hanya entry setelah candle 1 jam konfirmasi (body solid, close di atas high sebelumnya)',
        'signals' => 5, 'affected' => 2, 'actual_r' => 1.5, 'shadow_r' => 1.5,
    ])
        ->and($variants['candle_confirmed_volume'])->toMatchArray([
            'label' => 'Hanya entry setelah candle 1 jam konfirmasi dengan volume ≥ 1,5x rata-rata 20 jam',
            'affected' => 3, 'shadow_r' => 2.5,
        ]);
});

test('the chandelier shadow trails 3x the 4h ATR under the highest high since entry, without targets', function () {
    config(['market.analysis.shadow_atr_since' => '2026-11-07 13:00:00', 'market.analysis.shadow_atr_multiplier' => 3.0]);
    $fourHour = strtotime('2026-11-10 12:00:00');
    $candle = fn (Token $token, string $timeframe, int $openTime, float $open, float $high, float $low, float $close) => Candle::query()->create(
        ['token_id' => $token->id, 'timeframe' => $timeframe, 'open_time' => $openTime, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => 100]);

    // Rises to 1.30, the 4h close at 16:00 lifts the stop to 1.30 - 3 x 0.02 = 1.24, then the 16:00 hour falls through it.
    $winner = Token::factory()->create();
    foreach (range(20, 0) as $back) {
        $candle($winner, '4h', $fourHour - $back * 14400, 1, 1.01, 0.99, 1);
    }
    $candle($winner, '1h', $fourHour + 3600, 1.0, 1.30, 0.99, 1.28);
    $candle($winner, '1h', $fourHour + 7200, 1.28, 1.29, 1.25, 1.27);
    $candle($winner, '1h', $fourHour + 10800, 1.27, 1.29, 1.25, 1.27);
    $candle($winner, '1h', $fourHour + 14400, 1.26, 1.27, 1.20, 1.21);
    reportSignal($winner, 1.0, '2026-11-12 00:00:00', ['stop_loss' => 0.9, 'entered_at' => '2026-11-10 12:05:00']);

    // Falls to the first stop before any 4h close.
    $loser = Token::factory()->create();
    $candle($loser, '1h', $fourHour + 3600, 1.0, 1.01, 0.85, 0.86);
    reportSignal($loser, -1.0, '2026-11-12 00:00:00', ['stop_loss' => 0.9, 'entered_at' => '2026-11-10 12:05:00']);

    reportSignal(Token::factory()->create(), 0.5, '2026-11-12 00:00:00', ['stop_loss' => 0.9, 'entered_at' => '2026-11-10 12:05:00']); // no candles: kept

    $atr = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('shadow_exit'))->firstWhere('key', 'atr_trail_4h');

    expect($atr)->toMatchArray([
        'label' => 'Tanpa target: trailing stop 3x ATR candle 4 jam sejak entry, paling lama 30 hari',
        'signals' => 3, 'affected' => 2, 'actual_r' => 0.5, 'shadow_r' => 1.7,
    ]);
});

test('the chandelier shadow stops after 30 days and marks signals with missing hours', function () {
    config(['market.analysis.shadow_atr_since' => '2026-11-07 13:00:00', 'market.analysis.shadow_atr_max_days' => 30]);
    $this->travelTo('2026-12-20 00:00:00');
    $start = strtotime('2026-11-10 13:00:00');
    $token = Token::factory()->create();
    // Every hour for 31 days at 1.10, then a crash on day 31 that a 30-day limit no longer sees.
    foreach (range(0, 31 * 24) as $hour) {
        $crash = $hour === 31 * 24;
        Candle::query()->create(['token_id' => $token->id, 'timeframe' => '1h', 'open_time' => $start + $hour * 3600,
            'open' => 1.1, 'high' => 1.1, 'low' => $crash ? 0.5 : 1.1, 'close' => $crash ? 0.5 : 1.1, 'volume' => 1]);
    }
    reportSignal($token, -1.0, '2026-11-12 00:00:00', ['stop_loss' => 0.9, 'entered_at' => '2026-11-10 12:05:00']);

    $sparse = Token::factory()->create();   // one candle in 30 days: valued at it, and marked
    Candle::query()->create(['token_id' => $sparse->id, 'timeframe' => '1h', 'open_time' => $start, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);
    reportSignal($sparse, -1.0, '2026-11-12 00:00:00', ['stop_loss' => 0.9, 'entered_at' => '2026-11-10 12:05:00']);

    $atr = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('shadow_exit'))->firstWhere('key', 'atr_trail_4h');

    // (1.1 - 1) / 0.1 = 1R less 0.1R costs, and (1 - 1) / 0.1 = 0R less 0.1R.
    expect($atr)->toMatchArray(['signals' => 2, 'affected' => 2, 'shadow_r' => 0.8, 'gaps' => 1]);
});

test('a confirmation candle after a gap in the hours before entry is not judged', function () {
    config(['market.analysis.shadow_candle_since' => '2026-11-07 13:00:00']);
    $hour = strtotime('2026-11-10 11:00:00');
    $token = Token::factory()->create();
    foreach (range(21, 2) as $back) {   // 20 quiet hours, then 10:00 missing
        Candle::query()->create(['token_id' => $token->id, 'timeframe' => '1h', 'open_time' => $hour - $back * 3600,
            'open' => 1, 'high' => 1.01, 'low' => 0.99, 'close' => 1, 'volume' => 100]);
    }
    Candle::query()->create(['token_id' => $token->id, 'timeframe' => '1h', 'open_time' => $hour, 'open' => 1, 'high' => 1.06, 'low' => 0.99, 'close' => 1.005, 'volume' => 300]);
    reportSignal($token, -1.0, '2026-11-12 00:00:00', ['stop_loss' => 0.9, 'entered_at' => '2026-11-10 12:05:00']);

    $variants = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('shadow_exit'))->keyBy('key');

    expect($variants['candle_confirmed'])->toMatchArray(['signals' => 1, 'affected' => 0, 'gaps' => 1])
        ->and($variants['candle_confirmed_volume'])->toMatchArray(['affected' => 0, 'gaps' => 1]);
});

test('shadow groups count the trades followed on incomplete candles apart', function () {
    $entered = strtotime('2026-11-10 12:00:00');
    $shadow = function (int $candles, float $netR) use ($entered): void {
        $token = Token::factory()->create();
        $analysis = Analysis::factory()->for($token)->create();
        foreach (range(0, $candles - 1) as $n) {
            Candle::query()->create(['token_id' => $token->id, 'timeframe' => '15m', 'open_time' => $entered + $n * 900, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);
        }
        ShadowSignal::query()->create(['token_id' => $token->id, 'analysis_id' => $analysis->id, 'rule' => 'accepted', 'recommendation' => 'STRONG_BUY',
            'strategy_version' => 'v6-test', 'metric' => 10, 'entry_low' => 0.99, 'entry_high' => 1.0, 'stop_loss' => 0.9, 'take_profit_1' => 1.15,
            'take_profit_2' => 1.25, 'take_profit_3' => 1.4, 'status' => 'stop_loss', 'net_r' => $netR,
            'entered_at' => date('Y-m-d H:i:s', $entered), 'closed_at' => date('Y-m-d H:i:s', $entered + 10 * 3600)]);
    };
    $shadow(40, -1.0);   // all 40 quarter hours
    $shadow(30, -1.2);   // a quarter missing

    $row = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('holder_filter'))->firstWhere('rule', 'accepted');

    expect($row)->toMatchArray(['trades' => 2, 'total_r' => -2.2, 'gaps' => 1, 'gap_r' => -1.2]);
});

test('buy shadows are reported apart, split by the BTC 4h trend when they were analyzed', function () {
    $shadow = function (?string $btcTrend, ?float $netR, string $status): void {
        $token = Token::factory()->create();
        $analysis = Analysis::factory()->for($token)->create(['market_context' => ['benchmarks' => ['BTC' => ['trend_4h' => $btcTrend]]]]);
        ShadowSignal::query()->create(['token_id' => $token->id, 'analysis_id' => $analysis->id, 'rule' => 'buy_watch', 'recommendation' => 'BUY',
            'strategy_version' => 'v6-test', 'metric' => 45, 'entry_low' => 0.99, 'entry_high' => 1.0, 'stop_loss' => 0.9, 'take_profit_1' => 1.15,
            'take_profit_2' => 1.25, 'take_profit_3' => 1.4, 'status' => $status, 'net_r' => $netR]);
    };
    $shadow('bullish', 1.5, 'take_profit');
    $shadow('bullish', null, 'pending');
    $shadow('bearish', -1.0, 'stop_loss');
    $shadow(null, -0.5, 'stop_loss');

    $rows = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('holder_filter'))->keyBy('rule');

    expect($rows['buy_btc_rising'])->toMatchArray(['recorded' => 2, 'open' => 1, 'trades' => 1, 'total_r' => 1.5])
        ->and($rows['buy_btc_not_rising'])->toMatchArray(['recorded' => 2, 'trades' => 2, 'total_r' => -1.5]);
});

test('results are also grouped per network, split by recommendation', function () {
    $bsc = Token::factory()->create(['chain_id' => 'bsc']);
    reportSignal($this->token, 1.5, '2026-11-03 10:00:00', ['recommendation' => 'STRONG_BUY']);
    reportSignal($this->token, -1.0, '2026-11-03 11:00:00');
    reportSignal($bsc, -1.0, '2026-11-03 12:00:00');

    $chains = collect($this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertOk()->json('chains'))->keyBy('chain');

    expect($chains->keys()->all())->toBe(['solana', 'bsc'])
        ->and($chains['solana'])->label->toBe('Solana')->trades->toBe(2)->total_r->toEqual(0.5)
        ->and($chains['solana']['by_recommendation']['STRONG_BUY']['total_r'])->toEqual(1.5)
        ->and($chains['solana']['by_recommendation']['BUY']['total_r'])->toEqual(-1.0)
        ->and($chains['bsc'])->label->toBe('BNB Chain')->trades->toBe(1);
});
