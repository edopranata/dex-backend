<?php

namespace App\Services\NewPairs;

use App\Models\NewPair;
use App\Models\TokenCandidate;
use App\Services\MarketData\GeckoTerminalClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds pools younger than 24 hours for Token Baru: the young pairs the Market discovery already sees and rejects for
 * their age (no extra request), and GeckoTerminal's newest pools per network.
 */
class NewPairRegistry
{
    public function __construct(private GeckoTerminalClient $geckoTerminal) {}

    /**
     * Young pairs from the latest discovery runs (token_candidates), which discovery rejects below 24 hours.
     */
    public function fromDiscovery(): int
    {
        $config = config('market.new_pairs');
        $added = 0;

        TokenCandidate::query()
            ->whereNotNull('pair_created_at')
            ->where('pair_created_at', '>', now()->subHours($config['max_age_hours']))
            ->where('last_seen_at', '>=', now()->subHour())
            ->get()
            ->each(function (TokenCandidate $candidate) use (&$added): void {
                $added += $this->register([
                    'chain_id' => $candidate->chain_id,
                    'pair_address' => $candidate->pair_address,
                    'token_address' => $candidate->token_address,
                    'symbol' => $candidate->symbol,
                    'name' => $candidate->name,
                    'quote_symbol' => $candidate->quote_symbol,
                    'url' => $candidate->url,
                    'pool_created_at' => $candidate->pair_created_at,
                    'liquidity_usd' => $candidate->liquidity_usd === null ? null : (float) $candidate->liquidity_usd,
                ], 'discovery') ? 1 : 0;
            });

        return $added;
    }

    /**
     * GeckoTerminal new_pools, one request per configured network.
     *
     * @return array{found: int, added: int, failed: int}
     */
    public function fromGeckoTerminal(): array
    {
        $found = $added = $failed = 0;

        foreach (config('market.new_pairs.networks') as $chainId) {
            $network = config("market.chains.{$chainId}.geckoterminal");

            if ($network === null) {
                continue;
            }

            try {
                $pools = $this->geckoTerminal->newPools($network);
            } catch (Throwable $e) {
                $failed++;
                Log::warning("Token Baru: new_pools {$chainId} gagal: ".mb_substr($e->getMessage(), 0, 200));

                continue;
            }

            foreach ($pools as $pool) {
                $found++;
                $added += $this->register([
                    'chain_id' => $chainId,
                    'pair_address' => $pool['pool_address'],
                    'token_address' => $pool['token_address'],
                    'symbol' => $pool['symbol'],
                    'name' => $pool['name'],
                    'dex_id' => $pool['dex_id'],
                    'pool_created_at' => $pool['pool_created_at'] !== null ? Carbon::parse($pool['pool_created_at']) : null,
                    'liquidity_usd' => $pool['liquidity_usd'],
                ], 'geckoterminal_new') ? 1 : 0;
            }
        }

        return ['found' => $found, 'added' => $added, 'failed' => $failed];
    }

    /**
     * Record a pool once. Skipped when already known, too old, too thin, a base asset, or when the tracking list is full.
     *
     * @param  array{chain_id: string, pair_address: string, token_address: string, symbol: string, name?: string|null, quote_symbol?: string|null, dex_id?: string|null, url?: string|null, pool_created_at: Carbon|null, liquidity_usd: float|null}  $pool
     */
    public function register(array $pool, string $source): bool
    {
        $config = config('market.new_pairs');

        if (! $config['enabled']
            || $pool['pool_created_at'] === null
            || $pool['pool_created_at']->lt(now()->subHours($config['max_age_hours']))
            || ($pool['liquidity_usd'] ?? 0) < $config['min_liquidity_usd']
            || in_array(strtoupper($pool['symbol']), config('market.discovery.excluded_symbols'), true)) {
            return false;
        }

        if (NewPair::query()->where('chain_id', $pool['chain_id'])->where('pair_address', $pool['pair_address'])->exists()) {
            return false;
        }

        if (NewPair::query()->tracking()->count() >= $config['max_tracked']) {
            return false;
        }

        NewPair::query()->create([
            'chain_id' => $pool['chain_id'],
            'pair_address' => $pool['pair_address'],
            'token_address' => $pool['token_address'],
            'symbol' => mb_substr($pool['symbol'] ?: '?', 0, 64),
            'name' => isset($pool['name']) ? mb_substr($pool['name'], 0, 255) : null,
            'quote_symbol' => $pool['quote_symbol'] ?? null,
            'dex_id' => $pool['dex_id'] ?? null,
            'url' => $pool['url'] ?? null,
            'source' => $source,
            'pool_created_at' => $pool['pool_created_at'],
            'first_seen_at' => now(),
            'first_liquidity_usd' => $pool['liquidity_usd'],
            'liquidity_usd' => $pool['liquidity_usd'],
            'status' => NewPair::TRACKING,
        ]);

        return true;
    }
}
