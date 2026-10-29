<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Signal;
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

test('a market buy with tp and sl places both exits for the bought quantity', function () {
    $buy = $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 1000, 'take_profit_price' => 2.6, 'stop_loss_price' => 1.8]);
    $exits = Order::query()->where('parent_id', $buy->id)->get()->keyBy(fn (Order $o) => $o->type->value);

    expect($exits)->toHaveCount(2)
        ->and($exits['take_profit']->trigger_price)->toEqual(2.6)
        ->and($exits['stop_loss']->trigger_price)->toEqual(1.8)
        ->and($exits['stop_loss']->quantity)->toEqualWithDelta($buy->filled_quantity, 1e-9)
        ->and($exits['stop_loss']->status)->toBe(OrderStatus::Pending);
});

test('when the stop loss fills its take profit is cancelled even if other tokens remain', function () {
    $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 500]);
    $buy = $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 1000, 'take_profit_price' => 2.6, 'stop_loss_price' => 1.8]);
    [$takeProfit, $stopLoss] = [Order::firstWhere(['parent_id' => $buy->id, 'type' => 'take_profit']), Order::firstWhere(['parent_id' => $buy->id, 'type' => 'stop_loss'])];

    $this->token->update(['price_usd' => 1.79]);
    $this->trading->matchPendingOrders(new Collection([$this->token->refresh()]));

    expect($stopLoss->refresh()->status)->toBe(OrderStatus::Filled)
        ->and($takeProfit->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($takeProfit->note)->toContain('OCO')
        ->and($this->user->positions()->first()->quantity)->toBeGreaterThan(0);
});

test('a limit buy places its tp and sl only after it fills', function () {
    $buy = $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'limit', 'amount_usd' => 500, 'trigger_price' => 1.8, 'take_profit_price' => 2.4, 'stop_loss_price' => 1.6]);

    expect(Order::where('parent_id', $buy->id)->count())->toBe(0);

    $this->token->update(['price_usd' => 1.79]);
    $this->trading->matchPendingOrders(new Collection([$this->token->refresh()]));

    expect($buy->refresh()->status)->toBe(OrderStatus::Filled)
        ->and(Order::where('parent_id', $buy->id)->where('status', OrderStatus::Pending)->count())->toBe(2);
});

test('a cancelled limit buy never places its exits', function () {
    $buy = $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'limit', 'amount_usd' => 500, 'trigger_price' => 1.8, 'stop_loss_price' => 1.6]);
    $this->trading->cancel($buy);

    $this->token->update(['price_usd' => 1.5]);
    $this->trading->matchPendingOrders(new Collection([$this->token->refresh()]));

    expect(Order::where('parent_id', $buy->id)->count())->toBe(0);
});

test('tp and sl must be on the right side of the entry price', function (array $exit, string $field) {
    expect(fn () => $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 100, ...$exit]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));

    expect(Order::count())->toBe(0);
})->with([
    'stop loss above market' => [['stop_loss_price' => 2.1], 'stop_loss_price'],
    'take profit below market' => [['take_profit_price' => 1.9], 'take_profit_price'],
]);

test('the order api accepts tp and sl on buys only', function () {
    $this->actingAs($this->user)->postJson('/api/orders', [
        'token_id' => $this->token->id, 'side' => 'buy', 'type' => 'market', 'amount_usd' => 100, 'take_profit_price' => 2.5, 'stop_loss_price' => 1.9,
    ])->assertCreated()->assertJsonPath('data.stop_loss_price', 1.9);

    $this->actingAs($this->user)->postJson('/api/orders', [
        'token_id' => $this->token->id, 'side' => 'sell', 'type' => 'market', 'quantity' => 1, 'stop_loss_price' => 1.9,
    ])->assertJsonValidationErrors('stop_loss_price');
});

