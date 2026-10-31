<?php

use App\Enums\SignalStatus;
use App\Models\Signal;
use App\Models\Token;
use App\Services\Analysis\SignalTracker;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->tracker = app(SignalTracker::class);
});

test('pending signal activates inside the entry zone', function () {
    $signal = Signal::factory()->create();

    $this->tracker->track($signal, 0.99);

    expect($signal->refresh()->status)->toBe(SignalStatus::Active)
        ->and($signal->entry_price)->toBe(0.99);
});

test('pending signal is invalidated when price hits the stop first', function () {
    $signal = Signal::factory()->create();

    $this->tracker->track($signal, 0.94);

    expect($signal->refresh()->status)->toBe(SignalStatus::Invalidated)
        ->and($signal->closed_at)->not->toBeNull();
});

test('pending signal expires when entry never comes', function () {
    $signal = Signal::factory()->create(['created_at' => now()->subHours(13)]);

    $this->tracker->track($signal, 1.05);

    expect($signal->refresh()->status)->toBe(SignalStatus::Expired);
});

test('stop loss before any target loses one R', function () {
    $signal = Signal::factory()->active(1.0)->create();

    $this->tracker->track($signal, 0.95);

    expect($signal->refresh()->status)->toBe(SignalStatus::StopLoss)
        ->and($signal->result_r)->toBe(-1.0);
});

test('after TP1 the stop moves to breakeven and the trade closes in profit', function () {
    $signal = Signal::factory()->active(1.0)->create();

    $this->tracker->track($signal, 1.07);
    expect($signal->refresh()->max_tp_hit)->toBe(1);

    $this->tracker->track($signal, 1.0);

    expect($signal->refresh()->status)->toBe(SignalStatus::TakeProfit)
        ->and($signal->result_r)->toEqualWithDelta((0.06 / 0.05) / 3, 0.01);
});

test('hitting all targets closes the signal with the full scaled result', function () {
    $signal = Signal::factory()->active(1.0)->create();

    $this->tracker->track($signal, 1.20);

    $expected = (0.06 + 0.10 + 0.16) / 0.05 / 3;

    expect($signal->refresh()->status)->toBe(SignalStatus::TakeProfit)
        ->and($signal->max_tp_hit)->toBe(3)
        ->and($signal->result_r)->toEqualWithDelta($expected, 0.01);
});

test('a stop that gaps below its level counts the real exit, and the net result pays fees and slippage', function () {
    config(['market.simulator.fee_rate' => 0.003, 'market.autofollow.amount_usd' => 100]);
    $token = Token::factory()->create(['liquidity_usd' => 1_000_000]);
    $signal = Signal::factory()->active(1.0)->for($token)->create();

    $this->tracker->track($signal, 0.94);
    $signal->refresh();

    expect($signal->status)->toBe(SignalStatus::StopLoss)
        ->and($signal->result_r)->toBe(-1.2)
        ->and($signal->cost_r)->toBeGreaterThan(0.1)->toBeLessThan(0.15)
        ->and($signal->net_r)->toBeLessThan(-1.3);
});

test('the recalculate command fills net results without sending signal notifications', function () {
    Queue::fake();
    $signal = Signal::factory()->active(1.0)->create(['status' => 'stop_loss', 'exit_price' => 0.95, 'result_r' => -1.0, 'closed_at' => now()]);

    $this->artisan('market:signals-recalculate')->assertSuccessful();

    expect($signal->refresh()->net_r)->toBeLessThan(-1.0)->and($signal->result_r)->toBe(-1.0);
    Queue::assertNothingPushed();
});
