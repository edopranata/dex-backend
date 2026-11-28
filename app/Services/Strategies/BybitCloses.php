<?php

namespace App\Services\Strategies;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Closed spot candles of a major coin from Bybit Indonesia's public API (the source FutureSim, SpotSim and
 * app:market-turn-check read BTC from), for the strategy accounts' BTC and ETH filters: the cbBTC pool has too short a
 * daily history for an SMA50 (38 days on 28 Nov 2026). One request per candle, cached; null when it cannot be read.
 */
class BybitCloses
{
    private const URL = 'https://api.bybit.id/v5/market/kline';

    /** Bybit interval per timeframe. */
    private const INTERVALS = ['4h' => '240', '1d' => 'D'];

    /**
     * Closes of the candles that closed by `until` (s), oldest first, or null.
     *
     * @return list<float>|null
     */
    public function closes(string $symbol, string $timeframe, int $limit, int $until): ?array
    {
        $seconds = Bars::SECONDS[$timeframe];
        $key = "strategy-accounts:bybit:{$symbol}:{$timeframe}:{$limit}:".intdiv($until, $seconds);

        $closes = Cache::get($key);
        if (is_array($closes)) {
            return $closes;
        }

        try {
            $list = Http::timeout(15)->get(self::URL, ['category' => 'spot', 'symbol' => $symbol, 'interval' => self::INTERVALS[$timeframe], 'limit' => $limit])
                ->throw()->json('result.list') ?? [];
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $closes = collect($list)->filter(fn (array $k): bool => intdiv((int) $k[0], 1000) + $seconds <= $until)
            ->sortBy(fn (array $k): int => (int) $k[0])->map(fn (array $k): float => (float) $k[4])->values()->all();
        Cache::put($key, $closes, now()->addSeconds($seconds * 2));

        return $closes;
    }
}
