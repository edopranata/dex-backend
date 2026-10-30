<?php

use App\Models\Token;
use App\Models\User;
use App\Models\WhaleTrade;

test('the whale summary sums buys and sells per token and names the token', function () {
    $token = Token::factory()->create(['symbol' => 'JOB', 'chain_id' => 'solana']);
    WhaleTrade::factory()->for($token)->create(['side' => 'buy', 'amount_usd' => 30_000]);
    WhaleTrade::factory()->for($token)->create(['side' => 'buy', 'amount_usd' => 20_000]);
    WhaleTrade::factory()->for($token)->create(['side' => 'sell', 'amount_usd' => 15_000]);
    WhaleTrade::factory()->for($token)->create(['side' => 'sell', 'amount_usd' => 99_000, 'traded_at' => now()->subDays(3)]);

    $summary = $this->actingAs(User::factory()->create())->getJson('/api/whales?hours=24')->assertOk()->json('summary');

    expect($summary)->toHaveCount(1)
        ->and($summary[0])->toMatchArray(['token_id' => $token->id, 'symbol' => 'JOB', 'slug' => $token->slug, 'chain' => 'SOL', 'trades' => 3])
        ->and((float) $summary[0]['buy_usd'])->toBe(50_000.0)
        ->and((float) $summary[0]['sell_usd'])->toBe(15_000.0);
});
