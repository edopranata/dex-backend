<?php

namespace App\Jobs;

use App\Models\NewsItem;
use App\Services\Ai\NewsSentimentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores every news item that has no sentiment yet, in batches.
 */
class AnalyzeNewsSentiment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function handle(NewsSentimentService $sentiment): void
    {
        NewsItem::query()
            ->whereNull('analyzed_at')
            ->where('published_at', '>=', now()->subHours(config('market.news.lookback_hours')))
            ->chunkById(config('market.news.sentiment_batch_size'), fn ($items) => $sentiment->analyze($items));
    }
}
