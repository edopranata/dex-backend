<?php

namespace App\Http\Controllers\Api;

use App\Enums\CandidateStatus;
use App\Enums\Recommendation;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnalysisResource;
use App\Http\Resources\NewsItemResource;
use App\Http\Resources\TokenCandidateResource;
use App\Http\Resources\WhaleTradeResource;
use App\Models\Analysis;
use App\Models\NewsItem;
use App\Models\Token;
use App\Models\TokenCandidate;
use App\Models\WhaleTrade;
use App\Services\MarketData\FearGreedClient;
use Illuminate\Http\JsonResponse;

class MarketController extends Controller
{
    public function overview(FearGreedClient $fearGreed): JsonResponse
    {
        $analyses = Analysis::query()
            ->whereIn('id', Analysis::query()->selectRaw('MAX(id)')->whereIn('token_id', Token::query()->active()->select('id'))->groupBy('token_id'))
            ->with('token')
            ->orderByDesc('final_score')
            ->get();

        return response()->json([
            'fear_greed' => $fearGreed->latest(),
            'recommendation_counts' => collect(Recommendation::cases())->mapWithKeys(fn (Recommendation $r): array => [
                $r->value => $analyses->where('recommendation', $r)->count(),
            ]),
            'top_picks' => AnalysisResource::collection($analyses->filter(fn (Analysis $a): bool => $a->recommendation->isBullish())->take(5)->values()),
            'warnings' => AnalysisResource::collection($analyses->filter(fn (Analysis $a): bool => in_array($a->recommendation, [Recommendation::Avoid, Recommendation::StrongSell], true))->take(5)->values()),
            'whales' => WhaleTradeResource::collection(WhaleTrade::query()->with('token')->latest('traded_at')->limit(10)->get()),
            'discoveries' => TokenCandidateResource::collection(TokenCandidate::query()
                ->where('last_seen_at', '>=', now()->subDay())
                ->where('status', '!=', CandidateStatus::Rejected)
                ->whereNotNull('discovery_score')
                ->where('whale_score', '>=', 50)
                ->orderByDesc('discovery_score')
                ->limit(5)
                ->get()),
            'news' => NewsItemResource::collection(NewsItem::query()->with('tokens')->latest('published_at')->limit(8)->get()),
            'chains' => collect(config('market.chains'))->map(fn (array $chain, string $id): array => ['id' => $id, 'label' => $chain['label']])->values(),
        ]);
    }
}
