<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Real-time pair prices, liquidity and volume. Rate limit: 300 req/min.
 *
 * DEX Screener does not provide candles or individual trades; those come from GeckoTerminal.
 */
class DexScreenerClient
{
    public const MAX_PAIRS_PER_REQUEST = 30;

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        return $this->request()->get('/latest/dex/search', ['q' => $query])->throw()->json('pairs') ?? [];
    }

    /**
     * @param  list<string>  $pairAddresses
     * @return list<array<string, mixed>>
     */
    public function pairs(string $chainId, array $pairAddresses): array
    {
        $pairs = [];

        foreach (array_chunk($pairAddresses, self::MAX_PAIRS_PER_REQUEST) as $chunk) {
            $response = $this->request()->get("/latest/dex/pairs/{$chainId}/".implode(',', $chunk))->throw();
            array_push($pairs, ...($response->json('pairs') ?? []));
        }

        return $pairs;
    }

    /**
     * Pairs where the token is the base token, sorted by liquidity (highest first).
     *
     * @return list<array<string, mixed>>
     */
    public function tokenPairs(string $chainId, string $tokenAddress): array
    {
        $pairs = array_values(array_filter(
            $this->request()->get("/token-pairs/v1/{$chainId}/{$tokenAddress}")->throw()->json() ?? [],
            fn (array $pair): bool => strcasecmp($pair['baseToken']['address'] ?? '', $tokenAddress) === 0,
        ));

        usort($pairs, fn (array $a, array $b): int => ($b['liquidity']['usd'] ?? 0) <=> ($a['liquidity']['usd'] ?? 0));

        return $pairs;
    }

    /**
     * Tokens with the most paid boosts right now. Boosts are advertising, not organic demand.
     *
     * @return list<array{chainId: string, tokenAddress: string, totalAmount?: int}>
     */
    public function topBoosts(): array
    {
        return $this->request()->get('/token-boosts/top/v1')->throw()->json() ?? [];
    }

    /**
     * All pairs for up to 30 token addresses on one chain.
     *
     * @param  list<string>  $tokenAddresses
     * @return list<array<string, mixed>>
     */
    public function pairsForTokens(string $chainId, array $tokenAddresses): array
    {
        $pairs = [];

        foreach (array_chunk($tokenAddresses, self::MAX_PAIRS_PER_REQUEST) as $chunk) {
            array_push($pairs, ...($this->request()->get("/tokens/v1/{$chainId}/".implode(',', $chunk))->throw()->json() ?? []));
        }

        return $pairs;
    }

    /**
     * Map a DEX Screener pair payload to Token model attributes.
     *
     * @param  array<string, mixed>  $pair
     * @return array<string, mixed>
     */
    public static function toTokenAttributes(array $pair): array
    {
        return [
            'chain_id' => $pair['chainId'],
            'pair_address' => $pair['pairAddress'],
            'token_address' => $pair['baseToken']['address'],
            'symbol' => mb_substr($pair['baseToken']['symbol'] ?? '?', 0, 64),
            'name' => mb_substr($pair['baseToken']['name'] ?? '?', 0, 255),
            'quote_symbol' => mb_substr($pair['quoteToken']['symbol'] ?? '', 0, 64),
            'dex_id' => $pair['dexId'] ?? null,
            'image_url' => $pair['info']['imageUrl'] ?? null,
            'url' => $pair['url'] ?? null,
            'price_usd' => isset($pair['priceUsd']) ? (float) $pair['priceUsd'] : null,
            'price_change_m5' => $pair['priceChange']['m5'] ?? null,
            'price_change_h1' => $pair['priceChange']['h1'] ?? null,
            'price_change_h6' => $pair['priceChange']['h6'] ?? null,
            'price_change_h24' => $pair['priceChange']['h24'] ?? null,
            'volume_h24' => $pair['volume']['h24'] ?? null,
            'liquidity_usd' => $pair['liquidity']['usd'] ?? null,
            'fdv' => $pair['fdv'] ?? null,
            'market_cap' => $pair['marketCap'] ?? null,
            'txns_h24_buys' => $pair['txns']['h24']['buys'] ?? null,
            'txns_h24_sells' => $pair['txns']['h24']['sells'] ?? null,
            'pair_created_at' => isset($pair['pairCreatedAt']) ? Carbon::createFromTimestampMs($pair['pairCreatedAt']) : null,
            'price_updated_at' => now(),
        ];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.dexscreener.com')
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 500, throw: false);
    }
}
