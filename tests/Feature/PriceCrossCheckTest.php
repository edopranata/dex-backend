<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderFillEvidence;
use App\Models\Position;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Analysis\SignalTracker;
use App\Services\MarketData\GeckoTerminalClient;
use App\Services\MarketData\PriceCrossCheck;
use App\Services\Trading\TradingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    config(['market.simulator.cross_check.enabled' => true]);
    $this->user = User::factory()->create(['balance_usd' => 10000]);
    $this->token = Token::factory()->create([
        'symbol' => 'STANK', 'chain_id' => 'solana', 'pair_address' => 'StankPool', 'token_address' => 'StankMint', 'price_usd' => 0.0027, 'liquidity_usd' => 100_000,
    ]);
});

/**
 * GeckoTerminal's answer for one pool, with the tracked token as its base (or quote) token.
 *
 * @return array<string, mixed>
 */
function geckoPoolBody(float|int $price, string $side = 'base'): array
{
    return ['data' => [
        'relationships' => [
            'base_token' => ['data' => ['id' => $side === 'base' ? 'solana_StankMint' : 'solana_So11111111111111111111111111111111111111112']],
            'quote_token' => ['data' => ['id' => $side === 'base' ? 'solana_StonkMint' : 'solana_StankMint']],
        ],
        'attributes' => [
            'base_token_price_usd' => (string) ($side === 'base' ? $price : 160),
            'quote_token_price_usd' => (string) ($side === 'base' ? 0.05 : $price),
        ],
    ]];
}

function fakeGeckoPool(float|int $price, string $side = 'base', int $status = 200): void
{
    Http::fake(['api.geckoterminal.com/*' => Http::response($status === 200 ? geckoPoolBody($price, $side) : [], $status)]);
}

function feedTwice(Token $token, float $price): void
{
    $token->update(['price_usd' => $price, 'previous_price_usd' => $price, 'previous_price_at' => now()->subSeconds(15), 'price_updated_at' => now()]);
}

function matchOrders(Token $token): int
{
    return app(TradingService::class)->matchPendingOrders(new Collection([$token->refresh()]));
}

test('a limit buy does not fill on a stray feed price that GeckoTerminal does not see, like STANK on 9 Nov', function () {
    $buy = Order::factory()->for($this->user, 'user')->for($this->token)->create(['side' => 'buy', 'type' => 'limit', 'status' => 'pending', 'amount_usd' => 100, 'trigger_price' => 0.00244]);
    fakeGeckoPool(0.0026);

    feedTwice($this->token, 0.002261);

    expect(matchOrders($this->token))->toBe(0)
        ->and($buy->refresh()->status)->toBe(OrderStatus::Pending);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/networks/solana/pools/StankPool'));
});

test('when both sources agree the order fills at the feed price, and the evidence keeps the GeckoTerminal price', function () {
    $buy = Order::factory()->for($this->user, 'user')->for($this->token)->create(['side' => 'buy', 'type' => 'limit', 'status' => 'pending', 'amount_usd' => 100, 'trigger_price' => 0.00244]);
    fakeGeckoPool(0.00242);

    feedTwice($this->token, 0.00241);

    expect(matchOrders($this->token))->toBe(1)
        ->and($buy->refresh()->status)->toBe(OrderStatus::Filled);
    expect(OrderFillEvidence::firstWhere('order_id', $buy->id))
        ->source->toBe('dexscreener')
        ->market_price->toEqual(0.00241)
        ->checked_price->toEqual(0.00242);
});

test('when the feed sits far above GeckoTerminal, a take profit waits until GeckoTerminal reaches it and fills at that price', function () {
    Position::factory()->for($this->user)->for($this->token)->create(['quantity' => 1000, 'avg_price' => 0.009]);
    $takeProfit = Order::factory()->for($this->user, 'user')->for($this->token)->create(['side' => 'sell', 'type' => 'take_profit', 'status' => 'pending', 'quantity' => 500, 'trigger_price' => 0.0100]);

    Http::fake(['api.geckoterminal.com/*' => Http::sequence()->push(geckoPoolBody(0.0090))->push(geckoPoolBody(0.0101))]);

    // FLOAT on 10 Nov: the feed 14% above the candles.
    feedTwice($this->token, 0.0103);
    expect(matchOrders($this->token))->toBe(0);

    // GeckoTerminal is asked again once its answer has aged out.
    $this->travel(46)->seconds();
    feedTwice($this->token, 0.0116);

    expect(matchOrders($this->token))->toBe(1)
        ->and(OrderFillEvidence::firstWhere('order_id', $takeProfit->id))
        ->source->toBe('geckoterminal')
        ->market_price->toEqual(0.0101)
        ->checked_price->toEqual(0.0101);
});

test('a token that is the quote side of its pool is priced from that side', function () {
    fakeGeckoPool(0.0026, side: 'quote');

    expect(app(GeckoTerminalClient::class)->poolTokenPrice('solana', 'StankPool', 'StankMint', 8))->toEqual(0.0026);
});

