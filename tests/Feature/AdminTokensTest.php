<?php

use App\Enums\WatchlistAction;
use App\Jobs\SyncTokenCandles;
use App\Models\Candle;
use App\Models\Token;
use App\Models\User;
use App\Models\WatchlistEvent;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
});

/**
 * A token that entered the Market, and left it some days ago unless it is still in.
 */
function everInMarketToken(array $attributes = [], ?int $leftDaysAgo = null): Token
{
    $token = Token::factory()->create(['is_active' => $leftDaysAgo === null, ...$attributes]);
    WatchlistEvent::factory()->for($token)->create(['action' => WatchlistAction::Added, 'created_at' => now()->subDays(20)]);

    if ($leftDaysAgo !== null) {
        WatchlistEvent::factory()->for($token)->create(['action' => WatchlistAction::Removed, 'reason' => 'Skor menurun.', 'created_at' => now()->subDays($leftDaysAgo)]);
    }

    return $token;
}

function storeQuarterCandles(Token $token, int $hoursAgo, int $count = 1): void
{
    Candle::query()->insert(array_map(fn (int $i): array => ['token_id' => $token->id, 'timeframe' => '15m', 'open_time' => now()->subHours($hoursAgo)->getTimestamp() - $i * 900, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1], range(0, $count - 1)));
}

test('the token list shows every token that has been in the Market, with its stored candles and how they are fetched', function () {
    $in = everInMarketToken(['symbol' => 'INN']);
    $out = everInMarketToken(['symbol' => 'OUT'], leftDaysAgo: 10);
    Token::factory()->create(['symbol' => 'NEVER', 'is_active' => false]);
    storeQuarterCandles($in, 0, 3);
    storeQuarterCandles($out, 240, 2);
    Candle::factory()->for($out)->create(['timeframe' => '1h']);

    $this->getJson('/api/admin/tokens')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/api/admin/tokens')->assertForbidden();

    $response = $this->actingAs($this->admin)->getJson('/api/admin/tokens')->assertOk();
    $rows = collect($response->json('tokens'))->keyBy('symbol');

    expect($rows->keys()->all())->toBe(['INN', 'OUT'])
        ->and($rows['INN'])->toMatchArray(['in_market' => true, 'fetch' => 'every_15m', 'candle_rows' => 3, 'out_days' => null])
        ->and($rows['OUT'])->toMatchArray(['in_market' => false, 'fetch' => 'rotation', 'candles' => ['15m' => 2, '1h' => 1], 'candle_rows' => 3, 'last_removed_reason' => 'Skor menurun.', 'entries' => 1])
        ->and($rows['OUT']['out_days'])->toEqual(10.0)
        ->and($response->json('summary'))->toMatchArray([
            'tokens' => 2,
            'in_market' => 1,
            'out_of_market' => 1,
            'out_groups' => ['under_7' => 0, '7_to_30' => 1, 'over_30' => 0],
            'candle_rows' => 6,
            'candle_rows_out_of_market' => 3,
            // 15m and whale every 15 minutes for the one in the Market, and one turn for the one out of it.
            'requests_per_hour' => 9,
            'rotation_hours' => 0.1,
            'rotation_limit_hours' => 168,
        ]);
});

test('tokens out of the Market get their 15m candles in turns, the oldest first', function () {
    config(['market.candles.out_of_market_per_hour' => 2]);
    Queue::fake();
    $fresh = everInMarketToken(leftDaysAgo: 3);
    $stale = everInMarketToken(leftDaysAgo: 5);
    $none = everInMarketToken(leftDaysAgo: 8);
    $inMarket = everInMarketToken();
    $candidate = Token::factory()->create(['is_active' => false]);
    storeQuarterCandles($fresh, 1);
    storeQuarterCandles($stale, 30);

    $this->artisan('market:candles', ['--out-of-market' => true])->assertSuccessful();

    $pushed = fn (Token $token): bool => Queue::pushed(SyncTokenCandles::class, fn (SyncTokenCandles $job): bool => $job->token->is($token) && $job->timeframes === [])->isNotEmpty();

    expect($pushed($none))->toBeTrue()
        ->and($pushed($stale))->toBeTrue()
        ->and($pushed($fresh))->toBeFalse()
        ->and($pushed($inMarket))->toBeFalse()
        ->and($pushed($candidate))->toBeFalse();
});

test('an admin can fetch 1,000 15m candles for a token whose data is older than the backfill reaches', function () {
    Queue::fake();
    $stale = everInMarketToken(leftDaysAgo: 12);
    $fresh = everInMarketToken(leftDaysAgo: 1);
    $candidate = Token::factory()->create(['is_active' => false]);
    storeQuarterCandles($stale, 24 * 9);
    storeQuarterCandles($fresh, 2);

    $response = $this->actingAs($this->admin)->getJson('/api/admin/tokens')->assertOk();
    $rows = collect($response->json('tokens'))->keyBy('id');

    expect($rows[$stale->id]['gap'])->toBeTrue()
        ->and($rows[$fresh->id]['gap'])->toBeFalse()
        ->and($response->json('summary.gaps'))->toBe(1);

    $this->postJson("/api/admin/tokens/{$stale->id}/candles")->assertStatus(202)->assertJsonPath('message', fn (string $m): bool => str_contains($m, '1.000'));

    Queue::assertPushed(SyncTokenCandles::class, fn (SyncTokenCandles $job): bool => $job->token->is($stale) && $job->timeframes === [] && $job->quarters === 1000);

    $this->postJson("/api/admin/tokens/{$candidate->id}/candles")->assertNotFound();
    $this->actingAs(User::factory()->create())->postJson("/api/admin/tokens/{$stale->id}/candles")->assertForbidden();
});
