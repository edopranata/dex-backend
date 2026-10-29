<?php

use App\Enums\SignalStatus;
use App\Models\Signal;
use App\Services\Analysis\SignalTracker;

beforeEach(function () {
    $this->tracker = new SignalTracker;
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
