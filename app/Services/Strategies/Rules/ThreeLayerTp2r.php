<?php

namespace App\Services\Strategies\Rules;

/**
 * Three-layer entry, sold at twice the risk or at the stop, at most 7 days. The steadiest exit per year in the
 * 16 Nov 2026 backtest; 17 Nov on 138 pairs with the divergence lock: +1.22% per trade while BTC was above its SMA50.
 */
class ThreeLayerTp2r extends ThreeLayer
{
    public function maxHours(): int
    {
        return 168;
    }

    protected function exitPlan(): array
    {
        return ['take_profit_r' => 2.0];
    }
}
