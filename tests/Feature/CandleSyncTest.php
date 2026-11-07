<?php

use App\Jobs\SyncTokenCandles;
use App\Models\Candle;
use App\Models\Token;
use App\Services\MarketData\CandleSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Carbon::setTestNow(Carbon::parse('2026-11-08 10:20:00', 'UTC'));
    config(['market.geckoterminal_requests_per_minute' => 600]);
    $this->token = Token::factory()->create();
    $this->responses = [];

    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $period = basename((string) parse_url($request->url(), PHP_URL_PATH));
        $key = $period === 'hour' ? "hour{$query['aggregate']}" : $period;
        $rows = array_reverse($this->responses[$key] ?? []);

        return Http::response(['data' => ['attributes' => ['ohlcv_list' => $rows]]]);
    });
});

/**
 * Stored candles: one row per open time, all at 1.0 with volume 1 unless given.
 *
 * @param  list<int>  $times
 */
function storeCandles(Token $token, string $timeframe, array $times, float $close = 1.0): void
{
    Candle::query()->insert(array_map(fn (int $t): array => ['token_id' => $token->id, 'timeframe' => $timeframe, 'open_time' => $t, 'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'volume' => 1], $times));
}

/**
 * @return list<int>
 */
function quarters(string $from, string $until): array
{
    return range(Carbon::parse($from, 'UTC')->getTimestamp(), Carbon::parse($until, 'UTC')->getTimestamp(), 900);
}

function at(string $moment): int
{
    return Carbon::parse($moment, 'UTC')->getTimestamp();
}

function candleAt(Token $token, string $timeframe, string $moment): ?Candle
{
    return $token->candles()->where('timeframe', $timeframe)->where('open_time', at($moment))->first();
}

/**
 * @return list<array{period: string, limit: int}>
 */
function geckoRequests(): array
{
    return Http::recorded()->map(function (array $pair): array {
        parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);
        $period = basename((string) parse_url($pair[0]->url(), PHP_URL_PATH));

        return ['period' => $period === 'hour' ? "hour{$query['aggregate']}" : $period, 'limit' => (int) $query['limit']];
    })->values()->all();
}

test('a token with its history only fetches 15m, and 1h, 4h and 1d are built from it', function () {
    storeCandles($this->token, '15m', quarters('2026-11-08 00:00', '2026-11-08 09:45'));
    storeCandles($this->token, '1h', [at('2026-11-08 09:00')], 99);
    storeCandles($this->token, '4h', [at('2026-11-08 08:00')], 99);
    storeCandles($this->token, '1d', [at('2026-11-08 00:00')], 99);
    $this->responses['minute'] = [
        [at('2026-11-08 10:00'), 1.0, 2.0, 0.5, 1.5, 10],
        [at('2026-11-08 10:15'), 1.5, 3.0, 1.2, 2.5, 5],
    ];

    $result = app(CandleSyncService::class)->refresh($this->token);

    expect(geckoRequests())->toBe([['period' => 'minute', 'limit' => 96]])
        ->and($result['requests'])->toBe(1);

    $hour = candleAt($this->token, '1h', '2026-11-08 10:00');
    expect([$hour->open, $hour->high, $hour->low, $hour->close, $hour->volume])->toEqual([1.0, 3.0, 0.5, 2.5, 15.0]);

    // 08:00-09:45 stored at 1.0 replace GeckoTerminal's 99 for the hour that is fully covered.
    $nine = candleAt($this->token, '1h', '2026-11-08 09:00');
    expect([$nine->open, $nine->close, $nine->volume])->toEqual([1.0, 1.0, 4.0]);

    $four = candleAt($this->token, '4h', '2026-11-08 08:00');
    expect([$four->open, $four->high, $four->low, $four->close, $four->volume])->toEqual([1.0, 3.0, 0.5, 2.5, 23.0]);

    $day = candleAt($this->token, '1d', '2026-11-08 00:00');
    expect([$day->open, $day->high, $day->low, $day->close, $day->volume])->toEqual([1.0, 3.0, 0.5, 2.5, 55.0]);
});

test('a new token gets the higher timeframes once and at most 7 days of 15m, keeping partly covered periods', function () {
    $this->responses['hour1'] = [[at('2026-11-01 10:00'), 99, 99, 99, 99, 1]];
    $this->responses['hour4'] = [[at('2026-11-01 08:00'), 99, 99, 99, 99, 1]];
    $this->responses['day'] = [[at('2026-11-01 00:00'), 99, 99, 99, 99, 1]];
    $this->responses['minute'] = array_map(fn (int $t): array => [$t, 1.0, 1.0, 1.0, 1.0, 1], quarters('2026-11-01 10:30', '2026-11-08 10:15'));

    app(CandleSyncService::class)->refresh($this->token);

    expect(geckoRequests())->toBe([
        ['period' => 'hour1', 'limit' => 300],
        ['period' => 'hour4', 'limit' => 300],
        ['period' => 'day', 'limit' => 300],
        ['period' => 'minute', 'limit' => 672],
    ]);

    // The 15m data starts at 10:30, so the hour, the 4 hours and the day it starts in keep GeckoTerminal's candle.
    expect(candleAt($this->token, '1h', '2026-11-01 10:00')->close)->toEqual(99.0)
        ->and(candleAt($this->token, '4h', '2026-11-01 08:00')->close)->toEqual(99.0)
        ->and(candleAt($this->token, '1d', '2026-11-01 00:00')->close)->toEqual(99.0)
        // Whole periods after it are built.
        ->and(candleAt($this->token, '1h', '2026-11-01 11:00')->volume)->toEqual(4.0)
        ->and(candleAt($this->token, '4h', '2026-11-01 12:00')->volume)->toEqual(16.0)
        ->and(candleAt($this->token, '1d', '2026-11-02 00:00')->volume)->toEqual(96.0);
});

test('a token back after three days fills the gap with one 15m request', function () {
    storeCandles($this->token, '15m', quarters('2026-11-04 00:00', '2026-11-05 10:00'));
    storeCandles($this->token, '1h', [at('2026-11-05 10:00')]);
    storeCandles($this->token, '4h', [at('2026-11-05 08:00')]);
    storeCandles($this->token, '1d', [at('2026-11-05 00:00')]);
    $this->responses['minute'] = array_map(fn (int $t): array => [$t, 2.0, 2.0, 2.0, 2.0, 1], quarters('2026-11-05 10:15', '2026-11-08 10:15'));

    app(CandleSyncService::class)->refresh($this->token);

    // 3 days and 20 minutes behind: 289 periods plus two to overlap.
    expect(geckoRequests())->toBe([['period' => 'minute', 'limit' => 291]])
        ->and(candleAt($this->token, '1d', '2026-11-06 00:00')->volume)->toEqual(96.0)
        ->and(candleAt($this->token, '1h', '2026-11-07 15:00')->close)->toEqual(2.0);
});

test('a gap longer than the 15m backfill fetches the higher timeframes again', function () {
    storeCandles($this->token, '15m', [at('2026-10-28 09:45')]);
    storeCandles($this->token, '1h', [at('2026-10-28 09:00')]);
    storeCandles($this->token, '4h', [at('2026-10-28 08:00')]);
    storeCandles($this->token, '1d', [at('2026-10-28 00:00')]);

    app(CandleSyncService::class)->refresh($this->token);

    expect(collect(geckoRequests())->pluck('period')->all())->toBe(['hour1', 'hour4', 'day', 'minute'])
        ->and(geckoRequests()[3]['limit'])->toBe(672);
});

test('15m candles are kept for 90 days, the rest for good', function () {
    storeCandles($this->token, '15m', [now()->subDays(60)->getTimestamp(), now()->subDays(100)->getTimestamp()]);
    storeCandles($this->token, '1h', [now()->subDays(100)->getTimestamp()]);

    $this->artisan('model:prune', ['--model' => Candle::class])->assertSuccessful();

    expect($this->token->candles()->where('timeframe', '15m')->count())->toBe(1)
        ->and($this->token->candles()->where('timeframe', '1h')->count())->toBe(1);
});

test('one candle run every 15 minutes replaces the separate 1h, 4h and 1d runs, and the rest take turns hourly', function () {
    $commands = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'market:candles'))
        ->mapWithKeys(fn ($event): array => [trim(substr((string) $event->command, strpos((string) $event->command, 'market:candles'))) => $event->expression]);

    expect($commands->all())->toBe([
        'market:candles' => '0,15,30,45 * * * *',
        'market:candles --out-of-market' => '20 * * * *',
    ]);
});

