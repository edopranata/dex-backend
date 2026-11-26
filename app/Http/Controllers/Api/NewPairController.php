<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewPair;
use App\Models\NewPairTrade;
use App\Services\NewPairs\NewPairReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Token Baru (26 Nov 2026): recorded young pools and the paper trades of the New Token Hunter strategies. Read only.
 */
class NewPairController extends Controller
{
    public function summary(NewPairReport $report): JsonResponse
    {
        return response()->json($report->summary());
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([NewPair::TRACKING, NewPair::DONE])],
            'chain' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:64'],
        ]);

        $page = NewPair::query()
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['chain'] ?? null, fn ($q, string $chain) => $q->where('chain_id', $chain))
            ->when($filters['search'] ?? null, fn ($q, string $search) => $q->where(fn ($w) => $w->where('symbol', 'like', "%{$search}%")->orWhere('token_address', $search)->orWhere('pair_address', $search)))
            ->withCount('trades')
            ->orderByDesc('first_seen_at')
            ->paginate(25);

        return response()->json($page->through(fn (NewPair $pair): array => [
            'id' => $pair->id,
            'chain_id' => $pair->chain_id,
            'symbol' => $pair->symbol,
            'name' => $pair->name,
            'quote_symbol' => $pair->quote_symbol,
            'dex_id' => $pair->dex_id,
            'pair_address' => $pair->pair_address,
            'token_address' => $pair->token_address,
            'url' => $pair->url,
            'source' => $pair->source,
            'status' => $pair->status,
            'end_reason' => $pair->end_reason,
            'pool_created_at' => $pair->pool_created_at,
            'first_seen_at' => $pair->first_seen_at,
            'age_at_first_seen_minutes' => $pair->pool_created_at ? (int) round($pair->pool_created_at->diffInMinutes($pair->first_seen_at, true)) : null,
            'price_usd' => $pair->price_usd,
            'change_since_seen_pct' => $pair->first_price_usd && $pair->price_usd ? round(($pair->price_usd / $pair->first_price_usd - 1) * 100, 1) : null,
            'max_since_seen_pct' => $pair->first_price_usd && $pair->high_price_usd ? round(($pair->high_price_usd / $pair->first_price_usd - 1) * 100, 1) : null,
            'min_since_seen_pct' => $pair->first_price_usd && $pair->low_price_usd ? round(($pair->low_price_usd / $pair->first_price_usd - 1) * 100, 1) : null,
            'liquidity_usd' => $pair->liquidity_usd,
            'first_liquidity_usd' => $pair->first_liquidity_usd,
            'volume_h1' => $pair->volume_h1,
            'buys_m5' => $pair->buys_m5,
            'sells_m5' => $pair->sells_m5,
            'security_checked' => $pair->security_checked_at !== null,
            'security_score' => $pair->security_score,
            'is_honeypot' => $pair->is_honeypot,
            'sell_tax' => $pair->sell_tax,
            'top10_percent' => $pair->top10_percent,
            'last_sample_at' => $pair->last_sample_at,
            'trades_count' => $pair->trades_count,
        ]));
    }

    public function trades(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'strategy' => ['nullable', Rule::in(array_keys(NewPairTrade::STRATEGIES))],
            'status' => ['nullable', Rule::in([NewPairTrade::OPEN, NewPairTrade::CLOSED])],
        ]);

        $page = NewPairTrade::query()
            ->with('newPair:id,chain_id,symbol,url,pair_address')
            ->when($filters['strategy'] ?? null, fn ($q, string $strategy) => $q->where('strategy', $strategy))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->orderByDesc('entry_at')
            ->paginate(25);

        return response()->json($page->through(fn (NewPairTrade $trade): array => [
            'id' => $trade->id,
            'strategy' => $trade->strategy,
            'strategy_label' => NewPairTrade::STRATEGIES[$trade->strategy] ?? $trade->strategy,
            'status' => $trade->status,
            'symbol' => $trade->newPair?->symbol,
            'chain_id' => $trade->newPair?->chain_id,
            'url' => $trade->newPair?->url,
            'entry_at' => $trade->entry_at,
            'entry_price' => $trade->entry_price,
            'pool_age_minutes' => $trade->pool_age_minutes,
            'liquidity_usd' => $trade->liquidity_usd,
            'tp_hit' => $trade->tp_hit,
            'mfe_pct' => round(($trade->high_price / $trade->entry_price - 1) * 100, 1),
            'mae_pct' => round(($trade->low_price / $trade->entry_price - 1) * 100, 1),
            'exit_at' => $trade->exit_at,
            'exit_price' => $trade->exit_price,
            'exit_reason' => $trade->exit_reason,
            'net_pct' => $trade->net_pct,
        ]));
    }
}
