<?php

namespace App\Services\Strategies\Rules;

use App\Services\Strategies\Bars;

/**
 * Shadow of willy_momentum_long with the owner's H4 confirmation (as FutureSim, 26 Nov 2026): the same Willy cross, BTC
 * filter, stop and EMA50 exit, and the pair's 4h close at the signal above the SMA50 of its 4h closes. Spot backtest
 * (spot/review/sistem-future/spot.py): +0.239R per trade over 1,800 trades (random -0.014R), 2022-2024 +0.500R,
 * 2025-2026 -0.057R, without the best 5 +0.085R; account +22.9% a year, drawdown 46%.
 */
class WillyMomentumH4 extends WillyMomentumLong
{
    private const SMA_PERIOD = 50;

    public function setup(Bars $bars): ?array
    {
        $setup = parent::setup($bars);
        if ($setup === null) {
            return null;
        }

        $closes = array_slice($bars->c, -self::SMA_PERIOD);
        $close = end($closes);

        return $close > array_sum($closes) / self::SMA_PERIOD ? $setup : null;
    }
}
