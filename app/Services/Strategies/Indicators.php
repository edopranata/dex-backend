<?php

namespace App\Services\Strategies;

/**
 * Each result lines up with its input and only uses values up to that position, with null until there
 * is enough history. Nothing is rounded here; rounding belongs to the display.
 */
class Indicators
{
    /**
     * Seeded with the simple average of the first period.
     *
     * @param  list<float>  $values
     * @return list<float|null>
     */
    public static function ema(array $values, int $period): array
    {
        $result = [];
        $ema = null;
        $k = 2 / ($period + 1);

        foreach ($values as $i => $value) {
            if ($i + 1 < $period) {
                $result[] = null;

                continue;
            }

            $ema = $ema === null ? array_sum(array_slice($values, 0, $period)) / $period : $value * $k + $ema * (1 - $k);
            $result[] = $ema;
        }

        return $result;
    }

    /**
     * Wilder's RSI.
     *
     * @param  list<float>  $closes
     * @return list<float|null>
     */
    public static function rsi(array $closes, int $period = 14): array
    {
        $result = [];
        $gain = 0.0;
        $loss = 0.0;

        foreach ($closes as $i => $close) {
            if ($i === 0) {
                $result[] = null;

                continue;
            }

            $change = $close - $closes[$i - 1];
            $up = max($change, 0);
            $down = max(-$change, 0);

            if ($i <= $period) {
                $gain += $up / $period;
                $loss += $down / $period;
            } else {
                $gain = ($gain * ($period - 1) + $up) / $period;
                $loss = ($loss * ($period - 1) + $down) / $period;
            }

            $result[] = $i < $period ? null : ($loss == 0 ? ($gain == 0 ? 50.0 : 100.0) : 100 - 100 / (1 + $gain / $loss));
        }

        return $result;
    }

    /**
     * Wilder's average true range.
     *
     * @param  list<float>  $highs
     * @param  list<float>  $lows
     * @param  list<float>  $closes
     * @return list<float|null>
     */
    public static function atr(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $result = [];
        $atr = 0.0;

        foreach ($closes as $i => $close) {
            if ($i === 0) {
                $result[] = null;

                continue;
            }

            $range = max($highs[$i] - $lows[$i], abs($highs[$i] - $closes[$i - 1]), abs($lows[$i] - $closes[$i - 1]));
            $atr = $i <= $period ? $atr + $range / $period : ($atr * ($period - 1) + $range) / $period;
            $result[] = $i < $period ? null : $atr;
        }

        return $result;
    }

    /**
     * @param  list<float>  $closes
     * @return array{line: list<float|null>, signal: list<float|null>, histogram: list<float|null>}
     */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $count = count($closes);
        $offset = $slow - 1;

        if ($count <= $offset) {
            $empty = array_fill(0, $count, null);

            return ['line' => $empty, 'signal' => $empty, 'histogram' => $empty];
        }

        $fastEma = self::ema($closes, $fast);
        $slowEma = self::ema($closes, $slow);
        $line = [];

        for ($i = $offset; $i < $count; $i++) {
            $line[] = $fastEma[$i] - $slowEma[$i];
        }

        $signalLine = self::ema($line, $signal);
        $padding = array_fill(0, $offset, null);
        $histogram = array_map(fn (float $value, ?float $average): ?float => $average === null ? null : $value - $average, $line, $signalLine);

        return [
            'line' => [...$padding, ...$line],
            'signal' => [...$padding, ...$signalLine],
            'histogram' => [...$padding, ...$histogram],
        ];
    }

    /**
     * Bands at a number of population standard deviations around the simple average.
     *
     * @param  list<float>  $closes
     * @return array{upper: list<float|null>, middle: list<float|null>, lower: list<float|null>}
     */
    public static function bollinger(array $closes, int $period = 20, float $deviations = 2.0): array
    {
        $bands = ['upper' => [], 'middle' => [], 'lower' => []];

        foreach ($closes as $i => $close) {
            if ($i + 1 < $period) {
                $bands['upper'][] = $bands['middle'][] = $bands['lower'][] = null;

                continue;
            }

            $window = array_slice($closes, $i + 1 - $period, $period);
            $mean = array_sum($window) / $period;
            $variance = array_sum(array_map(fn (float $value): float => ($value - $mean) ** 2, $window)) / $period;
            $spread = $deviations * sqrt($variance);

            $bands['upper'][] = $mean + $spread;
            $bands['middle'][] = $mean;
            $bands['lower'][] = $mean - $spread;
        }

        return $bands;
    }
}
