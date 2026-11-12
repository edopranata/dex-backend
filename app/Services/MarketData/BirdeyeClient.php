<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Birdeye Data Services. The free Standard package gives 30,000 compute units a month at 1 request per second
 * (checked 12 Nov 2026); the trader list costs 30 units per request. The key is the owner's, for the owner's use
 * only: Birdeye's terms forbid sharing or showing the data to others.
 */
class BirdeyeClient
{
    public function hasKey(): bool
    {
        return filled(config('market.birdeye.key'));
    }

    /**
     * Most profitable traders of a chain over a period (yesterday, today, 1W, 30d or 90d), best first.
     *
     * @return list<array{address: string, pnl?: float, realized_pnl?: float, unrealized_pnl?: float, volume?: float, trade_count?: int, score?: float}>
     */
    public function topTraders(string $chain, string $period, string $sortBy, int $limit): array
    {
        return $this->request($chain)->get('/trader/gainers-losers', [
            'type' => $period,
            'sort_by' => $sortBy,
            'sort_type' => 'desc',
            'offset' => 0,
            'limit' => $limit,
        ])->throw()->json('data.items') ?? [];
    }

    private function request(string $chain): PendingRequest
    {
        return Http::baseUrl((string) config('market.birdeye.base_url'))
            ->acceptJson()
            ->timeout(30)
            ->withHeaders(['X-API-KEY' => (string) config('market.birdeye.key'), 'x-chain' => $chain]);
    }
}
