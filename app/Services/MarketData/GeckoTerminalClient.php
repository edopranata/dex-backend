<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * OHLCV candles and recent trades for DEX pools. The free public API is rate limited per IP
 * and rejects bursts, so requests are spaced evenly across every worker (one every
 * 60 / limit seconds) and a 429 pauses all of them.
 */
class GeckoTerminalClient
{
    private const MAX_429_RETRIES = 3;

    /**
     * Unix time (float) before which no request may start.
     */
    private const NEXT_SLOT_KEY = 'geckoterminal:next-slot';

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
    public function ohlcv(string $network, string $poolAddress, string $tokenAddress, string $timeframe, int $limit = 300, ?int $beforeTimestamp = null): array
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
            ...($beforeTimestamp !== null ? ['before_timestamp' => $beforeTimestamp] : []),
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
     * The USD price of a token in one pool, as a second opinion on the DEX Screener price. It takes the
     * next free request slot ahead of the background syncs, but never waits longer than $maxWaitSeconds.
     *
     * @return float|null Null when the pool is unknown or does not hold the token.
     *
     * @throws GeckoTerminalBusyException When no slot comes up in time or GeckoTerminal asks for a pause.
     */
    public function poolTokenPrice(string $network, string $poolAddress, string $tokenAddress, float $maxWaitSeconds): ?float
    {
        $this->reserveTurn($maxWaitSeconds);

        $response = $this->request(timeout: 8, retries: 0)->get("/networks/{$network}/pools/{$poolAddress}");

        if ($response->status() === 429) {
            $pause = max(20, (int) $response->header('Retry-After'));
            Cache::put(self::NEXT_SLOT_KEY, $this->clock() + $pause, 600);
            Log::warning("GeckoTerminal 429 (cek harga pool): semua request dijeda {$pause} detik.");

            throw new GeckoTerminalBusyException('GeckoTerminal asked for a pause.');
        }

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        foreach (['base', 'quote'] as $side) {
            $id = (string) $response->json("data.relationships.{$side}_token.data.id");

            // Ids look like "solana_<address>" or "polygon_pos_<address>"; addresses never hold an underscore.
            if ($id !== '' && strcasecmp(substr($id, strrpos($id, '_') + 1), $tokenAddress) === 0) {
                $price = $response->json("data.attributes.{$side}_token_price_usd");

                return is_numeric($price) && (float) $price > 0 ? (float) $price : null;
            }
        }

        return null;
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
            $this->waitForTurn();

            $response = $this->request()->get($path, $query);

            if ($response->status() !== 429) {
                break;
            }

            $pause = max(20 * ($attempt + 1), (int) $response->header('Retry-After'));
            Cache::put(self::NEXT_SLOT_KEY, $this->clock() + $pause, 600);
            Log::warning(sprintf('GeckoTerminal 429 (%s): semua request dijeda %d detik, percobaan %d/%d.', strtok($path, '?'), $pause, $attempt + 1, self::MAX_429_RETRIES + 1));

            if ($attempt >= self::MAX_429_RETRIES) {
                break;
            }
        }

        if ($response->status() === 404) {
            return $response;
        }

        return $response->throw();
    }

    /**
     * Claim the next free request slot, waiting until it comes up. The slot is shared through
     * the cache, so web requests and every worker together stay under the limit.
     */
    private function waitForTurn(): void
    {
        $interval = 60 / max(1, (int) config('market.geckoterminal_requests_per_minute'));
        $deadline = $this->clock() + 180;

        while (true) {
            $wait = Cache::lock('geckoterminal:slot-lock', 5)->block(5, function () use ($interval): float {
                $now = $this->clock();
                $next = (float) Cache::get(self::NEXT_SLOT_KEY, 0);

                if ($now < $next) {
                    return $next - $now;
                }

                Cache::put(self::NEXT_SLOT_KEY, $now + $interval, 600);

                return 0.0;
            });

            if ($wait <= 0) {
                return;
            }

            if ($this->clock() + $wait > $deadline) {
                throw new RuntimeException('GeckoTerminal rate limit wait timed out.');
            }

            Sleep::usleep((int) ceil($wait * 1_000_000));
        }
    }

    /**
     * Book the next free slot for this caller (moving everyone else back by one) and wait for it, or
     * give up at once when that slot is further away than $maxWaitSeconds.
     *
     * @throws GeckoTerminalBusyException
     */
    private function reserveTurn(float $maxWaitSeconds): void
    {
        $interval = 60 / max(1, (int) config('market.geckoterminal_requests_per_minute'));

        $wait = Cache::lock('geckoterminal:slot-lock', 5)->block(5, function () use ($interval, $maxWaitSeconds): ?float {
            $now = $this->clock();
            $slot = max($now, (float) Cache::get(self::NEXT_SLOT_KEY, 0));

            if ($slot - $now > $maxWaitSeconds) {
                return null;
            }

            Cache::put(self::NEXT_SLOT_KEY, $slot + $interval, 600);

            return $slot - $now;
        });

        if ($wait === null) {
            throw new GeckoTerminalBusyException("No GeckoTerminal slot within {$maxWaitSeconds} seconds.");
        }

        if ($wait > 0) {
            Sleep::usleep((int) ceil($wait * 1_000_000));
        }
    }

    /**
     * Unix time with microseconds from the framework clock (so tests can travel in time).
     */
    private function clock(): float
    {
        return (float) now()->format('U.u');
    }

    private function request(int $timeout = 15, int $retries = 2): PendingRequest
    {
        return Http::baseUrl('https://api.geckoterminal.com/api/v2')
            ->acceptJson()
            ->timeout($timeout)
            ->when($retries > 0, fn (PendingRequest $request) => $request->retry($retries, 1000, fn (Throwable $e): bool => ! $e instanceof RequestException, throw: false));
    }
}
