<?php

use App\Jobs\AnalyzeToken;
use App\Jobs\DiscoverTokens;
use App\Jobs\SendTelegramMessage;
use App\Jobs\SyncTokenCandles;
use App\Jobs\SyncTokenWhaleTrades;
use App\Models\Token;
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
