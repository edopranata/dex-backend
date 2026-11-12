<?php

use App\Enums\CandidateStatus;
use App\Enums\WatchlistAction;
use App\Models\Analysis;
use App\Models\DiscoverySighting;
use App\Models\Token;
use App\Models\TokenCandidate;
use App\Models\User;
use App\Services\Analysis\TokenDiscoveryService;
use App\Services\Analysis\WatchlistManager;
use App\Services\MarketData\TokenRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, mixed>
 */
function dexPair(string $pair, string $token, string $symbol, array $overrides = []): array
{
    return array_replace_recursive([
        'chainId' => 'solana',
        'dexId' => 'raydium',
        'url' => "https://dexscreener.com/solana/{$pair}",
        'pairAddress' => $pair,
        'baseToken' => ['address' => $token, 'name' => $symbol, 'symbol' => $symbol],
        'quoteToken' => ['address' => 'So11111111111111111111111111111111111111112', 'symbol' => 'SOL'],
        'priceUsd' => '0.05',
        'priceChange' => ['m5' => 0.5, 'h1' => 2, 'h6' => 8, 'h24' => 25],
        'volume' => ['h24' => 900_000],
        'liquidity' => ['usd' => 400_000],
        'txns' => ['h24' => ['buys' => 3000, 'sells' => 2000]],
        'fdv' => 5_000_000,
        'pairCreatedAt' => now()->subDays(20)->getTimestampMs(),
    ], $overrides);
}

/**
 * @param  array<string, array{pair: array<string, mixed>, trades: list<array{side: string, usd: float, wallet: string}>, security: array<string, mixed>}>  $tokens
 * @param  list<array<string, mixed>>  $boosts
 */
function fakeDiscoveryApis(array $tokens, array $boosts = []): void
{
    Http::fake(function (Request $request) use ($tokens, $boosts) {
        $url = $request->url();

        if (str_contains($url, '/trending_pools')) {
            $data = [];
            $included = [];

            foreach ($tokens as $item) {
                if (! ($item['trending'] ?? true)) {
                    continue;
                }

                $pair = $item['pair'];
                $data[] = [
                    'attributes' => ['address' => $pair['pairAddress'], 'transactions' => ['h24' => ['buyers' => 900, 'sellers' => 500]]],
                    'relationships' => ['base_token' => ['data' => ['id' => 'solana_'.$pair['baseToken']['address']]]],
                ];
                $included[] = ['id' => 'solana_'.$pair['baseToken']['address'], 'attributes' => [
                    'address' => $pair['baseToken']['address'], 'symbol' => $pair['baseToken']['symbol'], 'name' => $pair['baseToken']['name'],
                ]];
            }

            return Http::response(['data' => $data, 'included' => $included]);
        }

        if (str_contains($url, '/trades')) {
            foreach ($tokens as $item) {
                if (str_contains($url, $item['pair']['pairAddress'])) {
                    $token = $item['pair']['baseToken']['address'];

                    return Http::response(['data' => array_map(fn (array $t, int $i): array => [
                        'id' => "trade-{$token}-{$i}",
                        'attributes' => [
                            'tx_hash' => "hash{$i}",
                            'tx_from_address' => $t['wallet'],
                            'from_token_address' => $t['side'] === 'buy' ? 'So11111111111111111111111111111111111111112' : $token,
                            'to_token_address' => $t['side'] === 'buy' ? $token : 'So11111111111111111111111111111111111111112',
                            'volume_in_usd' => (string) $t['usd'],
                            'block_timestamp' => now()->toIso8601String(),
                        ],
                    ], $item['trades'], array_keys($item['trades']))]);
                }
            }

            return Http::response(['data' => []]);
        }

        if (str_contains($url, 'api.dexscreener.com/latest/dex/pairs/')) {
            $pairs = array_values(array_filter(array_column($tokens, 'pair'), fn (array $p): bool => str_contains($url, $p['pairAddress'])));

            return Http::response(['pairs' => $pairs]);
        }

        if (str_contains($url, 'api.dexscreener.com/tokens/v1/')) {
            return Http::response(array_values(array_filter(array_column($tokens, 'pair'), fn (array $p): bool => str_contains($url, $p['baseToken']['address']))));
        }

        if (str_contains($url, 'token-boosts')) {
            return Http::response($boosts);
        }

        if (str_contains($url, 'api.coingecko.com')) {
            return Http::response(['coins' => []]);
        }

        if (str_contains($url, 'gopluslabs')) {
            foreach ($tokens as $item) {
                $address = $item['pair']['baseToken']['address'];

                if (str_contains($url, $address)) {
                    return Http::response(['code' => 1, 'result' => [$address => $item['security']]]);
                }
            }
        }

        return Http::response([], 404);
    });
}

