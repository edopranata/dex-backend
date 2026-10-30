<?php

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Position;
use App\Models\Token;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->held = Token::factory()->create();
    $this->sold = Token::factory()->create();
    Position::factory()->for($this->user)->for($this->held)->create(['quantity' => 50]);
    Position::factory()->for($this->user)->for($this->sold)->create(['quantity' => 0]);
});

function historyIds(array $query): array
{
    return collect(test()->actingAs(test()->user)->getJson('/api/orders?'.http_build_query($query))->assertOk()->json('data'))
        ->pluck('id')->sort()->values()->all();
}

test('open positions scope only shows orders of tokens still held', function () {
    $held = Order::factory()->for($this->user)->for($this->held)->create();
    Order::factory()->for($this->user)->for($this->sold)->create();

    expect(historyIds(['scope' => 'open_positions']))->toBe([$held->id]);
});

test('date range uses the fill time in the viewer timezone', function () {
    Carbon::setTestNow('2026-10-30 12:00:00');
    // 2026-10-29 23:30 WIB, placed earlier but filled that evening.
    $evening = Order::factory()->for($this->user)->for($this->held)->create(['created_at' => '2026-10-28 10:00:00', 'filled_at' => '2026-10-29 16:30:00']);
    // 2026-10-30 07:30 WIB.
    $morning = Order::factory()->for($this->user)->for($this->held)->create(['created_at' => '2026-10-30 00:30:00']);

    expect(historyIds(['from' => '2026-10-29', 'to' => '2026-10-29', 'tz' => 'Asia/Jakarta']))->toBe([$evening->id])
        ->and(historyIds(['from' => '2026-10-30', 'tz' => 'Asia/Jakarta']))->toBe([$morning->id]);
});

test('side, type, source and result filters combine', function () {
    $win = Order::factory()->for($this->user)->for($this->held)->create(['side' => OrderSide::Sell, 'type' => OrderType::TakeProfit, 'status' => OrderStatus::Filled, 'realized_pnl' => 12.5]);
    $loss = Order::factory()->for($this->user)->for($this->held)->create(['side' => OrderSide::Sell, 'type' => OrderType::StopLoss, 'status' => OrderStatus::Filled, 'realized_pnl' => -4]);
    $buy = Order::factory()->for($this->user)->for($this->held)->create(['type' => OrderType::Limit]);

    expect(historyIds(['side' => 'sell']))->toBe([$win->id, $loss->id])
        ->and(historyIds(['result' => 'profit']))->toBe([$win->id])
        ->and(historyIds(['side' => 'buy', 'type' => 'limit']))->toBe([$buy->id])
        ->and(historyIds(['source' => 'signal']))->toBe([])
        ->and(historyIds(['source' => 'manual']))->toHaveCount(3);
});

test('the summary covers every matching order, not only the page', function () {
    Order::factory()->for($this->user)->for($this->held)->count(3)->create(['side' => OrderSide::Sell, 'status' => OrderStatus::Filled, 'realized_pnl' => 10, 'fee_usd' => 0.5]);
    Order::factory()->for($this->user)->for($this->held)->create(['side' => OrderSide::Sell, 'status' => OrderStatus::Filled, 'realized_pnl' => -5, 'fee_usd' => 0.5]);
    Order::factory()->for(User::factory())->for($this->held)->create(['realized_pnl' => 999]);

    $this->actingAs($this->user)->getJson('/api/orders?per_page=10&side=sell')
        ->assertOk()
        ->assertJsonPath('summary.orders', 4)
        ->assertJsonPath('summary.realized_pnl', 25)
        ->assertJsonPath('summary.fees', 2)
        ->assertJsonPath('summary.wins', 3)
        ->assertJsonPath('summary.losses', 1);
});

test('an end date before the start date is rejected', function () {
    $this->actingAs($this->user)->getJson('/api/orders?from=2026-10-30&to=2026-10-01')->assertJsonValidationErrors('to');
});
