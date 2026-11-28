<?php

namespace App\Services\Strategies\Rules;

use App\Models\StrategyPosition;
use App\Services\Strategies\StrategyContext;

/**
 * Shadow of willy_momentum_long with fewer full losses (as FutureSim, 26 Nov 2026): the same entry, stop and EMA50
 * exit, and the stop moved to the fill price once a 1h candle since the entry reached 1R (2 x ATR14 of the signal,
 * above the fill price). Spot backtest (spot/review/sistem-future/spot.py): +0.074R per trade, 2025-2026 -0.104R,
 * account -1.5% a year.
 */
class WillyMomentumBreakeven extends WillyMomentumLong
{
    public function manage(StrategyPosition $position, StrategyContext $context): ?array
    {
        $exit = parent::manage($position, $context);
        if ($exit !== null || ($position->state['breakeven'] ?? false)) {
            return $exit;
        }

        $risk = (float) ($position->state['risk'] ?? 0);
        if ($risk > 0 && WillyMomentumHalfBreakeven::highSinceEntry($position, $context) >= $position->entry_price + $risk) {
            $position->state = [...$position->state, 'breakeven' => true];
            $position->stop = max((float) $position->stop, $position->entry_price);
        }

        return null;
    }
}
