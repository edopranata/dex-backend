<?php

namespace App\Services\Analysis;

use App\Models\NewsItem;
use App\Models\Token;
use App\Services\MarketData\FearGreedClient;
use Illuminate\Database\Eloquent\Collection;

/**
 * Combines recency-weighted news sentiment with the Fear & Greed Index.
 */
class SentimentAnalyzer
{
    public function __construct(private FearGreedClient $fearGreed) {}

    /**
     * @return array{score: int, reasons: list<string>, metrics: array<string, float|int|string|null>}
     */
    public function analyze(Token $token): array
    {
        $since = now()->subHours(config('market.news.lookback_hours'));
        $reasons = [];

        $tokenNews = $token->newsItems()
            ->whereNotNull('sentiment_score')
            ->where('published_at', '>=', $since)
            ->get();

        if ($tokenNews->isNotEmpty()) {
            $newsScore = $this->weightedAverage($tokenNews);
            $reasons[] = sprintf('%d berita terkait %s, sentimen rata-rata %.0f/100.', $tokenNews->count(), $token->symbol, $newsScore);
        } else {
            $marketNews = NewsItem::query()
                ->whereNotNull('sentiment_score')
                ->where('published_at', '>=', now()->subDay())
                ->get();

            $marketScore = $marketNews->isEmpty() ? 50.0 : $this->weightedAverage($marketNews);
            $newsScore = 50 + ($marketScore - 50) * 0.5;
            $reasons[] = $marketNews->isEmpty()
                ? 'Belum ada berita yang dianalisis.'
                : sprintf('Tidak ada berita khusus %s; sentimen pasar umum %.0f/100.', $token->symbol, $marketScore);
        }

        $fearGreed = $this->fearGreed->latest();
        $score = $newsScore;

        if ($fearGreed !== null) {
            $score = $newsScore * 0.8 + $fearGreed['value'] * 0.2;
            $reasons[] = "Fear & Greed Index: {$fearGreed['value']} ({$fearGreed['classification']}).";
        }

        return [
            'score' => (int) max(0, min(100, round($score))),
            'reasons' => $reasons,
            'metrics' => [
                'news_count' => $tokenNews->count(),
                'news_score' => round($newsScore, 1),
                'fear_greed' => $fearGreed['value'] ?? null,
                'fear_greed_label' => $fearGreed['classification'] ?? null,
            ],
        ];
    }

    /**
     * Newer headlines weigh more: weight halves roughly every 12 hours.
     *
     * @param  Collection<int, NewsItem>  $items
     */
    private function weightedAverage(Collection $items): float
    {
        $weighted = 0.0;
        $weights = 0.0;

        foreach ($items as $item) {
            $weight = 1 / (1 + $item->published_at->diffInHours(now(), true) / 12);
            $weighted += $item->sentiment_score * $weight;
            $weights += $weight;
        }

        return $weights > 0 ? $weighted / $weights : 50.0;
    }
}
