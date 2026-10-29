<?php

namespace App\Http\Controllers\Api;

use App\Enums\CandidateStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TokenCandidateResource;
use App\Http\Resources\TokenResource;
use App\Models\TokenCandidate;
use App\Services\MarketData\TokenRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DiscoveryController extends Controller
{
    /**
     * Candidates seen in the last 24 hours, best first; unchecked ones last.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(CandidateStatus::class)],
            'chain_id' => ['sometimes', 'string'],
        ]);

        $candidates = TokenCandidate::query()
            ->with('token.latestAnalysis')
            ->where('last_seen_at', '>=', now()->subDay())
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', CandidateStatus::Rejected))
            ->when($filters['chain_id'] ?? null, fn ($q, $chain) => $q->where('chain_id', $chain))
            ->orderByRaw('discovery_score IS NULL')
            ->orderByDesc('discovery_score')
            ->orderByDesc('trend_score')
            ->limit(30)
            ->get();

        return TokenCandidateResource::collection($candidates)->additional([
            'last_run_at' => TokenCandidate::query()->latest('last_seen_at')->first()?->last_seen_at,
            'counts' => TokenCandidate::query()
                ->where('last_seen_at', '>=', now()->subDay())
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ]);
    }

    /**
     * Manually add a discovered token to the watchlist.
     */
    public function track(TokenCandidate $candidate, TokenRegistry $registry): TokenResource
    {
        if ($candidate->status === CandidateStatus::Rejected) {
            throw ValidationException::withMessages(['candidate' => 'Kandidat ini ditolak oleh filter keamanan/likuiditas.']);
        }

        $token = $registry->add($candidate->chain_id, $candidate->pair_address, 'discovery');

        if ($token->wasRecentlyCreated || $token->wasChanged('is_active')) {
            $registry->bootstrap($token);
        }

        $candidate->update(['token_id' => $token->id, 'status' => CandidateStatus::Tracked]);

        return new TokenResource($token);
    }
}