beforeEach(function () {
    Bus::fake();
    config([
        'market.discovery.networks' => ['solana'],
        'market.discovery.use_coingecko_trending' => true,
        'market.discovery.auto_track' => true,
    ]);
});

test('trend score rewards a healthy uptrend over a parabolic pump', function () {
    $healthy = TokenDiscoveryService::trendScore(['h1' => 1, 'h6' => 5, 'h24' => 25, 'buys' => 600, 'sells' => 400, 'buyers' => 300, 'sellers' => 200, 'volume' => 1_000_000, 'liquidity' => 500_000]);
    $parabolic = TokenDiscoveryService::trendScore(['h1' => 1, 'h6' => 5, 'h24' => 900, 'buys' => 600, 'sells' => 400, 'buyers' => 300, 'sellers' => 200, 'volume' => 1_000_000, 'liquidity' => 500_000]);

    expect($healthy['score'])->toBeGreaterThan($parabolic['score']);
});

test('whale score is below neutral without whales and high under accumulation', function () {
    expect(TokenDiscoveryService::whaleScore(0, 0, 0))->toBe(40)
        ->and(TokenDiscoveryService::whaleScore(90_000, 10_000, 4))->toBeGreaterThan(80)
        ->and(TokenDiscoveryService::whaleScore(10_000, 90_000, 0))->toBeLessThan(20);
});

test('discovery tracks a safe trending token with whale accumulation', function () {
    fakeDiscoveryApis([
        'GOOD' => [
            'pair' => dexPair('PairGood1111111111111111111111111111111111', 'TokenGood111111111111111111111111111111111', 'GOOD'),
            'trades' => [
                ['side' => 'buy', 'usd' => 20_000, 'wallet' => 'w1'],
                ['side' => 'buy', 'usd' => 15_000, 'wallet' => 'w2'],
                ['side' => 'buy', 'usd' => 12_000, 'wallet' => 'w3'],
                ['side' => 'sell', 'usd' => 9_000, 'wallet' => 'w4'],
            ],
            'security' => ['mintable' => ['status' => '0'], 'freezable' => ['status' => '0'], 'holder_count' => '25000'],
        ],
    ]);

    $result = app(TokenDiscoveryService::class)->run();
    $candidate = TokenCandidate::firstWhere('symbol', 'GOOD');

    expect($result['added'])->toBe(['GOOD'])
        ->and($candidate->status)->toBe(CandidateStatus::Tracked)
        ->and($candidate->whale_buy_wallets)->toBe(3)
        ->and($candidate->discovery_score)->toBeGreaterThanOrEqual(65)
        ->and(Token::firstWhere('symbol', 'GOOD')->source)->toBe('discovery')
        ->and(Token::firstWhere('symbol', 'GOOD')->is_pinned)->toBeFalse()
        ->and(Token::firstWhere('symbol', 'GOOD')->watchlistEvents()->first()->reason)->toContain('Ditemukan otomatis');
});

