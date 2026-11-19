<?php

namespace App\Services\Analysis;

use App\Enums\SignalStatus;
use App\Models\Candle;
use App\Models\Signal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Koin kena TP" (owner, 19 Nov 2026): every token whose signal reached TP1, with what the analysis and market looked
 * like at entry (pattern, entry, distances, BTC, volume), and the same measures for entered signals that missed, so a
 * trait is only read as special when it differs from the misses. All versions; the version is on each row.
 *
 * Most measures come from the analysis the signal was made from (stored at the time). Time to TP1 and the deepest
 * dip are estimated from stored 15m candles (the tracker acts on price checks, which can differ from candles). A
 * finished signal's row is cached for good.
 *
 * Check 19 Nov 2026 on 196 finished signals: a token's next signal after a TP averaged -0.23R (70), after a miss
 * -0.47R (42), first signals -0.37R (84). Still a loss, so this list is for study, not a filter.
 */
class TpCoins
{
    /** Raise when a measure changes, so cached rows are rebuilt. */
    private const VERSION = 1;

    /**
     * @return array{coins: list<array<string, mixed>>, events: list<array<string, mixed>>, compare: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function build(): array
    {
        $events = Signal::query()
            ->with(['token:id,symbol,chain_id,slug', 'analysis:id,final_score,indicators,market_context,reasons'])
            ->whereNotNull('entered_at')
            ->orderBy('entered_at')
            ->get()
            ->map(fn (Signal $s): array => $s->closed_at !== null
                ? Cache::rememberForever('tp-coins:v'.self::VERSION.":{$s->id}", fn (): array => $this->event($s))
                : $this->event($s))
            ->values();

        $tpTokens = $events->where('outcome', 'tp')->pluck('token_id')->unique();

        return [
            'coins' => $tpTokens->map(fn (int $id): array => $this->coin($events->where('token_id', $id)))
                ->sortByDesc('tp')->values()->all(),
            'events' => $events->whereIn('token_id', $tpTokens)->sortByDesc('entered_at')->values()->all(),
            'compare' => $this->compare($events->where('outcome', 'tp'), $events->whereIn('outcome', ['stop', 'other'])),
            'totals' => [
                'signals' => $events->count(),
                'tp' => $events->where('outcome', 'tp')->count(),
                'missed' => $events->whereIn('outcome', ['stop', 'other'])->count(),
                'open' => $events->where('outcome', 'open')->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function event(Signal $s): array
    {
        $ind = (array) ($s->analysis?->indicators ?? []);
        $ctx = (array) ($s->analysis?->market_context ?? []);
        $entry = (float) $s->entry_price;
        $at = $s->entered_at->timestamp;
        $end = $s->closed_at?->timestamp ?? now()->timestamp;
        $tp1 = (float) $s->take_profit_1;
        $tpAt = $s->max_tp_hit > 0 ? $this->firstReach($s, $tp1, $at, $end) : null;
        $path = fn (int $until) => Candle::query()->where('token_id', $s->token_id)->where('timeframe', '15m')
            ->whereBetween('open_time', [intdiv($at, 900) * 900, $until]);
        $low = $path($tpAt ?? $end)->min('low');
        $high = $path($end)->max('high');
        $ema50 = (float) ($ind['ema50'] ?? 0);

        return [
            'id' => $s->id,
            'token_id' => $s->token_id,
            'symbol' => $s->token?->symbol,
            'slug' => $s->token?->slug,
            'chain' => $s->token?->chain_id,
            'version' => $s->strategy_version,
            'recommendation' => $s->recommendation->value,
            'entered_at' => $s->entered_at->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'tp_at' => $tpAt === null ? null : CarbonImmutable::createFromTimestamp($tpAt, 'UTC')->toIso8601String(),
            'outcome' => $s->max_tp_hit > 0 ? 'tp' : match ($s->status) {
                SignalStatus::Active => 'open',
                SignalStatus::StopLoss => 'stop',
                default => 'other',
            },
            'status' => $s->status->value,
            'max_tp_hit' => (int) $s->max_tp_hit,
            'net_r' => $s->net_r,
            // Pola
            'score' => $s->analysis?->final_score,
            'reasons' => array_values(array_slice(array_filter((array) ($s->analysis?->reasons ?? []), fn ($r): bool => str_starts_with((string) $r, '[Teknikal]')), 0, 4)),
            'candle_1h' => $this->pattern($s->token_id, $at),
            'rsi' => isset($ind['rsi']) ? round((float) $ind['rsi'], 1) : null,
            'trend_higher_tf' => $ind['trend_higher_tf'] ?? null,
            'macd_rising' => isset($ind['macd_histogram']) ? (float) $ind['macd_histogram'] > 0 : null,
            // Entry
            'entry_price' => $entry,
            'stop_pct' => self::pct($entry - (float) $s->stop_loss, $entry),
            'rise_24h_pct' => isset($ind['rise_24h_pct']) ? round((float) $ind['rise_24h_pct'], 2) : null,
            'below_high_24h_pct' => isset($ind['below_high_24h_pct']) ? round((float) $ind['below_high_24h_pct'], 2) : null,
            'vs_ema50_pct' => $ema50 > 0 ? self::pct($entry - $ema50, $ema50) : null,
            'age_days' => isset($ind['fundamental']['age_days']) ? round((float) $ind['fundamental']['age_days']) : null,
            'liquidity_usd' => isset($ind['fundamental']['liquidity_usd']) ? round((float) $ind['fundamental']['liquidity_usd']) : null,
            'top10_pct' => $ind['onchain']['top10_percent'] ?? null,
            // Jarak
            'tp_pct' => self::pct($tp1 - $entry, $entry),
            'tp_r' => $entry > (float) $s->stop_loss ? round(($tp1 - $entry) / ($entry - (float) $s->stop_loss), 2) : null,
            'minutes_to_tp' => $tpAt === null ? null : max(0, intdiv($tpAt - $at, 60)),
            'minutes_held' => $s->closed_at === null ? null : intdiv($end - $at, 60),
            'mae_pct' => $low === null ? null : self::pct((float) $low - $entry, $entry),
            'mfe_pct' => $high === null ? null : self::pct((float) $high - $entry, $entry),
            // Kondisi BTC
            'btc_trend_4h' => $ctx['benchmarks']['BTC']['trend_4h'] ?? null,
            'btc_change_24h_pct' => $ctx['benchmarks']['BTC']['change_24h'] ?? ($ind['btc']['btc_change_24h'] ?? null),
            'btc_corr' => $ind['btc']['corr'] ?? null,
            'fear_greed' => $ctx['fear_greed']['value'] ?? ($ind['sentiment']['fear_greed'] ?? null),
            'breadth_up_pct' => $ctx['breadth']['up_24h_pct'] ?? null,
            // Volume
            'volume_ratio' => isset($ind['volume_ratio']) ? round((float) $ind['volume_ratio'], 2) : null,
            'turnover' => isset($ind['fundamental']['turnover']) ? round((float) $ind['fundamental']['turnover'], 2) : null,
            'buy_ratio' => isset($ind['fundamental']['buy_ratio']) ? round((float) $ind['fundamental']['buy_ratio'] * 100, 1) : null,
            'whale_net_usd' => isset($ind['whale']['net_usd']) ? round((float) $ind['whale']['net_usd']) : null,
        ];
    }

    /**
     * The close of the first 15m candle from entry whose high reached the level, or null when the candles never show it.
     */
    private function firstReach(Signal $s, float $level, int $from, int $until): ?int
    {
        $open = Candle::query()->where('token_id', $s->token_id)->where('timeframe', '15m')
            ->whereBetween('open_time', [intdiv($from, 900) * 900, $until])->where('high', '>=', $level)
            ->orderBy('open_time')->value('open_time');

        return $open === null ? null : max($from, (int) $open + 900);
    }

