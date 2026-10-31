<?php

namespace App\Services\Analysis;

use App\Enums\CandidateStatus;
use App\Enums\OrderSide;
use App\Enums\WatchlistAction;
use App\Models\NewsItem;
use App\Models\Token;
use App\Models\TokenCandidate;
use App\Services\MarketData\CoinGeckoClient;
use App\Services\MarketData\DexScreenerClient;
use App\Services\MarketData\GeckoTerminalClient;
use App\Services\MarketData\TokenRegistry;
use App\Services\MarketData\TokenSecurityService;
use App\Services\MarketData\WhaleSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Finds tokens the user did not enter: trending pools, trending searches and boosted tokens,
 * filtered for liquidity and age, scored on trend, attention, whale flow and contract safety.
 * The best candidates are added to the watchlist so the regular analysis produces entry/SL/TP.
 */
class TokenDiscoveryService
{
    /** Upper bounds for values from market data; anything above is broken data (and overflows the columns). */
    private const PLAUSIBLE_LIMITS = [
        'price_usd' => 1e12,
        'price_change_h1' => 1e9,
        'price_change_h6' => 1e9,
        'price_change_h24' => 1e9,
        'volume_h24' => 1e15,
        'liquidity_usd' => 1e15,
        'fdv' => 1e18,
    ];

    /**
     * Sources driven by real demand. Paid boosts alone never qualify a token for auto-tracking.
     */
    public const ORGANIC_SOURCES = ['geckoterminal_trending', 'coingecko_trending'];

    /**
     * Points removed from candidates that only appear through paid boosts.
     */
    public const PAID_ONLY_PENALTY = 15;

    public function __construct(
        private GeckoTerminalClient $geckoTerminal,
        private DexScreenerClient $dexScreener,
        private CoinGeckoClient $coinGecko,
        private TokenSecurityService $security,
        private TokenRegistry $registry,
        private WatchlistManager $watchlist,
    ) {}

    /**
     * @return array{found: int, candidates: int, rejected: int, deep_checked: int, added: list<string>, replaced: list<string>}
     */
    public function run(): array
    {
        $config = config('market.discovery');
        $trendingSymbols = $config['use_coingecko_trending'] ? collect($this->coinGecko->trending())->keyBy('symbol') : collect();

        $found = $this->collect($config, $trendingSymbols);
        $newsTitles = NewsItem::query()->where('published_at', '>=', now()->subDay())->pluck('title');

        // One bad pool must not stop the whole round (and with it deep checks and auto-tracking).
        $candidates = $found->map(fn (array $item): ?TokenCandidate => rescue(fn () => $this->upsert($item, $trendingSymbols, $newsTitles, $config)))->filter();

        $deepChecked = $candidates
            ->filter(fn (TokenCandidate $c): bool => $c->status !== CandidateStatus::Rejected)
            ->sortByDesc(fn (TokenCandidate $c): float => $c->trend_score * 0.6 + $c->attention_score * 0.4)
            ->take($config['deep_check_limit'])
            ->each(fn (TokenCandidate $c) => $this->deepCheck($c, $config));

        return [
            'found' => $found->count(),
            'candidates' => $candidates->where('status', '!==', CandidateStatus::Rejected)->count(),
            'rejected' => $candidates->where('status', CandidateStatus::Rejected)->count(),
            'deep_checked' => $deepChecked->count(),
            ...($config['auto_track'] ? $this->autoTrack($config) : ['added' => [], 'replaced' => []]),
        ];
    }

