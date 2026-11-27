<?php

namespace App\Services\Analysis;

use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Models\Analysis;
use App\Models\Candle;
use App\Models\ShadowSignal;
use App\Models\Signal;
use App\Models\Token;
use App\Observers\SignalObserver;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Trading\TradingService;
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
        private MarketContext $marketContext,
        private BtcRelation $btcRelation,
        private TelegramNotifier $notifier,
    ) {}

    public function analyze(Token $token): ?Analysis
    {
        // A job queued when the token joined can run after it left, and must not start a signal on it.
        if ($token->price_usd === null || ! $token->isInMarket()) {
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
        $heldByHolders = null;

        if ($recommendation->isBullish() && ($holders = $this->concentratedHolders($onchain, $config)) !== null) {
            [$heldByHolders, $recommendation, $overrideReason] = [$recommendation, Recommendation::Hold, $holders];
        }

        if ($recommendation === Recommendation::Buy && ($chase = $this->chasesTheMove($technical['indicators'], $config)) !== null) {
            [$recommendation, $overrideReason] = [Recommendation::Hold, $chase];
        }

        $levels = $recommendation->isBullish() && $technical['atr']
            ? TradeLevels::calculate($price, $technical['atr'], $technical['swing_low'], $config['tp_r_multiples'], $config['sl_atr_min'], $config['sl_atr_max'])
            : [];
        // The levels a held Strong Buy would have traded with, under the same minimum stop.
        $shadowLevels = $heldByHolders !== null && in_array($heldByHolders->value, $config['signal_recommendations'], true) && $technical['atr']
            ? TradeLevels::calculate($price, $technical['atr'], $technical['swing_low'], $config['tp_r_multiples'], $config['sl_atr_min'], $config['sl_atr_max'])
            : [];

        if ($shadowLevels !== [] && ($price - $shadowLevels['stop_loss']) / $price * 100 < $config['min_stop_pct']) {
            $shadowLevels = [];
        }

        if ($levels !== [] && ($price - $levels['stop_loss']) / $price * 100 < $config['min_stop_pct']) {
            $overrideReason = sprintf(
                'Skor mendukung beli, tetapi jarak stop hanya %.1f%% (minimum %.1f%%): biaya akan memakan hasilnya.',
                ($price - $levels['stop_loss']) / $price * 100,
                $config['min_stop_pct'],
            );
            $recommendation = Recommendation::Hold;
            $levels = [];
        }

        // v7: a Strong Buy scored this high stays a Strong Buy on the page but makes no signal; it is followed as a
        // shadow instead, so the rule keeps being measured.
        $tooHighScore = $levels !== [] && $this->scoreTooHigh($recommendation, $finalScore, $config);

        // v8: the same for a Strong Buy that follows an analysis which was not Buy or Strong Buy.
        $previous = $token->analyses()->latest()->first();
        $firstBuy = $levels !== [] && ! $tooHighScore && $this->unconfirmed($recommendation, $previous, $config);

        $reasons = array_values(array_filter([
            $overrideReason,
            $tooHighScore ? sprintf('Skor %d di atas batas sinyal %d: Strong Buy dengan skor setinggi ini rata-rata rugi di v3, v4, dan v6, jadi tidak dijadikan sinyal (v7).', $finalScore, (int) $config['strong_buy_max_score'] - 1) : null,
            $firstBuy ? 'Analisa sebelumnya bukan Buy atau Strong Buy: Strong Buy pertama tidak dijadikan sinyal, sinyal menunggu analisa berikutnya yang masih Strong Buy (v8).' : null,
            ...array_map(fn (string $r): string => "[Teknikal] {$r}", $technical['reasons']),
            ...array_map(fn (string $r): string => "[On-chain] {$r}", $onchain['reasons']),
            ...array_map(fn (string $r): string => "[Whale] {$r}", $whale['reasons']),
            ...array_map(fn (string $r): string => "[Sentimen] {$r}", $sentiment['reasons']),
            ...array_map(fn (string $r): string => "[Fundamental] {$r}", $fundamental['reasons']),
        ]));

        $summary = $this->summarizer->summarize($token, $recommendation, [
            'final_score' => $finalScore,
            'scores' => $scores,
            'levels' => $levels,
            'reasons' => $reasons,
            'news_headlines' => $token->newsItems()->latest('published_at')->limit(5)->pluck('title')->all(),
        ], $previous);

        $strategyVersion = StrategyVersion::current();

        $analysis = $token->analyses()->create([
            'timeframe' => $config['timeframe'],
            'strategy_version' => $strategyVersion,
            'market_context' => $this->marketContext->snapshot(),
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
                // Recorded only: no rule uses it yet (shadow A and B in the performance report).
                'btc' => $this->btcRelation->measure($token),
            ],
            'reasons' => $reasons,
            'summary' => $summary['summary'],
            'summary_provider' => $summary['provider'],
        ]);

        $this->noteDowngrade($token, $analysis);
        $this->closeHoldingsOnSell($token, $analysis);

        if ($onchain['rug_alert'] || $onchain['is_honeypot']) {
            $this->notifier->riskAlert($token, $onchain['reasons']);
        }

        if ($tooHighScore) {
            $this->recordShadow($token, $analysis, ShadowSignal::HIGH_SCORE, $recommendation, $levels, (float) $finalScore);
        }

        if ($firstBuy) {
            $this->recordShadow($token, $analysis, ShadowSignal::FIRST_BUY, $recommendation, $levels, (float) $finalScore);
        }

        // v7 beside v8: what v7 would have traded, confirmed or not (owner, 27 Nov 2026).
        if ($levels !== [] && ! $tooHighScore && in_array($recommendation->value, $config['signal_recommendations'], true)) {
            $this->recordShadow($token, $analysis, ShadowSignal::V7_RULE, $recommendation, $levels, (float) $finalScore);
        }

        if ($levels !== [] && ! $tooHighScore && ! $firstBuy && in_array($recommendation->value, config('market.analysis.signal_recommendations'), true)) {
            $signal = $token->withMembershipLock(function () use ($token, $analysis, $recommendation, $strategyVersion, $levels): ?Signal {
                if (! $token->isInMarket() || $token->signals()->open()->exists()) {
                    return null;
                }

                $signal = $token->signals()->make([
                    'analysis_id' => $analysis->id,
                    'recommendation' => $recommendation,
                    'status' => SignalStatus::Pending,
                    'strategy_version' => $strategyVersion,
                    ...$levels,
                ]);
                $signal->saveQuietly();

                return $signal;
            });

            // After the lock: following the signal places orders, which take the same lock.
            if ($signal !== null) {
                app(SignalObserver::class)->created($signal);
                $this->recordShadow($token, $analysis, ShadowSignal::ACCEPTED, $recommendation, $levels, $this->top10($onchain), $signal);
            }
        }

        if ($shadowLevels !== []) {
            $this->recordShadow($token, $analysis, ShadowSignal::HELD_BY_HOLDERS, $heldByHolders, $shadowLevels, $this->top10($onchain));
        }

        // Buy has not made a signal since v5. Each one is followed untraded with the Fear & Greed value, so whether to
        // trade Buy again when BTC rises can be judged on data (owner, 15 Nov 2026).
        if ($recommendation === Recommendation::Buy && $levels !== []) {
            $fearGreed = $analysis->market_context['fear_greed']['value'] ?? null;
            $this->recordShadow($token, $analysis, ShadowSignal::BUY_WATCH, $recommendation, $levels, $fearGreed === null ? null : (float) $fearGreed);
        }

        return $analysis;
    }

    /**
     * Shadow measures for a possible v5 exit rule, without changing any trade: the first analysis after entry
     * that is no longer bullish, and the second one in a row, each with the price and the targets already
     * reached at that moment.
     */
    /**
     * Sell every account's holding once the analysis turns to Sell, Strong Sell or Avoid (owner's rule, 12 Nov 2026).
     * Never breaks the analysis: a failed sale is retried by the next analysis while the recommendation holds.
     */
    private function closeHoldingsOnSell(Token $token, Analysis $analysis): void
    {
        if (! config('market.simulator.close_on_sell') || ! in_array($analysis->recommendation->value, config('market.simulator.close_on_recommendations'), true)) {
            return;
        }

        if (! $token->positions()->where('quantity', '>', 0)->exists()) {
            return;
        }

        $reason = sprintf('analisa %s menjadi %s (skor %d).', $analysis->created_at->timezone('Asia/Jakarta')->format('H.i').' WIB', $analysis->recommendation->label(), $analysis->final_score);

        $closed = rescue(fn (): array => app(TradingService::class)->closeHoldingsOnSell($token, $reason), []);

        foreach ($closed as $sale) {
            $this->notifier->holdingClosed($sale['user'], $token, ucfirst($reason), $sale['pnl']);
        }
    }

    private function noteDowngrade(Token $token, Analysis $analysis): void
    {
        if ($analysis->recommendation->isBullish()) {
            return;
        }

        $previous = $token->analyses()->whereKeyNot($analysis->id)->where('id', '<', $analysis->id)->latest('id')->first(['id', 'recommendation', 'created_at']);

        $token->signals()
            ->where('status', SignalStatus::Active)
            ->where(fn ($query) => $query->whereNull('downgraded_at')->orWhereNull('second_downgraded_at'))
            ->get()
            ->each(function (Signal $signal) use ($analysis, $previous): void {
                $changes = [];

                if ($signal->downgraded_at === null) {
                    $changes += [
                        'downgraded_at' => $analysis->created_at,
                        'downgrade_price' => $analysis->price,
                        'downgrade_recommendation' => $analysis->recommendation->value,
                        'downgrade_tp_hit' => $signal->max_tp_hit,
                    ];
                }

                $twiceInARow = $previous !== null
                    && ! $previous->recommendation->isBullish()
                    && $signal->entered_at !== null
                    && $previous->created_at->gte($signal->entered_at);

                if ($signal->second_downgraded_at === null && $twiceInARow) {
                    $changes += [
                        'second_downgraded_at' => $analysis->created_at,
                        'second_downgrade_price' => $analysis->price,
                        'second_downgrade_tp_hit' => $signal->max_tp_hit,
                    ];
                }

                if ($changes !== []) {
                    $signal->update($changes);
                }

                if ($this->exitsOnDowngrade($signal)) {
                    app(SignalTracker::class)->closeOnDowngrade($signal, (float) $analysis->price);
                }
            });
    }

    /**
     * v7: the first analysis after entry that is no longer bullish closes the signal. For signals from v7 on, so v6
     * signals still open at the v7 switch finished with the exits they were measured by, while v7 signals still open
     * at the v8 switch keep the v7 exit.
     */
    private function exitsOnDowngrade(Signal $signal): bool
    {
        return (bool) config('market.analysis.exit_on_downgrade')
            && preg_match('/^v(\d+)-/', (string) $signal->strategy_version, $match) === 1
            && (int) $match[1] >= 7;
    }

    /**
     * v8: a signal recommendation whose previous analysis of the token (within the configured minutes) was not Buy or
     * Strong Buy. Analyses held back as Hold by the holder or chase rules count as not Buy.
     *
     * @param  array<string, mixed>  $config
     */
    private function unconfirmed(Recommendation $recommendation, ?Analysis $previous, array $config): bool
    {
        $minutes = (int) ($config['previous_buy_max_minutes'] ?? 0);

        if ($minutes <= 0 || ! in_array($recommendation->value, $config['signal_recommendations'], true)) {
            return false;
        }

        return $previous === null || ! $previous->recommendation->isBullish() || $previous->created_at->lt(now()->subMinutes($minutes));
    }

    /**
     * v7: a Strong Buy at or above the score limit (review 21 Nov 2026: score 77+ -0.63R per signal over v3, v4 and v6,
     * n=27; 72-76 -0.01R, n=56). 0 switches the rule off.
     *
     * @param  array<string, mixed>  $config
     */
    private function scoreTooHigh(Recommendation $recommendation, int $finalScore, array $config): bool
    {
        $limit = (int) ($config['strong_buy_max_score'] ?? 0);

        return $limit > 0 && $recommendation === Recommendation::StrongBuy && $finalScore >= $limit;
    }

    /**
     * @param  array{score: int, available: bool}  $technical
     * @param  array{score: int, is_honeypot: bool, rug_alert: bool}  $onchain
     * @param  array<string, mixed>  $config
     * @return array{0: Recommendation, 1: string|null}
     */
    /**
     * Strategy v4: a Buy (not a Strong Buy) right after a big rise, or close to the day's high, usually met
     * its stop in v3. Null when the Buy may stand.
     *
     * @param  array<string, mixed>  $indicators
     * @param  array<string, mixed>  $config
     */
    private function chasesTheMove(array $indicators, array $config): ?string
    {
        $rise = $indicators['rise_24h_pct'] ?? null;
        $belowHigh = $indicators['below_high_24h_pct'] ?? null;
        $maxRise = $config['buy_max_rise_24h_pct'] ?? null;
        $minBelowHigh = $config['buy_min_below_high_24h_pct'] ?? null;

        // Null or 0 switches a rule off, so the admin panel can turn either one off on its own.
        if ($maxRise > 0 && $rise !== null && $rise > $maxRise) {
            return sprintf('Skor cukup untuk Buy, tetapi harga sudah naik %.1f%% dalam 24 jam (batas %.0f%%): menunggu, tidak mengejar.', $rise, $maxRise);
        }

        if ($minBelowHigh > 0 && $belowHigh !== null && $belowHigh < $minBelowHigh) {
            return sprintf('Skor cukup untuk Buy, tetapi harga hanya %.1f%% di bawah puncak 24 jam (minimal %.0f%%): menunggu, tidak mengejar.', max(0, $belowHigh), $minBelowHigh);
        }

        return null;
    }

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
     * @param  array<string, mixed>  $onchain
     */
    private function top10(array $onchain): ?float
    {
        $top10 = $onchain['metrics']['top10_percent'] ?? null;

        return $top10 === null ? null : (float) $top10;
    }

    /**
     * v6: the reason to hold a buy whose top ten holders own at least the limit, or null. Skipped without holder data.
     *
     * @param  array<string, mixed>  $onchain
     * @param  array<string, mixed>  $config
     */
    private function concentratedHolders(array $onchain, array $config): ?string
    {
        $top10 = $onchain['metrics']['top10_percent'] ?? null;
        $maxTop10 = (float) ($config['max_top10_holder_pct'] ?? 0);

        if ($maxTop10 <= 0 || $top10 === null || (float) $top10 < $maxTop10) {
            return null;
        }

        return sprintf('Skor cukup untuk beli, tetapi 10 holder teratas memegang %.1f%% supply (batas %.0f%%): terlalu terpusat.', (float) $top10, $maxTop10);
    }

    /**
     * Keep an untraded copy: a held Strong Buy or a real signal as the reference (v6 holder rule, metric = top 10 share),
     * or a Buy (metric = Fear & Greed).
     *
     * @param  array<string, float>  $levels
     */
    private function recordShadow(Token $token, Analysis $analysis, string $rule, Recommendation $recommendation, array $levels, ?float $metric, ?Signal $signal = null): void
    {
        rescue(fn () => ShadowSignal::query()->create([
            'token_id' => $token->id,
            'analysis_id' => $analysis->id,
            'signal_id' => $signal?->id,
            'rule' => $rule,
            'recommendation' => $recommendation->value,
            'strategy_version' => $analysis->strategy_version,
            'metric' => $metric,
            'liquidity_usd' => $token->liquidity_usd,
            ...array_intersect_key($levels, array_flip(['entry_low', 'entry_high', 'stop_loss', 'take_profit_1', 'take_profit_2', 'take_profit_3'])),
        ]));
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
