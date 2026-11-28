<?php

namespace App\Services\Strategies\Rules;

/**
 * FutureSim's three_layer_any: the three-layer long with TP 2R in any BTC trend, to measure whether the BTC filter is
 * still needed.
 */
class ThreeLayerAnyMarket extends ThreeLayerTp2r
{
    protected function needsBtcAboveSma50(): bool
    {
        return false;
    }
}
