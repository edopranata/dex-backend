<?php

namespace App\Services\NewPairs;

use App\Models\NewPair;
use App\Models\NewPairTrade;

/**
 * Paper trades of Token Baru, followed on each minute's sample. The exits are those of the 26 Nov 2026 test:
 * a third sold at each take-profit level, the stop moves to the entry after the first one, and the trade closes after
 * 24 hours, or when the pool's liquidity falls to a fifth of what it was at entry. A stop fills at the sampled price,
 * so a gap below the stop is taken in full.
 */
class PaperTradeBook
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function open(NewPair $pair, string $strategy, array $context = []): ?NewPairTrade
    {
        if ($pair->price_usd === null || $pair->price_usd <= 0 || $pair->trades()->where('strategy', $strategy)->exists()) {
            return null;
        }

        $price = $pair->price_usd;

        return $pair->trades()->create([
            'strategy' => $strategy,
            'status' => NewPairTrade::OPEN,
            'entry_at' => now(),
            'entry_price' => $price,
            'stop_price' => $price * (1 - config('market.new_pairs.stop_pct') / 100),
            'pool_age_minutes' => (int) round($pair->ageMinutes()),
            'liquidity_usd' => $pair->liquidity_usd,
            'high_price' => $price,
            'low_price' => $price,
            'context' => $context + [
                'source' => $pair->source,
                'security_score' => $pair->security_score,
                'is_honeypot' => $pair->is_honeypot,
                'sell_tax' => $pair->sell_tax,
                'top10_percent' => $pair->top10_percent,
                'volume_h1' => $pair->volume_h1,
                'buys_m5' => $pair->buys_m5,
                'sells_m5' => $pair->sells_m5,
                'fdv' => $pair->fdv,
                'price_change_h1' => $pair->price_change_h1,
            ],
        ]);
    }

    /**
     * Follow an open trade on the pool's latest sample.
     */
    public function follow(NewPairTrade $trade, NewPair $pair): void
    {
        $price = $pair->price_usd;

        if ($price === null || $price <= 0) {
            return;
        }

        $config = config('market.new_pairs');
        $entry = $trade->entry_price;
        $trade->high_price = max($trade->high_price, $price);
        $trade->low_price = min($trade->low_price, $price);

        $stop = $trade->tp_hit > 0 ? $entry : $trade->stop_price;

        if ($price <= $stop) {
            $this->close($trade, $price, $trade->tp_hit > 0 ? 'impas' : 'stop');

            return;
        }

        $levels = $config['take_profit_pct'];

        while ($trade->tp_hit < count($levels) && $price >= $entry * (1 + $levels[$trade->tp_hit] / 100)) {
            $trade->realized_pct += $levels[$trade->tp_hit] / count($levels);
            $trade->tp_hit++;
        }

        if ($trade->tp_hit === count($levels)) {
            $this->close($trade, $entry * (1 + end($levels) / 100), 'tp3');

            return;
        }

        if ($trade->liquidity_usd > 0 && $pair->liquidity_usd !== null && $pair->liquidity_usd < $trade->liquidity_usd * $config['liquidity_exit_ratio']) {
            $this->close($trade, $price, 'likuiditas');

            return;
        }

        if ($trade->entry_at->diffInHours(now(), true) >= $config['max_hold_hours']) {
            $this->close($trade, $price, 'waktu');

            return;
        }

        $trade->save();
    }

    /**
     * Close the part not yet sold at the given price.
     */
    public function close(NewPairTrade $trade, float $price, string $reason): void
    {
        $levels = count(config('market.new_pairs.take_profit_pct'));
        $left = 1 - $trade->tp_hit / $levels;
        $gross = $trade->realized_pct + $left * ($price / $trade->entry_price - 1) * 100;

        $trade->fill([
            'status' => NewPairTrade::CLOSED,
            'exit_at' => now(),
            'exit_price' => $price,
            'exit_reason' => $reason,
            'gross_pct' => round($gross, 2),
            'net_pct' => round($gross - config('market.new_pairs.round_trip_cost_pct'), 2),
        ])->save();
    }
}
