<?php

namespace App\Services\MarketData;

use App\Models\Token;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks a DEX Screener price against the same pool on GeckoTerminal (where the charts come from)
 * before an order fills or a signal moves on it. Audit 10 Nov 2026: a limit buy on STANK filled 12%
 * under every candle, and FLOAT buys 8-10% above them; the two checks in a row could not catch this
 * because the feed repeated the same stray price.
 *
 * Only asked when a level is reached, so it costs a few GeckoTerminal requests per hour.
 */
class PriceCrossCheck
{
    public function __construct(private GeckoTerminalClient $geckoTerminal) {}

    /**
     * The price to act on now that the feed says a level was reached: the feed price when GeckoTerminal
     * agrees, GeckoTerminal's price when it does not, or null to wait for a later check.
     *
     * @param  bool  $waitWhenBusy  False for orders that must fill now (market orders): they take the feed
     *                              price unchecked instead of waiting.
     */
    public function priceToActOn(Token $token, bool $waitWhenBusy = true): ?CheckedPrice
    {
        $feed = $token->price_usd;
        $config = config('market.simulator.cross_check');

        if ($feed === null || ! $config['enabled']) {
            return $feed === null ? null : CheckedPrice::feed($feed);
        }

        $reference = $this->reference($token, (float) $config['max_wait_seconds'], (int) $config['cache_seconds']);

        if ($reference === false) {
            return $waitWhenBusy ? null : new CheckedPrice($feed, null, CheckedPrice::UNCHECKED);
        }

        if ($reference === null) {
            return new CheckedPrice($feed, null, CheckedPrice::UNCHECKED);
        }

        $gapPct = abs($feed / $reference - 1) * 100;

        return $gapPct <= (float) $config['tolerance_pct']
            ? new CheckedPrice($feed, $reference, CheckedPrice::FEED)
            : new CheckedPrice($reference, $reference, CheckedPrice::REFERENCE);
    }

    /**
     * GeckoTerminal's price for the token's pool: a float, null when it cannot be had (the feed is then
     * used), or false when the request slots are taken right now.
     */
    private function reference(Token $token, float $maxWaitSeconds, int $cacheSeconds): float|false|null
    {
        $cached = Cache::get($this->cacheKey($token));

        if (is_array($cached)) {
            return $cached['price'];
        }

        // Busy a moment ago: do not queue up again for every order on this token in the same run.
        if (Cache::has($this->busyKey($token))) {
            return false;
        }

        $network = config("market.chains.{$token->chain_id}.geckoterminal");

        if ($network === null) {
            return null;
        }

        try {
            $price = $this->geckoTerminal->poolTokenPrice($network, $token->pair_address, $token->token_address, $maxWaitSeconds);
        } catch (GeckoTerminalBusyException) {
            Cache::put($this->busyKey($token), true, 10);

            return false;
        } catch (Throwable $e) {
            Log::warning("Cek harga GeckoTerminal untuk {$token->symbol} gagal, memakai harga DEX Screener: {$e->getMessage()}");
            $price = null;
        }

        Cache::put($this->cacheKey($token), ['price' => $price], $cacheSeconds);

        return $price;
    }

    private function cacheKey(Token $token): string
    {
        return "price-cross-check:{$token->id}";
    }

    private function busyKey(Token $token): string
    {
        return "price-cross-check-busy:{$token->id}";
    }
}