test('young pairs, unsafe contracts and whale distribution are not tracked', function () {
    fakeDiscoveryApis([
        'NEW' => [
            'pair' => dexPair('PairNew11111111111111111111111111111111111', 'TokenNew1111111111111111111111111111111111', 'NEW', ['pairCreatedAt' => now()->subHours(3)->getTimestampMs()]),
            'trades' => [],
            'security' => [],
        ],
        'RUG' => [
            'pair' => dexPair('PairRug11111111111111111111111111111111111', 'TokenRug1111111111111111111111111111111111', 'RUG'),
            'trades' => [['side' => 'buy', 'usd' => 50_000, 'wallet' => 'w1']],
            'security' => ['balance_mutable_authority' => ['status' => '1'], 'freezable' => ['status' => '1'], 'mintable' => ['status' => '1']],
        ],
        'DUMP' => [
            'pair' => dexPair('PairDump1111111111111111111111111111111111', 'TokenDump111111111111111111111111111111111', 'DUMP'),
            'trades' => [['side' => 'sell', 'usd' => 80_000, 'wallet' => 'w1'], ['side' => 'buy', 'usd' => 5_000, 'wallet' => 'w2']],
            'security' => ['holder_count' => '25000'],
        ],
    ]);

    $result = app(TokenDiscoveryService::class)->run();

    expect($result['added'])->toBe([])
        ->and(TokenCandidate::firstWhere('symbol', 'NEW')->status)->toBe(CandidateStatus::Rejected)
        ->and(TokenCandidate::firstWhere('symbol', 'RUG')->status)->toBe(CandidateStatus::Rejected)
        ->and(TokenCandidate::firstWhere('symbol', 'DUMP')->whale_score)->toBeLessThan(50)
        ->and(Token::count())->toBe(0);
});

test('paid boosts alone never trigger auto-tracking', function () {
    $pair = dexPair('PairAd111111111111111111111111111111111111', 'TokenAd11111111111111111111111111111111111', 'ADS');

    fakeDiscoveryApis([
        'ADS' => [
            'pair' => $pair,
            'trending' => false,
            'trades' => [['side' => 'buy', 'usd' => 40_000, 'wallet' => 'w1'], ['side' => 'buy', 'usd' => 30_000, 'wallet' => 'w2'], ['side' => 'buy', 'usd' => 20_000, 'wallet' => 'w3']],
            'security' => ['holder_count' => '25000'],
        ],
    ], boosts: [['chainId' => 'solana', 'tokenAddress' => $pair['baseToken']['address']]]);

    $result = app(TokenDiscoveryService::class)->run();

    expect($result['added'])->toBe([])
        ->and(TokenCandidate::firstWhere('symbol', 'ADS')->sources)->toBe(['dexscreener_boost']);
});

test('discovery api lists candidates and rejects tracking a rejected one', function () {
    $user = User::factory()->create();
    TokenCandidate::factory()->create(['symbol' => 'TOP', 'discovery_score' => 80]);
    $rejected = TokenCandidate::factory()->create(['status' => CandidateStatus::Rejected]);

    $this->actingAs($user)->getJson('/api/discovery')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.symbol', 'TOP');

    $this->actingAs($user)->postJson("/api/discovery/{$rejected->id}/track")->assertUnprocessable();
});

test('a full market replaces its weakest unpinned token with a strong discovery', function () {
    config(['market.simulator.max_tokens' => 2]);

    Token::factory()->create(['is_pinned' => true]);
    $weak = Token::factory()->create(['is_pinned' => false, 'source' => 'discovery']);
    Analysis::factory()->for($weak)->create(['final_score' => 35, 'recommendation' => 'HOLD']);

    fakeDiscoveryApis([
        'GOOD' => [
            'pair' => dexPair('PairGood1111111111111111111111111111111111', 'TokenGood111111111111111111111111111111111', 'GOOD'),
            'trades' => [
                ['side' => 'buy', 'usd' => 20_000, 'wallet' => 'w1'],
                ['side' => 'buy', 'usd' => 15_000, 'wallet' => 'w2'],
                ['side' => 'buy', 'usd' => 12_000, 'wallet' => 'w3'],
            ],
            'security' => ['holder_count' => '25000'],
        ],
    ]);

    $result = app(TokenDiscoveryService::class)->run();

    expect($result['replaced'])->toBe([$weak->symbol])
        ->and($result['added'])->toBe(['GOOD'])
        ->and($weak->refresh()->is_active)->toBeFalse()
        ->and(Token::query()->active()->count())->toBe(2);
});

