<?php

use App\Jobs\AnalyzeToken;
use App\Jobs\DiscoverTokens;
use App\Jobs\SendTelegramMessage;
use App\Jobs\SyncTokenCandles;
use App\Jobs\SyncTokenWhaleTrades;
use App\Models\Token;
use App\Services\MarketData\GeckoTerminalBusyException;
use App\Services\MarketData\GeckoTerminalClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Carbon::setTestNow(Carbon::parse('2026-10-29 12:00:00.000'));
    config(['market.geckoterminal_requests_per_minute' => 10]);
});

function ohlcvResponse(): array
{
    return ['data' => ['attributes' => ['ohlcv_list' => [[1_761_739_200, 1, 1.1, 0.9, 1.05, 1000]]]]];
}

test('requests are spaced evenly instead of bursting the whole minute budget', function () {
    Http::fake(['api.geckoterminal.com/*' => Http::response(ohlcvResponse())]);
    $client = app(GeckoTerminalClient::class);

    foreach (range(1, 3) as $i) {
        $client->ohlcv('solana', 'pool', 'token', '1h');
    }

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
    expect(now()->diffInSeconds(Carbon::parse('2026-10-29 12:00:00.000')))->toEqualWithDelta(-12, 0.01);
});

test('a 429 pauses every request, is logged, and the request is retried', function () {
    Log::spy();
    Http::fake(['api.geckoterminal.com/*' => Http::sequence()
        ->push([], 429)
        ->push(ohlcvResponse())]);

    $candles = app(GeckoTerminalClient::class)->ohlcv('solana', 'pool', 'token', '1h');

    expect($candles)->toHaveCount(1);
    Http::assertSentCount(2);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'GeckoTerminal 429') && str_contains($message, '20 detik'));
});

test('jobs that call geckoterminal run on their own queue, the rest stay on default', function () {
    Queue::fake();
    $token = Token::factory()->create();

    SyncTokenCandles::dispatch($token, ['1h']);
    SyncTokenWhaleTrades::dispatch($token);
    DiscoverTokens::dispatch();
    AnalyzeToken::dispatch($token);
    SendTelegramMessage::dispatch('42', 'hi');

    Queue::assertPushedOn('market-data', SyncTokenCandles::class);
    Queue::assertPushedOn('market-data', SyncTokenWhaleTrades::class);
    Queue::assertPushedOn('market-data', DiscoverTokens::class);
    Queue::assertPushed(AnalyzeToken::class, fn (AnalyzeToken $job): bool => $job->queue === null);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->queue === null);
});

test('a price check takes the next slot ahead of the syncs, keeps the spacing, and gives up rather than wait too long', function () {
    $pool = ['data' => [
        'relationships' => ['base_token' => ['data' => ['id' => 'solana_token']], 'quote_token' => ['data' => ['id' => 'solana_sol']]],
        'attributes' => ['base_token_price_usd' => '0.5', 'quote_token_price_usd' => '160'],
    ]];
    Http::fake(['api.geckoterminal.com/*/ohlcv/*' => Http::response(ohlcvResponse()), 'api.geckoterminal.com/*' => Http::response($pool)]);
    $client = app(GeckoTerminalClient::class);
    $start = now()->copy();

    $client->ohlcv('solana', 'pool', 'token', '1h');
    expect($client->poolTokenPrice('solana', 'pool', 'token', 8))->toEqual(0.5)
        ->and($start->diffInSeconds(now()))->toEqualWithDelta(6, 0.01);

    // The next sync waits a full interval after the price check.
    $client->ohlcv('solana', 'pool', 'token', '1h');
    expect($start->diffInSeconds(now()))->toEqualWithDelta(12, 0.01);

    expect(fn () => $client->poolTokenPrice('solana', 'pool', 'token', 2))->toThrow(GeckoTerminalBusyException::class);
    Http::assertSentCount(3);
});
