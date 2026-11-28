<?php

namespace App\Services\Strategies\Rules;

use App\Models\StrategyPosition;
use App\Models\Token;
use App\Services\Strategies\Bars;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\Indicators;
use App\Services\Strategies\StrategyContext;

/**
 * The owner's three-layer buy (16 Nov 2026, spot/review/div3.py): a bullish RSI(14) divergence on 4h (lower low,
 * higher RSI low, one RSI under 35, 5 to 30 candles apart), then within 24 hours a 1h hammer or bullish engulfing
 * confirmed by a solid green candle closing above its high, volume of either at least 1.5x the 20-hour average,
 * the confirmation closing above the 1h EMA50 or EMA200, and only while BTC is above its daily SMA50. The stop is
 * 1.5% under the lowest wick of the pattern and the divergence. The exits differ per subclass.
 *
 * A new divergence is skipped while the previous one on the pair is still playing out: its low not broken by
 * 1.5% and under 30 days old (lockedByEarlier). The 16 Nov backtests applied this by accident; without it the rule
 * loses. Backtest 17 Nov 2026 (spot/review/atr, 138 pairs, BTC above SMA50, TP 2R): +1.22% per trade over 244
 * trades; on 101 alts never used to build the rule +1.21% over 169; without the lock -0.49% over 611.
 * 19 Nov 2026: all 339 Bybit pairs +0.81% (615 trades); the Binance archive with delisted coins +0.68% (no
 * survivorship bias), but by year it leans on 2024: Bybit 2024 +2.2%, 2025 -0.9%; Binance 2025 -0.6%, 2026 -1.7%.
 */
abstract class ThreeLayer extends BaseStrategy
{
    /** About 125 days: the indicators, plus earlier divergences whose lock (up to 30 days) may still hold. */
    protected const HOURS_1H = 3000;

    /** How long a divergence locks the pair at most, and how far under its low the price must trade to free it. */
    private const LOCK_HOURS = 720;

    private const LOCK_BREAK = 0.985;

    public function timeframe(): string
    {
        return '1h';
    }

    public function entry(Token $token, StrategyContext $context): ?array
    {
        if ($this->needsBtcAboveSma50() && ! $context->btcAboveDailySma50()) {
            return null;
        }

        $h1 = $context->bars($token->id, '1h', self::HOURS_1H);
        $j = $h1->count() - 1;
        $i = $j - 1;

        if ($j < 200 || ! $h1->contiguous(22, 3600) || ! ($h1->hammer($i) || $h1->bullishEngulfing($i)) || ! $h1->solid($j) || $h1->c[$j] <= $h1->h[$i]) {
            return null;
        }

        if (max($h1->v[$i], $h1->v[$j]) < 1.5 * $h1->volumeAverage($j, 20)) {
            return null;
        }

        $ema50 = Indicators::ema($h1->c, 50);
        $ema200 = Indicators::ema($h1->c, 200);
        if (! ($h1->c[$j] > ($ema50[$j] ?? INF) || $h1->c[$j] > ($ema200[$j] ?? INF))) {
            return null;
        }

        $h4 = $context->bars4h($token->id, intdiv(self::HOURS_1H, 4));
        if (! $h4->contiguous(40, 14400)) {
            return null;  // pivots and the 5-30 candle spacing are read by position
        }
        $divergence = $this->divergence($h4, $h1, $j);
        if ($divergence === null || self::lockedByEarlier(self::divergences($h4), $h1, $divergence['at'])) {
            return null;
        }

        $low = min(min(array_slice($h1->l, max(0, $i - 2), $j - $i + 3)), $divergence['low']);

        return [
            'reason' => 'Divergence RSI 4 jam, konfirmasi candle 1 jam, volume',
            'stop' => $low * 0.985,
            // One trade per divergence (the runner skips an entry whose once_key it has already traded).
            'state' => ['once_key' => "divergence:{$divergence['at']}"],
        ] + $this->exitPlan();
    }

    /**
     * Whether entries wait for BTC's daily close above its SMA50 (FutureSim's three_layer_any does not).
     */
    protected function needsBtcAboveSma50(): bool
    {
        return true;
    }

    /**
     * Target settings added to the entry, e.g. ['take_profit_r' => 2].
     *
     * @return array<string, float>
     */
    protected function exitPlan(): array
    {
        return [];
    }

