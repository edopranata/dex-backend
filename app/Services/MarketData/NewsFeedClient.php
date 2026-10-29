<?php

namespace App\Services\MarketData;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Throwable;

/**
 * Crypto news from free RSS feeds.
 */
class NewsFeedClient
{
    /**
     * @return list<array{source: string, title: string, url: string, summary: string|null, published_at: Carbon}>
     */
    public function fetch(string $source, string $feedUrl): array
    {
        try {
            $body = Http::withUserAgent('Mozilla/5.0 (compatible; DexSimulator/1.0)')
                ->timeout(15)
                ->get($feedUrl)
                ->throw()
                ->body();

            $xml = new SimpleXMLElement($body, LIBXML_NOCDATA | LIBXML_NONET);
        } catch (Throwable $e) {
            Log::warning("News feed [{$source}] failed: {$e->getMessage()}");

            return [];
        }

        $items = [];

        foreach ($xml->channel->item ?? [] as $item) {
            $url = trim((string) $item->link);
            $title = trim(html_entity_decode(strip_tags((string) $item->title)));

            if ($url === '' || $title === '') {
                continue;
            }

            $summary = trim(html_entity_decode(strip_tags((string) $item->description)));

            $items[] = [
                'source' => $source,
                'title' => mb_substr($title, 0, 500),
                'url' => mb_substr($url, 0, 1000),
                'summary' => $summary === '' ? null : mb_substr($summary, 0, 1000),
                'published_at' => rescue(fn (): Carbon => Carbon::parse((string) $item->pubDate), now(), report: false),
            ];
        }

        return $items;
    }
}
