<?php

namespace App\Services\MarketData;

use App\Models\NewsItem;
use App\Models\Token;
use Illuminate\Database\Eloquent\Collection;

/**
 * Imports RSS news and links each item to the tracked tokens it mentions.
 */
class NewsSyncService
{
    /**
     * Extra names to look for in headlines, keyed by symbol.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'BTC' => ['Bitcoin'],
        'ETH' => ['Ethereum', 'Ether'],
        'SOL' => ['Solana'],
        'BNB' => ['Binance Coin', 'BNB Chain'],
        'ARB' => ['Arbitrum'],
        'POL' => ['Polygon'],
        'AVAX' => ['Avalanche'],
        'OP' => ['Optimism'],
    ];

    /**
     * Symbols that are too generic to match on their own.
     */
    private const GENERIC_SYMBOLS = ['THE', 'AI', 'FOR', 'ARE', 'ALL', 'NEW', 'ONE', 'USD', 'APP', 'CAT', 'DOG', 'MOON', 'PUMP'];

    public function __construct(private NewsFeedClient $feeds) {}

    /**
     * @return Collection<int, NewsItem> Newly inserted items.
     */
    public function sync(): Collection
    {
        $created = new Collection;

        foreach (config('market.news.feeds') as $source => $url) {
            foreach ($this->feeds->fetch($source, $url) as $item) {
                $news = NewsItem::firstOrCreate(['url_hash' => hash('sha256', $item['url'])], $item);

                if ($news->wasRecentlyCreated) {
                    $created->push($news);
                }
            }
        }

        $this->linkTokens($created);

        return $created;
    }

    /**
     * @param  Collection<int, NewsItem>  $items
     */
    public function linkTokens(Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $patterns = Token::query()->active()->get()
            ->mapWithKeys(fn (Token $token): array => [$token->id => $this->patterns($token)])
            ->filter();

        foreach ($items as $item) {
            $text = $item->title.' '.$item->summary;

            $tokenIds = $patterns
                ->filter(fn (array $tokenPatterns): bool => collect($tokenPatterns)->contains(fn (string $p): bool => preg_match($p, $text) === 1))
                ->keys();

            $item->tokens()->syncWithoutDetaching($tokenIds);
        }
    }

    /**
     * @return list<string>
     */
    private function patterns(Token $token): array
    {
        $symbol = strtoupper($token->symbol);
        $unwrapped = preg_match('/^W[A-Z]{3,}$/', $symbol) ? substr($symbol, 1) : $symbol;
        $patterns = [];

        foreach (array_unique([$symbol, $unwrapped]) as $candidate) {
            if (strlen($candidate) >= 3 && ! in_array($candidate, self::GENERIC_SYMBOLS, true)) {
                $patterns[] = '/(?<![A-Za-z0-9])\$?'.preg_quote($candidate, '/').'(?![A-Za-z0-9])/';
            }

            foreach (self::ALIASES[$candidate] ?? [] as $alias) {
                $patterns[] = '/\b'.preg_quote($alias, '/').'\b/i';
            }
        }

        $name = trim(preg_replace('/^Wrapped\s+/i', '', $token->name) ?? '');

        if (mb_strlen($name) >= 5 && ! str_contains($name, ' ')) {
            $patterns[] = '/\b'.preg_quote($name, '/').'\b/i';
        }

        return $patterns;
    }
}
