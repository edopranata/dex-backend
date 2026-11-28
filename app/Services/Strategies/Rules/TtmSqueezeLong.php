<?php

namespace App\Services\Strategies\Rules;

use App\Models\StrategyPosition;
use App\Models\Token;
use App\Services\Strategies\Bars;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\Indicators;
use App\Services\Strategies\StrategyContext;

/**
 * FutureSim's Bollinger "TTM squeeze" on 4h, BUY only (owner, 26 Nov 2026, shadow): the Bollinger Bands (20, 2 sd) were
 * inside the Keltner channel (EMA20 +- 1.5 x ATR20) on the candle before and are not on the one that just closed
 * ("fired"), the TTM momentum (linear regression over 20 candles of the close minus the mean of the Donchian midpoint
 * and the SMA20) is above zero, the candle's turnover is at least twice the average of the 20 candles before, and BTC's
 * daily close is above its SMA50. BUY at the ask, stop 2 x ATR14 (4h) under the 4h close, sold at the first 4h close
 * under the middle band (SMA20), at most 15 days.
 * Spot backtest (spot/review/sistem-future/spot.py, 30 watch pairs Nov 2021 - Nov 2026, fee + PPh 0.31% and half the
 * spread per side): +0.363R per trade over 495 trades (random entries -0.074R), 2022-2024 +0.211R, 2025-2026 +0.585R,
 * without the best 5 +0.111R; account at 10% a position +11.6% a year, drawdown 14%.
 */
class TtmSqueezeLong extends BaseStrategy
{
    private const VOLUME_MULTIPLE = 2.0;

    public function timeframe(): string
    {
        return '1h';
    }

    public function maxHours(): int
    {
        return 360;
    }

    public function entry(Token $token, StrategyContext $context): ?array
    {
        if (! $context->fourHourClosed() || ! $context->btcAboveDailySma50()) {
            return null;
        }

        $setup = $this->setup($context->bars4h($token->id, 250));
        if ($setup === null) {
            return null;
        }

        return [
            'reason' => sprintf('TTM squeeze 4 jam menyala, momentum naik, volume %.1fx rata-rata, BTC di atas SMA50', $setup['volume_multiple']),
            'stop' => $setup['stop'],
        ];
    }

    public function manage(StrategyPosition $position, StrategyContext $context): ?array
    {
        if (! $context->fourHourClosed()) {
            return null;
        }

        $bars = $context->bars4h($position->token_id, 25);
        $last = $bars->count() - 1;
        $middle = Indicators::bollinger($bars->c)['middle'][$last] ?? null;

        // Only 4h candles that closed after the entry count.
        return $last >= 0 && $middle !== null && $position->opened_at->getTimestamp() < $bars->t[$last] + 14400 && $bars->c[$last] < $middle
            ? ['fraction' => 1.0, 'reason' => 'Close 4 jam di bawah tengah pita Bollinger']
            : null;
    }

    /**
     * The squeeze firing on the last closed 4h candle, with the stop; null when it did not.
     *
     * @return array{stop: float, momentum: float, volume_multiple: float}|null
     */
    public function setup(Bars $bars): ?array
    {
        $i = $bars->count() - 1;
        if ($i < 60 || ! $bars->contiguous(60, 14400)) {
            return null;
        }

        $bands = Indicators::bollinger($bars->c);
        $ema = Indicators::ema($bars->c, 20);
        $atr20 = Indicators::atr($bars->h, $bars->l, $bars->c, 20);
        $atr14 = Indicators::atr($bars->h, $bars->l, $bars->c);

        $inside = fn (int $k): bool => $bands['upper'][$k] < $ema[$k] + 1.5 * $atr20[$k] && $bands['lower'][$k] > $ema[$k] - 1.5 * $atr20[$k];
        if (! $inside($i - 1) || $inside($i)) {
            return null;
        }

        $momentum = self::momentum($bars, $bands['middle'], $i);
        if ($momentum <= 0) {
            return null;
        }

        $average = array_sum(array_slice($bars->turnover, $i - 20, 20)) / 20;
        if ($average <= 0 || $bars->turnover[$i] < self::VOLUME_MULTIPLE * $average) {
            return null;
        }

        return ['stop' => $bars->c[$i] - 2 * $atr14[$i], 'momentum' => $momentum, 'volume_multiple' => $bars->turnover[$i] / $average];
    }

    /**
     * TTM momentum at i: the linear-regression value, at the last point, of close minus the mean of the 20-candle
     * Donchian midpoint and the SMA20, over the 20 candles ending at i.
     *
     * @param  list<float|null>  $middle
     */
    private static function momentum(Bars $bars, array $middle, int $i): float
    {
        $ys = [];
        for ($k = $i - 19; $k <= $i; $k++) {
            $donchian = (max(array_slice($bars->h, $k - 19, 20)) + min(array_slice($bars->l, $k - 19, 20))) / 2;
            $ys[] = $bars->c[$k] - ($donchian + $middle[$k]) / 2;
        }

        $n = count($ys);
        $xMean = ($n - 1) / 2;
        $yMean = array_sum($ys) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        foreach ($ys as $x => $y) {
            $sxy += ($x - $xMean) * ($y - $yMean);
            $sxx += ($x - $xMean) ** 2;
        }

        return $yMean + $sxy / $sxx * ($n - 1 - $xMean);
    }
}
