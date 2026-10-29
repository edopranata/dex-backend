<?php

use App\Services\Analysis\Indicators;
use App\Services\Analysis\TradeLevels;

test('sma averages the trailing window', function () {
    expect(Indicators::sma([1.0, 2.0, 3.0, 4.0, 5.0], 3))->toBe([null, null, 2.0, 3.0, 4.0]);
});

test('ema is seeded with the sma of the first period', function () {
    $ema = Indicators::ema([1.0, 2.0, 3.0, 4.0, 5.0], 3);

    expect($ema[1])->toBeNull()
        ->and($ema[2])->toBe(2.0)
        ->and($ema[3])->toBe(3.0)
        ->and($ema[4])->toBe(4.0);
});

test('rsi is 100 when price only rises and 50 when flat', function () {
    expect(Indicators::last(Indicators::rsi(range(1, 30))))->toBe(100.0)
        ->and(Indicators::last(Indicators::rsi(array_fill(0, 30, 5.0))))->toBe(50.0);
});

test('macd histogram is positive in an accelerating uptrend', function () {
    $closes = array_map(fn (int $i): float => 100 + $i ** 1.5, range(1, 60));

    expect(Indicators::last(Indicators::macd($closes)['histogram']))->toBeGreaterThan(0);
});

test('atr equals the constant candle range', function () {
    $highs = array_fill(0, 30, 11.0);
    $lows = array_fill(0, 30, 9.0);
    $closes = array_fill(0, 30, 10.0);

    expect(Indicators::last(Indicators::atr($highs, $lows, $closes)))->toBe(2.0);
});

test('trade levels place the stop below entry and targets at R multiples', function () {
    $levels = TradeLevels::calculate(price: 100, atr: 2, swingLow: 97, rMultiples: [1.5, 2.5, 4], minAtr: 1, maxAtr: 3);
    $entry = ($levels['entry_low'] + $levels['entry_high']) / 2;
    $risk = $entry - $levels['stop_loss'];

    expect($levels['entry_high'])->toBe(100.0)
        ->and($levels['entry_low'])->toBe(99.0)
        ->and($risk)->toBeGreaterThanOrEqual(2.0)->toBeLessThanOrEqual(6.0)
        ->and($levels['take_profit_1'])->toEqualWithDelta($entry + 1.5 * $risk, 1e-9)
        ->and($levels['take_profit_3'])->toEqualWithDelta($entry + 4 * $risk, 1e-9);
});

test('stop distance is clamped to the atr bounds', function () {
    $far = TradeLevels::calculate(price: 100, atr: 1, swingLow: 50, rMultiples: [1.5, 2.5, 4], minAtr: 1, maxAtr: 3);
    $near = TradeLevels::calculate(price: 100, atr: 1, swingLow: 99.9, rMultiples: [1.5, 2.5, 4], minAtr: 1, maxAtr: 3);

    expect(99.75 - $far['stop_loss'])->toEqualWithDelta(3.0, 1e-9)
        ->and(99.75 - $near['stop_loss'])->toEqualWithDelta(1.0, 1e-9);
});