test('a fill by hand asks for the most candles one request gives, whatever the gap', function () {
    storeCandles($this->token, '15m', quarters('2026-11-08 09:00', '2026-11-08 10:00'));
    storeCandles($this->token, '1h', [at('2026-11-08 09:00')]);
    storeCandles($this->token, '4h', [at('2026-11-08 08:00')]);
    storeCandles($this->token, '1d', [at('2026-11-08 00:00')]);

    app(CandleSyncService::class)->refresh($this->token, 5000);

    expect(geckoRequests())->toBe([['period' => 'minute', 'limit' => 1000]]);
});

test('a candle job queued before the 15m fill size existed still runs', function () {
    // What unserializing an older payload gives: the constructor never runs, so only declared defaults exist.
    $job = (new ReflectionClass(SyncTokenCandles::class))->newInstanceWithoutConstructor();
    $job->token = $this->token;
    $job->timeframes = [];
    storeCandles($this->token, '15m', quarters('2026-11-08 09:00', '2026-11-08 10:00'));
    storeCandles($this->token, '1h', [at('2026-11-08 09:00')]);
    storeCandles($this->token, '4h', [at('2026-11-08 08:00')]);
    storeCandles($this->token, '1d', [at('2026-11-08 00:00')]);

    expect($job->uniqueId())->toBe("{$this->token->id}:refresh");

    $job->handle(app(CandleSyncService::class));

    expect(geckoRequests())->toBe([['period' => 'minute', 'limit' => 96]]);
});