    /**
     * Trend quality from price action, order flow and turnover (0-100).
     *
     * @param  array{h1: float|null, h6: float|null, h24: float|null, buys: int|null, sells: int|null, buyers: int|null, sellers: int|null, volume: float|null, liquidity: float|null}  $m
     * @return array{score: int, reasons: list<string>}
     */
    public static function trendScore(array $m): array
    {
        $score = 50;
        $reasons = [];
        $h24 = $m['h24'] ?? 0;

        [$points, $reason] = match (true) {
            $h24 > 150 => [-10, sprintf('Naik %.0f%% dalam 24 jam: parabolik, risiko beli di pucuk.', $h24)],
            $h24 > 60 => [5, sprintf('Naik %.0f%% dalam 24 jam (momentum kuat, mulai panas).', $h24)],
            $h24 >= 5 => [15, sprintf('Naik %.0f%% dalam 24 jam (tren naik sehat).', $h24)],
            $h24 >= 0 => [5, sprintf('Naik tipis %.1f%% dalam 24 jam.', $h24)],
            $h24 >= -20 => [-10, sprintf('Turun %.0f%% dalam 24 jam.', abs($h24))],
            default => [-20, sprintf('Turun tajam %.0f%% dalam 24 jam.', abs($h24))],
        };
        $score += $points;
        $reasons[] = $reason;

        $score += ($m['h6'] ?? 0) > 0 ? 5 : -5;
        $score += ($m['h1'] ?? 0) > 0 ? 5 : -5;

        $txns = ($m['buys'] ?? 0) + ($m['sells'] ?? 0);

        if ($txns > 0) {
            $buyRatio = $m['buys'] / $txns;
            $score += match (true) {
                $buyRatio > 0.55 => 10,
                $buyRatio < 0.45 => -10,
                default => 0,
            };
            $reasons[] = sprintf('%d%% transaksi 24 jam adalah beli.', round($buyRatio * 100));
        }

        if (($m['buyers'] ?? 0) > 0 && ($m['sellers'] ?? 0) > 0) {
            $ratio = $m['buyers'] / $m['sellers'];
            $score += $ratio > 1.2 ? 5 : ($ratio < 0.8 ? -5 : 0);
            $reasons[] = sprintf('%s pembeli unik vs %s penjual unik.', number_format($m['buyers']), number_format($m['sellers']));
        }

        if (($m['liquidity'] ?? 0) > 0) {
            $turnover = ($m['volume'] ?? 0) / $m['liquidity'];
            $score += match (true) {
                $turnover > 10 => -5,
                $turnover >= 0.5 => 5,
                default => 0,
            };
        }

        return ['score' => (int) max(0, min(100, $score)), 'reasons' => $reasons];
    }

    /**
     * Net whale pressure (0-100); no whale activity counts slightly below neutral.
     */
    public static function whaleScore(float $buyUsd, float $sellUsd, int $buyWallets): int
    {
        if ($buyUsd + $sellUsd <= 0) {
            return 40;
        }

        $score = 50 + 45 * ($buyUsd - $sellUsd) / ($buyUsd + $sellUsd);

        if ($buyWallets >= 3) {
            $score += 5;
        }

        return (int) max(0, min(100, round($score)));
    }

