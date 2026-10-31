<?php

use App\Enums\OrderSide;
use App\Models\Token;
use App\Models\User;
use App\Models\WhaleTrade;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->eth = Token::factory()->create(['chain_id' => 'ethereum', 'symbol' => 'PEPE']);
    $this->base = Token::factory()->create(['chain_id' => 'base', 'symbol' => 'BRETT']);
    Cache::flush();
});

function walletTrade(Token $token, string $wallet, float $usd, OrderSide $side = OrderSide::Buy, ?string $at = null): WhaleTrade
{
    return WhaleTrade::factory()->for($token)->create(['wallet' => $wallet, 'amount_usd' => $usd, 'side' => $side, 'traded_at' => $at ?? now()]);
}

test('wallet pages need a login', function () {
    $this->getJson('/api/wallets')->assertUnauthorized();
    $this->getJson('/api/wallets/ethereum/0xabc')->assertUnauthorized();
});

test('every signed-in user can see wallets, totalled per wallet and chain', function () {
    walletTrade($this->eth, '0xAbC123', 100_000);
    walletTrade($this->eth, '0xAbC123', 40_000, OrderSide::Sell);
    walletTrade($this->base, '0xAbC123', 5_000);
    WhaleTrade::factory()->for($this->eth)->create(['wallet' => null]);

    $response = $this->actingAs($this->user)->getJson('/api/wallets?sort=volume_usd')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('summary.wallets', 2)
        ->assertJsonPath('data.0.chain', 'ethereum')
        ->assertJsonPath('data.0.trades', 2)
        ->assertJsonPath('data.0.buys', 1)
        ->assertJsonPath('data.0.sells', 1);

    expect($response->json('data.0.volume_usd'))->toEqual(140000)
        ->and($response->json('data.0.buy_usd'))->toEqual(100000)
        ->and(collect($response->json('chains'))->pluck('id')->all())->toBe(['base', 'ethereum']);
});

test('wallets can be searched, filtered by chain, sorted and paged', function () {
    walletTrade($this->eth, '0xAAA111', 300);
    walletTrade($this->eth, '0xBBB222', 100);
    walletTrade($this->base, '0xCCC333', 200);
    foreach (range(1, 31) as $i) {
        walletTrade($this->base, "0xPAGE{$i}", 1);
    }

    $get = fn (string $query) => $this->actingAs($this->user)->getJson("/api/wallets?{$query}")->assertOk();

    expect($get('search=aaa1')->json('data.*.wallet'))->toBe(['0xAAA111'])
        ->and($get('chain=ethereum&sort=volume_usd&direction=asc')->json('data.*.wallet'))->toBe(['0xBBB222', '0xAAA111'])
        ->and($get('sort=volume_usd')->json('data.0.wallet'))->toBe('0xAAA111')
        ->and($get('page=2')->json('meta'))->toBe(['current_page' => 2, 'last_page' => 2, 'total' => 34])
        ->and($get('page=2')->json('data'))->toHaveCount(4);

    $this->actingAs($this->user)->getJson('/api/wallets?sort=wallet')->assertUnprocessable();
});

test('a wallet that trades constantly is marked as a likely bot and can be hidden', function () {
    foreach (range(1, 60) as $i) {
        walletTrade($this->eth, '0xBOT', 1000, OrderSide::Buy, now()->subMinutes($i)->toDateTimeString());
    }
    // Just as many trades, but spread over weeks.
    foreach (range(1, 60) as $i) {
        walletTrade($this->eth, '0xSLOW', 1000, OrderSide::Buy, now()->subHours($i * 12)->toDateTimeString());
    }
    walletTrade($this->eth, '0xPERSON', 1000);

    $response = $this->actingAs($this->user)->getJson('/api/wallets')->assertJsonPath('summary.likely_bots', 1);
    $flags = collect($response->json('data'))->pluck('likely_bot', 'wallet');

    expect($flags['0xBOT'])->toBeTrue()
        ->and($flags['0xSLOW'])->toBeFalse()
        ->and($flags['0xPERSON'])->toBeFalse()
        ->and($this->actingAs($this->user)->getJson('/api/wallets?hide_bots=1')->json('data.*.wallet'))->not->toContain('0xBOT');
});

test('a wallet page shows its totals, tokens and trades on that chain only', function () {
    $other = Token::factory()->create(['chain_id' => 'ethereum', 'symbol' => 'SHIB']);
    walletTrade($this->eth, '0xAbC123', 100_000);
    walletTrade($other, '0xAbC123', 200_000, OrderSide::Sell);
    walletTrade($this->base, '0xAbC123', 5_000);

    $response = $this->actingAs($this->user)->getJson('/api/wallets/ethereum/0xAbC123')
        ->assertOk()
        ->assertJsonPath('wallet.trades', 2)
        ->assertJsonPath('wallet.tokens', 2)
        ->assertJsonPath('tokens.0.symbol', 'SHIB')
        ->assertJsonCount(2, 'trades.data')
        ->assertJsonPath('trades.meta.total', 2);

    expect($response->json('tokens.0.sell_usd'))->toEqual(200000);
});

test('an unknown wallet is not found', function () {
    $this->actingAs($this->user)->getJson('/api/wallets/ethereum/0xNOPE')->assertNotFound();
});