test('the GeckoTerminal answer is reused for a while, so one token asks once for all its orders', function () {
    foreach ([0.0025, 0.00244] as $trigger) {
        Order::factory()->for($this->user, 'user')->for($this->token)->create(['side' => 'buy', 'type' => 'limit', 'status' => 'pending', 'amount_usd' => 50, 'trigger_price' => $trigger]);
    }
    fakeGeckoPool(0.0026);

    feedTwice($this->token, 0.002261);
    matchOrders($this->token);
    $this->travel(15)->seconds();
    feedTwice($this->token, 0.002261);
    matchOrders($this->token);

    Http::assertSentCount(1);
});

test('when no request slot is free in time the order waits, while a market order fills at the feed price unchecked', function () {
    $limit = Order::factory()->for($this->user, 'user')->for($this->token)->create(['side' => 'buy', 'type' => 'limit', 'status' => 'pending', 'amount_usd' => 100, 'trigger_price' => 0.00244]);
    fakeGeckoPool(0.0024);
    Cache::put('geckoterminal:next-slot', now()->timestamp + 60, 600);

    feedTwice($this->token, 0.00241);
    expect(matchOrders($this->token))->toBe(0)
        ->and($limit->refresh()->status)->toBe(OrderStatus::Pending);

    $market = app(TradingService::class)->place($this->user, $this->token->refresh(), ['side' => 'buy', 'type' => 'market', 'amount_usd' => 50]);

    expect($market->status)->toBe(OrderStatus::Filled)
        ->and(OrderFillEvidence::firstWhere('order_id', $market->id)->source)->toBe('dexscreener_unchecked');
    Http::assertNothingSent();
});

test('when GeckoTerminal fails the feed price is used and the failure is logged', function () {
    Log::spy();
    $buy = Order::factory()->for($this->user, 'user')->for($this->token)->create(['side' => 'buy', 'type' => 'limit', 'status' => 'pending', 'amount_usd' => 100, 'trigger_price' => 0.00244]);
    fakeGeckoPool(0, status: 500);

    feedTwice($this->token, 0.00241);

    expect(matchOrders($this->token))->toBe(1)
        ->and(OrderFillEvidence::firstWhere('order_id', $buy->id))
        ->source->toBe('dexscreener_unchecked')
        ->checked_price->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'Cek harga GeckoTerminal untuk STANK gagal'));
});

test('a manual market buy far above GeckoTerminal fills at the GeckoTerminal price, unlike FLOAT order 357', function () {
    fakeGeckoPool(0.0113);
    $this->token->update(['price_usd' => 0.01225, 'price_updated_at' => now()]);

    $buy = app(TradingService::class)->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 100]);

    expect(OrderFillEvidence::firstWhere('order_id', $buy->id))
        ->source->toBe('geckoterminal')
        ->market_price->toEqual(0.0113);
});

test('a signal does not take a target the feed alone reached, and enters at the GeckoTerminal price', function () {
    $active = Signal::withoutEvents(fn () => Signal::factory()->for($this->token)->create([
        'status' => 'active', 'entry_price' => 0.009, 'entered_at' => now(), 'stop_loss' => 0.008, 'take_profit_1' => 0.0100, 'take_profit_2' => 0.011, 'take_profit_3' => 0.012, 'max_tp_hit' => 0,
    ]));
    Http::fake([
        'api.dexscreener.com/*' => Http::response(['pairs' => [['chainId' => 'solana', 'pairAddress' => 'StankPool', 'priceUsd' => '0.0103', 'baseToken' => ['symbol' => 'STANK', 'name' => 'STANK', 'address' => 'StankMint'], 'quoteToken' => ['symbol' => 'STONK']]]]),
        'api.geckoterminal.com/*' => Http::response(geckoPoolBody(0.0090)),
    ]);
    $this->token->update(['price_usd' => 0.0103, 'price_updated_at' => now()->subSeconds(15)]);

    $this->artisan('market:prices')->assertSuccessful();

    expect($active->refresh()->max_tp_hit)->toBe(0)
        ->and($active->status->value)->toBe('active');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'geckoterminal'));
});

test('a pending signal entered through GeckoTerminal takes its price as the entry', function () {
    $pending = Signal::withoutEvents(fn () => Signal::factory()->for($this->token)->create([
        'status' => 'pending', 'entry_low' => 0.0088, 'entry_high' => 0.0092, 'stop_loss' => 0.008, 'take_profit_1' => 0.0100, 'take_profit_2' => 0.011, 'take_profit_3' => 0.012,
    ]));
    fakeGeckoPool(0.0091);
    feedTwice($this->token, 0.0084);

    app(SignalTracker::class)->track($pending, 0.0084, 0.0084, fn () => app(PriceCrossCheck::class)->priceToActOn($this->token->refresh())?->price);

    expect($pending->refresh()->status->value)->toBe('active')
        ->and((float) $pending->entry_price)->toEqual(0.0091);
});
