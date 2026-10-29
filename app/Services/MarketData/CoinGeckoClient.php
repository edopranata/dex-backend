<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CoinGecko trending searches: the coins people are looking up most in the last 24 hours.
 * Works without a key; a free Demo key (COINGECKO_DEMO_API_KEY) gives steadier limits.
 */
class CoinGeckoClient
{
    /**
     * @return list<array{id: string, symbol: string, name: string, market_cap_rank: int|null, rank: int}>
     */
    public function trending(): array
    {
        return Cache::remember('coingecko:trending', 1800, function (): array {
            try {
                $coins = Http::baseUrl('https://api.coingecko.com/api/v3')
                    ->acceptJson()
                    ->timeout(15)
                    ->when(config('market.discovery.coingecko_key'), fn ($request, $key) => $request->withHeaders(['x-cg-demo-api-key' => $key]))
                    ->get('/search/trending')
                    ->throw()
                    ->json('coins') ?? [];
            } catch (Throwable $e) {
                Log::warning("CoinGecko trending failed: {$e->getMessage()}");

                return [];
            }

            return array_values(array_map(fn (array $coin, int $index): array => [
                'id' => $coin['item']['id'],
                'symbol' => strtoupper($coin['item']['symbol']),
                'name' => $coin['item']['name'],
                'market_cap_rank' => $coin['item']['market_cap_rank'] ?? null,
                'rank' => $index + 1,
            ], $coins, array_keys($coins)));
        });
    }
}