test('the reason says net selling when whales sold more than they bought', function () {
    $reason = fn (float $buy, float $sell): string => (new ReflectionMethod(TokenDiscoveryService::class, 'additionReason'))->invoke(
        app(TokenDiscoveryService::class),
        new TokenCandidate(['discovery_score' => 65, 'trend_score' => 85, 'whale_score' => 54, 'security_score' => 75, 'whale_buy_usd' => $buy, 'whale_sell_usd' => $sell, 'price_change_h24' => 3.3]),
    );

    expect($reason(10_000, 139_640))->toContain('whale net jual $129,640')
        ->and($reason(31_422, 0))->toContain('whale net beli $31,422');
});

test('a token that just came in is not thrown out by the next candidate of the same round', function () {
    config(['market.simulator.max_tokens' => 2, 'market.discovery.max_new_per_run' => 3]);

    Token::factory()->create(['is_pinned' => true]);
    $weak = Token::factory()->create(['is_pinned' => false, 'source' => 'discovery']);
    Analysis::factory()->for($weak)->create(['final_score' => 35, 'recommendation' => 'HOLD']);

    $trades = [['side' => 'buy', 'usd' => 20_000, 'wallet' => 'w1'], ['side' => 'buy', 'usd' => 15_000, 'wallet' => 'w2'], ['side' => 'buy', 'usd' => 12_000, 'wallet' => 'w3']];

    fakeDiscoveryApis([
        'GOOD' => ['pair' => dexPair('PairGood1111111111111111111111111111111111', 'TokenGood111111111111111111111111111111111', 'GOOD'), 'trades' => $trades, 'security' => ['holder_count' => '25000']],
        'NEXT' => ['pair' => dexPair('PairNext1111111111111111111111111111111111', 'TokenNext111111111111111111111111111111111', 'NEXT'), 'trades' => $trades, 'security' => ['holder_count' => '25000']],
    ]);

    $result = app(TokenDiscoveryService::class)->run();

    expect($result['added'])->toHaveCount(1)
        ->and($result['replaced'])->toBe([$weak->symbol])
        ->and(Token::query()->active()->where('symbol', $result['added'][0])->exists())->toBeTrue();

    $this->assertDatabaseMissing('watchlist_events', ['action' => 'removed', 'token_id' => Token::query()->where('symbol', $result['added'][0])->value('id')]);
});

test('after the grace period a weak newcomer can be replaced again', function () {
    config(['market.simulator.max_tokens' => 1, 'market.watchlist.replace_grace_hours' => 24]);
    $token = Token::factory()->create(['is_pinned' => false, 'source' => 'discovery']);
    Analysis::factory()->for($token)->create(['final_score' => 35, 'recommendation' => 'HOLD']);
    app(WatchlistManager::class)->log($token, WatchlistAction::Added, 'Masuk.');

    expect(app(WatchlistManager::class)->replaceable())->toBeNull();

    $this->travel(25)->hours();

    expect(app(WatchlistManager::class)->replaceable()?->id)->toBe($token->id);
});

test('a full market of strong or pinned tokens accepts no replacement', function () {
    config(['market.simulator.max_tokens' => 1]);
    Token::factory()->create(['is_pinned' => true]);

    fakeDiscoveryApis([
        'GOOD' => [
            'pair' => dexPair('PairGood1111111111111111111111111111111111', 'TokenGood111111111111111111111111111111111', 'GOOD'),
            'trades' => [['side' => 'buy', 'usd' => 20_000, 'wallet' => 'w1'], ['side' => 'buy', 'usd' => 15_000, 'wallet' => 'w2'], ['side' => 'buy', 'usd' => 12_000, 'wallet' => 'w3']],
            'security' => ['holder_count' => '25000'],
        ],
    ]);

    expect(app(TokenDiscoveryService::class)->run()['added'])->toBe([]);
});

