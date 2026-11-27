<?php

namespace App\Services\NewPairs;

use App\Models\NewPair;
use App\Models\NewPairTrade;
use Illuminate\Support\Collection;

/**
 * Summary for the Token Baru page: what is being recorded and how each strategy's paper trades did so far, in total
 * and per chain, with how often a trade or a pool ended in a rug pull.
 */
class NewPairReport
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $trades = NewPairTrade::query()
            ->join('new_pairs', 'new_pairs.id', '=', 'new_pair_trades.new_pair_id')
            ->get(['new_pair_trades.strategy', 'new_pair_trades.status', 'new_pair_trades.net_pct', 'new_pairs.chain_id']);

        $strategies = collect(NewPairTrade::STRATEGIES)->map(fn (string $label, string $key): array => [
            'key' => $key,
            'label' => $label,
            'open' => $trades->where('strategy', $key)->where('status', NewPairTrade::OPEN)->count(),
            ...$this->results($trades->where('strategy', $key)),
        ])->values();

        $byChain = collect(NewPairTrade::STRATEGIES)->flatMap(fn (string $label, string $key) => $trades->where('strategy', $key)
            ->where('status', NewPairTrade::CLOSED)
            ->groupBy('chain_id')
            ->sortKeys()
            ->map(fn (Collection $rows, string $chainId): array => ['strategy' => $key, 'strategy_label' => $label, 'chain_id' => $chainId, ...$this->results($rows)])
            ->values())
            ->values();

        return [
            'strategies' => $strategies,
            'by_chain' => $byChain,
            'pairs' => [
                'tracking' => NewPair::query()->tracking()->count(),
                'total' => NewPair::query()->count(),
                'last_24h' => NewPair::query()->where('first_seen_at', '>=', now()->subDay())->count(),
                'by_source' => NewPair::query()->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source'),
                'by_chain' => NewPair::query()->selectRaw('chain_id, count(*) as n')->groupBy('chain_id')->pluck('n', 'chain_id'),
                'chains' => $this->pullsPerChain(),
                'first_seen_at' => NewPair::query()->min('first_seen_at'),
            ],
            'rules' => [
                'stop_pct' => config('market.new_pairs.stop_pct'),
                'take_profit_pct' => config('market.new_pairs.take_profit_pct'),
                'max_hold_hours' => config('market.new_pairs.max_hold_hours'),
                'round_trip_cost_pct' => config('market.new_pairs.round_trip_cost_pct'),
                'min_liquidity_usd' => config('market.new_pairs.min_liquidity_usd'),
                'max_tracked' => config('market.new_pairs.max_tracked'),
                'rug_loss_pct' => config('market.new_pairs.rug_loss_pct'),
            ],
        ];
    }

    /**
     * Closed-trade results of a group of trades.
     *
     * @param  Collection<int, NewPairTrade>  $trades
     * @return array{closed: int, avg_net_pct: float|null, median_net_pct: float|null, win_rate: float|null, rug_rate: float|null, profit_factor: float|null, total_net_pct: float|null}
     */
    private function results(Collection $trades): array
    {
        $net = $trades->where('status', NewPairTrade::CLOSED)->pluck('net_pct')->map(fn ($v): float => (float) $v)->sort()->values();
        $gain = $net->filter(fn (float $v): bool => $v > 0)->sum();
        $loss = -$net->filter(fn (float $v): bool => $v < 0)->sum();
        $count = $net->count();
        $rugLoss = -(float) config('market.new_pairs.rug_loss_pct');

        return [
            'closed' => $count,
            'avg_net_pct' => $count ? round($net->avg(), 2) : null,
            'median_net_pct' => $count ? round($count % 2 ? $net[intdiv($count, 2)] : ($net[$count / 2 - 1] + $net[$count / 2]) / 2, 2) : null,
            'win_rate' => $count ? round($net->filter(fn (float $v): bool => $v > 0)->count() / $count * 100, 1) : null,
            'rug_rate' => $count ? round($net->filter(fn (float $v): bool => $v <= $rugLoss)->count() / $count * 100, 1) : null,
            'profit_factor' => $loss > 0 ? round($gain / $loss, 2) : null,
            'total_net_pct' => $count ? round($net->sum(), 2) : null,
        ];
    }

    /**
     * Pools per chain and how many of the finished ones ended because their liquidity was pulled.
     *
     * @return Collection<int, array{chain_id: string, total: int, done: int, liquidity_pulled: int, liquidity_pulled_pct: float|null}>
     */
    private function pullsPerChain(): Collection
    {
        return NewPair::query()
            ->selectRaw('chain_id, count(*) as total, sum(status = ?) as done, sum(end_reason = ?) as pulled', [NewPair::DONE, 'likuiditas'])
            ->groupBy('chain_id')
            ->orderBy('chain_id')
            ->get()
            ->map(fn (NewPair $row): array => [
                'chain_id' => $row->chain_id,
                'total' => (int) $row->total,
                'done' => (int) $row->done,
                'liquidity_pulled' => (int) $row->pulled,
                'liquidity_pulled_pct' => $row->done > 0 ? round($row->pulled / $row->done * 100, 1) : null,
            ]);
    }
}
