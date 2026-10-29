<?php

namespace App\Services\Analysis;

/**
 * Entry zone, stop loss and take profit levels from volatility (ATR) and the recent swing low.
 */
class TradeLevels
{
    /**
     * @param  list<float>  $rMultiples
     * @return array{entry_low: float, entry_high: float, stop_loss: float, take_profit_1: float, take_profit_2: float, take_profit_3: float}
     */
    public static function calculate(float $price, float $atr, float $swingLow, array $rMultiples, float $minAtr, float $maxAtr): array
    {
        $entryHigh = $price;
        $entryLow = max($price - 0.5 * $atr, $price * 0.5);
        $entry = ($entryLow + $entryHigh) / 2;

        $structuralDistance = $entry - ($swingLow - 0.2 * $atr);
        $distance = max($minAtr * $atr, min($maxAtr * $atr, $structuralDistance));
        $stopLoss = max($entry - $distance, $entry * 0.05);
        $risk = $entry - $stopLoss;

        return [
            'entry_low' => $entryLow,
            'entry_high' => $entryHigh,
            'stop_loss' => $stopLoss,
            'take_profit_1' => $entry + $risk * $rMultiples[0],
            'take_profit_2' => $entry + $risk * $rMultiples[1],
            'take_profit_3' => $entry + $risk * $rMultiples[2],
        ];
    }
}
