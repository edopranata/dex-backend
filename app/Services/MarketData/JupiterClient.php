<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Jupiter Tokens API v2 (Solana only). Without a key about 30 requests per minute (checked 11 Nov 2026); a key from
 * the owner, when set, is sent as x-api-key. Organic score filters out wash trading and bot volume.
 */
class JupiterClient
{
    public const CATEGORIES = ['toptrending', 'toporganicscore', 'toptraded'];

    /**
     * Top tokens of one category over an interval (5m, 1h, 6h or 24h), best first.
     *
     * @return list<array{id: string, symbol: string, organicScore?: float, liquidity?: float}>
     */
    public function topTokens(string $category, string $interval, int $limit): array
    {
        return $this->request()->get("/tokens/v2/{$category}/{$interval}", ['limit' => $limit])->throw()->json() ?? [];
    }

    private function request(): PendingRequest
    {
        $key = config('market.jupiter.key');

        return Http::baseUrl((string) config('market.jupiter.base_url'))
            ->acceptJson()
            ->timeout(20)
            ->when(filled($key), fn (PendingRequest $request) => $request->withHeaders(['x-api-key' => $key]));
    }
}
