<?php

namespace App\Services\NewPairs;

use App\Models\NewPair;
use App\Models\NewPairBar;
use App\Models\NewPairTrade;
use App\Services\MarketData\DexScreenerClient;
use App\Services\MarketData\TokenSecurityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One Token Baru minute: register young pairs from discovery, sample every tracked pool on DEX Screener (one request
 * per 30 pools), extend its 5-minute bar, follow and open paper trades, check one pool's security on GoPlus, and stop
 * tracking pools past 24 hours. Recording only.
 */
class NewPairTracker
{
    public function __construct(
        private NewPairRegistry $registry,
        private DexScreenerClient $dexScreener,
        private TokenSecurityService $security,
        private NewPairStrategies $strategies,
        private PaperTradeBook $book,
    ) {}

    /**
     * @return array{registered: int, sampled: int, opened: int, closed: int, ended: int, security: string|null}
     */
    public function run(): array
    {
        $result = ['registered' => 0, 'sampled' => 0, 'opened' => 0, 'closed' => 0, 'ended' => 0, 'security' => null];

        if (! config('market.new_pairs.enabled')) {
            return $result;
        }

        $result['registered'] = $this->registry->fromDiscovery();
        $result['security'] = $this->checkOneSecurity();

        $pairs = NewPair::query()->tracking()->with(['trades' => fn ($q) => $q->where('status', NewPairTrade::OPEN)])->get();

        foreach ($pairs->groupBy('chain_id') as $chainId => $group) {
            try {
                $payloads = collect($this->dexScreener->pairs($chainId, $group->pluck('pair_address')->all()))
                    ->keyBy(fn (array $p): string => strtolower($p['pairAddress'] ?? ''));
            } catch (Throwable $e) {
                Log::warning("Token Baru: DEX Screener {$chainId} gagal: ".mb_substr($e->getMessage(), 0, 200));

                continue;
            }

            foreach ($group as $pair) {
                $payload = $payloads->get(strtolower($pair->pair_address));

                if ($payload === null || ! isset($payload['priceUsd']) || (float) $payload['priceUsd'] <= 0) {
                    continue;
                }

                // One odd payload must not stop the other pools' minute.
                try {
                    [$opened, $closed] = $this->sample($pair, $payload);
                    $result['sampled']++;
                    $result['opened'] += $opened;
                    $result['closed'] += $closed;
                } catch (Throwable $e) {
                    Log::warning("Token Baru: sampel {$pair->symbol} gagal: ".mb_substr($e->getMessage(), 0, 200));
                }
            }
        }

        foreach ($pairs as $pair) {
            $result['ended'] += $this->endIfFinished($pair) ? 1 : 0;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: int} Trades opened and closed.
     */
    private function sample(NewPair $pair, array $payload): array
    {
        $price = (float) $payload['priceUsd'];

        $pair->fill([
            'price_usd' => $price,
            'first_price_usd' => $pair->first_price_usd ?? $price,
            'high_price_usd' => max($pair->high_price_usd ?? $price, $price),
            'low_price_usd' => min($pair->low_price_usd ?? $price, $price),
            'liquidity_usd' => $payload['liquidity']['usd'] ?? $pair->liquidity_usd,
            'fdv' => self::bounded($payload['fdv'] ?? null, 1e20),
            'volume_m5' => $payload['volume']['m5'] ?? null,
            'volume_h1' => $payload['volume']['h1'] ?? null,
            'volume_h24' => $payload['volume']['h24'] ?? null,
            'buys_m5' => $payload['txns']['m5']['buys'] ?? null,
            'sells_m5' => $payload['txns']['m5']['sells'] ?? null,
            'price_change_m5' => self::bounded($payload['priceChange']['m5'] ?? null),
            'price_change_h1' => self::bounded($payload['priceChange']['h1'] ?? null),
            'quote_symbol' => $pair->quote_symbol ?? ($payload['quoteToken']['symbol'] ?? null),
            'dex_id' => $pair->dex_id ?? ($payload['dexId'] ?? null),
            'url' => $pair->url ?? ($payload['url'] ?? null),
            'last_sample_at' => now(),
        ]);

        $pair->save();

        $barStarted = $this->extendBar($pair, $price);

        $closed = 0;

        foreach ($pair->trades as $trade) {
            $this->book->follow($trade, $pair);
            $closed += $trade->status === NewPairTrade::CLOSED ? 1 : 0;
        }

        return [$this->openTrades($pair, $barStarted), $closed];
    }

    /**
     * Add the sample to the pool's current 5-minute bar. True when this sample started a new bar, so the bar before
     * it has just closed.
     */
    private function extendBar(NewPair $pair, float $price): bool
    {
        $seconds = (int) config('market.new_pairs.bar_seconds');
        $openTime = intdiv(now()->getTimestamp(), $seconds) * $seconds;
        $bar = NewPairBar::query()->firstOrNew(['new_pair_id' => $pair->id, 'open_time' => $openTime]);
        $started = ! $bar->exists;

        $bar->fill([
            'open' => $started ? $price : $bar->open,
            'high' => $started ? $price : max($bar->high, $price),
            'low' => $started ? $price : min($bar->low, $price),
            'close' => $price,
            'liquidity_usd' => $pair->liquidity_usd,
            'volume_m5' => $pair->volume_m5,
            'buys_m5' => $pair->buys_m5,
            'sells_m5' => $pair->sells_m5,
            'samples' => $started ? 1 : min(255, $bar->samples + 1),
        ])->save();

        return $started;
    }

    private function openTrades(NewPair $pair, bool $barClosed): int
    {
        $config = config('market.new_pairs');
        $age = $pair->ageMinutes();

        if ($age < $config['min_entry_age_minutes'] || $age > $config['max_age_hours'] * 60) {
            return 0;
        }

        $opened = $this->book->open($pair, 'first_seen') !== null ? 1 : 0;

        // The document's rule: unknown or pending security never buys. A pool GoPlus has no data on, or that failed
        // every attempt, stays unknown and is left out; the comparison above still takes it.
        if (! $pair->securitySettled() || $pair->security_score === null || $pair->is_honeypot === true
            || ($pair->sell_tax !== null && $pair->sell_tax > $config['max_sell_tax_pct'])) {
            return $opened;
        }

        $taken = $pair->trades()->pluck('strategy')->all();

        if (! in_array('early_liquidity', $taken, true) && ($reasons = $this->strategies->earlyLiquidity($pair)) !== null) {
            $opened += $this->book->open($pair, 'early_liquidity', $reasons) !== null ? 1 : 0;
        }

        if (! $barClosed || (in_array('breakout', $taken, true) && in_array('pullback', $taken, true))) {
            return $opened;
        }

        $closedBars = $this->closedBars($pair);

        foreach (['breakout', 'pullback'] as $strategy) {
            if (! in_array($strategy, $taken, true) && ($reasons = $this->strategies->{$strategy}($closedBars)) !== null) {
                $opened += $this->book->open($pair, $strategy, $reasons) !== null ? 1 : 0;
            }
        }

        return $opened;
    }

    /**
     * @return Collection<int, NewPairBar>
     */
    private function closedBars(NewPair $pair): Collection
    {
        $seconds = (int) config('market.new_pairs.bar_seconds');
        $current = intdiv(now()->getTimestamp(), $seconds) * $seconds;

        return $pair->bars()->where('open_time', '<', $current)->orderBy('open_time')->get();
    }

    /**
     * Stop tracking a pool past 24 hours once its paper trades are closed, or a pool DEX Screener has stopped showing
     * (its open trades close at the last sampled price).
     */
    private function endIfFinished(NewPair $pair): bool
    {
        $config = config('market.new_pairs');
        $lastData = $pair->last_sample_at ?? $pair->first_seen_at;

        if ($lastData->lt(now()->subMinutes($config['no_data_minutes']))) {
            $pair->trades()->where('status', NewPairTrade::OPEN)->get()
                ->each(fn (NewPairTrade $trade) => $this->book->close($trade, (float) ($pair->price_usd ?? $trade->entry_price), 'tanpa_data'));

            return $this->end($pair, 'tanpa_data');
        }

        $hasOpenTrade = $pair->trades()->where('status', NewPairTrade::OPEN)->exists();

        if ($pair->ageMinutes() > $config['max_age_hours'] * 60 && ! $hasOpenTrade) {
            return $this->end($pair, 'umur');
        }

        // A pool whose liquidity was pulled (many new pools within minutes) frees its place on the list.
        if (! $hasOpenTrade && $pair->first_liquidity_usd > 0 && $pair->liquidity_usd !== null
            && $pair->liquidity_usd < $pair->first_liquidity_usd * $config['liquidity_exit_ratio']) {
            return $this->end($pair, 'likuiditas');
        }

        return false;
    }

    private function end(NewPair $pair, string $reason): bool
    {
        $pair->fill(['status' => NewPair::DONE, 'end_reason' => $reason, 'ended_at' => now()])->save();

        return true;
    }

    /**
     * GoPlus for the oldest pool still waiting, one per minute so it stays far below GoPlus's limit together with
     * the Market checks. A refusal or timeout is tried again in a later minute, up to the configured attempts.
     */
    private function checkOneSecurity(): ?string
    {
        $pair = NewPair::query()->tracking()->whereNull('security_checked_at')->orderBy('first_seen_at')->first();

        if ($pair === null) {
            return null;
        }

        try {
            $assessment = $this->security->assess($pair->chain_id, $pair->token_address, $pair->pair_address);
        } catch (Throwable $e) {
            $attempts = $pair->security_attempts + 1;
            $pair->fill([
                'security_attempts' => $attempts,
                'security_checked_at' => $attempts >= config('market.new_pairs.security_attempts') ? now() : null,
            ])->save();

            return "{$pair->symbol}: gagal ({$attempts})";
        }

        $pair->fill([
            'security_checked_at' => now(),
            'security_attempts' => $pair->security_attempts + 1,
            'security_score' => $assessment['score'] ?? null,
            'is_honeypot' => $assessment['is_honeypot'] ?? null,
            'buy_tax' => $assessment['buy_tax'] ?? null,
            'sell_tax' => $assessment['sell_tax'] ?? null,
            'top10_percent' => $assessment['top10_percent'] ?? null,
            'security_flags' => $assessment['flags'] ?? null,
        ])->save();

        return $pair->symbol.': '.($assessment === null ? 'tanpa data' : "skor {$assessment['score']}");
    }

    /**
     * DEX Screener reports changes such as +24,455,904,208,035% on a pool's first trades; kept within the column.
     */
    private static function bounded(mixed $value, float $limit = 1e9): ?float
    {
        return is_numeric($value) ? max(-$limit, min($limit, (float) $value)) : null;
    }
}
