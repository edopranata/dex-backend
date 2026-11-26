<?php

namespace App\Services\NewPairs;

use App\Models\NewPair;
use App\Models\NewPairBar;
use Illuminate\Support\Collection;

/**
 * Entry rules of the New Token Hunter document (dex/new_token_hunter/), as tested on 26 Nov 2026 but on our own
 * data: A on the DEX Screener snapshot (volume, trades and buy/sell counts the 26 Nov test could not see), B and C on
 * closed 5-minute bars. Recording only.
 */
class NewPairStrategies
{
    /**
     * Strategy A, Early Liquidity: liquidity, 5-minute and 1-hour volume, 5-minute trades and buy/sell count ratio
     * above the document's limits, and no vertical 5-minute move. Checked during the first hour after the pool is seen.
     *
     * @return array<string, float|int|null>|null The reasons, or null when the rule is not met.
     */
    public function earlyLiquidity(NewPair $pair): ?array
    {
        $config = config('market.new_pairs.early');

        if ($pair->first_seen_at->diffInMinutes(now(), true) > $config['window_minutes']) {
            return null;
        }

        $txns = (int) $pair->buys_m5 + (int) $pair->sells_m5;
        $ratio = $pair->sells_m5 > 0 ? $pair->buys_m5 / $pair->sells_m5 : ($pair->buys_m5 > 0 ? INF : 0);

        $met = ($pair->liquidity_usd ?? 0) >= config('market.new_pairs.min_liquidity_usd')
            && ($pair->volume_m5 ?? 0) >= $config['min_volume_m5']
            && ($pair->volume_h1 ?? 0) >= $config['min_volume_h1']
            && $txns >= $config['min_txns_m5']
            && $ratio >= $config['min_buy_sell_ratio']
            && ($pair->price_change_m5 ?? 0) < $config['max_change_m5_pct'];

        return $met ? ['volume_m5' => $pair->volume_m5, 'volume_h1' => $pair->volume_h1, 'txns_m5' => $txns, 'buy_sell_ratio' => is_infinite($ratio) ? null : round($ratio, 2)] : null;
    }

    /**
     * Strategy B, Breakout, on the bar that just closed: close above the high of the previous bars, volume at least
     * 1.5x their average, a green bar with more buys than sells, and not more than 15% above the broken high.
     *
     * @param  Collection<int, NewPairBar>  $closed  Closed bars, oldest first, the last one just closed.
     * @return array<string, float|int|null>|null
     */
    public function breakout(Collection $closed): ?array
    {
        $config = config('market.new_pairs.breakout');

        if ($closed->count() < $config['lookback_bars'] + 1) {
            return null;
        }

        $bar = $closed->last();
        $previous = $closed->slice(-($config['lookback_bars'] + 1), $config['lookback_bars']);
        $high = (float) $previous->max('high');
        $averageVolume = (float) $previous->avg(fn (NewPairBar $b): float => (float) $b->volume_m5);

        $met = $bar->close > $high
            && $bar->close <= $high * $config['max_extension']
            && $bar->close > $bar->open
            && (float) $bar->volume_m5 >= $config['min_volume']
            && (float) $bar->volume_m5 >= $config['volume_multiplier'] * $averageVolume
            && (int) $bar->buys_m5 > (int) $bar->sells_m5;

        return $met ? ['broken_high' => $high, 'volume_m5' => $bar->volume_m5, 'average_volume' => round($averageVolume, 2)] : null;
    }

    /**
     * Strategy C, Pullback: after an impulse (a high at least 30% above the VWAP in the previous bars) the bar that
     * just closed dipped to within 3% of the VWAP and closed green above it. The VWAP covers our own bars only, from
     * when the pool was first seen (the 26 Nov test used the pool's whole history).
     *
     * @param  Collection<int, NewPairBar>  $closed
     * @return array<string, float|int|null>|null
     */
    public function pullback(Collection $closed): ?array
    {
        $config = config('market.new_pairs.pullback');

        if ($closed->count() < $config['lookback_bars'] + 1) {
            return null;
        }

        $volume = $closed->sum(fn (NewPairBar $b): float => (float) $b->volume_m5);
        $vwap = $volume > 0
            ? $closed->sum(fn (NewPairBar $b): float => ($b->high + $b->low + $b->close) / 3 * (float) $b->volume_m5) / $volume
            : null;

        if ($vwap === null || $vwap <= 0) {
            return null;
        }

        $bar = $closed->last();
        $previousHigh = (float) $closed->slice(-($config['lookback_bars'] + 1), $config['lookback_bars'])->max('high');

        $met = $previousHigh >= $config['impulse'] * $vwap
            && $bar->low <= $vwap * $config['touch']
            && $bar->close > $vwap
            && $bar->close > $bar->open
            && (float) $bar->volume_m5 >= $config['min_volume'];

        return $met ? ['vwap' => $vwap, 'impulse_high' => $previousHigh] : null;
    }
}
