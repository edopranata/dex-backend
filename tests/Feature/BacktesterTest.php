<?php

use App\Models\Candle;
use App\Models\Token;
use App\Services\Analysis\Backtester;
use App\Services\Analysis\TechnicalAnalyzer;
use Mockery\MockInterface;

const BACKTEST_START = 1_700_000_000 - (1_700_000_000 % 3600);

/**
 * Flat history, then the given [open, high, low, close] candles right after the signal candle.
 *
 * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $after
 */
function backtestToken(array $after, float $liquidity = 10_000_000): Token
{
    $token = Token::factory()->create(['liquidity_usd' => $liquidity, 'symbol' => 'TST']);
    $time = BACKTEST_START;

    foreach ([...array_fill(0, 60, [1.0, 1.0, 1.0, 1.0]), ...$after] as [$open, $high, $low, $close]) {
        Candle::factory()->create(['token_id' => $token->id, 'timeframe' => '1h', 'open_time' => $time, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => 100]);
        $time += 3600;
    }

    return $token;
}

/**
 * Technical analysis that says "buy" only at candle 60 (the last flat one): entry zone up to 1.00,
 * stop 0.95 (ATR 0.05 and the swing low put it 1 ATR away), first target 1.075.
 */
function backtestBuyAtSignalCandle(?string $trend = 'bullish', float $rsi = 50.0): void
{
    test()->mock(TechnicalAnalyzer::class, function (MockInterface $mock) use ($trend, $rsi): void {
        $mock->shouldReceive('analyze')->andReturnUsing(fn ($candles): array => [
            'score' => $candles->count() === 60 ? 95 : 10,
            'available' => true,
            'reasons' => [],
            'indicators' => ['rsi' => $rsi, 'trend_higher_tf' => $trend],
            'atr' => 0.05,
            'swing_low' => 0.99,
            'swing_high' => 1.1,
        ]);
    });
}

function backtestRun(Token $token, array $scenario = []): array
{
    $backtester = app(Backtester::class);

    return $backtester->simulate($backtester->prepare($token, BACKTEST_START), $scenario);
}

test('a candle touching both stop and target counts as the stop', function () {
    backtestBuyAtSignalCandle();

    $trades = backtestRun(backtestToken([[1.0, 1.2, 0.9, 1.1], [1.1, 1.1, 1.1, 1.1]]));

    expect($trades)->toHaveCount(1)
        ->and($trades[0]['result'])->toBe('stop')
        ->and($trades[0]['tp_hit'])->toBe(0);
});

test('a stop that gaps below its level fills at the open and the net result pays costs', function () {
    backtestBuyAtSignalCandle();

    $trade = backtestRun(backtestToken([[1.0, 1.01, 0.99, 1.0], [0.9, 0.91, 0.88, 0.9]]))[0];

    expect($trade['exit_price'])->toBe(0.9)
        ->and($trade['gross_r'])->toBeLessThan(-1.5)
        ->and($trade['net_r'])->toBeLessThan($trade['gross_r']);
});

test('after the first target the stop moves to breakeven', function () {
    backtestBuyAtSignalCandle();

    $trade = backtestRun(backtestToken([[1.0, 1.08, 0.99, 1.05], [1.05, 1.06, 0.99, 1.0]]))[0];

    expect($trade['result'])->toBe('breakeven')->and($trade['tp_hit'])->toBe(1)->and($trade['gross_r'])->toBeGreaterThan(0.3);
});

test('reaching all targets closes the trade, and an unfinished trade at the end of data is not counted', function () {
    backtestBuyAtSignalCandle();
    $trade = backtestRun(backtestToken([[1.0, 1.5, 0.99, 1.4]]))[0];
    expect($trade['result'])->toBe('take_profit')->and($trade['tp_hit'])->toBe(3);

    backtestBuyAtSignalCandle();
    expect(backtestRun(backtestToken([[1.0, 1.01, 0.99, 1.0]])))->toBe([]);
});

