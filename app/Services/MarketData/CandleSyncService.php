<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\Token;

class CandleSyncService
{
    private const QUARTER = 900;

    /** The most candles GeckoTerminal returns in one request. */
    public const MAX_CANDLES = 1000;

    public function __construct(private GeckoTerminalClient $geckoTerminal) {}

    /**
     * Keep a token's candles current with as few GeckoTerminal requests as possible: only 15m is fetched, and
     * 1h, 4h and 1d are built from it. A higher timeframe is fetched directly only when its last candle is
     * older than the 15m backfill can reach, as for a new token or one that was out of the Market for long.
     *
     * @param  int|null  $quarters  Fetch this many 15m candles instead of what the gap needs, up to 1,000:
     *                              an admin filling a long gap by hand.
     * @return array{requests: int, received: int, built: int}
     */
    public function refresh(Token $token, ?int $quarters = null): array
    {
        if ($this->network($token) === null) {
            return ['requests' => 0, 'received' => 0, 'built' => 0];
        }

        $config = config('market.candles');
        $now = now()->getTimestamp();
        $reach = (int) $config['backfill_days'] * 86400;
        $requests = 0;
        $received = 0;

        foreach ($config['built'] as $timeframe) {
            $latest = $this->latest($token, $timeframe);

            if ($latest === null || $now - $latest > $reach) {
                $received += $this->sync($token, $timeframe, (int) $config['history_limit']);
                $requests++;
            }
        }

        $latest = $this->latest($token, '15m');
        $behind = $latest === null ? PHP_INT_MAX : intdiv(max(0, $now - $latest), self::QUARTER) + 2;
        $limit = $quarters !== null
            ? max(1, min(self::MAX_CANDLES, $quarters))
            : min(intdiv($reach, self::QUARTER), max((int) $config['sync_limit'], $behind));
        $fetched = $this->sync($token, '15m', $limit);
        $requests++;
        $received += $fetched;

        $built = $fetched > 0 ? $this->build($token, $now - $limit * self::QUARTER) : 0;

        return ['requests' => $requests, 'received' => $received, 'built' => $built];
    }

    /**
     * Rebuild the higher timeframe candles from the stored 15m ones, from a moment until now. A period is only
     * built when the 15m data starts at or before it: an earlier, partly covered period keeps the candle
     * GeckoTerminal gave. A 15m period without trades is simply missing, which is what GeckoTerminal does too.
     *
     * @return int Candles written.
     */
    public function build(Token $token, int $from): int
    {
        $first = $token->candles()->where('timeframe', '15m')->min('open_time');

        if ($first === null) {
            return 0;
        }

        $written = 0;

        foreach (config('market.candles.built') as $timeframe) {
            $size = GeckoTerminalClient::TIMEFRAMES[$timeframe][2];
            // The first whole period the 15m data covers, and the period the moment falls in.
            $start = max((int) (ceil($first / $size) * $size), intdiv($from, $size) * $size);
            $quarters = $token->candles()->where('timeframe', '15m')->where('open_time', '>=', $start)->orderBy('open_time')
                ->get(['open_time', 'open', 'high', 'low', 'close', 'volume']);
            $rows = [];

            foreach ($quarters as $quarter) {
                $period = intdiv((int) $quarter->open_time, $size) * $size;

                if (! isset($rows[$period])) {
                    $rows[$period] = ['token_id' => $token->id, 'timeframe' => $timeframe, 'open_time' => $period, 'open' => $quarter->open, 'high' => $quarter->high, 'low' => $quarter->low, 'close' => $quarter->close, 'volume' => $quarter->volume];

                    continue;
                }

                $rows[$period]['high'] = max($rows[$period]['high'], $quarter->high);
                $rows[$period]['low'] = min($rows[$period]['low'], $quarter->low);
                $rows[$period]['close'] = $quarter->close;
                $rows[$period]['volume'] += $quarter->volume;
            }

            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                Candle::query()->upsert($chunk, ['token_id', 'timeframe', 'open_time'], ['open', 'high', 'low', 'close', 'volume']);
            }

            $written += count($rows);
        }

        return $written;
    }

    /**
     * Fetch and upsert candles for one timeframe. Returns the number of candles received.
     */
    public function sync(Token $token, string $timeframe, int $limit = 300): int
    {
        $network = $this->network($token);

        if ($network === null) {
            return 0;
        }

        $candles = $this->geckoTerminal->ohlcv($network, $token->pair_address, $token->token_address, $timeframe, $limit);

        if ($candles === []) {
            return 0;
        }

        $rows = array_map(fn (array $candle): array => [
            'token_id' => $token->id,
            'timeframe' => $timeframe,
            ...$candle,
        ], $candles);

        foreach (array_chunk($rows, 500) as $chunk) {
            $token->candles()->upsert($chunk, ['token_id', 'timeframe', 'open_time'], ['open', 'high', 'low', 'close', 'volume']);
        }

        return count($rows);
    }

    private function network(Token $token): ?string
    {
        return config("market.chains.{$token->chain_id}.geckoterminal");
    }

    private function latest(Token $token, string $timeframe): ?int
    {
        $latest = $token->candles()->where('timeframe', $timeframe)->max('open_time');

        return $latest === null ? null : (int) $latest;
    }
}
