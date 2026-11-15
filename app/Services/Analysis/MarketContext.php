<?php

namespace App\Services\Analysis;

use App\Models\Candle;
use App\Models\Token;
use App\Services\MarketData\BenchmarkTokens;
use App\Services\MarketData\FearGreedClient;
use Illuminate\Support\Facades\Cache;

/**
 * The market around a signal, stored with each analysis: it cannot be reconstructed later, and
 * results per market condition are what market regime rules will be decided on.
 */
class MarketContext
{
    /** A benchmark whose newest candle is older than this is treated as unknown rather than reported stale. */
    private const STALE_SECONDS = 3 * 3600;

    public function __construct(private FearGreedClient $fearGreed, private TechnicalAnalyzer $technical, private BenchmarkTokens $benchmarks) {}

    /**
     * @return array{captured_at: string, fear_greed: array{value: int, classification: string}|null, benchmarks: array<string, array{price: float|null, change_24h: float|null, trend_4h: string|null, as_of: string|null}>, breadth: array{tokens: int, up_24h_pct: float|null, avg_change_24h: float|null}}
     */
    public function snapshot(): array
    {
        // Analyses run for all tokens in one go; they share one snapshot.
        return Cache::remember('market:context', now()->addMinutes(5), fn (): array => [
            'captured_at' => now()->toIso8601String(),
            'fear_greed' => $this->fearGreedValue(),
            'benchmarks' => collect($this->benchmarks->names())
                ->mapWithKeys(fn (string $name): array => [$name => $this->benchmark($this->benchmarks->find($name))])
                ->all(),
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
     * Price, 24 hour change and 4 hour trend from the benchmark's own candles. Benchmarks are outside the Market,
     * so their price columns are not refreshed; candles are, every market:candles round.
     *
     * @return array{price: float|null, change_24h: float|null, trend_4h: string|null, as_of: string|null}
     */
    private function benchmark(?Token $token): array
    {
        $unknown = ['price' => null, 'change_24h' => null, 'trend_4h' => null, 'as_of' => null];

        if ($token === null) {
            return $unknown;
        }

        $hourly = Candle::query()->where('token_id', $token->id)->where('timeframe', '1h')
            ->orderByDesc('open_time')->limit(25)->pluck('close', 'open_time');
        $latest = $hourly->keys()->first();

        if ($latest === null || now()->getTimestamp() - ($latest + 3600) > self::STALE_SECONDS) {
            return $unknown;
        }

        $price = (float) $hourly->first();
        $dayAgo = $hourly->get($latest - 86400);
        $trend = Candle::query()->where('token_id', $token->id)->where('timeframe', config('market.analysis.trend_timeframe'))
            ->orderByDesc('open_time')->limit(120)->get()->reverse()->values();

        return [
            'price' => $price,
            'change_24h' => $dayAgo > 0 ? round(($price / (float) $dayAgo - 1) * 100, 2) : null,
            'trend_4h' => $this->technical->higherTimeframeTrend($trend),
            'as_of' => now()->setTimestamp(min(now()->getTimestamp(), $latest + 3600))->toIso8601String(),
        ];
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