test('analysis at a candle never sees later candles, and only closed higher timeframe candles', function () {
    $token = backtestToken([[1.0, 1.0, 1.0, 1.0], [1.0, 1.0, 1.0, 1.0]]);

    foreach ([0, 4, 8] as $i => $hours) {
        Candle::factory()->create(['token_id' => $token->id, 'timeframe' => '4h', 'open_time' => BACKTEST_START + 236 * 3600 + $hours * 3600, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);
    }
    foreach (range(0, 2) as $i) {
        Candle::factory()->create(['token_id' => $token->id, 'timeframe' => '4h', 'open_time' => BACKTEST_START + $i * 4 * 3600, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);
    }

    $seen = [];
    $this->mock(TechnicalAnalyzer::class, function (MockInterface $mock) use (&$seen): void {
        $mock->shouldReceive('analyze')->andReturnUsing(function ($candles, $trend) use (&$seen): array {
            $closedAt = $candles->last()->open_time + 3600;
            $seen[] = ['closed_at' => $closedAt, 'count' => $candles->count(), 'latest_trend_close' => $trend->isEmpty() ? null : $trend->last()->open_time + 4 * 3600];

            return ['score' => 10, 'available' => true, 'reasons' => [], 'indicators' => [], 'atr' => 0.05, 'swing_low' => 1.0, 'swing_high' => 1.0];
        });
    });

    app(Backtester::class)->prepare($token, BACKTEST_START);

    expect($seen)->toHaveCount(3);
    foreach ($seen as $step) {
        expect($step['latest_trend_close'])->toBeLessThanOrEqual($step['closed_at']);
    }
    expect($seen[0]['count'])->toBe(60);
});

test('scenario rules block signals: trend, rsi and minimum stop distance', function () {
    $after = [[1.0, 1.5, 0.99, 1.4]];

    backtestBuyAtSignalCandle('bearish');
    expect(backtestRun(backtestToken($after), ['require_trend' => 'bullish']))->toBe([]);

    backtestBuyAtSignalCandle('bullish', 60.0);
    expect(backtestRun(backtestToken($after), ['max_rsi' => 55.0]))->toBe([]);

    backtestBuyAtSignalCandle();
    expect(backtestRun(backtestToken($after), ['min_stop_pct' => 50.0]))->toBe([])
        ->and(backtestRun(backtestToken($after), ['require_trend' => 'bullish', 'max_rsi' => 55.0, 'min_stop_pct' => 1.0]))->toHaveCount(1);
});

test('the summary reports win rate, expectancy, profit factor and drawdown in net R', function () {
    $trade = fn (float $net, int $closed, string $token = 'A'): array => ['net_r' => $net, 'gross_r' => $net + 0.1, 'cost_r' => 0.1, 'closed_at' => $closed, 'token' => $token];

    $summary = Backtester::summarize([$trade(2.0, 1), $trade(-1.0, 2), $trade(-1.0, 3, 'B'), $trade(1.0, 4)]);

    expect($summary)->toMatchArray(['trades' => 4, 'win_rate' => 50.0, 'avg_r' => 0.25, 'total_r' => 1.0, 'profit_factor' => 1.5, 'max_drawdown_r' => 2.0, 'tokens' => 2]);
    expect(Backtester::summarize([]))->toMatchArray(['trades' => 0, 'win_rate' => null, 'profit_factor' => null]);
});

test('the backfill walks back page by page and stops at the cutoff', function () {
    Http::fake(function ($request) {
        $before = (int) ($request->data()['before_timestamp'] ?? now()->timestamp);
        $rows = array_map(fn (int $i): array => [$before - ($i + 1) * 3600, 1, 1, 1, 1, 1], range(0, 999));

        return Http::response(['data' => ['attributes' => ['ohlcv_list' => $rows]]]);
    });
    config(['market.geckoterminal_requests_per_minute' => 60000]);
    $token = Token::factory()->create(['chain_id' => 'solana']);

    $this->artisan('market:backfill-candles', ['--days' => 60, '--timeframe' => ['1h'], '--token' => [$token->symbol]])->assertSuccessful();

    Http::assertSentCount(2);
    expect(Candle::query()->where('token_id', $token->id)->count())->toBeGreaterThan(1400);
});

test('market rules use the benchmark trend known at that moment', function () {
    $after = [[1.0, 1.5, 0.99, 1.4]];
    $marketTrades = function (string $trend, array $scenario) use ($after): array {
        $token = backtestToken($after);
        $benchmark = Token::factory()->create(['chain_id' => 'ethereum', 'symbol' => 'WETH']);
        // Closed before the signal; a later candle must not count.
        Candle::factory()->create(['token_id' => $benchmark->id, 'timeframe' => '4h', 'open_time' => BACKTEST_START, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);
        Candle::factory()->create(['token_id' => $benchmark->id, 'timeframe' => '4h', 'open_time' => BACKTEST_START + 60 * 3600, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);
        $token->update(['chain_id' => 'base']);

        test()->mock(TechnicalAnalyzer::class, function (MockInterface $mock) use ($trend): void {
            $calls = 0;
            $mock->shouldReceive('higherTimeframeTrend')->andReturnUsing(function () use ($trend, &$calls): string {
                return ++$calls === 1 ? $trend : 'bullish';
            });
            $mock->shouldReceive('analyze')->andReturnUsing(fn ($candles): array => [
                'score' => $candles->count() === 60 ? 95 : 10,
                'available' => true,
                'reasons' => [],
                'indicators' => ['rsi' => 50.0, 'trend_higher_tf' => 'bullish'],
                'atr' => 0.05,
                'swing_low' => 0.99,
                'swing_high' => 1.1,
            ]);
        });

        return backtestRun($token, $scenario);
    };

    expect($marketTrades('bearish', ['block_market' => 'bearish']))->toBe([])
        ->and($marketTrades('bearish', ['require_market' => 'bullish']))->toBe([])
        ->and($marketTrades('bullish', ['block_market' => 'bearish']))->toHaveCount(1)
        ->and($marketTrades('bullish', ['require_market' => 'bullish']))->toHaveCount(1);
});

test('the open position limit skips trades until a slot is free', function () {
    $trade = fn (string $token, int $at, int $closed): array => ['token' => $token, 'signal_at' => $at, 'closed_at' => $closed, 'net_r' => 1.0];

    $kept = Backtester::limitOpenPositions([$trade('A', 1, 10), $trade('B', 2, 5), $trade('C', 3, 8), $trade('D', 6, 9), $trade('E', 12, 13)], 2);

    expect(array_column($kept, 'token'))->toBe(['A', 'B', 'D', 'E']);
});
