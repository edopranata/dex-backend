<?php

namespace App\Services\Strategies\Rules;

use App\Models\StrategyPosition;
use App\Models\Token;
use App\Services\Strategies\Bars;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\Indicators;
use App\Services\Strategies\StrategyContext;

/**
 * FutureSim's Ichimoku on 4h, BUY only (owner, 26 Nov 2026, shadow): the 4h close crosses above the cloud (spans of the
 * 9/26/52 lines shifted 26 candles forward) while the tenkan is above the kijun, and BTC's daily close is above its
 * SMA50. BUY at the ask, stop 2 x ATR14 (4h) under the 4h close, sold at the first 4h close under the kijun, at most 20
 * days. Spot backtest (spot/review/sistem-future/spot.py, 30 watch pairs Nov 2021 - Nov 2026, fee + PPh 0.31% and half
 * the spread per side): +0.265R per trade over 1,730 trades (random entries -0.057R), 2022-2024 +0.249R, 2025-2026
 * +0.285R, without the best 5 +0.119R; account at 10% a position +15.0% a year, drawdown 27%.
 */
class IchimokuLong extends BaseStrategy
{
    public function timeframe(): string
    {
        return '1h';
    }

    public function maxHours(): int
    {
        return 480;
    }

    public function entry(Token $token, StrategyContext $context): ?array
    {
        if (! $context->fourHourClosed() || ! $context->btcAboveDailySma50()) {
            return null;
        }

        $setup = $this->setup($context->bars4h($token->id, 300));
        if ($setup === null) {
            return null;
        }

        return [
            'reason' => sprintf('Ichimoku 4 jam: close menembus awan (%s), tenkan di atas kijun, BTC di atas SMA50', self::price($setup['cloud'])),
            'stop' => $setup['stop'],
        ];
    }

    public function manage(StrategyPosition $position, StrategyContext $context): ?array
    {
        if (! $context->fourHourClosed()) {
            return null;
        }

        $bars = $context->bars4h($position->token_id, 300);
        $last = $bars->count() - 1;
        $kijun = $last >= 0 ? self::lines($bars)['kijun'][$last] : null;

        // Only 4h candles that closed after the entry count.
        return $kijun !== null && $position->opened_at->getTimestamp() < $bars->t[$last] + 14400 && $bars->c[$last] < $kijun
            ? ['fraction' => 1.0, 'reason' => 'Close 4 jam di bawah kijun']
            : null;
    }

    /**
     * The close crossing above the cloud on the last closed 4h candle with the tenkan above the kijun, with the stop;
     * null when it did not.
     *
     * @return array{stop: float, cloud: float}|null
     */
    public function setup(Bars $bars): ?array
    {
        $i = $bars->count() - 1;
        if ($i < 100 || ! $bars->contiguous(100, 14400)) {
            return null;
        }

        $lines = self::lines($bars);
        $top = $lines['cloud_top'];
        if ($top[$i] === null || $top[$i - 1] === null || ! ($bars->c[$i] > $top[$i] && $bars->c[$i - 1] <= $top[$i - 1])) {
            return null;
        }
        if ($lines['tenkan'][$i] === null || $lines['kijun'][$i] === null || $lines['tenkan'][$i] <= $lines['kijun'][$i]) {
            return null;
        }

        $atr = Indicators::atr($bars->h, $bars->l, $bars->c)[$i];

        return $atr === null ? null : ['stop' => $bars->c[$i] - 2 * $atr, 'cloud' => $top[$i]];
    }

    /**
     * Tenkan (9), kijun (26), and the top of the cloud under each candle: the higher of span A ((tenkan + kijun) / 2)
     * and span B (52-candle midpoint) of 26 candles before.
     *
     * @return array{tenkan: list<float|null>, kijun: list<float|null>, cloud_top: list<float|null>}
     */
    public static function lines(Bars $bars): array
    {
        $n = $bars->count();
        $mid = function (int $i, int $period) use ($bars): ?float {
            if ($i < $period - 1) {
                return null;
            }

            return (max(array_slice($bars->h, $i - $period + 1, $period)) + min(array_slice($bars->l, $i - $period + 1, $period))) / 2;
        };

        $tenkan = $kijun = $spanB = $top = [];
        for ($i = 0; $i < $n; $i++) {
            $tenkan[] = $mid($i, 9);
            $kijun[] = $mid($i, 26);
            $spanB[] = $mid($i, 52);
        }
        for ($i = 0; $i < $n; $i++) {
            $j = $i - 26;
            $spanA = $j >= 0 && $tenkan[$j] !== null && $kijun[$j] !== null ? ($tenkan[$j] + $kijun[$j]) / 2 : null;
            $top[] = $spanA !== null && $j >= 0 && $spanB[$j] !== null ? max($spanA, $spanB[$j]) : null;
        }

        return ['tenkan' => $tenkan, 'kijun' => $kijun, 'cloud_top' => $top];
    }

    private static function price(float $price): string
    {
        return rtrim(rtrim(number_format($price, $price < 1 ? 6 : 2, '.', ''), '0'), '.');
    }
}
