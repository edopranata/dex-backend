<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * OHLCV candles and recent trades for DEX pools. The free public API is rate limited
 * per IP, so requests are throttled across every worker through the cache-backed limiter.
 */
class GeckoTerminalClient
{
    private const MAX_429_RETRIES = 3;

    /**
     * @var array<string, array{0: string, 1: int, 2: int}>
     */
    public const TIMEFRAMES = [
        '15m' => ['minute', 15, 900],
        '1h' => ['hour', 1, 3600],
        '4h' => ['hour', 4, 14400],
        '1d' => ['day', 1, 86400],
    ];

    /**
     * Candles oldest first, priced in USD for the given token.
     *
     * @return list<array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function ohlcv(string $network, string $poolAddress, string $tokenAddress, string $timeframe, int $limit = 300): array
    {
        if (! isset(self::TIMEFRAMES[$timeframe])) {
            throw new InvalidArgumentException("Unsupported timeframe [{$timeframe}].");
        }

        [$period, $aggregate] = self::TIMEFRAMES[$timeframe];

        $rows = $this->get("/networks/{$network}/pools/{$poolAddress}/ohlcv/{$period}", [
            'aggregate' => $aggregate,
            'limit' => $limit,
            'currency' => 'usd',
            'token' => $tokenAddress,
        ])->json('data.attributes.ohlcv_list') ?? [];

        $candles = array_map(fn (array $row): array => [
            'open_time' => (int) $row[0],
            'open' => (float) $row[1],
            'high' => (float) $row[2],
            'low' => (float) $row[3],
            'close' => (float) $row[4],
            'volume' => (float) $row[5],
        ], $rows);

        return array_reverse($candles);
    }

    /**
     * Trades from the last 24 hours above a USD size (max 300 returned by the API).
     *
     * @return list<array<string, mixed>>
     */
    public function trades(string $network, string $poolAddress, float $minUsd): array
    {
        return $this->get("/networks/{$network}/pools/{$poolAddress}/trades", [
            'trade_volume_in_usd_greater_than' => $minUsd,
        ])->json('data') ?? [];
    }

    /**
     * Trending pools on one network with their base token details.
     *
     * @return list<array{pool_address: string, token_address: string, symbol: string, name: string, image_url: string|null, buyers_h24: int|null, sellers_h24: int|null}>
     */
    public function trendingPools(string $network, string $duration = '6h'): array
    {
        $response = $this->get("/networks/{$network}/trending_pools", ['include' => 'base_token', 'duration' => $duration]);

        $tokens = collect($response->json('included') ?? [])->keyBy('id');

        return collect($response->json('data') ?? [])
            ->map(function (array $pool) use ($tokens): ?array {
                $token = $tokens->get($pool['relationships']['base_token']['data']['id'] ?? '');

                if ($token === null) {
                    return null;
                }

                $transactions = $pool['attributes']['transactions']['h24'] ?? [];

                return [
                    'pool_address' => $pool['attributes']['address'],
                    'token_address' => $token['attributes']['address'],
                    'symbol' => $token['attributes']['symbol'],
                    'name' => $token['attributes']['name'],
                    'image_url' => $token['attributes']['image_url'] ?? null,
                    'buyers_h24' => isset($transactions['buyers']) ? (int) $transactions['buyers'] : null,
                    'sellers_h24' => isset($transactions['sellers']) ? (int) $transactions['sellers'] : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query): Response
    {
        for ($attempt = 0; ; $attempt++) {
            $this->waitForRateLimit();

            $response = $this->request()->get($path, $query);

            if ($response->status() !== 429 || $attempt >= self::MAX_429_RETRIES) {
                break;
            }

            sleep(20 * ($attempt + 1));
        }

        if ($response->status() === 404) {
            return $response;
        }

        return $response->throw();
    }

    private function waitForRateLimit(): void
    {
        $deadline = time() + 120;
        $perMinute = config('market.geckoterminal_requests_per_minute');

        while (! RateLimiter::attempt('geckoterminal', $perMinute, fn (): bool => true, 60)) {
            if (time() > $deadline) {
                throw new RuntimeException('GeckoTerminal rate limit wait timed out.');
            }

            sleep(2);
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.geckoterminal.com/api/v2')
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 1000, fn (Throwable $e): bool => ! $e instanceof RequestException, throw: false);
    }
}
