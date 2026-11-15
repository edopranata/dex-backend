<?php

use App\Jobs\SyncTokenCandles;
use App\Models\Candle;
use App\Models\Token;
use App\Services\Analysis\BtcRelation;
use App\Services\Analysis\MarketContext;
use App\Services\MarketData\BenchmarkTokens;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::fake(['api.alternative.me/*' => Http::response(['data' => [['value' => '20', 'value_classification' => 'Fear', 'timestamp' => '0']]])]);
    Http::preventStrayRequests();
    $this->freezeTime();
});

/**
 * Hourly 1h candles ending with the last closed hour, built from a list of hourly log returns.
 *
 * @param  list<float>  $returns
 */
function hourlyCandles(Token $token, array $returns, float $start = 100.0): void
{
    $close = $start;
    $first = now()->startOfHour()->timestamp - 3600 * count($returns);

    Candle::factory()->for($token)->create(['timeframe' => '1h', 'open_time' => $first - 3600, 'close' => $close]);

    foreach ($returns as $i => $return) {
        $close *= exp($return);
        Candle::factory()->for($token)->create(['timeframe' => '1h', 'open_time' => $first + $i * 3600, 'close' => $close]);
    }
}

/**
 * @return list<float>
 */
function randomReturns(int $seed, int $hours = 72): array
{
    mt_srand($seed);

    return array_map(fn (): float => (mt_rand(0, 2000) - 1000) / 100_000, range(1, $hours));
}

test('market:candles keeps the benchmarks current with the Market and creates the BTC token once', function () {
    Queue::fake();
    $inMarket = Token::factory()->create();
    $sol = Token::factory()->create(['chain_id' => 'solana', 'symbol' => 'SOL', 'is_active' => false]);

    $this->artisan('market:candles')->assertSuccessful();
    $this->artisan('market:candles')->assertSuccessful();

    $btc = Token::query()->where('source', 'benchmark')->sole();
    $queued = collect(Queue::pushed(SyncTokenCandles::class))->map(fn (SyncTokenCandles $job): int => $job->token->id);

    expect($btc)->symbol->toBe('cbBTC')->chain_id->toBe('base')->is_active->toBeFalse()
        ->and($btc->pair_address)->toBe(config('market.benchmarks.BTC.pair_address'))
        ->and($queued->unique()->sort()->values()->all())->toBe(collect([$inMarket->id, $sol->id, $btc->id])->sort()->values()->all());
});

test('the market context reports BTC from its candles and leaves a stale benchmark unknown', function () {
    $btc = app(BenchmarkTokens::class)->ensure()['BTC'];
    $hour = now()->startOfHour()->timestamp;
    Candle::factory()->for($btc)->create(['timeframe' => '1h', 'open_time' => $hour - 86400, 'close' => 80_000.0]);
    Candle::factory()->for($btc)->create(['timeframe' => '1h', 'open_time' => $hour, 'close' => 76_000.0]);
    $eth = Token::factory()->create(['chain_id' => 'ethereum', 'symbol' => 'WETH', 'is_active' => false]);
    Candle::factory()->for($eth)->create(['timeframe' => '1h', 'open_time' => $hour - 5 * 3600, 'close' => 3_000.0]);

    $benchmarks = app(MarketContext::class)->snapshot()['benchmarks'];

    expect($benchmarks['BTC'])->toMatchArray(['price' => 76_000.0, 'change_24h' => -5.0])
        ->and($benchmarks['ETH']['price'])->toBeNull();
});

test('a token that moves with BTC has a high correlation and its beta, one that moves alone a low one', function () {
    $btcReturns = randomReturns(7);
    hourlyCandles(app(BenchmarkTokens::class)->ensure()['BTC'], $btcReturns, 80_000.0);
    $follower = Token::factory()->create();
    hourlyCandles($follower, array_map(fn (float $r): float => 2 * $r, $btcReturns));
    $loner = Token::factory()->create();
    hourlyCandles($loner, randomReturns(99));
    $young = Token::factory()->create();
    hourlyCandles($young, array_slice($btcReturns, -10));

    $relation = app(BtcRelation::class);
    $followed = $relation->measure($follower);

    expect($followed['corr'])->toEqual(1.0)
        ->and($followed['beta'])->toEqual(2.0)
        ->and($followed['hours'])->toBe(72)
        ->and($followed['relative_24h'])->toEqual(round($followed['token_change_24h'] - $followed['btc_change_24h'], 2))
        ->and(abs($relation->measure($loner)['corr']))->toBeLessThan(0.3)
        ->and($relation->measure($young))->toBeNull()
        ->and($relation->measure(app(BenchmarkTokens::class)->find('BTC')))->toBeNull();
});
