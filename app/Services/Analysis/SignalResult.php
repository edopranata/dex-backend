<?php

namespace App\Services\Analysis;

use App\Models\Signal;
use App\Services\Trading\TradingService;

/**
 * A signal's result in R: 1/3 of the position leaves at each target reached and the rest at the
 * actual exit price (a stop can fill below its level). The net result also pays the swap fee and
 * price impact on the way in and out, for a position of the auto-follow size.
 */
class SignalResult
{
    /**
     * @return array{gross_r: float, net_r: float, cost_r: float}
     */
    public function calculate(Signal $signal, float $exitPrice, int $maxTpHit, ?float $liquidityUsd): array
    {
        $entry = (float) $signal->entry_price;
        $risk = $entry - (float) $signal->stop_loss;

        if ($risk <= 0 || $entry <= 0) {
            return ['gross_r' => 0.0, 'net_r' => 0.0, 'cost_r' => 0.0];
        }

        $exits = array_map(fn (float $target): array => [$target, 1 / 3], array_slice($signal->takeProfits(), 0, $maxTpHit));

        if ($maxTpHit < 3) {
            $exits[] = [$exitPrice, (3 - $maxTpHit) / 3];
        }

        $size = (float) config('market.autofollow.amount_usd');
        $gross = 0.0;
        $costUsd = $this->cost($size, $liquidityUsd);

        foreach ($exits as [$price, $share]) {
            $gross += $share * ($price - $entry) / $risk;
            $costUsd += $this->cost($size * $share * $price / $entry, $liquidityUsd);
        }

        $costR = $size > 0 ? $costUsd / ($size * $risk / $entry) : 0.0;

        return ['gross_r' => round($gross, 2), 'net_r' => round($gross - $costR, 2), 'cost_r' => round($costR, 2)];
    }

    private function cost(float $usd, ?float $liquidityUsd): float
    {
        return $usd * ((float) config('market.simulator.fee_rate') + TradingService::priceImpact($usd, $liquidityUsd));
    }
}
