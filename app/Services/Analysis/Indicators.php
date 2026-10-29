<?php

namespace App\Services\Analysis;

/**
 * Technical indicators over price series (oldest first). Each returns a series
 * aligned with the input, with null for the warm-up period.
 */
class Indicators
{
    /**
     * @param  list<float>  $values
     * @return list<float|null>
     */
    public static function sma(array $values, int $period): array
    {
        $result = [];
        $sum = 0.0;

        foreach ($values as $i => $value) {
            $sum += $value;

            if ($i >= $period) {
                $sum -= $values[$i - $period];
            }

            $result[] = $i >= $period - 1 ? $sum / $period : null;
        }

        return $result;
    }

    /**
     * Exponential moving average seeded with the SMA of the first period.
     *
     * @param  list<float>  $values
     * @return list<float|null>
     */
    public static function ema(array $values, int $period): array
    {
        $result = array_fill(0, count($values), null);

        if (count($values) < $period) {
            return $result;
        }

        $multiplier = 2 / ($period + 1);
        $previous = array_sum(array_slice($values, 0, $period)) / $period;
        $result[$period - 1] = $previous;

        for ($i = $period, $n = count($values); $i < $n; $i++) {
            $previous = ($values[$i] - $previous) * $multiplier + $previous;
            $result[$i] = $previous;
        }

        return $result;
    }

    /**
     * Relative Strength Index with Wilder smoothing.
     *
     * @param  list<float>  $closes
     * @return list<float|null>
     */
    public static function rsi(array $closes, int $period = 14): array
    {
        $n = count($closes);
        $result = array_fill(0, $n, null);

        if ($n <= $period) {
            return $result;
        }

        $gain = 0.0;
        $loss = 0.0;

        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain += max($change, 0);
            $loss += max(-$change, 0);
        }

        $avgGain = $gain / $period;
        $avgLoss = $loss / $period;
        $result[$period] = self::rsiValue($avgGain, $avgLoss);

        for ($i = $period + 1; $i < $n; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $avgGain = ($avgGain * ($period - 1) + max($change, 0)) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + max(-$change, 0)) / $period;
            $result[$i] = self::rsiValue($avgGain, $avgLoss);
        }

        return $result;
    }

    /**
     * @param  list<float>  $closes
     * @return array{macd: list<float|null>, signal: list<float|null>, histogram: list<float|null>}
     */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $signalPeriod = 9): array
    {
        $fastEma = self::ema($closes, $fast);
        $slowEma = self::ema($closes, $slow);

        $macd = [];

        foreach ($closes as $i => $_) {
            $macd[] = $fastEma[$i] !== null && $slowEma[$i] !== null ? $fastEma[$i] - $slowEma[$i] : null;
        }

        $offset = $slow - 1;
        $signalSeries = array_fill(0, count($closes), null);

        foreach (self::ema(array_values(array_slice($macd, $offset)), $signalPeriod) as $i => $value) {
            $signalSeries[$i + $offset] = $value;
        }

        $histogram = [];

        foreach ($macd as $i => $value) {
            $histogram[] = $value !== null && $signalSeries[$i] !== null ? $value - $signalSeries[$i] : null;
        }

        return ['macd' => $macd, 'signal' => $signalSeries, 'histogram' => $histogram];
    }

    /**
     * Average True Range with Wilder smoothing.
     *
     * @param  list<float>  $highs
     * @param  list<float>  $lows
     * @param  list<float>  $closes
     * @return list<float|null>
     */
    public static function atr(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $n = count($closes);
        $result = array_fill(0, $n, null);

        if ($n <= $period) {
            return $result;
        }

        $trueRanges = [$highs[0] - $lows[0]];

        for ($i = 1; $i < $n; $i++) {
            $trueRanges[] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1]),
            );
        }

        $atr = array_sum(array_slice($trueRanges, 1, $period)) / $period;
        $result[$period] = $atr;

        for ($i = $period + 1; $i < $n; $i++) {
            $atr = ($atr * ($period - 1) + $trueRanges[$i]) / $period;
            $result[$i] = $atr;
        }

        return $result;
    }

    /**
     * @param  list<float>  $closes
     * @return array{upper: list<float|null>, middle: list<float|null>, lower: list<float|null>}
     */
    public static function bollinger(array $closes, int $period = 20, float $deviations = 2.0): array
    {
        $middle = self::sma($closes, $period);
        $upper = [];
        $lower = [];

        foreach ($closes as $i => $_) {
            if ($middle[$i] === null) {
                $upper[] = null;
                $lower[] = null;

                continue;
            }

            $window = array_slice($closes, $i - $period + 1, $period);
            $variance = array_sum(array_map(fn (float $v): float => ($v - $middle[$i]) ** 2, $window)) / $period;
            $std = sqrt($variance);

            $upper[] = $middle[$i] + $deviations * $std;
            $lower[] = $middle[$i] - $deviations * $std;
        }

        return ['upper' => $upper, 'middle' => $middle, 'lower' => $lower];
    }

    /**
     * @param  list<float|null>  $series
     */
    public static function last(array $series, int $offset = 0): ?float
    {
        $index = count($series) - 1 - $offset;

        return $index >= 0 ? $series[$index] : null;
    }

    private static function rsiValue(float $avgGain, float $avgLoss): float
    {
        if ($avgLoss == 0.0) {
            return $avgGain == 0.0 ? 50.0 : 100.0;
        }

        return 100 - 100 / (1 + $avgGain / $avgLoss);
    }
}
