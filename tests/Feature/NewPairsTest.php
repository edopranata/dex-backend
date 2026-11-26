<?php

use App\Models\NewPair;
use App\Models\NewPairBar;
use App\Models\NewPairTrade;
use App\Models\TokenCandidate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Set what the faked DEX Screener (and GoPlus) answer for Token Baru from now on. $pairs maps a pair address to its
 * payload fields. The fake itself is installed once per test (beforeEach), because later Http::fake calls would not
 * replace it.
 *
 * @param  array<string, array<string, mixed>>  $pairs
 * @param  array<string, array<string, mixed>>  $goPlus
 */
function fakeNewPairFeeds(array $pairs, array $goPlus = []): void
{
    $GLOBALS['newPairFeed'] = ['pairs' => $pairs, 'goplus' => $goPlus];
}

function installNewPairFake(): void
{
    $GLOBALS['newPairFeed'] = ['pairs' => [], 'goplus' => []];

    Http::fake(function (Request $request) {
        $url = $request->url();
        ['pairs' => $pairs, 'goplus' => $goPlus] = $GLOBALS['newPairFeed'];

        if (str_contains($url, 'api.dexscreener.com/latest/dex/pairs/')) {
            $found = [];

            foreach ($pairs as $address => $fields) {
                if (str_contains($url, $address)) {
                    $found[] = array_replace_recursive([
                        'chainId' => 'solana', 'pairAddress' => $address, 'dexId' => 'pumpswap', 'url' => "https://dexscreener.com/solana/{$address}",
                        'quoteToken' => ['symbol' => 'SOL'], 'liquidity' => ['usd' => 50_000], 'volume' => ['m5' => 500, 'h1' => 3_000, 'h24' => 9_000],
                        'txns' => ['m5' => ['buys' => 3, 'sells' => 3]], 'priceChange' => ['m5' => 1, 'h1' => 2],
                    ], $fields);
                }
            }

            return Http::response(['pairs' => $found]);
        }

        if (str_contains($url, 'gopluslabs')) {
            foreach ($goPlus as $address => $data) {
                if (str_contains($url, $address)) {
                    return Http::response(['code' => 1, 'result' => [$address => $data]]);
                }
            }

            return Http::response(['code' => 1, 'result' => []]);
        }

        return null;
    });
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:30', 'UTC'));
    installNewPairFake();
});

test('young pairs from discovery are registered once, and old, thin or base-asset pairs are not', function () {
    TokenCandidate::factory()->create(['symbol' => 'NEW', 'pair_created_at' => now()->subHours(3), 'liquidity_usd' => 40_000]);
    TokenCandidate::factory()->create(['symbol' => 'OLD', 'pair_created_at' => now()->subHours(30)]);
    TokenCandidate::factory()->create(['symbol' => 'THIN', 'pair_created_at' => now()->subHours(3), 'liquidity_usd' => 5_000]);
    TokenCandidate::factory()->create(['symbol' => 'WSOL', 'pair_created_at' => now()->subHours(3)]);
    TokenCandidate::factory()->create(['symbol' => 'STALE', 'pair_created_at' => now()->subHours(3), 'last_seen_at' => now()->subHours(2)]);
    fakeNewPairFeeds([]);

    $this->artisan('newpairs:track')->assertSuccessful();
    $this->artisan('newpairs:track')->assertSuccessful();

    expect(NewPair::query()->pluck('symbol')->all())->toBe(['NEW'])
        ->and(NewPair::query()->first())->source->toBe('discovery')->first_liquidity_usd->toBe(40_000.0);
});

test('GeckoTerminal new_pools are recorded when liquid enough', function () {
    config(['market.new_pairs.networks' => ['solana']]);
    Http::fake(['api.geckoterminal.com/*' => Http::response([
        'data' => [
            ['attributes' => ['address' => 'PoolA', 'pool_created_at' => '2026-11-26T09:58:00Z', 'reserve_in_usd' => '25000'],
                'relationships' => ['base_token' => ['data' => ['id' => 'solana_MintA']], 'dex' => ['data' => ['id' => 'pumpswap']]]],
            ['attributes' => ['address' => 'PoolB', 'pool_created_at' => '2026-11-26T09:59:00Z', 'reserve_in_usd' => '1700'],
                'relationships' => ['base_token' => ['data' => ['id' => 'solana_MintB']], 'dex' => ['data' => ['id' => 'pump-fun']]]],
        ],
        'included' => [
            ['id' => 'solana_MintA', 'type' => 'token', 'attributes' => ['address' => 'MintA', 'symbol' => 'AAA', 'name' => 'A']],
            ['id' => 'solana_MintB', 'type' => 'token', 'attributes' => ['address' => 'MintB', 'symbol' => 'BBB', 'name' => 'B']],
        ],
    ])]);

    $this->artisan('newpairs:discover', ['--sync' => true])->assertSuccessful();

    $pair = NewPair::query()->sole();
    expect($pair)->symbol->toBe('AAA')->source->toBe('geckoterminal_new')->dex_id->toBe('pumpswap')
        ->and($pair->pool_created_at->toIso8601String())->toBe('2026-11-26T09:58:00+00:00');
});

