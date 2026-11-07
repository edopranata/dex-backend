<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\WatchlistAction;
use App\Http\Controllers\Controller;
use App\Jobs\SyncTokenCandles;
use App\Models\Token;
use App\Models\WatchlistEvent;
use App\Services\MarketData\CandleSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TokenController extends Controller
{
    /** Age groups of tokens out of the Market, in days, for deciding later how long to keep them. */
    private const OUT_GROUPS = ['under_7' => [0, 7], '7_to_30' => [7, 30], 'over_30' => [30, PHP_INT_MAX]];

    /**
     * Every token that has been in the Market, in it now or not: how often it came and went, what candle data
     * is stored for it, and whether its candles are fetched now. Candidates that never entered are left out.
     * Only tokens in the Market have their candles fetched; one that comes back fills its gap once.
     */
    public function index(): JsonResponse
    {
        $tokens = Token::query()->everInMarket()->withCount('signals')->get();
        $ids = $tokens->pluck('id');

        $events = WatchlistEvent::query()
            ->whereIn('token_id', $ids)
            ->whereIn('action', [WatchlistAction::Added, WatchlistAction::Removed])
            ->orderBy('created_at')
            ->get(['token_id', 'action', 'reason', 'created_at'])
            ->groupBy('token_id');

        $candles = DB::table('candles')
            ->whereIn('token_id', $ids)
            ->select('token_id', 'timeframe', DB::raw('count(*) as total'), DB::raw('max(open_time) as latest'))
            ->groupBy('token_id', 'timeframe')
            ->get()
            ->groupBy('token_id');

        $rows = $tokens->map(function (Token $token) use ($events, $candles): array {
            $history = $events->get($token->id, collect());
            $added = $history->where('action', WatchlistAction::Added);
            $removed = $history->where('action', WatchlistAction::Removed)->last();
            $stored = $candles->get($token->id, collect());
            $latest = $stored->firstWhere('timeframe', '15m')?->latest;
            $outSince = $token->is_active ? null : $removed?->created_at;

            return [
                'id' => $token->id,
                'symbol' => $token->symbol,
                'name' => $token->name,
                'slug' => $token->slug,
                'chain_label' => config("market.chains.{$token->chain_id}.label", $token->chain_id),
                'in_market' => $token->is_active,
                'pinned' => $token->is_pinned,
                'entries' => max(1, $added->count()),
                'first_added_at' => $added->first()?->created_at?->toIso8601String(),
                'last_removed_at' => $removed?->created_at?->toIso8601String(),
                'last_removed_reason' => $token->is_active ? null : $removed?->reason,
                'out_days' => $outSince === null ? null : round($outSince->diffInHours(now()) / 24, 1),
                'candles' => $stored->mapWithKeys(fn ($row): array => [$row->timeframe => (int) $row->total])->all(),
                'candle_rows' => (int) $stored->sum('total'),
                'latest_15m_at' => $latest === null ? null : date(DATE_ATOM, (int) $latest),
                // Older than the backfill reaches: the next turn leaves a hole unless filled by hand.
                'gap' => $latest === null || (int) $latest < now()->subDays((int) config('market.candles.backfill_days'))->getTimestamp(),
                // In the Market every 15 minutes; out of it in turns (market:candles --out-of-market).
                'fetch' => $token->is_active ? 'every_15m' : 'rotation',
                'signals' => $token->signals_count,
            ];
        })
            ->sortBy([['in_market', 'desc'], fn (array $a, array $b): int => strcmp((string) $b['last_removed_at'], (string) $a['last_removed_at'])])
            ->values();

        return response()->json(['summary' => $this->summary($rows), 'tokens' => $rows]);
    }

    /**
     * Fetch the most 15m candles GeckoTerminal gives in one request (±10 days) for one token and build its
     * higher timeframes from them, through the same rate-limited queue. A gap longer than that also fetches
     * 1h, 4h and 1d directly, like a token coming back to the Market.
     */
    public function fillCandles(Token $token): JsonResponse
    {
        abort_unless(Token::query()->everInMarket()->whereKey($token->id)->exists(), 404);

        SyncTokenCandles::dispatch($token, [], CandleSyncService::MAX_CANDLES);

        return response()->json(['message' => "{$token->symbol}: pengambilan 1.000 candle 15m masuk antrean."], 202);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $out = $rows->where('in_market', false);
        $inMarket = $rows->count() - $out->count();
        $rotation = (int) config('market.candles.out_of_market_per_hour');

        return [
            'tokens' => $rows->count(),
            'in_market' => $rows->where('in_market', true)->count(),
            'out_of_market' => $out->count(),
            'gaps' => $rows->where('gap', true)->count(),
            'out_groups' => collect(self::OUT_GROUPS)->map(fn (array $range): int => $out->filter(fn (array $row): bool => $row['out_days'] !== null && $row['out_days'] >= $range[0] && $row['out_days'] < $range[1])->count())->all(),
            'rotation_per_hour' => $rotation,
            // How often each token out of the Market gets its turn; the data stays whole while under the backfill.
            'rotation_hours' => $out->isEmpty() || $rotation < 1 ? null : round($out->count() / $rotation, 1),
            'rotation_limit_hours' => (int) config('market.candles.backfill_days') * 24,
            'candle_rows' => (int) $rows->sum('candle_rows'),
            'candle_rows_out_of_market' => (int) $out->sum('candle_rows'),
            // A 15m and a whale request per Market token every 15 minutes, and the hourly turn of the others.
            'requests_per_hour' => $inMarket * 2 * 4 + min($rotation, $out->count()),
            'request_limit_per_hour' => (int) config('market.geckoterminal_requests_per_minute') * 60,
        ];
    }
}
