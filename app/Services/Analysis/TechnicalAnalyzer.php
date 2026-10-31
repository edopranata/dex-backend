<?php

namespace App\Services\Analysis;

use App\Models\Candle;
use Illuminate\Support\Collection;

/**
 * Scores trend, momentum and volume on the signal timeframe, confirmed by the higher timeframe trend.
 */
class TechnicalAnalyzer
{
    public const MIN_CANDLES = 60;

    /**
     * @param  Collection<int, Candle>  $candles  Signal timeframe, oldest first.
     * @param  Collection<int, Candle>  $trendCandles  Higher timeframe, oldest first.
     * @return array{score: int, available: bool, reasons: list<string>, indicators: array<string, float|string|null>, atr: float|null, swing_low: float|null, swing_high: float|null}
     */
    public function analyze(Collection $candles, Collection $trendCandles, float $price): array
    {
        if ($candles->count() < self::MIN_CANDLES) {
            return [
                'score' => 50,
                'available' => false,
                'reasons' => ['Data candle belum cukup untuk analisis teknikal ('.$candles->count().'/'.self::MIN_CANDLES.').'],
                'indicators' => [],
                'atr' => null,
                'swing_low' => null,
                'swing_high' => null,
            ];
        }

        $closes = $candles->pluck('close')->all();
        $highs = $candles->pluck('high')->all();
        $lows = $candles->pluck('low')->all();
        $volumes = $candles->pluck('volume')->all();

        $ema20 = Indicators::last(Indicators::ema($closes, 20));
        $ema50 = Indicators::last(Indicators::ema($closes, 50));
        $ema200 = Indicators::last(Indicators::ema($closes, 200));
        $rsiSeries = Indicators::rsi($closes);
        $rsi = Indicators::last($rsiSeries);
        $macd = Indicators::macd($closes);
        $histogram = Indicators::last($macd['histogram']);
        $previousHistogram = Indicators::last($macd['histogram'], 1);
        $bands = Indicators::bollinger($closes);
        $atr = Indicators::last(Indicators::atr($highs, $lows, $closes));

        $score = 50;
        $reasons = [];

        if ($price > $ema20 && $ema20 > $ema50) {
            $score += 13;
            $reasons[] = 'Tren naik: harga di atas EMA20 dan EMA20 di atas EMA50.';
        } elseif ($price < $ema20 && $ema20 < $ema50) {
            $score -= 13;
            $reasons[] = 'Tren turun: harga di bawah EMA20 dan EMA20 di bawah EMA50.';
        } else {
            $reasons[] = 'Tren campuran (EMA20/EMA50 belum searah).';
        }

        if ($ema200 !== null) {
            $score += $price > $ema200 ? 7 : -7;
            $reasons[] = $price > $ema200 ? 'Harga di atas EMA200 (tren jangka menengah positif).' : 'Harga di bawah EMA200 (tren jangka menengah negatif).';
        }

        [$rsiPoints, $rsiReason] = match (true) {
            $rsi >= 80 => [-12, 'RSI %.0f: sangat overbought, rawan koreksi.'],
            $rsi >= 70 => [-6, 'RSI %.0f: overbought.'],
            $rsi >= 55 => [8, 'RSI %.0f: momentum bullish sehat.'],
            $rsi >= 45 => [0, 'RSI %.0f: netral.'],
            $rsi >= 30 => [-6, 'RSI %.0f: momentum melemah.'],
            default => [6, 'RSI %.0f: oversold, potensi rebound.'],
        };
        $score += $rsiPoints;
        $reasons[] = sprintf($rsiReason, $rsi);

        if ($histogram !== null && $previousHistogram !== null) {
            $score += $histogram > 0 ? 5 : -5;
            $score += $histogram > $previousHistogram ? 5 : -5;

            if ($previousHistogram <= 0 && $histogram > 0) {
                $reasons[] = 'MACD baru saja cross ke atas signal line (bullish crossover).';
            } elseif ($previousHistogram >= 0 && $histogram < 0) {
                $reasons[] = 'MACD baru saja cross ke bawah signal line (bearish crossover).';
            } else {
                $reasons[] = $histogram > 0 ? 'MACD histogram positif.' : 'MACD histogram negatif.';
            }
        }

        $upper = Indicators::last($bands['upper']);
        $lower = Indicators::last($bands['lower']);

        if ($lower !== null && $price < $lower) {
            $score += 5;
            $reasons[] = 'Harga di bawah Bollinger Band bawah (potensi mean reversion).';
        } elseif ($upper !== null && $price > $upper) {
            $score -= 5;
            $reasons[] = 'Harga di atas Bollinger Band atas (overextended).';
        }

        $averageVolume = array_sum(array_slice($volumes, -23, 20)) / 20;
        $recentVolume = array_sum(array_slice($volumes, -3)) / 3;
        $volumeRatio = $averageVolume > 0 ? $recentVolume / $averageVolume : null;
        $lastCandle = $candles->last();

        if ($volumeRatio !== null && $volumeRatio > 1.5) {
            $bullishCandle = $lastCandle->close >= $lastCandle->open;
            $score += $bullishCandle ? 5 : -5;
            $reasons[] = sprintf('Volume %.1fx rata-rata dengan candle %s.', $volumeRatio, $bullishCandle ? 'hijau' : 'merah');
        }

        $trend = $this->higherTimeframeTrend($trendCandles);
        $score += match ($trend) {
            'bullish' => 10,
            'bearish' => -10,
            default => 0,
        };

        if ($trend !== null) {
            $reasons[] = "Tren timeframe besar: {$trend}.";
        }

        return [
            'score' => (int) max(0, min(100, round($score))),
            'available' => true,
            'reasons' => $reasons,
            'indicators' => [
                'rsi' => $rsi,
                'ema20' => $ema20,
                'ema50' => $ema50,
                'ema200' => $ema200,
                'macd' => Indicators::last($macd['macd']),
                'macd_signal' => Indicators::last($macd['signal']),
                'macd_histogram' => $histogram,
                'bb_upper' => $upper,
                'bb_lower' => $lower,
                'atr' => $atr,
                'atr_pct' => $atr !== null && $price > 0 ? $atr / $price * 100 : null,
                'volume_ratio' => $volumeRatio,
                'trend_higher_tf' => $trend,
            ],
            'atr' => $atr,
            'swing_low' => min(array_slice($lows, -24)),
            'swing_high' => max(array_slice($highs, -48)),
        ];
    }

    /**
     * @param  Collection<int, Candle>  $candles
     */
    public function higherTimeframeTrend(Collection $candles): ?string
    {
        if ($candles->count() < 50) {
            return null;
        }

        $closes = $candles->pluck('close')->all();
        $close = end($closes);
        $ema20 = Indicators::last(Indicators::ema($closes, 20));
        $ema50 = Indicators::last(Indicators::ema($closes, 50));

        return match (true) {
            $close > $ema50 && $ema20 > $ema50 => 'bullish',
            $close < $ema50 && $ema20 < $ema50 => 'bearish',
            default => 'sideways',
        };
    }
}