test('each minute extends a 5-minute bar, the comparison buys at first sight, and a stop fills at the sampled price', function () {
    $pair = NewPair::factory()->create(['pair_address' => 'PairStop', 'pool_created_at' => now()->subMinutes(30)]);
    fakeNewPairFeeds(['PairStop' => ['priceUsd' => '1.00']]);
    $this->artisan('newpairs:track')->assertSuccessful();

    $this->travel(1)->minutes();
    fakeNewPairFeeds(['PairStop' => ['priceUsd' => '1.10']]);
    $this->artisan('newpairs:track')->assertSuccessful();

    $bar = NewPairBar::query()->sole();
    expect($bar)->open->toBe(1.0)->high->toBe(1.1)->close->toBe(1.1)->samples->toBe(2)
        ->and($pair->trades()->sole())->strategy->toBe('first_seen')->status->toBe('open')->entry_price->toBe(1.0)->pool_age_minutes->toBe(30);

    // GoPlus has no data on this pool, so security stays unknown and only the comparison trades; a gap to 0.80 closes the trade there, not at the stop.
    $this->travel(1)->minutes();
    fakeNewPairFeeds(['PairStop' => ['priceUsd' => '0.80']]);
    $this->artisan('newpairs:track')->assertSuccessful();

    expect($pair->trades()->sole())->status->toBe('closed')->exit_reason->toBe('stop')->gross_pct->toBe(-20.0)->net_pct->toBe(-22.0)
        ->and($pair->fresh())->high_price_usd->toBe(1.1)->low_price_usd->toBe(0.8);
});

test('a take-profit sells a third and moves the stop to the entry', function () {
    $pair = NewPair::factory()->create(['pair_address' => 'PairTp']);
    foreach (['1.00', '1.25', '1.00'] as $price) {
        fakeNewPairFeeds(['PairTp' => ['priceUsd' => $price]]);
        $this->artisan('newpairs:track')->assertSuccessful();
        $this->travel(1)->minutes();
    }

    expect($pair->trades()->sole())->tp_hit->toBe(1)->exit_reason->toBe('impas')->gross_pct->toBe(6.67)->net_pct->toBe(4.67)->high_price->toBe(1.25);
});

test('early liquidity waits for a settled security check and takes the documents filters from the snapshot', function () {
    $pair = NewPair::factory()->create(['pair_address' => 'PairEarly', 'token_address' => 'MintEarly']);
    $busy = ['priceUsd' => '1.00', 'volume' => ['m5' => 3_000, 'h1' => 15_000], 'txns' => ['m5' => ['buys' => 20, 'sells' => 10]], 'priceChange' => ['m5' => 5]];
    fakeNewPairFeeds(['PairEarly' => $busy], ['MintEarly' => []]);

    // The first run checks security and samples; GoPlus answering puts a score on the pool.
    $this->artisan('newpairs:track')->assertSuccessful();
    expect($pair->fresh())->security_score->toBe(100)->is_honeypot->toBeNull();

    $this->travel(1)->minutes();
    $this->artisan('newpairs:track')->assertSuccessful();

    expect($pair->trades()->orderBy('strategy')->pluck('strategy')->all())->toBe(['early_liquidity', 'first_seen'])
        ->and($pair->trades()->where('strategy', 'early_liquidity')->sole()->context)->toMatchArray(['txns_m5' => 30, 'buy_sell_ratio' => 2.0, 'source' => 'discovery']);
});

test('a honeypot or unknown security never opens a strategy trade', function () {
    NewPair::factory()->create(['pair_address' => 'PairHoney', 'security_checked_at' => now(), 'is_honeypot' => true, 'security_score' => 10]);
    NewPair::factory()->create(['pair_address' => 'PairUnknown', 'security_checked_at' => now(), 'security_score' => null]);
    $busy = ['volume' => ['m5' => 3_000, 'h1' => 15_000], 'txns' => ['m5' => ['buys' => 20, 'sells' => 10]]];
    fakeNewPairFeeds(['PairHoney' => $busy + ['priceUsd' => '1'], 'PairUnknown' => $busy + ['priceUsd' => '1']]);

    $this->artisan('newpairs:track')->assertSuccessful();

    expect(NewPairTrade::query()->pluck('strategy')->unique()->all())->toBe(['first_seen']);
});