    /**
     * Gather raw candidates keyed by "chain:pair" with their DEX Screener pair payload.
     *
     * @param  array<string, mixed>  $config
     * @param  Collection<string, array<string, mixed>>  $trendingSymbols
     * @return Collection<string, array{pair: array<string, mixed>, sources: list<string>, buyers: int|null, sellers: int|null}>
     */
    private function collect(array $config, Collection $trendingSymbols): Collection
    {
        $found = collect();
        $add = function (array $pair, string $source, ?int $buyers = null, ?int $sellers = null) use ($found): void {
            $key = $pair['chainId'].':'.strtolower($pair['pairAddress']);
            $existing = $found->get($key);

            $found->put($key, [
                'pair' => $pair,
                'sources' => array_values(array_unique([...($existing['sources'] ?? []), $source])),
                'buyers' => $buyers ?? $existing['buyers'] ?? null,
                'sellers' => $sellers ?? $existing['sellers'] ?? null,
            ]);
        };

        foreach ($config['networks'] as $chainId) {
            $network = config("market.chains.{$chainId}.geckoterminal");

            try {
                $pools = collect($this->geckoTerminal->trendingPools($network, $config['trending_duration']));
                $pairs = collect($this->dexScreener->pairs($chainId, $pools->pluck('pool_address')->all()))
                    ->keyBy(fn (array $pair): string => strtolower($pair['pairAddress']));
            } catch (Throwable $e) {
                Log::warning("Discovery: trending pools [{$chainId}] failed: {$e->getMessage()}");

                continue;
            }

            foreach ($pools as $pool) {
                $pair = $pairs->get(strtolower($pool['pool_address']));

                if ($pair !== null && strcasecmp($pair['baseToken']['address'], $pool['token_address']) === 0) {
                    $add($pair, 'geckoterminal_trending', $pool['buyers_h24'], $pool['sellers_h24']);
                }
            }
        }

        if ($config['use_dexscreener_boosts']) {
            try {
                foreach (collect($this->dexScreener->topBoosts())->groupBy('chainId') as $chainId => $boosts) {
                    if (in_array($chainId, $config['networks'], true)) {
                        foreach ($this->bestPairs($chainId, $boosts->pluck('tokenAddress')->unique()->values()->all()) as $pair) {
                            $add($pair, 'dexscreener_boost');
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning("Discovery: DEX Screener boosts failed: {$e->getMessage()}");
            }
        }

        foreach ($trendingSymbols as $coin) {
            if (($coin['market_cap_rank'] ?? 999) <= 20 || in_array($coin['symbol'], $config['excluded_symbols'], true)) {
                continue;
            }

            try {
                $pair = collect($this->dexScreener->search($coin['symbol']))
                    ->filter(fn (array $p): bool => in_array($p['chainId'], $config['networks'], true)
                        && strtoupper($p['baseToken']['symbol']) === $coin['symbol']
                        && ($p['liquidity']['usd'] ?? 0) >= $config['min_liquidity_usd'])
                    ->sortByDesc(fn (array $p): float => $p['liquidity']['usd'] ?? 0)
                    ->first();
            } catch (Throwable) {
                continue;
            }

            if ($pair !== null) {
                $add($pair, 'coingecko_trending');
            }
        }

        return $found;
    }

    /**
     * The most liquid pair per token where the token is the base asset.
     *
     * @param  list<string>  $tokenAddresses
     * @return list<array<string, mixed>>
     */
    private function bestPairs(string $chainId, array $tokenAddresses): array
    {
        return collect($this->dexScreener->pairsForTokens($chainId, $tokenAddresses))
            ->filter(fn (array $pair): bool => in_array(strtolower($pair['baseToken']['address']), array_map('strtolower', $tokenAddresses), true))
            ->groupBy(fn (array $pair): string => strtolower($pair['baseToken']['address']))
            ->map(fn (Collection $pairs): array => $pairs->sortByDesc(fn (array $p): float => $p['liquidity']['usd'] ?? 0)->first())
            ->values()
            ->all();
    }

    /**
     * Some pools report broken data (a price of 2.7e26, a 24h change of 2e31 %). They are not real
     * markets, and such values do not fit the database either.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function hasPlausibleNumbers(array $attributes): bool
    {
        foreach (self::PLAUSIBLE_LIMITS as $key => $limit) {
            if (abs((float) ($attributes[$key] ?? 0)) >= $limit) {
                Log::notice("Discovery melewati {$attributes['symbol']}: {$key} tidak masuk akal.", ['pair' => $attributes['pair_address'] ?? null, 'value' => $attributes[$key]]);

                return false;
            }
        }

        return true;
    }

    /**
     * Apply filters and cheap scores, then store the candidate.
     *
     * @param  array{pair: array<string, mixed>, sources: list<string>, buyers: int|null, sellers: int|null}  $item
     * @param  Collection<string, array<string, mixed>>  $trendingSymbols
     * @param  Collection<int, string>  $newsTitles
     * @param  array<string, mixed>  $config
     */
    private function upsert(array $item, Collection $trendingSymbols, Collection $newsTitles, array $config): ?TokenCandidate
    {
        $attributes = DexScreenerClient::toTokenAttributes($item['pair']);
        $symbol = strtoupper($attributes['symbol']);

        if (in_array($symbol, $config['excluded_symbols'], true) || $attributes['price_usd'] === null || ! $this->hasPlausibleNumbers($attributes)) {
            return null;
        }

        $sources = $item['sources'];

        if ($trendingSymbols->has($symbol) && ! in_array('coingecko_trending', $sources, true)) {
            $sources[] = 'coingecko_trending';
        }

        $newsMentions = $this->newsMentions($symbol, $attributes['name'], $newsTitles);

        $trend = self::trendScore([
            'h1' => $attributes['price_change_h1'],
            'h6' => $attributes['price_change_h6'],
            'h24' => $attributes['price_change_h24'],
            'buys' => $attributes['txns_h24_buys'],
            'sells' => $attributes['txns_h24_sells'],
            'buyers' => $item['buyers'],
            'sellers' => $item['sellers'],
            'volume' => $attributes['volume_h24'],
            'liquidity' => $attributes['liquidity_usd'],
        ]);

        [$attention, $attentionReasons] = $this->attentionScore($sources, $newsMentions, $trendingSymbols->get($symbol));
        $rejection = $this->rejectionReason($attributes, $config);

        $candidate = TokenCandidate::firstOrNew(['chain_id' => $attributes['chain_id'], 'pair_address' => $attributes['pair_address']]);
        $trackedToken = Token::query()->active()->where('chain_id', $attributes['chain_id'])->where('pair_address', $attributes['pair_address'])->first();

        $candidate->fill([
            'token_id' => $trackedToken?->id ?? $candidate->token_id,
            'token_address' => $attributes['token_address'],
            'symbol' => $attributes['symbol'],
            'name' => $attributes['name'],
            'quote_symbol' => $attributes['quote_symbol'],
            'image_url' => $attributes['image_url'] ?? $candidate->image_url,
            'url' => $attributes['url'],
            'sources' => $sources,
            'price_usd' => $attributes['price_usd'],
            'price_change_h1' => $attributes['price_change_h1'],
            'price_change_h6' => $attributes['price_change_h6'],
            'price_change_h24' => $attributes['price_change_h24'],
            'volume_h24' => $attributes['volume_h24'],
            'liquidity_usd' => $attributes['liquidity_usd'],
            'fdv' => $attributes['fdv'],
            'buys_h24' => $attributes['txns_h24_buys'],
            'sells_h24' => $attributes['txns_h24_sells'],
            'buyers_h24' => $item['buyers'] ?? $candidate->buyers_h24,
            'sellers_h24' => $item['sellers'] ?? $candidate->sellers_h24,
            'news_mentions' => $newsMentions,
            'trend_score' => $trend['score'],
            'attention_score' => $attention,
            'pair_created_at' => $attributes['pair_created_at'],
            'first_seen_at' => $candidate->first_seen_at ?? now(),
            'last_seen_at' => now(),
            'status' => match (true) {
                $trackedToken !== null => CandidateStatus::Tracked,
                $rejection !== null => CandidateStatus::Rejected,
                $candidate->is_honeypot || ($candidate->security_score !== null && $candidate->security_score < $config['min_security_score']) => CandidateStatus::Rejected,
                default => CandidateStatus::Candidate,
            },
            'reasons' => array_values(array_filter([$rejection, ...$attentionReasons, ...$trend['reasons']])),
        ]);

        $this->score($candidate, $config);
        $candidate->save();

        return $candidate;
    }

    /**
     * Contract safety and whale flow: the expensive checks, cached between runs.
     *
     * @param  array<string, mixed>  $config
     */
    private function deepCheck(TokenCandidate $candidate, array $config): void
    {
        if ($candidate->security_checked_at === null || $candidate->security_checked_at->lt(now()->subHours($config['security_recheck_hours']))) {
            try {
                $assessment = $this->security->assess($candidate->chain_id, $candidate->token_address);
            } catch (Throwable $e) {
                Log::warning("Discovery: security check for {$candidate->symbol} failed: {$e->getMessage()}");
                $assessment = null;
            }

            if ($assessment !== null) {
                $candidate->fill([
                    'security_score' => $assessment['score'],
                    'is_honeypot' => $assessment['is_honeypot'],
                    'security_flags' => $assessment['flags'],
                    'security_checked_at' => now(),
                ]);
            }
        }

        if ($candidate->whale_checked_at === null || $candidate->whale_checked_at->lt(now()->subMinutes($config['whale_recheck_minutes']))) {
            try {
                $this->checkWhales($candidate);
            } catch (Throwable $e) {
                Log::warning("Discovery: whale check for {$candidate->symbol} failed: {$e->getMessage()}");
            }
        }

        if ($candidate->status === CandidateStatus::Candidate
            && ($candidate->is_honeypot || ($candidate->security_score !== null && $candidate->security_score < $config['min_security_score']))) {
            $candidate->status = CandidateStatus::Rejected;
            $candidate->reasons = [
                $candidate->is_honeypot ? 'Ditolak: terdeteksi honeypot.' : "Ditolak: skor keamanan kontrak {$candidate->security_score}/100 terlalu rendah.",
                ...$candidate->reasons,
            ];
        }

        $this->score($candidate, $config);
        $candidate->save();
    }

    private function checkWhales(TokenCandidate $candidate): void
    {
        $network = config("market.chains.{$candidate->chain_id}.geckoterminal");
        $threshold = WhaleSyncService::thresholdForLiquidity((float) $candidate->liquidity_usd);
        $buyUsd = 0.0;
        $sellUsd = 0.0;
        $trades = 0;
        $buyWallets = [];

        foreach ($this->geckoTerminal->trades($network, $candidate->pair_address, $threshold) as $trade) {
            $attributes = $trade['attributes'] ?? [];
            $side = WhaleSyncService::side($candidate->token_address, $attributes);

            if ($side === null || ($attributes['is_anomalous'] ?? false)) {
                continue;
            }

            $trades++;

            if ($side === OrderSide::Buy) {
                $buyUsd += (float) $attributes['volume_in_usd'];
                $buyWallets[$attributes['tx_from_address'] ?? ''] = true;
            } else {
                $sellUsd += (float) $attributes['volume_in_usd'];
            }
        }

        $candidate->fill([
            'whale_buy_usd' => $buyUsd,
            'whale_sell_usd' => $sellUsd,
            'whale_trades' => $trades,
            'whale_buy_wallets' => count(array_filter(array_keys($buyWallets))),
            'whale_checked_at' => now(),
        ]);
    }

    /**
     * Fill whale_score and discovery_score once both deep checks are available.
     *
     * @param  array<string, mixed>  $config
     */
    private function score(TokenCandidate $candidate, array $config): void
    {
        if ($candidate->whale_checked_at === null) {
            return;
        }

        $candidate->whale_score = self::whaleScore((float) $candidate->whale_buy_usd, (float) $candidate->whale_sell_usd, (int) $candidate->whale_buy_wallets);

        if ($candidate->security_score === null) {
            return;
        }

        $weights = $config['weights'];
        $score = $candidate->trend_score * $weights['trend']
            + $candidate->attention_score * $weights['attention']
            + $candidate->whale_score * $weights['whale']
            + $candidate->security_score * $weights['security'];

        if (array_intersect($candidate->sources, self::ORGANIC_SOURCES) === []) {
            $score -= self::PAID_ONLY_PENALTY;
        }

        $candidate->discovery_score = (int) max(0, round($score));
    }

    /**
     * @param  list<string>  $sources
     * @param  array<string, mixed>|null  $coinGeckoCoin
     * @return array{0: int, 1: list<string>}
     */
    private function attentionScore(array $sources, int $newsMentions, ?array $coinGeckoCoin): array
    {
        $score = 0;
        $reasons = [];

        if (in_array('geckoterminal_trending', $sources, true)) {
            $score += 35;
            $reasons[] = 'Masuk trending pool GeckoTerminal.';
        }

        if (in_array('coingecko_trending', $sources, true)) {
            $score += 35;
            $reasons[] = 'Trending di pencarian CoinGecko'.($coinGeckoCoin ? " (peringkat #{$coinGeckoCoin['rank']})." : '.');
        }

        if ($newsMentions > 0) {
            $score += min(20, $newsMentions * 7);
            $reasons[] = "Disebut di {$newsMentions} berita dalam 24 jam.";
        }

        if (in_array('dexscreener_boost', $sources, true)) {
            $score += 10;
            $reasons[] = array_intersect($sources, self::ORGANIC_SOURCES) === []
                ? 'Hanya muncul dari boost berbayar DEX Screener, bukan trending organik (skor −'.self::PAID_ONLY_PENALTY.').'
                : 'Juga di-boost di DEX Screener (promosi berbayar).';
        }

        return [min(100, $score), $reasons];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $config
     */
    private function rejectionReason(array $attributes, array $config): ?string
    {
        $ageHours = $attributes['pair_created_at']?->diffInHours(now(), true);

        return match (true) {
            ($attributes['liquidity_usd'] ?? 0) < $config['min_liquidity_usd'] => 'Ditolak: likuiditas $'.number_format((float) $attributes['liquidity_usd']).' di bawah minimum.',
            ($attributes['volume_h24'] ?? 0) < $config['min_volume_h24_usd'] => 'Ditolak: volume 24 jam $'.number_format((float) $attributes['volume_h24']).' terlalu kecil.',
            $ageHours !== null && $ageHours < $config['min_age_hours'] => sprintf('Ditolak: pair baru berumur %.0f jam (rawan rug pull).', $ageHours),
            default => null,
        };
    }

    /**
     * @param  Collection<int, string>  $titles
     */
    private function newsMentions(string $symbol, string $name, Collection $titles): int
    {
        $patterns = [];

        if (strlen($symbol) >= 3) {
            $patterns[] = '/(?<![A-Za-z0-9])\$?'.preg_quote($symbol, '/').'(?![A-Za-z0-9])/';
        }

        if (mb_strlen($name) >= 5 && ! str_contains($name, ' ')) {
            $patterns[] = '/\b'.preg_quote($name, '/').'\b/i';
        }

        return $titles->filter(fn (string $title): bool => collect($patterns)->contains(fn (string $p): bool => preg_match($p, $title) === 1))->count();
    }

    /**
     * Add the strongest candidates to the Market list: fill free slots first, then replace
     * the weakest unpinned token when the list is full.
     *
     * @param  array<string, mixed>  $config
     * @return array{added: list<string>, replaced: list<string>}
     */
    private function autoTrack(array $config): array
    {
        $added = [];
        $replaced = [];

        $eligible = TokenCandidate::query()
            ->where('status', CandidateStatus::Candidate)
            ->where('last_seen_at', '>=', now()->subHour())
            ->where('discovery_score', '>=', $config['auto_track_min_score'])
            ->where('whale_score', '>', 50)
            ->where('security_score', '>=', 60)
            ->orderByDesc('discovery_score')
            ->get()
            ->filter(fn (TokenCandidate $c): bool => array_intersect($c->sources, self::ORGANIC_SOURCES) !== []
                && ($c->token_id === null || ! $this->watchlist->recentlyRemoved($c->token_id)));

        foreach ($eligible as $candidate) {
            if (count($added) >= $config['max_new_per_run']) {
                break;
            }

            if (! $this->watchlist->hasFreeSlot()) {
                $weakest = $this->watchlist->replaceable();

                if ($weakest === null) {
                    break;
                }

                $this->watchlist->remove($weakest, "Digantikan {$candidate->symbol} (skor discovery {$candidate->discovery_score}) karena Market penuh.");
                $replaced[] = $weakest->symbol;
            }

            try {
                $token = $this->registry->add($candidate->chain_id, $candidate->pair_address, 'discovery');
            } catch (ValidationException) {
                break;
            }

            if ($token->wasRecentlyCreated || $token->wasChanged('is_active')) {
                $this->registry->bootstrap($token);
            }

            $candidate->update(['token_id' => $token->id, 'status' => CandidateStatus::Tracked]);
            $this->watchlist->log($token, WatchlistAction::Added, $this->additionReason($candidate), $candidate->discovery_score);
            $added[] = $token->symbol;
        }

        return ['added' => $added, 'replaced' => $replaced];
    }

    private function additionReason(TokenCandidate $candidate): string
    {
        $whaleNet = (float) $candidate->whale_buy_usd - (float) $candidate->whale_sell_usd;

        return sprintf(
            'Ditemukan otomatis: skor discovery %d (tren %d, whale %d, keamanan %d), whale net %s $%s, %+.1f%% dalam 24 jam.',
            $candidate->discovery_score,
            $candidate->trend_score,
            $candidate->whale_score,
            $candidate->security_score,
            $whaleNet < 0 ? 'jual' : 'beli',
            number_format(abs($whaleNet)),
            $candidate->price_change_h24,
        );
    }
}
