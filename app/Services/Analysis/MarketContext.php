<?php

namespace App\Services\Analysis;

use App\Models\Candle;
use App\Models\Token;
use App\Services\MarketData\FearGreedClient;
use Illuminate\Support\Facades\Cache;

/**
 * The market around a signal, stored with each analysis: it cannot be reconstructed later, and
 * results per market condition are what market regime rules will be decided on.
 */
class MarketContext
{
    /** Main asset per chain that is followed as the market benchmark. */
    private const BENCHMARKS = ['SOL' => ['solana', 'SOL'], 'ETH' => ['ethereum', 'WETH']];

    public function __construct(private FearGreedClient $fearGreed, private TechnicalAnalyzer $technical) {}

    /**
     * @return array{captured_at: string, fear_greed: array{value: int, classification: string}|null, benchmarks: array<string, array{change_24h: float|null, trend_4h: string|null}>, breadth: array{tokens: int, up_24h_pct: float|null, avg_change_24h: float|null}}
     */
    public function snapshot(): array
    {
        // Analyses run for all tokens in one go; they share one snapshot.
        return Cache::remember('market:context', now()->addMinutes(5), fn (): array => [
            'captured_at' => now()->toIso8601String(),
            'fear_greed' => $this->fearGreedValue(),
            'benchmarks' => array_map(fn (array $pair): array => $this->benchmark(...$pair), self::BENCHMARKS),
            'breadth' => $this->breadth(),
        ]);
    }

    /**
     * @return array{value: int, classification: string}|null
     */
    private function fearGreedValue(): ?array
    {
        $latest = $this->fearGreed->latest();

        return $latest === null ? null : ['value' => $latest['value'], 'classification' => $latest['classification']];
    }

    /**
     * @return array{change_24h: float|null, trend_4h: string|null}
     */
    private function benchmark(string $chainId, string $symbol): array
    {
        $token = Token::query()->where('chain_id', $chainId)->where('symbol', $symbol)->orderByDesc('liquidity_usd')->first();

        if ($token === null) {
            return ['change_24h' => null, 'trend_4h' => null];
        }

        $candles = Candle::query()->where('token_id', $token->id)->where('timeframe', config('market.analysis.trend_timeframe'))
            ->orderByDesc('open_time')->limit(120)->get()->reverse()->values();

        return ['change_24h' => $token->price_change_h24, 'trend_4h' => $this->technical->higherTimeframeTrend($candles)];
    }

    /**
     * How many tracked tokens are up over 24 hours.
     *
     * @return array{tokens: int, up_24h_pct: float|null, avg_change_24h: float|null}
     */
    private function breadth(): array
    {
        $changes = Token::query()->active()->whereNotNull('price_change_h24')->pluck('price_change_h24')->map(fn (mixed $change): float => (float) $change);

        return [
            'tokens' => $changes->count(),
            'up_24h_pct' => $changes->isEmpty() ? null : round($changes->filter(fn (float $change): bool => $change > 0)->count() / $changes->count() * 100, 1),
            'avg_change_24h' => $changes->isEmpty() ? null : round((float) $changes->avg(), 2),
        ];
    }
}