test('a pool with broken numbers is skipped without stopping the discovery round', function () {
    $trades = [['side' => 'buy', 'usd' => 20_000, 'wallet' => 'w1'], ['side' => 'buy', 'usd' => 15_000, 'wallet' => 'w2'], ['side' => 'buy', 'usd' => 12_000, 'wallet' => 'w3']];

    fakeDiscoveryApis([
        'SOPH' => [
            'pair' => dexPair('PairBroken11111111111111111111111111111111', 'TokenBroken1111111111111111111111111111111', 'SOPH', [
                'priceUsd' => '276981337021740000000000000',
                'priceChange' => ['h1' => 1.8e31, 'h6' => 2e31, 'h24' => 2e31],
                'liquidity' => ['usd' => 0],
            ]),
            'trades' => [],
        ],
        'GOOD' => [
            'pair' => dexPair('PairGood1111111111111111111111111111111111', 'TokenGood111111111111111111111111111111111', 'GOOD'),
            'trades' => $trades,
        ],
    ]);

    app(TokenDiscoveryService::class)->run();

    expect(TokenCandidate::query()->where('symbol', 'SOPH')->exists())->toBeFalse()
        ->and(TokenCandidate::query()->where('symbol', 'GOOD')->exists())->toBeTrue();
});

test('a trending pair quoted in another token is swapped for the same token\'s SOL pair', function () {
    $safe = ['mintable' => ['status' => '0'], 'freezable' => ['status' => '0'], 'holder_count' => '25000'];
    $trades = [['side' => 'buy', 'usd' => 20_000, 'wallet' => 'w1'], ['side' => 'buy', 'usd' => 15_000, 'wallet' => 'w2'], ['side' => 'buy', 'usd' => 12_000, 'wallet' => 'w3']];
    fakeDiscoveryApis([
        'ODD' => ['pair' => dexPair('PairStonk111111111111111111111111111111111', 'TokenStank11111111111111111111111111111111', 'STANK', ['quoteToken' => ['address' => 'StonkMint', 'symbol' => 'STONK'], 'liquidity' => ['usd' => 100_000]]), 'trades' => $trades, 'security' => $safe],
        'SOL' => ['pair' => dexPair('PairStankSol1111111111111111111111111111111', 'TokenStank11111111111111111111111111111111', 'STANK'), 'trades' => $trades, 'security' => $safe, 'trending' => false],
    ]);

    app(TokenDiscoveryService::class)->run();

    expect(TokenCandidate::query()->where('symbol', 'STANK')->pluck('pair_address')->all())->toBe(['PairStankSol1111111111111111111111111111111'])
        ->and(TokenCandidate::firstWhere('symbol', 'STANK')->quote_symbol)->toBe('SOL');
});

test('a token already in the Market keeps the pair it is tracked on', function () {
    Token::factory()->create(['symbol' => 'STANK', 'chain_id' => 'solana', 'pair_address' => 'PairStonk111111111111111111111111111111111', 'token_address' => 'TokenStank11111111111111111111111111111111', 'quote_symbol' => 'STONK']);
    $safe = ['mintable' => ['status' => '0'], 'freezable' => ['status' => '0'], 'holder_count' => '25000'];
    fakeDiscoveryApis([
        'ODD' => ['pair' => dexPair('PairStonk111111111111111111111111111111111', 'TokenStank11111111111111111111111111111111', 'STANK', ['quoteToken' => ['address' => 'StonkMint', 'symbol' => 'STONK']]), 'trades' => [], 'security' => $safe],
        'SOL' => ['pair' => dexPair('PairStankSol1111111111111111111111111111111', 'TokenStank11111111111111111111111111111111', 'STANK'), 'trades' => [], 'security' => $safe, 'trending' => false],
    ]);

    app(TokenDiscoveryService::class)->run();

    expect(TokenCandidate::query()->where('symbol', 'STANK')->pluck('pair_address')->all())->toBe(['PairStonk111111111111111111111111111111111'])
        ->and(Token::query()->where('symbol', 'STANK')->count())->toBe(1);
});