    /**
     * The last stored 1h candle that closed before entry (within two hours), read as a pattern.
     */
    private function pattern(int $tokenId, int $at): ?string
    {
        $rows = Candle::query()->where('token_id', $tokenId)->where('timeframe', '1h')
            ->whereBetween('open_time', [intdiv($at, 3600) * 3600 - 3 * 3600, intdiv($at, 3600) * 3600 - 3600])
            ->orderByDesc('open_time')->limit(2)->get(['open', 'high', 'low', 'close'])->reverse()->values();
        if ($rows->isEmpty()) {
            return null;
        }

        [$o, $h, $l, $c] = [(float) $rows->last()->open, (float) $rows->last()->high, (float) $rows->last()->low, (float) $rows->last()->close];
        $range = $h - $l;
        $body = abs($c - $o);
        $prev = $rows->count() === 2 ? $rows->first() : null;

        return match (true) {
            $prev !== null && $c > $o && (float) $prev->close < (float) $prev->open && $o <= (float) $prev->close && $c >= (float) $prev->open => 'Bullish engulfing',
            $range > 0 && (min($o, $c) - $l) >= 2 * max($body, 0.05 * $range) && ($h - max($o, $c)) <= 0.25 * $range => 'Hammer',
            $range > 0 && ($h - max($o, $c)) >= 2 * max($body, 0.05 * $range) && (min($o, $c) - $l) <= 0.25 * $range => 'Shooting star',
            $range > 0 && $c > $o && $body >= 0.5 * $range && ($h - $c) <= 0.25 * $range => 'Hijau solid',
            $c > $o => 'Hijau',
            $c < $o => 'Merah',
            default => 'Doji',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function coin(Collection $events): array
    {
        $tp = $events->where('outcome', 'tp');
        $first = $events->first();

        return [
            'token_id' => $first['token_id'],
            'symbol' => $first['symbol'],
            'slug' => $first['slug'],
            'chain' => $first['chain'],
            'tp' => $tp->count(),
            'stop' => $events->where('outcome', 'stop')->count(),
            'other' => $events->where('outcome', 'other')->count(),
            'open' => $events->where('outcome', 'open')->count(),
            'net_r' => round((float) $events->sum('net_r'), 2),
            'avg_minutes_to_tp' => $tp->avg('minutes_to_tp') === null ? null : (int) round($tp->avg('minutes_to_tp')),
            'last_tp_at' => $tp->max('tp_at') ?? $tp->max('entered_at'),
        ];
    }

    /**
     * Median of each measure (share for yes/no measures) for signals that reached TP1 and entered signals that did not.
     *
     * @param  Collection<int, array<string, mixed>>  $hit
     * @param  Collection<int, array<string, mixed>>  $missed
     * @return list<array<string, mixed>>
     */
    private function compare(Collection $hit, Collection $missed): array
    {
        $measures = [
            ['group' => 'Pola', 'key' => 'score', 'label' => 'Skor akhir', 'unit' => ''],
            ['group' => 'Pola', 'key' => 'rsi', 'label' => 'RSI', 'unit' => ''],
            ['group' => 'Pola', 'key' => 'macd_rising', 'label' => 'Histogram MACD positif', 'unit' => 'share'],
            ['group' => 'Entry', 'key' => 'stop_pct', 'label' => 'Jarak stop', 'unit' => '%'],
            ['group' => 'Entry', 'key' => 'rise_24h_pct', 'label' => 'Naik 24 jam sebelum entry', 'unit' => '%'],
            ['group' => 'Entry', 'key' => 'below_high_24h_pct', 'label' => 'Di bawah harga tertinggi 24 jam', 'unit' => '%'],
            ['group' => 'Entry', 'key' => 'vs_ema50_pct', 'label' => 'Entry terhadap EMA50', 'unit' => '%'],
            ['group' => 'Entry', 'key' => 'age_days', 'label' => 'Umur pair (hari)', 'unit' => ''],
            ['group' => 'Entry', 'key' => 'liquidity_usd', 'label' => 'Likuiditas', 'unit' => 'usd'],
            ['group' => 'Jarak', 'key' => 'tp_pct', 'label' => 'Jarak TP1', 'unit' => '%'],
            ['group' => 'Jarak', 'key' => 'mae_pct', 'label' => 'Turun terdalam sebelum TP1/keluar (candle 15 menit)', 'unit' => '%'],
            ['group' => 'Kondisi BTC', 'key' => 'btc_change_24h_pct', 'label' => 'BTC 24 jam', 'unit' => '%'],
            ['group' => 'Kondisi BTC', 'key' => 'btc_corr', 'label' => 'Korelasi dengan BTC', 'unit' => ''],
            ['group' => 'Kondisi BTC', 'key' => 'fear_greed', 'label' => 'Fear & Greed', 'unit' => ''],
            ['group' => 'Kondisi BTC', 'key' => 'breadth_up_pct', 'label' => 'Token pantauan yang naik 24 jam', 'unit' => '%'],
            ['group' => 'Volume', 'key' => 'volume_ratio', 'label' => 'Rasio volume', 'unit' => 'x'],
            ['group' => 'Volume', 'key' => 'turnover', 'label' => 'Turnover (volume / likuiditas)', 'unit' => 'x'],
            ['group' => 'Volume', 'key' => 'buy_ratio', 'label' => 'Porsi transaksi beli', 'unit' => '%'],
            ['group' => 'Volume', 'key' => 'whale_net_usd', 'label' => 'Beli bersih whale', 'unit' => 'usd'],
        ];

        $value = function (Collection $events, array $m): ?float {
            $values = $events->pluck($m['key'])->filter(fn ($v): bool => $v !== null);
            if ($values->isEmpty()) {
                return null;
            }

            return $m['unit'] === 'share' ? round($values->filter()->count() / $values->count() * 100, 1) : round((float) $values->median(), 2);
        };

        return array_map(fn (array $m): array => $m + ['hit' => $value($hit, $m), 'missed' => $value($missed, $m)], $measures);
    }

    private static function pct(float $change, float $base): ?float
    {
        return $base > 0 ? round($change / $base * 100, 2) : null;
    }
}
