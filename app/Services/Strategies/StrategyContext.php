<?php

namespace App\Services\Strategies;

use App\Models\Token;
use App\Services\MarketData\BenchmarkTokens;

/**
 * What one runner pass knows: the time, the hour whose candle is being decided on, candles per token (kept until the
 * runner moves to the next token) and the BTC trend from the cbBTC benchmark pool.
 */
class StrategyContext
{
    /** @var array<string, Bars> */
    private array $cache = [];

    /** @var array<string, bool|null> */
    private array $daily = [];

    private ?bool $btc4h = null;

    /** @var array<string, Token|null> */
    private array $benchmarks = [];

    /** The Bybit symbol whose closes stand for each benchmark in the filters. */
    private const SIGNALS = ['BTC' => 'BTCUSDT', 'ETH' => 'ETHUSDT'];

    /**
     * @param  int  $hourEnd  End of the last closed hour (s); decisions are taken on the candles closed by then.
     */
    public function __construct(public int $now, public int $hourEnd, private BenchmarkTokens $benchmarkTokens, private ?BybitCloses $bybit = null) {}

    public function bars(int $tokenId, string $timeframe, int $count): Bars
    {
        return $this->cache["{$tokenId}:{$timeframe}:{$count}"] ??= Bars::load($tokenId, $timeframe, $count, $this->hourEnd);
    }

    /**
     * 4h candles as stored (built from 15m).
     */
    public function bars4h(int $tokenId, int $count): Bars
    {
        return $this->bars($tokenId, '4h', $count);
    }

    /**
     * Drops a token's candles once the runner is done with it, so a pass over many tokens stays small.
     */
    public function forget(int $tokenId): void
    {
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, "{$tokenId}:")) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Whether the hour that just closed also ended a 4h candle.
     */
    public function fourHourClosed(): bool
    {
        return $this->hourEnd % 14400 === 0;
    }

    /**
     * Whether the hour that just closed also ended the UTC day.
     */
    public function dayClosed(): bool
    {
        return $this->hourEnd % 86400 === 0;
    }

    public function benchmark(string $name): ?Token
    {
        if (! array_key_exists($name, $this->benchmarks)) {
            $this->benchmarks[$name] = $this->benchmarkTokens->find($name);
        }

        return $this->benchmarks[$name];
    }

    /**
     * Whether BTC's or ETH's last daily close (Bybit spot, the day before the hour being decided on) is above the SMA50
     * of the 50 daily closes up to it, as FutureSim decides; null while they cannot be read.
     */
    public function aboveDailySma50(string $name): ?bool
    {
        if (! array_key_exists($name, $this->daily)) {
            $closes = $this->bybit()->closes(self::SIGNALS[$name], '1d', 60, intdiv($this->hourEnd, 86400) * 86400);
            $closes = $closes === null ? [] : array_slice($closes, -50);
            $this->daily[$name] = count($closes) === 50 ? $closes[49] > array_sum($closes) / 50 : null;
        }

        return $this->daily[$name];
    }

    public function btcAboveDailySma50(): bool
    {
        return $this->aboveDailySma50('BTC') === true;
    }

    /**
     * Whether BTC's last closed 4h candle (Bybit spot) closed above its 4h EMA200.
     */
    public function btcAbove4hEma200(): bool
    {
        if ($this->btc4h === null) {
            $closes = $this->bybit()->closes('BTCUSDT', '4h', 1000, intdiv($this->hourEnd, 14400) * 14400) ?? [];
            $ema = Indicators::ema($closes, 200);
            $last = count($closes) - 1;
            $this->btc4h = $last >= 0 && ($ema[$last] ?? null) !== null && $closes[$last] > $ema[$last];
        }

        return $this->btc4h;
    }

    private function bybit(): BybitCloses
    {
        return $this->bybit ??= app(BybitCloses::class);
    }
}
