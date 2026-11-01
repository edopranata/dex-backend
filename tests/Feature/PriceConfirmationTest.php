<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderFillEvidence;
use App\Models\Position;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Analysis\SignalTracker;
use App\Services\Trading\TradingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create(['balance_usd' => 10000]);
    $this->token = Token::factory()->create(['price_usd' => 0.0085, 'liquidity_usd' => 5_000_000, 'pair_address' => '0xpair', 'chain_id' => 'base']);
    Position::factory()->for($this->user)->for($this->token)->create(['quantity' => 1000, 'avg_price' => 0.008]);
    $this->takeProfit = Order::factory()->for($this->user, 'user')->for($this->token)->create(['side' => 'sell', 'type' => 'take_profit', 'status' => 'pending', 'quantity' => 300, 'trigger_price' => 0.008784]);
    $this->stop = Order::factory()->for($this->user, 'user')->for($this->token)->create(['side' => 'sell', 'type' => 'stop_loss', 'status' => 'pending', 'quantity' => 300, 'trigger_price' => 0.0062]);
});

function tick(Token $token, float $price, ?float $previous, ?int $previousAgoSeconds = 15): void
{
    $token->update(['price_usd' => $price, 'previous_price_usd' => $previous, 'previous_price_at' => $previous === null ? null : now()->subSeconds($previousAgoSeconds), 'price_updated_at' => now()]);
}

function match_orders(Token $token): int
{
    return app(TradingService::class)->matchPendingOrders(new Collection([$token->refresh()]));
}

test('one stray price, up or down, fills nothing, like the Dacity take profit did', function () {
    tick($this->token, 0.00967, 0.0085);
    expect(match_orders($this->token))->toBe(0);

    // Gone again on the next check, so it never counted.
    tick($this->token, 0.0085, 0.00967);
    expect(match_orders($this->token))->toBe(0);

    tick($this->token, 0.00616, 0.0085);
    expect(match_orders($this->token))->toBe(0)
        ->and($this->takeProfit->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($this->stop->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a price that holds fills on the second check, at the price of that check', function () {
    tick($this->token, 0.0088, 0.0085);
    expect(match_orders($this->token))->toBe(0);

    tick($this->token, 0.0089, 0.0088);
    expect(match_orders($this->token))->toBe(1)
        ->and($this->takeProfit->refresh()->status)->toBe(OrderStatus::Filled)
        ->and(OrderFillEvidence::firstWhere('order_id', $this->takeProfit->id)->market_price)->toEqual(0.0089);
});

test('an earlier check from too long ago, or none at all, does not confirm anything', function () {
    tick($this->token, 0.0089, 0.0089, 121);
    expect(match_orders($this->token))->toBe(0);

    tick($this->token, 0.0089, null);
    expect(match_orders($this->token))->toBe(0);
});

test('each refresh keeps the check before it, so two refreshes confirm', function () {
    Http::fake(['api.dexscreener.com/*' => Http::sequence()
        ->push(['pairs' => [['chainId' => 'base', 'pairAddress' => '0xpair', 'priceUsd' => '0.0088', 'baseToken' => ['symbol' => 'X', 'name' => 'X', 'address' => '0x1'], 'quoteToken' => ['symbol' => 'USDC']]]])
        ->push(['pairs' => [['chainId' => 'base', 'pairAddress' => '0xpair', 'priceUsd' => '0.0089', 'baseToken' => ['symbol' => 'X', 'name' => 'X', 'address' => '0x1'], 'quoteToken' => ['symbol' => 'USDC']]]])]);
    $this->token->update(['price_updated_at' => now()->subSeconds(15)]);

    $this->artisan('market:prices')->assertSuccessful();
    expect($this->token->refresh()->price_usd)->toEqual(0.0088)
        ->and($this->token->previous_price_usd)->toEqual(0.0085)
        ->and($this->takeProfit->refresh()->status)->toBe(OrderStatus::Pending);

    $this->travel(15)->seconds();
    $this->artisan('market:prices')->assertSuccessful();
    expect($this->token->refresh()->previous_price_usd)->toEqual(0.0088)
        ->and($this->takeProfit->refresh()->status)->toBe(OrderStatus::Filled);
});

test('a signal is not settled by one stray price either', function () {
    $signal = Signal::withoutEvents(fn () => Signal::factory()->for($this->token)->create([
        'status' => 'active', 'entry_price' => 1, 'entered_at' => now(), 'stop_loss' => 0.95, 'take_profit_1' => 1.05, 'take_profit_2' => 1.1, 'take_profit_3' => 1.2, 'max_tp_hit' => 0,
    ]));
    $tracker = app(SignalTracker::class);

    $tracker->track($signal, 1.3, 1.0);
    expect($signal->refresh()->max_tp_hit)->toBe(0)->and($signal->status->value)->toBe('active');

    $tracker->track($signal, 0.8, 1.0);
    expect($signal->refresh()->status->value)->toBe('active');

    $tracker->track($signal, 1.06, 1.05);
    expect($signal->refresh()->max_tp_hit)->toBe(1);

    $tracker->track($signal, 0.99, 1.0);
    expect($signal->refresh()->status->value)->toBe('take_profit')
        ->and((float) $signal->exit_price)->toEqual(0.99);
});

test('every fill leaves evidence that cannot be changed and outlives a reset, for system accounts too', function () {
    $account = User::autoFollower('buy');
    $account->update(['balance_usd' => 10000]);
    tick($this->token, 0.0085, 0.0084);

    $trading = app(TradingService::class);
    $trading->place($account, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 100]);

    $evidence = OrderFillEvidence::firstWhere('user_id', $account->id);

    expect($evidence)->pair_address->toBe('0xpair')
        ->source->toBe('dexscreener')
        ->market_price->toEqual(0.0085)
        ->previous_price->toEqual(0.0084)
        ->liquidity_usd->toEqual(5000000.0)
        ->and($evidence->price_at)->not->toBeNull()
        ->and($evidence->previous_price_at)->not->toBeNull();

    expect(fn () => $evidence->update(['market_price' => 1]))->toThrow(LogicException::class);
    expect(fn () => $evidence->delete())->toThrow(LogicException::class);

    $trading->reset($account);

    expect(Order::where('user_id', $account->id)->count())->toBe(0)
        ->and(OrderFillEvidence::where('user_id', $account->id)->count())->toBe(1);
});

test('an order that was refused leaves no evidence of a fill', function () {
    tick($this->token, 0.0085, 0.0084);
    $order = Order::factory()->for($this->user, 'user')->for($this->token)->create(['side' => 'buy', 'type' => 'limit', 'status' => 'pending', 'amount_usd' => 500, 'trigger_price' => 0.009]);
    $this->user->update(['balance_usd' => 1]);

    expect(app(TradingService::class)->execute($order, 0.0085)->status)->toBe(OrderStatus::Rejected)
        ->and(OrderFillEvidence::where('order_id', $order->id)->count())->toBe(0);
});
