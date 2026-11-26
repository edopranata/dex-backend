<?php

namespace App\Services\NewPairs;

use App\Models\NewPair;
use App\Models\NewPairTrade;

/**
 * Summary for the Token Baru page: what is being recorded and how each strategy's paper trades did so far.
 */
class NewPairReport
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $trades = NewPairTrade::query()->get(['strategy', 'status', 'net_pct']);

        $strategies = collect(NewPairTrade::STRATEGIES)->map(function (string $label, string $key) use ($trades): array {
            $own = $trades->where('strategy', $key);
            $net = $own->where('status', NewPairTrade::CLOSED)->pluck('net_pct')->map(fn ($v): float => (float) $v)->sort()->values();
            $gain = $net->filter(fn (float $v): bool => $v > 0)->sum();
            $loss = -$net->filter(fn (float $v): bool => $v < 0)->sum();
            $count = $net->count();

            return [
                'key' => $key,
                'label' => $label,
                'closed' => $count,
                'open' => $own->where('status', NewPairTrade::OPEN)->count(),
                'avg_net_pct' => $count ? round($net->avg(), 2) : null,
                'median_net_pct' => $count ? round($count % 2 ? $net[intdiv($count, 2)] : ($net[$count / 2 - 1] + $net[$count / 2]) / 2, 2) : null,
                'win_rate' => $count ? round($net->filter(fn (float $v): bool => $v > 0)->count() / $count * 100, 1) : null,
                'profit_factor' => $loss > 0 ? round($gain / $loss, 2) : null,
                'total_net_pct' => $count ? round($net->sum(), 2) : null,
            ];
        })->values();

        return [
            'strategies' => $strategies,
            'pairs' => [
                'tracking' => NewPair::query()->tracking()->count(),
                'total' => NewPair::query()->count(),
                'last_24h' => NewPair::query()->where('first_seen_at', '>=', now()->subDay())->count(),
                'by_source' => NewPair::query()->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source'),
                'by_chain' => NewPair::query()->selectRaw('chain_id, count(*) as n')->groupBy('chain_id')->pluck('n', 'chain_id'),
                'first_seen_at' => NewPair::query()->min('first_seen_at'),
            ],
            'rules' => [
                'stop_pct' => config('market.new_pairs.stop_pct'),
                'take_profit_pct' => config('market.new_pairs.take_profit_pct'),
                'max_hold_hours' => config('market.new_pairs.max_hold_hours'),
                'round_trip_cost_pct' => config('market.new_pairs.round_trip_cost_pct'),
                'min_liquidity_usd' => config('market.new_pairs.min_liquidity_usd'),
                'max_tracked' => config('market.new_pairs.max_tracked'),
            ],
        ];
    }
}
