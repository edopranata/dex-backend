<?php

namespace App\Services\Ai;

use App\Models\NewsItem;
use Illuminate\Support\Collection;

/**
 * Scores news headlines 1-100 (1 = very bearish, 50 = neutral, 100 = very bullish).
 * Uses the LLM when configured, otherwise a small keyword lexicon.
 */
class NewsSentimentService
{
    /**
     * Whole-word matches; a trailing "*" matches any word starting with the stem.
     *
     * @var array<string, int>
     */
    private const LEXICON = [
        'surge' => 2, 'soar' => 2, 'rally' => 2, 'record high' => 2, 'all-time high' => 2, 'ath' => 2,
        'bullish' => 2, 'approval' => 2, 'approved' => 2, 'etf inflow' => 2, 'partnership' => 1,
        'adoption' => 1, 'launch' => 1, 'gain' => 1, 'gains' => 1, 'rise' => 1, 'rises' => 1, 'jump' => 1,
        'jumps' => 1, 'upgrade' => 1, 'integrat*' => 1, 'buyback' => 1, 'accumulat*' => 1, 'inflow' => 1,
        'crash' => -2, 'plunge' => -2, 'hack' => -2, 'hacked' => -2, 'exploit' => -2, 'rug' => -2,
        'scam' => -2, 'fraud' => -2, 'lawsuit' => -2, 'sues' => -2, 'bankrupt' => -2, 'bearish' => -2,
        'liquidat*' => -1, 'drop' => -1, 'drops' => -1, 'fall' => -1, 'falls' => -1, 'slump' => -1,
        'outflow' => -1, 'ban' => -1, 'crackdown' => -1, 'delist' => -2, 'sell-off' => -2, 'selloff' => -2,
        'warning' => -1, 'fear' => -1, 'decline' => -1, 'probe' => -1, 'sec charges' => -2,
    ];

    public function __construct(private AiClient $ai) {}

    /**
     * Score and persist sentiment for the given news items.
     *
     * @param  Collection<int, NewsItem>  $items
     */
    public function analyze(Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $scores = $this->ai->isEnabled() ? $this->scoreWithAi($items) : [];
        $provider = $this->ai->lastProvider();

        foreach ($items as $item) {
            $score = $scores[$item->id] ?? null;
            $usedProvider = $score === null ? 'lexicon' : $provider;
            $score ??= $this->scoreWithLexicon($item->title.' '.$item->summary);

            $item->update([
                'sentiment_score' => $score,
                'sentiment_label' => self::label($score),
                'sentiment_provider' => $usedProvider,
                'analyzed_at' => now(),
            ]);
        }
    }

    public static function label(int $score): string
    {
        return match (true) {
            $score >= 60 => 'bullish',
            $score <= 40 => 'bearish',
            default => 'neutral',
        };
    }

    public function scoreWithLexicon(string $text): int
    {
        $text = mb_strtolower($text);
        $total = 0;

        foreach (self::LEXICON as $word => $weight) {
            $pattern = str_ends_with($word, '*')
                ? '/\b'.preg_quote(rtrim($word, '*'), '/').'/u'
                : '/\b'.preg_quote($word, '/').'\b/u';

            if (preg_match($pattern, $text) === 1) {
                $total += $weight;
            }
        }

        return (int) max(1, min(100, 50 + $total * 10));
    }

    /**
     * @param  Collection<int, NewsItem>  $items
     * @return array<int, int>
     */
    private function scoreWithAi(Collection $items): array
    {
        $headlines = $items->map(fn (NewsItem $item): array => [
            'id' => $item->id,
            'title' => $item->title,
            'summary' => mb_substr((string) $item->summary, 0, 280),
        ])->values()->all();

        $result = $this->ai->json(
            'You are a crypto market analyst. Score how each news item is likely to affect the price of the '
            .'crypto assets it mentions in the next 24-72 hours. 1 = very bearish, 50 = neutral or irrelevant, '
            .'100 = very bullish. Be conservative: most news is 40-60. Respond with JSON only: '
            .'{"items":[{"id":<id>,"score":<1-100>}]}',
            json_encode($headlines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $scores = [];

        foreach ($result['items'] ?? [] as $row) {
            if (isset($row['id'], $row['score']) && is_numeric($row['score'])) {
                $scores[(int) $row['id']] = (int) max(1, min(100, (int) $row['score']));
            }
        }

        return $scores;
    }
}