test('a second pool of a token already in the Market is not tracked, like VVV twice on 10 Nov', function () {
    Token::factory()->create(['symbol' => 'VVV', 'chain_id' => 'solana', 'pair_address' => 'PairVvvFirst11111111111111111111111111111111', 'token_address' => 'TokenVvv1111111111111111111111111111111111']);
    fakeDiscoveryApis([
        'VVV' => [
            'pair' => dexPair('PairVvvSecond1111111111111111111111111111111', 'TokenVvv1111111111111111111111111111111111', 'VVV'),
            'trades' => [['side' => 'buy', 'usd' => 20_000, 'wallet' => 'w1'], ['side' => 'buy', 'usd' => 15_000, 'wallet' => 'w2'], ['side' => 'buy', 'usd' => 12_000, 'wallet' => 'w3']],
            'security' => ['mintable' => ['status' => '0'], 'freezable' => ['status' => '0'], 'holder_count' => '25000'],
        ],
    ]);

    $result = app(TokenDiscoveryService::class)->run();

    expect($result['added'])->toBe([])
        ->and(Token::query()->where('symbol', 'VVV')->count())->toBe(1);

    expect(fn () => app(TokenRegistry::class)->add('solana', 'PairVvvSecond1111111111111111111111111111111'))
        ->toThrow(ValidationException::class, 'VVV sudah ada di Market lewat pair lain.');
});

test('jupiter lists are recorded and labelled without changing scores or adding jupiter-only candidates', function () {
    Http::fake(['api.jup.ag/tokens/v2/toptrending/*' => Http::response([
        ['id' => 'TokenGood111111111111111111111111111111111', 'symbol' => 'GOOD', 'organicScore' => 88.4],
        ['id' => 'So11111111111111111111111111111111111111112', 'symbol' => 'SOL'],
    ]), 'api.jup.ag/tokens/v2/toporganicscore/*' => Http::response([
        ['id' => 'TokenOnly111111111111111111111111111111111', 'symbol' => 'ONLY', 'organicScore' => 91.0],
    ])]);
    fakeDiscoveryApis([
        'GOOD' => [
            'pair' => dexPair('PairGood1111111111111111111111111111111111', 'TokenGood111111111111111111111111111111111', 'GOOD'),
            'trades' => [['side' => 'buy', 'usd' => 20_000, 'wallet' => 'w1']],
            'security' => ['mintable' => ['status' => '0'], 'freezable' => ['status' => '0'], 'holder_count' => '25000'],
        ],
    ]);

    app(TokenDiscoveryService::class)->run();
    $good = TokenCandidate::firstWhere('symbol', 'GOOD');

    expect($good->sources)->toBe(['geckoterminal_trending', 'jupiter_trending'])
        ->and($good->attention_score)->toBe(35)
        ->and(TokenCandidate::where('symbol', 'ONLY')->exists())->toBeFalse()
        ->and(DiscoverySighting::query()->orderBy('source')->get(['symbol', 'source', 'list_rank', 'organic_score'])->toArray())->toBe([
            ['symbol' => 'GOOD', 'source' => 'geckoterminal_trending', 'list_rank' => null, 'organic_score' => null],
            ['symbol' => 'ONLY', 'source' => 'jupiter_organic', 'list_rank' => 1, 'organic_score' => 91.0],
            ['symbol' => 'GOOD', 'source' => 'jupiter_trending', 'list_rank' => 1, 'organic_score' => 88.4],
        ]);
});

test('discovery carries on when jupiter is down', function () {
    Http::fake(['api.jup.ag/*' => Http::response('busy', 503)]);
    fakeDiscoveryApis([
        'GOOD' => [
            'pair' => dexPair('PairGood1111111111111111111111111111111111', 'TokenGood111111111111111111111111111111111', 'GOOD'),
            'trades' => [],
            'security' => ['mintable' => ['status' => '0'], 'freezable' => ['status' => '0'], 'holder_count' => '25000'],
        ],
    ]);

    app(TokenDiscoveryService::class)->run();

    expect(TokenCandidate::firstWhere('symbol', 'GOOD')->sources)->toBe(['geckoterminal_trending'])
        ->and(DiscoverySighting::query()->pluck('source')->all())->toBe(['geckoterminal_trending']);
});
