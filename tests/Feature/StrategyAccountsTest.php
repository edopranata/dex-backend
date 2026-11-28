<?php

use App\Models\Candle;
use App\Models\StrategyAccount;
use App\Models\StrategyPosition;
use App\Models\StrategySnapshot;
use App\Models\Token;
use App\Models\User;
use App\Services\Admin\SettingsStore;
use App\Services\MarketData\BenchmarkTokens;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\Rules\MartingaleLong;
use App\Services\Strategies\Rules\TrendLong;
use App\Services\Strategies\StrategyContext;
use App\Services\Strategies\StrategyRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * A rule that enters with whatever the test sets.
 */
class StubStrategy extends BaseStrategy
{
    /** @var array<string, mixed>|null */
    public static ?array $entry = null;

    public function maxHours(): int
    {
        return 0;
    }

    public function entry(Token $token, StrategyContext $context): ?array
    {
        return static::$entry;
    }
}

beforeEach(function () {
    // 08:10 UTC: the 07:00 hour and the 04:00 4h candle have closed.
    $this->travelTo(CarbonImmutable::parse('2026-11-28 08:10:00', 'UTC'));
    config([
        'market.simulator.fee_rate' => 0.003,
        'market.autofollow.gas_usd.solana' => 0.01,
        'market.strategy_accounts.strategies' => ['stub' => ['class' => StubStrategy::class, 'label' => 'Uji', 'kind' => 'experiment']],
    ]);
    StubStrategy::$entry = null;
    $this->token = Token::factory()->create(['price_usd' => 1.0, 'price_updated_at' => now(), 'liquidity_usd' => 10_000_000]);
    hourCandles($this->token);
});

/**
 * The 1h candle of 07:00 and the 4h candle of 04:00, so the token is ready for the 08:00 decision.
 */
function hourCandles(Token $token): void
{
    Candle::factory()->for($token)->create(['timeframe' => '1h', 'open_time' => CarbonImmutable::parse('2026-11-28 07:00', 'UTC')->timestamp]);
    Candle::factory()->for($token)->create(['timeframe' => '4h', 'open_time' => CarbonImmutable::parse('2026-11-28 04:00', 'UTC')->timestamp]);
}

