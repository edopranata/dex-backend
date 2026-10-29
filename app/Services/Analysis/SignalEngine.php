<?php

namespace App\Services\Analysis;

use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Models\Analysis;
use App\Models\Candle;
use App\Models\Token;
use Illuminate\Support\Collection;

/**
 * Combines all analyzers into a scored recommendation, trade levels and a trackable signal.
 */
class SignalEngine
{
    public function __construct(
        private TechnicalAnalyzer $technical,
        private OnchainAnalyzer $onchain,
        private WhaleAnalyzer $whale,
        private SentimentAnalyzer $sentiment,
        private FundamentalAnalyzer $fundamental,
        private AnalysisSummarizer $summarizer,
    ) {}

    public function analyze(Token $token): ?Analysis
    {
        if ($token->price_usd === null) {
            return null;
        }

        $config = config('market.analysis');
        $price = $token->price_usd;

        $technical = $this->technical->analyze(
            $this->candles($token, $config['timeframe'], 250),
            $this->candles($token, $config['trend_timeframe'], 120),
            $price,
        );
        $onchain = $this->onchain->analyze($token);
        $whale = $this->whale->analyze($token);
        $sentiment = $this->sentiment->analyze($token);
        $fundamental = $this->fundamental->analyze($token);

        $scores = [
            'technical' => $technical['score'],
            'onchain' => $onchain['score'],
            'whale' => $whale['score'],
            'sentiment' => $sentiment['score'],
            'fundamental' => $fundamental['score'],
        ];

        $finalScore = (int) round(array_sum(array_map(
            fn (string $key): float => $scores[$key] * $config['weights'][$key],
            array_keys($scores),
        )));

        [$recommendation, $overrideReason] = $this->recommend($finalScore, $technical, $onchain, $config);

        $levels = $recommendation->isBullish() && $technical['atr']
            ? TradeLevels::calculate($price, $technical['atr'], $technical['swing_low'], $config['tp_r_multiples'], $config['sl_atr_min'], $config['sl_atr_max'])
            : [];

        $reasons = array_values(array_filter([
            $overrideReason,
            ...array_map(fn (string $r): string => "[Teknikal] {$r}", $technical['reasons']),
            ...array_map(fn (string $r): string => "[On-chain] {$r}", $onchain['reasons']),
            ...array_map(fn (string $r): string => "[Whale] {$r}", $whale['reasons']),
            ...array_map(fn (string $r): string => "[Sentimen] {$r}", $sentiment['reasons']),
            ...array_map(fn (string $r): string => "[Fundamental] {$r}", $fundamental['reasons']),
        ]));

        $previous = $token->analyses()->latest()->first();
        $summary = $this->summarizer->summarize($token, $recommendation, [
            'final_score' => $finalScore,
            'scores' => $scores,
            'levels' => $levels,
            'reasons' => $reasons,
            'news_headlines' => $token->newsItems()->latest('published_at')->limit(5)->pluck('title')->all(),
        ], $previous);

        $analysis = $token->analyses()->create([
            'timeframe' => $config['timeframe'],
            'recommendation' => $recommendation,
            'final_score' => $finalScore,
            'technical_score' => $scores['technical'],
            'onchain_score' => $scores['onchain'],
            'whale_score' => $scores['whale'],
            'sentiment_score' => $scores['sentiment'],
            'fundamental_score' => $scores['fundamental'],
            'price' => $price,
            ...$levels,
            'indicators' => [
                ...$technical['indicators'],
                'whale' => $whale['metrics'],
                'sentiment' => $sentiment['metrics'],
                'onchain' => $onchain['metrics'],
                'fundamental' => $fundamental['metrics'],
            ],
            'reasons' => $reasons,
            'summary' => $summary['summary'],
            'summary_provider' => $summary['provider'],
        ]);

        if ($levels !== [] && ! $token->signals()->open()->exists()) {
            $token->signals()->create([
                'analysis_id' => $analysis->id,
                'recommendation' => $recommendation,
                'status' => SignalStatus::Pending,
                ...$levels,
            ]);
        }

        return $analysis;
    }

    /**
     * @param  array{score: int, available: bool}  $technical
     * @param  array{score: int, is_honeypot: bool, rug_alert: bool}  $onchain
     * @param  array<string, mixed>  $config
     * @return array{0: Recommendation, 1: string|null}
     */
    private function recommend(int $finalScore, array $technical, array $onchain, array $config): array
    {
        if ($onchain['is_honeypot']) {
            return [Recommendation::Avoid, 'Token terdeteksi honeypot. Jangan dibeli.'];
        }

        if ($onchain['rug_alert']) {
            return [Recommendation::StrongSell, 'Likuiditas ditarik drastis. Keluar dari posisi.'];
        }

        $recommendation = Recommendation::StrongSell;

        foreach ($config['thresholds'] as $value => $minimum) {
            if ($finalScore >= $minimum) {
                $recommendation = Recommendation::from($value);

                break;
            }
        }

        if ($recommendation->isBullish() && $onchain['score'] < $config['min_onchain_score']) {
            return [Recommendation::Avoid, 'Skor teknikal bagus tetapi keamanan kontrak terlalu berisiko.'];
        }

        if ($recommendation->isBullish() && ! $technical['available']) {
            return [Recommendation::Hold, 'Data candle belum cukup untuk menentukan entry.'];
        }

        if ($recommendation->isBullish() && $technical['score'] < 50) {
            return [Recommendation::Hold, 'Skor total positif tetapi teknikal belum mengonfirmasi entry.'];
        }

        return [$recommendation, null];
    }

    /**
     * @return Collection<int, Candle>
     */
    private function candles(Token $token, string $timeframe, int $limit): Collection
    {
        return $token->candles()
            ->where('timeframe', $timeframe)
            ->orderByDesc('open_time')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}
