<?php

namespace App\Services\MarketData;

use App\Models\Token;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps token prices fresh from DEX Screener, batching pairs per chain.
 */
class PriceService
{
    /**
     * Attributes that change with every price update.
     */
    private const LIVE_ATTRIBUTES = [
        'price_usd', 'price_change_m5', 'price_change_h1', 'price_change_h6', 'price_change_h24',
        'volume_h24', 'liquidity_usd', 'fdv', 'market_cap', 'txns_h24_buys', 'txns_h24_sells',
        'image_url', 'price_updated_at',
    ];

    public function __construct(private DexScreenerClient $dexScreener) {}

    /**
     * @param  Collection<int, Token>  $tokens
     * @return Collection<int, Token>
     */
    public function refresh(Collection $tokens): Collection
    {
        foreach ($tokens->groupBy('chain_id') as $chainId => $chainTokens) {
            try {
                $pairs = $this->dexScreener->pairs($chainId, $chainTokens->pluck('pair_address')->all());
            } catch (Throwable $e) {
                Log::warning("Price refresh for [{$chainId}] failed: {$e->getMessage()}");

                continue;
            }

            $byAddress = collect($pairs)->keyBy(fn (array $pair): string => strtolower($pair['pairAddress']));

            foreach ($chainTokens as $token) {
                $pair = $byAddress->get(strtolower($token->pair_address));

                if ($pair !== null && isset($pair['priceUsd'])) {
                    $attributes = DexScreenerClient::toTokenAttributes($pair);
                    $token->update(array_intersect_key($attributes, array_flip(self::LIVE_ATTRIBUTES)));
                }
            }
        }

        return $tokens;
    }

    /**
     * Make sure the token has a recent price before trading against it.
     */
    public function ensureFresh(Token $token): Token
    {
        if ($token->isPriceStale(config('market.simulator.max_price_age_seconds'))) {
            $this->refresh(new Collection([$token]));
        }

        return $token;
    }
}
