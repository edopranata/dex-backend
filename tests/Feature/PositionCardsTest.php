<?php

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Trading\TradingService;

beforeEach(function () {
    $this->token = Token::factory()->create(['symbol' => 'PEPE', 'price_usd' => 2.0, 'price_updated_at' => now()]);
    $this->signal = Signal::factory()->for($this->token)->create(['entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);
    $this->user = User::factory()->create(['balance_usd' => 10_000]);
    $this->trading = app(TradingService::class);
});

function pendingExitOf(Order $buy, OrderType $type, ?string $label = null): Order
{
    return Order::query()->where('parent_id', $buy->id)->where('type', $type)->where('status', 'pending')
        ->when($label, fn ($query) => $query->where('note', 'like', "{$label}%"))->sole();
}

test('a position card shows the entry, the stop and the three targets of the signal it follows', function () {
    [$buy] = $this->trading->followSignal($this->user, $this->signal, 100);

    $card = $this->actingAs($this->user)->getJson('/api/portfolio')->assertOk()->json('positions.0');

    expect($card['plan']['lot_id'])->toBe($buy->id)
        ->and($card['plan']['signal_id'])->toBe($this->signal->id)
        ->and($card['plan']['entry'])->toEqual($card['avg_price'])
        ->and($card['plan']['stop'])->toEqual(1.8)
        ->and($card['plan']['stop_original'])->toEqual(1.8)
        ->and($card['plan']['stop_breakeven'])->toBeFalse()
        ->and(collect($card['plan']['targets'])->pluck('label')->all())->toBe(['TP1', 'TP2', 'TP3'])
        ->and(collect($card['plan']['targets'])->pluck('price')->all())->toEqual([2.3, 2.5, 2.8])
        ->and(collect($card['plan']['targets'])->pluck('filled')->all())->toBe([false, false, false])
        ->and($card['history'])->toBe([]);
});

test('after the first target the card marks it filled and shows the stop moved to the entry', function () {
    [$buy] = $this->trading->followSignal($this->user, $this->signal, 100);
    $this->trading->execute(pendingExitOf($buy, OrderType::TakeProfit, 'TP1'), 2.35);

    $plan = $this->actingAs($this->user)->getJson('/api/portfolio')->json('positions.0.plan');

    expect($plan['targets'][0]['filled'])->toBeTrue()
        ->and($plan['targets'][0]['filled_at'])->not->toBeNull()
        ->and($plan['targets'][1]['filled'])->toBeFalse()
        ->and($plan['stop'])->toEqual($buy->refresh()->filled_price)
        ->and($plan['stop_original'])->toEqual(1.8)
        ->and($plan['stop_breakeven'])->toBeTrue();
});

test('earlier trades in the token are listed under the card, newest first, with how each one ended', function () {
    [$first] = $this->trading->followSignal($this->user, $this->signal, 100);
    $this->travel(5)->minutes();
    $this->trading->execute(pendingExitOf($first, OrderType::StopLoss), 1.79);

    $this->travel(5)->minutes();
    [$second] = $this->trading->followSignal($this->user, $this->signal, 100);
    $this->travel(5)->minutes();
    $this->trading->execute(pendingExitOf($second, OrderType::TakeProfit, 'TP1'), 2.35);
    $this->travel(5)->minutes();
    $this->trading->execute(pendingExitOf($second, OrderType::StopLoss), 2.0);

    $this->travel(5)->minutes();
    $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 50]);

    $card = $this->actingAs($this->user)->getJson('/api/portfolio')->json('positions.0');

    expect($card['history_total'])->toBe(2)
        ->and(collect($card['history'])->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and(collect($card['history'][0]['exits'])->pluck('label')->all())->toBe(['TP1', 'SL di entry'])
        ->and(collect($card['history'][1]['exits'])->pluck('label')->all())->toBe(['SL'])
        ->and($card['history'][1]['pnl'])->toBeLessThan(0)
        ->and($card['history'][0]['lot_id'])->toBe($second->id)
        ->and($card['history'][0]['closed_at'])->not->toBeNull()
        // The new manual buy has no exits and no signal.
        ->and($card['plan']['targets'])->toBe([])
        ->and($card['plan']['stop'])->toBeNull()
        ->and($card['plan']['lot_id'])->toBeNull();
});

test('a manual buy with its own take profit and stop loss shows them as one target and one stop', function () {
    $this->trading->place($this->user, $this->token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => 100, 'take_profit_price' => 2.6, 'stop_loss_price' => 1.7]);

    $plan = $this->actingAs($this->user)->getJson('/api/portfolio')->json('positions.0.plan');

    expect($plan['stop'])->toEqual(1.7)
        ->and($plan['stop_original'])->toEqual(1.7)
        ->and($plan['targets'])->toHaveCount(1)
        ->and($plan['targets'][0]['label'])->toBe('TP')
        ->and($plan['targets'][0]['price'])->toEqual(2.6);
});

test('the auto-follow account portfolio carries the same cards', function () {
    $account = User::autoFollower('buy');
    $account->update(['balance_usd' => 10_000]);
    [$buy] = $this->trading->followSignal($account, $this->signal, 100);

    $this->actingAs($this->user)->getJson('/api/reports/accounts/buy/portfolio')->assertOk()
        ->assertJsonPath('positions.0.plan.lot_id', $buy->id)
        ->assertJsonCount(3, 'positions.0.plan.targets')
        ->assertJsonPath('positions.0.history', []);
});
