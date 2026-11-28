<?php

namespace App\Services\Strategies\Rules;

use App\Models\StrategyPosition;
use App\Services\Strategies\StrategyContext;

/**
 * Shadow of willy_momentum_long for a higher win rate (as FutureSim, 26 Nov 2026): the same entry and stop, then half
 * the position sold once a 1h candle since the entry reached 0.5R (1R = 2 x ATR14 of the signal, above the fill price),
 * at that candle's close, and the stop moved to the fill price; the rest leaves at a 4h close under the EMA50. Spot
 * backtest (spot/review/sistem-future/spot.py): win rate 40%, -0.056R per trade, 2025-2026 -0.141R; the extra sale
 * costs more than it saves at spot fees.
 */
class WillyMomentumHalfBreakeven extends WillyMomentumLong
{
    public function manage(StrategyPosition $position, StrategyContext $context): ?array
    {
        $exit = parent::manage($position, $context);
        if ($exit !== null || ($position->state['half_sold'] ?? false)) {
            return $exit;
        }

        $risk = (float) ($position->state['risk'] ?? 0);
        if ($risk <= 0 || self::highSinceEntry($position, $context) < $position->entry_price + 0.5 * $risk) {
            return null;
        }

        $position->state = [...$position->state, 'half_sold' => true];
        $position->stop = max((float) $position->stop, $position->entry_price);

        return ['fraction' => 0.5, 'reason' => 'Separuh di 0,5R, stop ke harga masuk'];
    }

    /**
     * Highest 1h high since the hour the position opened in.
     */
    public static function highSinceEntry(StrategyPosition $position, StrategyContext $context): float
    {
        $bars = $context->bars($position->token_id, '1h', 500);
        $from = intdiv($position->opened_at->getTimestamp(), 3600) * 3600;
        $high = 0.0;
        foreach ($bars->t as $i => $time) {
            if ($time >= $from) {
                $high = max($high, $bars->h[$i]);
            }
        }

        return $high;
    }
}
