<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Token;
use App\Models\User;
use App\Services\Trading\TradingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['market.simulator.fee_rate' => 0.003]);

    $this->user = User::factory()->create(['balance_usd' => 10_000]);
    $this->token = Token::factory()->create(['price_usd' => 2.0, 'liquidity_usd' => 2_000_000]);
    $this->trading = app(TradingService::class);
});

test('market buy charges fee and slippage and records cost basis', function () {
    $order = $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 1000]);

    $impact = TradingService::priceImpact(997, 2_000_000);
    $fillPrice = 2.0 / (1 - $impact);
    $position = $this->user->positions()->first();

    expect($order->status)->toBe(OrderStatus::Filled)
        ->and($order->fee_usd)->toEqualWithDelta(3.0, 1e-6)
        ->and($order->filled_price)->toEqualWithDelta($fillPrice, 1e-9)
        ->and($order->filled_quantity)->toEqualWithDelta(997 / $fillPrice, 1e-6)
        ->and($this->user->refresh()->balance_usd)->toEqualWithDelta(9000, 1e-6)
        ->and($position->avg_price)->toEqualWithDelta(1000 / $order->filled_quantity, 1e-9);
});

test('selling the whole position realizes pnl and cancels other pending sells', function () {
    $buy = $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 1000]);
    $stop = $this->trading->place($this->user, $this->token, ['side' => 'sell', 'type' => 'stop_loss', 'quantity' => $buy->filled_quantity, 'trigger_price' => 1.5]);

    $this->token->update(['price_usd' => 2.2]);
    $sell = $this->trading->place($this->user, $this->token, ['side' => 'sell', 'type' => 'market', 'quantity' => $buy->filled_quantity]);

    expect($sell->status)->toBe(OrderStatus::Filled)
        ->and($sell->realized_pnl)->toBeGreaterThan(90)->toBeLessThan(100)
        ->and($this->user->refresh()->balance_usd)->toEqualWithDelta(9000 + $sell->total_usd, 1e-6)
        ->and($this->user->positions()->first()->quantity)->toEqual(0)
        ->and($stop->refresh()->status)->toBe(OrderStatus::Cancelled);
});

test('market buy above the balance is rejected', function () {
    $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 20_000]);
})->throws(ValidationException::class);

test('selling without a position is rejected', function () {
    $this->trading->place($this->user, $this->token, ['side' => 'sell', 'type' => 'market', 'quantity' => 1]);
})->throws(ValidationException::class);

test('pending orders fill only when their trigger is reached', function () {
    $limit = $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'limit', 'amount_usd' => 500, 'trigger_price' => 1.8]);

    expect($this->trading->matchPendingOrders(new Collection([$this->token])))->toBe(0)
        ->and($limit->refresh()->status)->toBe(OrderStatus::Pending);

    $this->token->update(['price_usd' => 1.79]);

    expect($this->trading->matchPendingOrders(new Collection([$this->token->refresh()])))->toBe(1)
        ->and($limit->refresh()->status)->toBe(OrderStatus::Filled);

    $stop = $this->trading->place($this->user, $this->token, ['side' => 'sell', 'type' => 'stop_loss', 'quantity' => $limit->filled_quantity, 'trigger_price' => 1.6]);
    $this->token->update(['price_usd' => 1.59]);
    $this->trading->matchPendingOrders(new Collection([$this->token->refresh()]));

    expect($stop->refresh()->status)->toBe(OrderStatus::Filled)
        ->and($stop->realized_pnl)->toBeLessThan(0);
});

test('price impact follows the constant product curve', function () {
    expect(TradingService::priceImpact(1000, null))->toBe(0.0)
        ->and(TradingService::priceImpact(50_000, 100_000))->toEqualWithDelta(0.5, 1e-9);
});

test('reset restores the starting balance and clears history', function () {
    config(['market.simulator.starting_balance' => 10_000]);
    $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 1000]);

    $this->trading->reset($this->user);

    expect($this->user->refresh()->balance_usd)->toEqual(10_000)
        ->and(Order::count())->toBe(0)
        ->and($this->user->positions()->count())->toBe(0);
});
