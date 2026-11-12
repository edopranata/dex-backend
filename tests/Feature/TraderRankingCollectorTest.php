<?php

use App\Jobs\RunAdminCommand;
use App\Models\TraderRanking;
use App\Services\Analysis\TraderRankingCollector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config([
        'market.birdeye.key' => 'birdeye-key',
        'market.radar.chains' => ['solana', 'bsc'],
        'market.radar.lists' => [['period' => '1W', 'sort_by' => 'realized_pnl'], ['period' => '30d', 'sort_by' => 'trader_score']],
    ]);
});

function fakeBirdeyeTraders(): void
{
    Http::fake(['public-api.birdeye.so/trader/gainers-losers*' => fn (Request $request) => Http::response(['success' => true, 'data' => ['items' => [
        ['network' => $request->header('x-chain')[0], 'address' => 'WalletA', 'pnl' => 900.129, 'realized_pnl' => 500.5, 'unrealized_pnl' => 399.629, 'volume' => 12000, 'trade_count' => 40, 'score' => 88.456],
        ['network' => $request->header('x-chain')[0], 'address' => 'WalletB', 'pnl' => 1e30, 'realized_pnl' => 100, 'unrealized_pnl' => 0, 'volume' => 3000, 'trade_count' => 7],
    ]]])]);
}

test('each list of every chain is stored once a day with its rank, without doubling on a second run', function () {
    fakeBirdeyeTraders();

    $result = app(TraderRankingCollector::class)->run();
    app(TraderRankingCollector::class)->run();

    $first = TraderRanking::query()->where('chain_id', 'bsc')->where('period', '30d')->where('wallet', 'WalletA')->sole();

    expect($result)->toBe(['skipped' => null, 'lists' => 4, 'wallets' => 8, 'failed' => 0])
        ->and(TraderRanking::count())->toBe(8)
        ->and($first->only(['source', 'sort_by', 'list_rank', 'pnl_usd', 'realized_pnl_usd', 'trade_count', 'score']))
        ->toBe(['source' => 'birdeye', 'sort_by' => 'trader_score', 'list_rank' => 1, 'pnl_usd' => 900.13, 'realized_pnl_usd' => 500.5, 'trade_count' => 40, 'score' => 88.46])
        ->and($first->seen_on->toDateString())->toBe(now()->toDateString())
        ->and(TraderRanking::query()->where('wallet', 'WalletB')->first()->pnl_usd)->toBeNull();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-API-KEY', 'birdeye-key')
        && $request->hasHeader('x-chain', 'solana')
        && $request['type'] === '1W' && $request['sort_by'] === 'realized_pnl' && $request['sort_type'] === 'desc' && (int) $request['limit'] === 100);
    Sleep::assertSleptTimes(8);
});

test('nothing is requested without a key or while switched off', function (array $config, string $reason) {
    config($config);
    Http::fake();

    expect(app(TraderRankingCollector::class)->run()['skipped'])->toBe($reason)
        ->and(TraderRanking::count())->toBe(0);

    Http::assertNothingSent();
})->with([
    'no key' => [['market.birdeye.key' => null], 'no_key'],
    'switched off' => [['market.radar.enabled' => false], 'disabled'],
]);

test('requests stop at the monthly budget', function () {
    fakeBirdeyeTraders();
    config(['market.radar.monthly_request_budget' => 3]);

    $result = app(TraderRankingCollector::class)->run();

    expect($result)->toMatchArray(['skipped' => 'budget', 'lists' => 3])
        ->and((int) Cache::get('radar:birdeye:requests:'.now()->format('Y-m')))->toBe(3);

    Http::assertSentCount(3);
});

test('a failing list is counted and the others are still stored', function () {
    Http::fake(['public-api.birdeye.so/*' => Http::sequence()
        ->push('busy', 503)
        ->push(['success' => true, 'data' => ['items' => [['address' => 'WalletA', 'pnl' => 1, 'realized_pnl' => 1, 'unrealized_pnl' => 0, 'volume' => 10, 'trade_count' => 2]]]])
        ->whenEmpty(Http::response(['success' => true, 'data' => ['items' => []]])),
    ]);

    expect(app(TraderRankingCollector::class)->run())->toMatchArray(['lists' => 3, 'wallets' => 1, 'failed' => 1]);
});

test('the command reports a missing key and the task can be run from the admin panel', function () {
    config(['market.birdeye.key' => null]);

    $this->artisan('market:trader-rankings')->expectsOutputToContain('Birdeye API key belum diisi')->assertSuccessful();

    expect(RunAdminCommand::ALLOWED)->toHaveKey('market:trader-rankings');
});