test('following a signal with a limit fills at once when the price is already in the entry zone', function () {
    $signal = Signal::factory()->for($this->token)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);

    $orders = $this->trading->followSignal($this->user, $signal, 300, limit: true);
    $buy = array_shift($orders);
    $exits = $orders;

    expect($buy->type)->toBe(OrderType::Limit)
        ->and($buy->trigger_price)->toEqual(2.05)
        ->and($buy->status)->toBe(OrderStatus::Filled)
        ->and($exits)->toHaveCount(4)
        ->and(collect($exits)->where('type', OrderType::StopLoss)->first()->quantity)->toEqualWithDelta($buy->filled_quantity, 1e-9)
        ->and(collect($exits)->where('type', OrderType::TakeProfit)->sum('quantity'))->toEqualWithDelta($buy->filled_quantity, 1e-9);
});

test('a signal limit above the market waits and places its exits only when filled', function () {
    $signal = Signal::factory()->for($this->token)->create(['entry_low' => 1.85, 'entry_high' => 1.9, 'stop_loss' => 1.7, 'take_profit_1' => 2.1, 'take_profit_2' => 2.3, 'take_profit_3' => 2.6]);

    [$buy] = $this->trading->followSignal($this->user, $signal, 300, limit: true);

    expect($buy->status)->toBe(OrderStatus::Pending)
        ->and(Order::where('parent_id', $buy->id)->count())->toBe(0);

    $this->token->update(['price_usd' => 1.89]);
    $this->trading->matchPendingOrders(new Collection([$this->token->refresh()]));

    expect($buy->refresh()->status)->toBe(OrderStatus::Filled)
        ->and(Order::where('parent_id', $buy->id)->where('status', OrderStatus::Pending)->count())->toBe(4);
});

test('after the first target the stop moves to the entry price for what is left, and the stop cancels the rest', function () {
    $signal = Signal::factory()->for($this->token)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);
    [$buy] = $this->trading->followSignal($this->user, $signal, 300, limit: true);
    $stop = Order::firstWhere(['parent_id' => $buy->id, 'type' => 'stop_loss']);

    $this->token->update(['price_usd' => 2.31]);
    $this->trading->matchPendingOrders(new Collection([$this->token->refresh()]));

    expect($stop->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($stop->trigger_price)->toEqualWithDelta($buy->filled_price, 1e-9)
        ->and($stop->quantity)->toEqualWithDelta($buy->filled_quantity * 2 / 3, 1e-9);

    $this->token->update(['price_usd' => 1.99]);
    $this->trading->matchPendingOrders(new Collection([$this->token->refresh()]));

    expect($stop->refresh()->status)->toBe(OrderStatus::Filled)
        ->and(Order::where('parent_id', $buy->id)->where('status', OrderStatus::Pending)->count())->toBe(0)
        ->and($this->user->positions()->first()->quantity)->toEqual(0);
});

test('the last target cancels the remaining stop', function () {
    $signal = Signal::factory()->for($this->token)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);
    [$buy] = $this->trading->followSignal($this->user, $signal, 300, limit: true);

    $this->token->update(['price_usd' => 2.9]);
    $this->trading->matchPendingOrders(new Collection([$this->token->refresh()]));

    expect(Order::where('parent_id', $buy->id)->where('type', 'take_profit')->where('status', OrderStatus::Filled)->count())->toBe(3)
        ->and(Order::firstWhere(['parent_id' => $buy->id, 'type' => 'stop_loss'])->status)->toBe(OrderStatus::Cancelled);
});

test('a closed signal or a price below its stop cannot be followed', function () {
    $closed = Signal::factory()->for($this->token)->create(['status' => 'stop_loss', 'closed_at' => now()]);
    $broken = Signal::factory()->for($this->token)->create(['stop_loss' => 2.1, 'entry_high' => 2.2, 'take_profit_1' => 2.5, 'take_profit_2' => 2.6, 'take_profit_3' => 2.7]);

    expect(fn () => $this->trading->followSignal($this->user, $closed, 100, limit: true))->toThrow(ValidationException::class, 'sudah ditutup')
        ->and(fn () => $this->trading->followSignal($this->user, $broken, 100, limit: true))->toThrow(ValidationException::class, 'di bawah stop loss');
});
