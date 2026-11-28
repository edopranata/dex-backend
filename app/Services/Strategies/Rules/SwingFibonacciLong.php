<?php

namespace App\Services\Strategies\Rules;

use App\Models\Token;
use App\Services\Strategies\Bars;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\StrategyContext;

/**
 * FutureSim's swing_fib_4h on DEX candles, long side only (a DEX swap cannot go short; short setups are skipped).
 *
 * The owner's momentum + Fibonacci setup in its swing form (20 Nov 2026, future/review/momentum-fib/swing.py), on 4h
 * candles built from 1h, with the BTC trend:
 *
 *  1. 3-5 short candles (each range <= 0.7 ATR14) form a box; one long candle (range >= 1.8 ATR14, body >= 60%)
 *     closes outside it in its own direction.
 *  2. The move runs on to a swing: the furthest high (low for a short) after the momentum candle, confirmed when the
 *     next candle does not exceed it. Cancelled if the price returns to the momentum candle's base first, or no
 *     swing within 13 candles.
 *  3. Fibonacci from the base to the swing: a limit entry at 0.236, stop at 0.745, target at -0.27. The order waits
 *     12 candles and is dropped if the target trades first. Long only while BTC's daily close is above its SMA50,
 *     short only below.
 *
 * Backtest (136 Bybit perpetuals with >= $5M turnover, 2022-2026): +0.167R per trade with maker fees, +0.146R taker,
 * 268 trades, positive in both halves and without the best 10 trades (+0.112R). On FutureSim's 30 contracts with real
 * funding +0.179R (105 trades). Experiment: many variants were tried before this one, so it is measured live.
 */
class SwingFibonacciLong extends BaseStrategy
{
    private const SHORT = 0.7;

    private const LONG = 1.8;

    private const BODY = 0.6;

    private const EXPIRY = 12;

    /** Candles after the swing that must not exceed it. */
    private const CONFIRM = 1;

    private const STEP = 14400;

    protected const LABEL = 'Swing Fibonacci 4 jam';

    public function maxHours(): int
    {
        return 0;  // held until the stop or the target, as in the backtest
    }

    public function entry(Token $token, StrategyContext $context): ?array
    {
        if (! $context->fourHourClosed() || ! $context->btcAboveDailySma50()) {
            return null;  // long setups only with the BTC trend
        }

        $bars = $context->bars4h($token->id, 60);
        $last = $bars->count() - 1;
        if ($last < 40 || ! $bars->contiguous(40, self::STEP) || $bars->t[$last] !== $context->hourEnd - self::STEP) {
            return null;
        }

        $atr = self::atr14($bars);
        // The setup whose swing the candle that just closed confirmed.
        for ($i = $last - 1; $i >= max(20, $last - self::EXPIRY - self::CONFIRM - 1); $i--) {
            $setup = $this->setupAt($bars, $atr, $i);
            if ($setup === null || $setup['confirmed'] !== $last) {
                continue;
            }
            if ($setup['side'] !== 'long' || $bars->c[$last] <= $setup['stop']) {
                return null;
            }

            return [
                'reason' => static::LABEL.': momentum '.gmdate('d M H:i', $bars->t[$i] + 25200).' WIB, swing '.self::price($setup['tip']).', BTC di atas SMA50',
                'limit' => $setup['entry'], 'stop' => $setup['stop'], 'take_profit' => $setup['target'],
                'cancel_price' => $setup['target'], 'expires_at' => $bars->t[$last] + self::STEP * (self::EXPIRY + 1),
            ];
        }

        return null;
    }

    /**
     * The momentum candle at i and its swing, or null.
     *
     * @param  list<float|null>  $atr
     * @return array{side: 'long'|'short', confirmed: int, tip: float, entry: float, stop: float, target: float}|null
     */
    private function setupAt(Bars $bars, array $atr, int $i): ?array
    {
        $a = $atr[$i - 1] ?? null;
        if ($a === null || $a <= 0) {
            return null;
        }
        $short = 0;
        while ($short < 5 && self::SHORT * $a >= $bars->h[$i - 1 - $short] - $bars->l[$i - 1 - $short]) {
            $short++;
        }
        $range = $bars->h[$i] - $bars->l[$i];
        if ($short < 3 || $range < self::LONG * $a || abs($bars->c[$i] - $bars->o[$i]) < self::BODY * $range) {
            return null;
        }
        $boxHigh = max(array_slice($bars->h, $i - $short, $short));
        $boxLow = min(array_slice($bars->l, $i - $short, $short));
        $d = match (true) {
            $bars->c[$i] > $bars->o[$i] && $bars->c[$i] > $boxHigh => 1,
            $bars->c[$i] < $bars->o[$i] && $bars->c[$i] < $boxLow => -1,
            default => 0,
        };
        if ($d === 0) {
            return null;
        }

        $base = $d === 1 ? $bars->l[$i] : $bars->h[$i];
        $extreme = fn (int $j): float => $d === 1 ? $bars->h[$j] : $bars->l[$j];
        $best = $i;
        $confirmed = null;
        $end = min($bars->count() - 1, $i + self::EXPIRY + self::CONFIRM);
        for ($j = $i + 1; $j <= $end; $j++) {
            if ($d * (($d === 1 ? $bars->l[$j] : $bars->h[$j]) - $base) <= 0) {
                return null;  // back to the base before the swing was confirmed
            }
            if ($d * ($extreme($j) - $extreme($best)) > 0) {
                $best = $j;
            } elseif ($j - $best >= self::CONFIRM) {
                $confirmed = $j;
                break;
            }
        }
        if ($confirmed === null) {
            return null;
        }

        $tip = $extreme($best);
        $length = abs($tip - $base);

        return [
            'side' => $d === 1 ? 'long' : 'short', 'confirmed' => $confirmed, 'tip' => $tip,
            'entry' => $tip - $d * 0.236 * $length, 'stop' => $tip - $d * 0.745 * $length, 'target' => $tip + $d * 0.27 * $length,
        ];
    }

    /**
     * ATR14 as the plain mean of the last 14 true ranges, as the backtest computes it.
     *
     * @return list<float|null>
     */
    private static function atr14(Bars $bars): array
    {
        $ranges = [];
        $out = [];
        foreach ($bars->t as $i => $time) {
            $previous = $i > 0 ? $bars->c[$i - 1] : $bars->c[$i];
            $ranges[] = max($bars->h[$i] - $bars->l[$i], abs($bars->h[$i] - $previous), abs($bars->l[$i] - $previous));
            $out[] = $i >= 14 ? array_sum(array_slice($ranges, $i - 13, 14)) / 14 : null;
        }

        return $out;
    }

    private static function price(float $value): string
    {
        return rtrim(rtrim(number_format($value, $value >= 100 ? 2 : 6, ',', '.'), '0'), ',');
    }
}
