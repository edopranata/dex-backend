<?php

namespace App\Http\Controllers\Api;

use App\Enums\WatchlistAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTokenRequest;
use App\Http\Resources\AnalysisResource;
use App\Http\Resources\NewsItemResource;
use App\Http\Resources\SignalResource;
use App\Http\Resources\TokenResource;
use App\Http\Resources\WatchlistEventResource;
use App\Http\Resources\WhaleTradeResource;
use App\Models\Token;
use App\Models\WatchlistEvent;
use App\Services\Analysis\WatchlistManager;
use App\Services\MarketData\DexScreenerClient;
use App\Services\MarketData\GeckoTerminalClient;
use App\Services\MarketData\TokenRegistry;
use App\Services\MarketData\WhaleSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class TokenController extends Controller
{
    public function index(WatchlistManager $watchlist): AnonymousResourceCollection
    {
        $tokens = Token::query()
            ->active()
            ->with(['latestAnalysis', 'latestSecurityCheck'])
            ->withMax(['watchlistEvents as last_added_at' => fn ($q) => $q->where('action', WatchlistAction::Added)], 'created_at')
            ->orderByDesc('liquidity_usd')
            ->get();

        $histories = $watchlist->dailyScoresFor($tokens->modelKeys());

        foreach ($tokens as $token) {
            $token->score_history = $histories->get($token->id, []);
            $token->score_trend = WatchlistManager::trend($token->score_history);
        }

        return TokenResource::collection($tokens)->additional([
            'capacity' => ['used' => $tokens->count(), 'max' => config('market.simulator.max_tokens')],
        ]);
    }

    /**
     * Pin keeps a token on the list regardless of its score; unpinned tokens are managed automatically.
     */
    public function pin(Request $request, Token $token, WatchlistManager $watchlist): TokenResource
    {
        $pinned = $request->validate(['pinned' => ['required', 'boolean']])['pinned'];

        $token->update(['is_pinned' => $pinned]);
        $watchlist->log(
            $token,
            $pinned ? WatchlistAction::Pinned : WatchlistAction::Unpinned,
            $pinned ? 'Dikunci oleh '.$request->user()->name.': tidak akan dikeluarkan otomatis.' : 'Kunci dilepas oleh '.$request->user()->name.': dikelola otomatis berdasarkan skor.',
        );

        return new TokenResource($token);
    }

    public function events(): AnonymousResourceCollection
    {
        return WatchlistEventResource::collection(WatchlistEvent::query()->with('token')->latest()->limit(40)->get());
    }

    /**
     * Search DEX Screener for pairs that can be added to the watchlist.
     */
    public function search(Request $request, DexScreenerClient $dexScreener): JsonResponse
    {
        $query = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];

        $pairs = Cache::remember('dexscreener:search:'.md5($query), 60, fn (): array => $dexScreener->search($query));

        $tracked = Token::query()->active()->get(['chain_id', 'pair_address'])
            ->map(fn (Token $token): string => $token->chain_id.':'.strtolower($token->pair_address))
            ->flip();

        $results = collect($pairs)
            ->filter(fn (array $pair): bool => config("market.chains.{$pair['chainId']}") !== null
                && ($pair['liquidity']['usd'] ?? 0) >= config('market.min_search_liquidity_usd'))
            ->sortByDesc(fn (array $pair): float => $pair['liquidity']['usd'] ?? 0)
            ->take(20)
            ->map(fn (array $pair): array => [
                'chain_id' => $pair['chainId'],
                'pair_address' => $pair['pairAddress'],
                'dex_id' => $pair['dexId'] ?? null,
                'symbol' => $pair['baseToken']['symbol'],
                'name' => mb_substr($pair['baseToken']['name'], 0, 80),
                'quote_symbol' => $pair['quoteToken']['symbol'] ?? null,
                'price_usd' => isset($pair['priceUsd']) ? (float) $pair['priceUsd'] : null,
                'liquidity_usd' => $pair['liquidity']['usd'] ?? null,
                'volume_h24' => $pair['volume']['h24'] ?? null,
                'image_url' => $pair['info']['imageUrl'] ?? null,
                'tracked' => $tracked->has($pair['chainId'].':'.strtolower($pair['pairAddress'])),
            ])
            ->values();

        return response()->json(['data' => $results]);
    }

    public function store(StoreTokenRequest $request, TokenRegistry $registry, WatchlistManager $watchlist): TokenResource
    {
        $token = $registry->add($request->validated('chain_id'), $request->validated('address'));

        if ($token->wasRecentlyCreated || $token->wasChanged('is_active')) {
            $registry->bootstrap($token);
            $watchlist->log($token, WatchlistAction::Added, 'Ditambahkan manual oleh '.$request->user()->name.' (otomatis dikunci).');
        }

        return new TokenResource($token);
    }

    public function show(Token $token): JsonResponse
    {
        $token->load(['latestAnalysis', 'latestSecurityCheck']);

        return response()->json([
            'data' => new TokenResource($token),
            'analysis' => $token->latestAnalysis ? new AnalysisResource($token->latestAnalysis) : null,
            'signal' => ($signal = $token->signals()->open()->latest()->first()) ? new SignalResource($signal) : null,
            'whale_threshold_usd' => WhaleSyncService::threshold($token),
        ]);
    }

    public function destroy(Request $request, Token $token, WatchlistManager $watchlist): JsonResponse
    {
        $watchlist->remove($token, 'Dikeluarkan manual oleh '.$request->user()->name.'.');

        return response()->json(['message' => "{$token->symbol} tidak lagi dipantau."]);
    }

    public function candles(Request $request, Token $token): JsonResponse
    {
        $timeframe = $request->validate([
            'timeframe' => ['sometimes', Rule::in(array_keys(GeckoTerminalClient::TIMEFRAMES))],
        ])['timeframe'] ?? '1h';

        $candles = $token->candles()
            ->where('timeframe', $timeframe)
            ->orderByDesc('open_time')
            ->limit(500)
            ->get(['open_time', 'open', 'high', 'low', 'close', 'volume'])
            ->reverse()
            ->values();

        return response()->json(['data' => $candles, 'timeframe' => $timeframe]);
    }

    public function whales(Token $token): AnonymousResourceCollection
    {
        return WhaleTradeResource::collection($token->whaleTrades()->latest('traded_at')->limit(50)->get());
    }

    public function news(Token $token): AnonymousResourceCollection
    {
        return NewsItemResource::collection($token->newsItems()->latest('published_at')->limit(30)->get());
    }

    public function analyses(Token $token): AnonymousResourceCollection
    {
        return AnalysisResource::collection($token->analyses()->latest()->limit(48)->get());
    }
}