function quarter(Token $token, string $openTime, float $open, float $high, float $low, float $close): void
{
    Candle::factory()->for($token)->create(['timeframe' => '15m', 'open_time' => CarbonImmutable::parse($openTime, 'UTC')->timestamp,
        'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close]);
}

test('an entry with a stop loses about half a percent of equity at the stop, costs included', function () {
    StubStrategy::$entry = ['reason' => 'uji', 'stop' => 0.95];

    expect(app(StrategyRunner::class)->run())->toMatchArray(['entries' => 1, 'decided' => 1]);

    $position = StrategyPosition::query()->sole();
    $account = StrategyAccount::query()->sole();
    expect($position->status)->toBe('open')
        ->and($position->entry_price)->toEqual(1.0)
        ->and($position->state['initial_risk'])->toEqualWithDelta($position->quantity * 0.05, 1e-6)
        ->and($account->cash)->toEqualWithDelta(10_000 - $position->cost_basis, 1e-6);

    // The 08:15 candle opens at 0.99 and trades through the stop.
    quarter($this->token, '2026-11-28 08:15', 0.99, 0.995, 0.94, 0.945);
    $this->travelTo(CarbonImmutable::parse('2026-11-28 08:38:00', 'UTC'));
    app(StrategyRunner::class)->run();

    $position->refresh();
    expect($position->status)->toBe('closed')
        ->and($position->exit_reason)->toBe('Stop')
        ->and($position->net_pnl)->toEqualWithDelta(-50, 1.0)
        ->and($position->net_pnl / $position->state['initial_risk'])->toBeLessThan(-1.0)->toBeGreaterThan(-1.15)
        ->and(StrategyAccount::query()->sole()->cash)->toEqualWithDelta(10_000 + $position->net_pnl, 1e-6);
});

test('a target at twice the risk is sold at the target', function () {
    StubStrategy::$entry = ['reason' => 'uji', 'stop' => 0.95, 'take_profit_r' => 2.0];
    app(StrategyRunner::class)->run();

    quarter($this->token, '2026-11-28 08:15', 1.0, 1.02, 0.99, 1.01);
    quarter($this->token, '2026-11-28 08:30', 1.01, 1.12, 1.0, 1.11);
    $this->travelTo(CarbonImmutable::parse('2026-11-28 08:53:00', 'UTC'));
    app(StrategyRunner::class)->run();

    $position = StrategyPosition::query()->sole();
    expect($position->status)->toBe('closed')
        ->and($position->exit_reason)->toBe('Target')
        ->and($position->net_pnl / $position->state['initial_risk'])->toBeGreaterThan(1.85)->toBeLessThan(2.0);
});

test('a token is decided once per closed hour, and only once its candle of that hour is stored', function () {
    StubStrategy::$entry = null;
    $late = Token::factory()->create();
    $runner = app(StrategyRunner::class);

    expect($runner->run()['decided'])->toBe(1)
        ->and($runner->run()['decided'])->toBe(0);

    // The late token's candles arrive within the hour: it is decided on the next run.
    hourCandles($late);
    expect($runner->run()['decided'])->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-28 09:08:00', 'UTC'));
    expect($runner->run()['decided'])->toBe(0);  // no 08:00 candles yet
});

test('a limit entry waits, fills at the limit, and an unfilled one expires or is dropped once the target trades', function () {
    $plan = ['reason' => 'uji', 'limit' => 0.98, 'stop' => 0.9, 'take_profit' => 1.2, 'cancel_price' => 1.2];
    StubStrategy::$entry = $plan + ['expires_at' => CarbonImmutable::parse('2026-11-28 09:00', 'UTC')->timestamp];
    app(StrategyRunner::class)->run();
    $filled = StrategyPosition::query()->sole();
    expect($filled->status)->toBe('pending');

    quarter($this->token, '2026-11-28 08:15', 1.0, 1.01, 0.99, 1.0);
    quarter($this->token, '2026-11-28 08:30', 0.985, 0.99, 0.97, 0.975);
    quarter($this->token, '2026-11-28 08:45', 0.98, 1.21, 0.975, 1.2);
    $this->travelTo(CarbonImmutable::parse('2026-11-28 09:08:00', 'UTC'));
    app(StrategyRunner::class)->run();

    $filled->refresh();
    expect($filled->status)->toBe('closed')
        ->and($filled->entry_price)->toEqual(0.98)
        ->and($filled->exit_reason)->toBe('Target')
        ->and($filled->state['initial_risk'])->toEqualWithDelta($filled->quantity * 0.08, 1e-6);

    // On other tokens: one that never comes down to the limit, and one whose target trades first.
    $this->travelTo(CarbonImmutable::parse('2026-11-28 08:10:00', 'UTC'));
    [$never, $runaway] = [Token::factory()->create(), Token::factory()->create()];
    hourCandles($never);
    hourCandles($runaway);
    app(StrategyRunner::class)->run();
    quarter($never, '2026-11-28 08:15', 1.0, 1.01, 0.99, 1.0);
    quarter($never, '2026-11-28 09:00', 1.0, 1.01, 0.99, 1.0);
    quarter($runaway, '2026-11-28 08:15', 1.0, 1.25, 0.99, 1.2);
    $this->travelTo(CarbonImmutable::parse('2026-11-28 09:23:00', 'UTC'));
    app(StrategyRunner::class)->run();

    expect(StrategyPosition::query()->where('token_id', $never->id)->sole()->exit_reason)->toBe('Order limit kedaluwarsa')
        ->and(StrategyPosition::query()->where('token_id', $runaway->id)->sole()->exit_reason)->toBe('Target tersentuh sebelum order limit terisi');
});

test('a martingale round adds 1.5 times more 2% lower and sells everything 1.5% above the average', function () {
    config([
        'market.strategy_accounts.strategies' => ['martingale_long' => ['class' => MartingaleLong::class, 'label' => 'Martingale', 'kind' => 'experiment']],
        'market.strategy_accounts.martingale' => ['tokens' => 1, 'step_pct' => 0.02, 'multiplier' => 1.5, 'adds' => 8, 'take_profit_pct' => 0.015],
    ]);
    app(StrategyRunner::class)->run();
    $position = StrategyPosition::query()->sole();
    $unit = $position->quantity * $position->entry_price;
    // 10,000 / (1 + 1.5 + ... + 1.5^8 = 74.887).
    expect($unit)->toEqualWithDelta(10_000 / 74.887, 0.01)->and($position->take_profit)->toEqualWithDelta(1.015, 1e-9);

    // Down to 0.975: the first add at 0.98, 1.5 units; the target moves to 1.5% over the new average.
    quarter($this->token, '2026-11-28 08:15', 0.99, 0.99, 0.975, 0.976);
    quarter($this->token, '2026-11-28 08:30', 0.976, 1.01, 0.976, 1.005);
    $this->travelTo(CarbonImmutable::parse('2026-11-28 08:53:00', 'UTC'));
    app(StrategyRunner::class)->run();

    $position->refresh();
    $average = ($unit + 1.5 * $unit) / ($unit / 1.0 + 1.5 * $unit / 0.98);
    expect($position->state['adds_done'])->toBe(1)
        ->and($position->entry_price)->toEqualWithDelta($average, 1e-9)
        ->and($position->status)->toBe('closed')
        ->and($position->exit_reason)->toBe('Target')
        ->and($position->gross_pnl)->toBeGreaterThan(0);
});

/**
 * Bybit spot daily klines of BTCUSDT (newest first, as the API returns them): 50 days up to `lastDay` closing at
 * `closes`, plus the day still running.
 *
 * @param  list<float>  $closes  Oldest first.
 */
function bybitDays(CarbonImmutable $lastDay, array $closes): array
{
    $list = [[(string) ($lastDay->addDay()->timestamp * 1000), '0', '0', '0', '1', '0', '0']];
    foreach (array_reverse($closes) as $back => $close) {
        $list[] = [(string) ($lastDay->subDays($back)->timestamp * 1000), '0', '0', '0', (string) $close, '0', '0'];
    }

    return ['retCode' => 0, 'result' => ['list' => $list]];
}

test('the trend account holds cbBTC while BTC closes above its SMA50 and sells when it falls below', function () {
    $btc = Token::factory()->create(['chain_id' => 'base', 'pair_address' => config('market.benchmarks.BTC.pair_address'), 'symbol' => 'cbBTC', 'liquidity_usd' => 0]);
    config(['market.strategy_accounts.strategies' => ['btc_trend' => ['class' => TrendLong::class, 'label' => 'Tren', 'kind' => 'shadow']]]);
    $day = CarbonImmutable::parse('2026-11-29 00:00', 'UTC');
    $rising = array_map(fn (int $k): float => 90_000 + 500 * $k, range(0, 49));
    Http::fake(['api.bybit.id/*' => Http::sequence()
        ->push(bybitDays($day->subDay(), $rising))
        ->push(bybitDays($day, [...array_slice($rising, 1), 50_000.0]))]);
    // The token's own candles: the day it is decided on, and its last 15m close as the price.
    Candle::factory()->for($btc)->create(['timeframe' => '1d', 'open_time' => $day->subDay()->timestamp]);
    Candle::factory()->for($btc)->create(['timeframe' => '1h', 'open_time' => $day->subHour()->timestamp]);
    quarter($btc, '2026-11-28 23:45', 114_000, 114_500, 113_800, 114_400);

    // 23:10 is not a day close: nothing is decided.
    $this->travelTo($day->subMinutes(50));
    expect(app(StrategyRunner::class)->run()['entries'])->toBe(0);

    $this->travelTo($day->addMinutes(10));
    expect(app(StrategyRunner::class)->run()['entries'])->toBe(1);
    $position = StrategyPosition::query()->sole();
    expect($position->token_id)->toBe($btc->id)
        ->and($position->stop)->toBeNull()
        ->and($position->entry_price)->toEqual(114_400.0)
        ->and($position->quantity * $position->entry_price)->toEqualWithDelta(1000, 1e-6);  // a tenth of equity, without a stop

    // The next day BTC closes far below its SMA50.
    Candle::factory()->for($btc)->create(['timeframe' => '1d', 'open_time' => $day->timestamp]);
    Candle::factory()->for($btc)->create(['timeframe' => '1h', 'open_time' => $day->addDay()->subHour()->timestamp]);
    $this->travelTo($day->addDay()->addMinutes(10));
    app(StrategyRunner::class)->run();

    expect($position->refresh()->status)->toBe('closed')
        ->and($position->exit_reason)->toBe('BTC turun ke bawah SMA50');
    Http::assertSentCount(2);
});

test('strategy accounts appear in the selection with their start, the benchmark left out', function () {
    app(SettingsStore::class)->set(['market.selection.min_trades' => 2]);
    $account = StrategyAccount::factory()->create(['key' => 'willy', 'started_at' => '2026-11-28 18:00:00']);
    StrategyAccount::factory()->create(['key' => 'hold_btc', 'kind' => 'benchmark']);
    foreach ([75.0, -50.0] as $net) {
        StrategyPosition::query()->create(['strategy_account_id' => $account->id, 'token_id' => $this->token->id, 'status' => 'closed', 'quantity' => 1000,
            'remaining' => 0, 'entry_price' => 1, 'cost_basis' => 1000, 'stop' => 0.95, 'state' => ['initial_risk' => 50], 'entry_reason' => 'uji',
            'opened_at' => now(), 'checked_until' => 0, 'closed_at' => now(), 'net_pnl' => $net]);
    }
    StrategySnapshot::query()->create(['strategy_account_id' => $account->id, 'equity' => 10_000, 'cash' => 10_000, 'taken_at' => now()]);

    $response = $this->actingAs(User::factory()->create())->getJson('/api/reports/selection')->assertOk();
    $rows = collect($response->json('strategy_accounts'))->keyBy('key');

    expect($rows->keys()->all())->toBe(['willy'])
        ->and($rows['willy'])->toMatchArray(['status' => 'failed', 'closed' => 2, 'wins' => 1, 'losses' => 1, 'win_rate' => 50, 'avg_r' => 0.25, 'avg_pct' => 1.25])
        ->and($rows['willy']['started_at'])->toStartWith('2026-11-28T18:00:00')
        ->and($rows['willy']['reasons'])->toBe(['Win rate 50% di bawah 80%']);
});

test('every ported rule reads DEX candles without errors on a 4h close', function () {
    $hourEnd = CarbonImmutable::parse('2026-11-28 08:00', 'UTC')->timestamp;
    mt_srand(7);
    $price = 1.0;
    $rows = [];
    // 130 days of 1h candles and the 4h candles built from them, a random walk.
    for ($t = $hourEnd - 130 * 86400; $t < $hourEnd; $t += 3600) {
        $open = $price;
        $price *= 1 + (mt_rand(-200, 210) / 10000);
        $rows[] = ['token_id' => $this->token->id, 'timeframe' => '1h', 'open_time' => $t, 'open' => $open, 'high' => max($open, $price) * 1.004,
            'low' => min($open, $price) * 0.996, 'close' => $price, 'volume' => mt_rand(1000, 9000)];
    }
    Candle::query()->where('token_id', $this->token->id)->delete();
    foreach (array_chunk($rows, 500) as $chunk) {
        Candle::query()->insert($chunk);
    }
    foreach (array_chunk($rows, 4) as $group) {
        Candle::query()->insert(['token_id' => $this->token->id, 'timeframe' => '4h', 'open_time' => $group[0]['open_time'], 'open' => $group[0]['open'],
            'high' => max(array_column($group, 'high')), 'low' => min(array_column($group, 'low')), 'close' => end($group)['close'], 'volume' => array_sum(array_column($group, 'volume'))]);
    }
    Http::fake(['api.bybit.id/*' => Http::response(bybitDays(CarbonImmutable::parse('2026-11-27', 'UTC'), array_map(fn (int $k): float => 90_000 + 500 * $k, range(0, 49))))]);

    $context = new StrategyContext($hourEnd + 600, $hourEnd, app(BenchmarkTokens::class));
    $position = StrategyPosition::query()->create(['strategy_account_id' => StrategyAccount::factory()->create()->id, 'token_id' => $this->token->id, 'status' => 'open',
        'quantity' => 100, 'remaining' => 100, 'entry_price' => 1, 'stop' => 0.9, 'state' => ['risk' => 0.1], 'entry_reason' => 'uji',
        'opened_at' => now()->subDays(2), 'checked_until' => 0]);

    $rules = require base_path('config/market.php');
    foreach ($rules['strategy_accounts']['strategies'] as $key => $definition) {
        $rule = app($definition['class']);
        $entry = $rule->entry($this->token, $context);
        $exit = $rule->manage($position, $context);
        expect($entry === null || isset($entry['reason']))->toBeTrue("{$key} entry")
            ->and($exit === null || isset($exit['fraction']))->toBeTrue("{$key} manage");
    }
});

test('the strategy accounts page lists each account with its equity and its positions', function () {
    StubStrategy::$entry = ['reason' => 'uji', 'stop' => 0.95];
    app(StrategyRunner::class)->run();
    $user = User::factory()->create();

    $this->getJson('/api/reports/strategy-accounts')->assertUnauthorized();
    $accounts = $this->actingAs($user)->getJson('/api/reports/strategy-accounts')->assertOk()->json('accounts');
    $positions = $this->actingAs($user)->getJson('/api/reports/strategy-accounts/stub')->assertOk()->json('positions');

    expect($accounts[0])->toMatchArray(['key' => 'stub', 'open' => 1, 'closed' => 0, 'is_active' => true])
        ->and($accounts[0]['equity'])->toBeLessThan(10_000)->toBeGreaterThan(9_990)
        ->and($positions[0])->toMatchArray(['status' => 'open', 'entry_price' => 1, 'stop' => 0.95, 'initial_stop' => 0.95, 'entry_reason' => 'uji']);
    $this->actingAs($user)->getJson('/api/reports/strategy-accounts/missing')->assertNotFound();
});
