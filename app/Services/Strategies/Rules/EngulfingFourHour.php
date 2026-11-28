<?php

namespace App\Services\Strategies\Rules;

use App\Models\StrategyPosition;
use App\Models\Token;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\Indicators;
use App\Services\Strategies\StrategyContext;

/**
 * Experiment P2 on 4h (spot/review/candle_conf.py): a bullish engulfing at support (near the 48-candle low, within
 * 1% of the EMA50 or EMA200, or above the EMA200), confirmed by the next solid 4h candle closing above its high on
 * more volume than the 5 before, while BTC's 4h close is above its 4h EMA200. Stop under the two pattern candles;
 * sold when a shooting star or bearish engulfing is confirmed by a close under its low; at most 7 days.
 * Backtest +0.53% per trade over 206 trades, but -0.68% in the first half of the year.
 */
class EngulfingFourHour extends BaseStrategy
{
    public function timeframe(): string
    {
        return '1h';
    }

    public function maxHours(): int
    {
        return 168;
    }

    public function entry(Token $token, StrategyContext $context): ?array
    {
        if (! $context->fourHourClosed() || ! $context->btcAbove4hEma200()) {
            return null;
        }

        // The EMA200 starts from an SMA; 1000 candles (about 5.5 months) bring it within 0.1% of the backtest's.
        $bars = $context->bars4h($token->id, 1000);
        $j = $bars->count() - 1;
        $i = $j - 1;
        if ($i < 210 || ! $bars->contiguous(50, 14400) || ! $bars->bullishEngulfing($i)) {
            return null;
        }

        $ema50 = Indicators::ema($bars->c, 50);
        $ema200 = Indicators::ema($bars->c, 200);
        $support = $bars->l[$i] <= min(array_slice($bars->l, $i - 48, 48)) * 1.005;
        $near = min(abs($bars->l[$i] / $ema50[$i] - 1), abs($bars->l[$i] / $ema200[$i] - 1)) <= 0.01;

        if (! ($support || $near || $bars->c[$i] > $ema200[$i])) {
            return null;
        }

        if (! $bars->solid($j) || $bars->c[$j] <= $bars->h[$i] || $bars->v[$j] <= $bars->volumeAverage($j, 5)) {
            return null;
        }

        return ['reason' => 'Bullish engulfing 4 jam terkonfirmasi, BTC di atas EMA200 4 jam', 'stop' => min($bars->l[$i], $bars->l[$i - 1])];
    }

    public function manage(StrategyPosition $position, StrategyContext $context): ?array
    {
        if (! $context->fourHourClosed()) {
            return null;
        }

        $bars = $context->bars4h($position->token_id, 10);
        $last = $bars->count() - 1;
        $opened = $position->opened_at->getTimestamp();

        // The bearish pattern candle must close after the entry.
        return $last >= 2 && $opened < $bars->t[$last - 1] + 14400 && $bars->bearishConfirmed($last)
            ? ['fraction' => 1.0, 'reason' => 'Pola bearish 4 jam terkonfirmasi']
            : null;
    }
}