    /**
     * The latest bullish divergence that became known (its pivot confirmed two 4h candles later) at most 24 hours
     * before the confirmation candle, and whose low has not been broken since.
     *
     * @return array{at: int, low: float}|null
     */
    private function divergence(Bars $h4, Bars $h1, int $j): ?array
    {
        $rsi = Indicators::rsi($h4->c, 14);
        $pivots = array_values(array_filter($h4->pivots(true), fn (int $k): bool => $rsi[$k] !== null));

        for ($a = count($pivots) - 1; $a > 0; $a--) {
            $k2 = $pivots[$a];
            if ($k2 + 2 >= $h4->count()) {
                continue;
            }
            $known = $h4->t[$k2 + 2] + 14400;
            if ($h1->t[$j] - $known > 86400) {
                return null;  // older pivots are even older
            }
            // The pattern candle itself must open after the divergence is known (backtest: i >= i0).
            if ($h1->t[$j - 1] < $known) {
                continue;
            }

            for ($b = $a - 1; $b >= 0; $b--) {
                $k1 = $pivots[$b];
                if ($k2 - $k1 < 5) {
                    continue;
                }
                if ($k2 - $k1 > 30) {
                    break;
                }
                if ($h4->l[$k2] < $h4->l[$k1] && $rsi[$k2] > $rsi[$k1] && min($rsi[$k1], $rsi[$k2]) < 35) {
                    $low = min(array_slice($h4->l, $k1, $k2 - $k1 + 3));
                    // From the divergence up to the pattern candle; the confirmation candle's low does not count.
                    $since = array_keys(array_filter($h1->t, fn (int $t): bool => $t >= $known && $t < $h1->t[$j]));
                    $broken = $since !== [] && min(array_map(fn (int $x): float => $h1->l[$x], $since)) < $low;

                    return $broken || $this->earlierPattern($h1, $since, $j) ? null : ['at' => $h4->t[$k2], 'low' => $low];
                }
            }
        }

        return null;
    }

    /**
     * Every bullish divergence in the 4h candles, oldest first, with the moment it became known and its low.
     *
     * @return list<array{at: int, known: int, low: float}>
     */
    public static function divergences(Bars $h4): array
    {
        $rsi = Indicators::rsi($h4->c, 14);
        $pivots = array_values(array_filter($h4->pivots(true), fn (int $k): bool => $rsi[$k] !== null));
        $found = [];

        foreach ($pivots as $a => $k2) {
            if ($k2 + 2 >= $h4->count()) {
                break;
            }
            for ($b = $a - 1; $b >= 0; $b--) {
                $k1 = $pivots[$b];
                if ($k2 - $k1 < 5) {
                    continue;
                }
                if ($k2 - $k1 > 30) {
                    break;
                }
                if ($h4->l[$k2] < $h4->l[$k1] && $rsi[$k2] > $rsi[$k1] && min($rsi[$k1], $rsi[$k2]) < 35) {
                    $found[] = ['at' => $h4->t[$k2], 'known' => $h4->t[$k2 + 2] + 14400, 'low' => min(array_slice($h4->l, $k1, $k2 - $k1 + 3))];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Whether the divergence at `at` came while an earlier one still locked the pair. Divergences are taken in order;
     * one that is not itself skipped locks the pair from the moment it is known until a 1h candle trades 1.5% under
     * its low, or for 30 days. Divergences older than the loaded candles are not seen (a lock lasts 30 days at most).
     *
     * @param  list<array{at: int, known: int, low: float}>  $divergences
     */
    public static function lockedByEarlier(array $divergences, Bars $h1, int $at): bool
    {
        $lockedUntil = 0;

        foreach ($divergences as $divergence) {
            if ($divergence['at'] === $at) {
                return $divergence['known'] < $lockedUntil;
            }
            if ($divergence['known'] < $lockedUntil || $divergence['known'] < ($h1->t[0] ?? PHP_INT_MAX)) {
                continue;
            }

            $lockedUntil = $divergence['known'] + self::LOCK_HOURS * 3600;
            foreach ($h1->t as $x => $time) {
                if ($time >= $lockedUntil) {
                    break;
                }
                if ($time >= $divergence['known'] && $h1->l[$x] <= $divergence['low'] * self::LOCK_BREAK) {
                    $lockedUntil = $time;
                    break;
                }
            }
        }

        return false;
    }

    /**
     * Only the first confirmed pattern after a divergence counts (as in the backtest): an earlier one, even one
     * that failed the volume or EMA check, uses the divergence up.
     *
     * @param  list<int>  $since  1h indexes from the moment the divergence became known
     */
    private function earlierPattern(Bars $h1, array $since, int $j): bool
    {
        foreach ($since as $x) {
            if ($x + 1 >= $j) {
                break;
            }
            if (($h1->hammer($x) || $h1->bullishEngulfing($x)) && $h1->solid($x + 1) && $h1->c[$x + 1] > $h1->h[$x]) {
                return true;
            }
        }

        return false;
    }

    public function manage(StrategyPosition $position, StrategyContext $context): ?array
    {
        return null;
    }
}
