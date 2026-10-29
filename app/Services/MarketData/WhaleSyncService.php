<?php

namespace App\Services\MarketData;

use App\Enums\OrderSide;
use App\Models\Token;
use Illuminate\Support\Carbon;

class WhaleSyncService
{
    public function __construct(private GeckoTerminalClient $geckoTerminal) {}

    /**
     * Minimum trade size counted as a whale for this pool, scaled to its liquidity.
     */
    public static function threshold(Token $token): float
    {
        return self::thresholdForLiquidity((float) $token->liquidity_usd);
    }

    public static function thresholdForLiquidity(float $liquidityUsd): float
    {
        $config = config('market.whale');

        return max($config['floor_usd'], min($config['min_usd'], $liquidityUsd * $config['liquidity_ratio']));
    }

    /**
     * Store large trades from the last 24 hours. Returns the number of new whale trades.
     */
    public function sync(Token $token): int
    {
        $network = config("market.chains.{$token->chain_id}.geckoterminal");

        if ($network === null) {
            return 0;
        }

        $rows = [];

        foreach ($this->geckoTerminal->trades($network, $token->pair_address, self::threshold($token)) as $trade) {
            $attributes = $trade['attributes'] ?? [];
            $side = self::side($token->token_address, $attributes);

            if ($side === null || ($attributes['is_anomalous'] ?? false)) {
                continue;
            }

            $isBuy = $side === OrderSide::Buy;

            $rows[] = [
                'token_id' => $token->id,
                'external_id' => mb_substr((string) $trade['id'], 0, 255),
                'tx_hash' => $attributes['tx_hash'],
                'wallet' => $attributes['tx_from_address'] ?? null,
                'side' => $side->value,
                'amount_usd' => (float) $attributes['volume_in_usd'],
                'token_amount' => (float) ($isBuy ? $attributes['to_token_amount'] : $attributes['from_token_amount']),
                'price_usd' => (float) ($isBuy ? $attributes['price_to_in_usd'] : $attributes['price_from_in_usd']),
                'traded_at' => Carbon::parse($attributes['block_timestamp']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows === [] ? 0 : $token->whaleTrades()->insertOrIgnore($rows);
    }

    /**
     * Whether a GeckoTerminal trade bought or sold the given token.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function side(string $tokenAddress, array $attributes): ?OrderSide
    {
        return match (true) {
            strcasecmp($attributes['to_token_address'] ?? '', $tokenAddress) === 0 => OrderSide::Buy,
            strcasecmp($attributes['from_token_address'] ?? '', $tokenAddress) === 0 => OrderSide::Sell,
            default => null,
        };
    }
}
