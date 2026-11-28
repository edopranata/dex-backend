<?php

namespace App\Services\Strategies\Rules;

use App\Models\StrategyPosition;
use App\Models\Token;
use App\Services\Strategies\Bars;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\Indicators;
use App\Services\Strategies\StrategyContext;

/**
 * FutureSim's Willy momentum on 4h, BUY only (owner, 26 Nov 2026, experiment): the EMA13 of Williams %R 21 (the chart's
 * Willy indicator) crosses above -50 on the 4h candle that just closed, and BTC's daily close is above its SMA50. BUY at
 * the ask, stop 2 x ATR14 (4h) under the 4h close, sold at the first 4h close under the EMA50, at most 20 days.
 * Spot backtest (spot/review/sistem-future/spot.py, 30 watch pairs Nov 2021 - Nov 2026, 1h candles, fee + PPh 0.31%
 * and half the spread per side): +0.101R per trade over 2,683 trades (random entries -0.014R), but 2022-2024 +0.277R
 * against 2025-2026 -0.106R, without the best 5 -0.003R; costs take 0.18R a trade (0.04R on perpetuals). Account at
 * 10% a position: +10.6% a year, drawdown 53%.
 */
class WillyMomentumLong extends BaseStrategy
{
    private const WR_PERIOD = 21;

    private const SMOOTHING = 13;

    private const LEVEL = -50.0;

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
            'reason' => sprintf('Willy 4 jam (EMA13 dari WR21) naik melewati -50 (%.1f), BTC di atas SMA50', $setup['willy']),
            'stop' => $setup['stop'],
            // 1R of the signal (2 x ATR14), for the shadow variants' levels after the fill.
            'state' => ['risk' => $setup['risk']],
        ];
    }

    public function manage(StrategyPosition $position, StrategyContext $context): ?array
    {
        if (! $context->fourHourClosed()) {
            return null;
        }

        $bars = $context->bars4h($position->token_id, 300);
        $last = $bars->count() - 1;
        $ema = Indicators::ema($bars->c, 50)[$last] ?? null;

        // Only 4h candles that closed after the entry count.
        return $last >= 0 && $ema !== null && $position->opened_at->getTimestamp() < $bars->t[$last] + 14400 && $bars->c[$last] < $ema
            ? ['fraction' => 1.0, 'reason' => 'Close 4 jam di bawah EMA50']
            : null;
    }

    /**
     * The Willy crossing above -50 on the last closed 4h candle, with the stop; null when it did not.
     *
     * @return array{stop: float, risk: float, willy: float}|null
     */
    public function setup(Bars $bars): ?array
    {
        $i = $bars->count() - 1;
        if ($i < 60 || ! $bars->contiguous(60, 14400)) {
            return null;
        }

        $willy = self::willy($bars);
        if ($willy[$i - 1] === null || $willy[$i] === null || ! ($willy[$i - 1] <= self::LEVEL && $willy[$i] > self::LEVEL)) {
            return null;
        }

        $atr = Indicators::atr($bars->h, $bars->l, $bars->c)[$i];

        return $atr === null ? null : ['stop' => $bars->c[$i] - 2 * $atr, 'risk' => 2 * $atr, 'willy' => $willy[$i]];
    }

    /**
     * EMA13 of Williams %R 21, started at the first %R value as the chart's Willy indicator does (src/lib/willy.ts).
     *
     * @return list<float|null>
     */
    public static function willy(Bars $bars): array
    {
        $k = 2 / (self::SMOOTHING + 1);
        $ema = null;
        $out = [];

        foreach ($bars->c as $i => $close) {
            if ($i < self::WR_PERIOD - 1) {
                $out[] = null;

                continue;
            }

            $highest = max(array_slice($bars->h, $i - self::WR_PERIOD + 1, self::WR_PERIOD));
            $lowest = min(array_slice($bars->l, $i - self::WR_PERIOD + 1, self::WR_PERIOD));
            $wr = $highest == $lowest ? 0.0 : ($close - $highest) / ($highest - $lowest) * 100;
            $ema = $ema === null ? $wr : $ema + $k * ($wr - $ema);
            $out[] = $ema;
        }

        return $out;
    }
}