test('a breakout on the bar that just closed opens a breakout trade at the next sample', function () {
    $pair = NewPair::factory()->secure()->create(['pair_address' => 'PairBreak', 'first_seen_at' => now()->subHours(2), 'pool_created_at' => now()->subHours(3)]);
    $current = intdiv(now()->getTimestamp(), 300) * 300;
    for ($i = 13; $i >= 2; $i--) {
        NewPairBar::query()->create(['new_pair_id' => $pair->id, 'open_time' => $current - $i * 300, 'open' => 1.0, 'high' => 1.02, 'low' => 0.98, 'close' => 1.0, 'volume_m5' => 2_000, 'buys_m5' => 5, 'sells_m5' => 5]);
    }
    NewPairBar::query()->create(['new_pair_id' => $pair->id, 'open_time' => $current - 300, 'open' => 1.0, 'high' => 1.1, 'low' => 1.0, 'close' => 1.08, 'volume_m5' => 4_000, 'buys_m5' => 30, 'sells_m5' => 10]);
    $pair->trades()->create(['strategy' => 'first_seen', 'status' => 'closed', 'entry_at' => now()->subHours(2), 'entry_price' => 1, 'stop_price' => 0.85, 'high_price' => 1, 'low_price' => 1]);
    fakeNewPairFeeds(['PairBreak' => ['priceUsd' => '1.09']]);

    $this->artisan('newpairs:track')->assertSuccessful();

    expect($pair->trades()->where('strategy', 'breakout')->sole())->entry_price->toBe(1.09)
        ->context->toMatchArray(['broken_high' => 1.02, 'average_volume' => 2000]);
});

test('tracking ends after 24 hours once trades are closed, or when DEX Screener stops showing the pool', function () {
    $old = NewPair::factory()->create(['pair_address' => 'PairOld', 'pool_created_at' => now()->subHours(25), 'first_seen_at' => now()->subHours(20), 'last_sample_at' => now()]);
    $gone = NewPair::factory()->create(['pair_address' => 'PairGone', 'first_seen_at' => now()->subHours(3), 'last_sample_at' => now()->subMinutes(61), 'price_usd' => 0.5]);
    $gone->trades()->create(['strategy' => 'first_seen', 'status' => 'open', 'entry_at' => now()->subHours(2), 'entry_price' => 1, 'stop_price' => 0.85, 'high_price' => 1, 'low_price' => 1]);
    fakeNewPairFeeds(['PairOld' => ['priceUsd' => '1']]);

    $this->artisan('newpairs:track')->assertSuccessful();

    expect($old->fresh())->status->toBe('done')->end_reason->toBe('umur')
        ->and($gone->fresh())->status->toBe('done')->end_reason->toBe('tanpa_data')
        ->and($gone->trades()->sole())->exit_reason->toBe('tanpa_data')->net_pct->toBe(-52.0);
});

test('a pool whose liquidity was pulled stops being tracked once its trades are closed', function () {
    $pulled = NewPair::factory()->create(['pair_address' => 'PairPulled', 'pool_created_at' => now()->subMinutes(2), 'first_liquidity_usd' => 20_000]);
    fakeNewPairFeeds(['PairPulled' => ['priceUsd' => '0.01', 'liquidity' => ['usd' => 6]]]);

    $this->artisan('newpairs:track')->assertSuccessful();

    expect($pulled->fresh())->status->toBe('done')->end_reason->toBe('likuiditas')
        ->and($pulled->trades()->count())->toBe(0);
});

test('the Token Baru page reads the summary, pools and trades', function () {
    $pair = NewPair::factory()->create(['symbol' => 'PAGE', 'first_price_usd' => 1, 'price_usd' => 1.5, 'high_price_usd' => 2]);
    $pair->trades()->create(['strategy' => 'pullback', 'status' => 'closed', 'entry_at' => now(), 'entry_price' => 1, 'stop_price' => 0.85, 'high_price' => 1.3, 'low_price' => 0.9, 'net_pct' => 10, 'exit_reason' => 'impas']);
    $pair->trades()->create(['strategy' => 'first_seen', 'status' => 'closed', 'entry_at' => now(), 'entry_price' => 1, 'stop_price' => 0.85, 'high_price' => 1, 'low_price' => 0.8, 'net_pct' => -17, 'exit_reason' => 'stop']);

    $this->getJson('/api/new-pairs/summary')->assertUnauthorized();

    $user = User::factory()->create();
    $summary = $this->actingAs($user)->getJson('/api/new-pairs/summary')->assertOk()->json();
    expect(collect($summary['strategies'])->firstWhere('key', 'pullback'))->toMatchArray(['closed' => 1, 'avg_net_pct' => 10, 'win_rate' => 100])
        ->and($summary['pairs'])->toMatchArray(['tracking' => 1, 'total' => 1]);

    $this->actingAs($user)->getJson('/api/new-pairs')->assertOk()
        ->assertJsonPath('data.0.symbol', 'PAGE')->assertJsonPath('data.0.change_since_seen_pct', 50)->assertJsonPath('data.0.max_since_seen_pct', 100);

    $this->actingAs($user)->getJson('/api/new-pairs/trades?strategy=pullback')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.mfe_pct', 30)->assertJsonPath('data.0.mae_pct', -10);
});
