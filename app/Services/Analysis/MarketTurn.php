<?php

namespace App\Services\Analysis;

use App\Models\Analysis;
use App\Models\ShadowSignal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The triggers of docs/UJI_ULANG_PASAR_NAIK.md (workspace repo, owner 15 Nov 2026): almost every analysis so far comes
 * from a falling market, so they are run again once the market turns. Checked once a day by app:market-turn-check.
 *   1. BTC daily close above its SMA50 for 14 days in a row (Bybit Indonesia spot BTCUSDT).
 *   2. BTC's 4h trend in the DEX market context bullish in most analyses, 7 days in a row.
 *   3. Fear & Greed above 50 for 14 days in a row (alternative.me).
 *   4. About 30 finished "Buy while BTC's 4h trend rises" shadows.
 */
class MarketTurn
{
    public const BTC_DAYS = 14;

    public const DEX_TREND_DAYS = 7;

    public const FEAR_GREED_DAYS = 14;

    public const BUY_RISING_TRADES = 30;

    /**
     * @return list<array{key: string, label: string, value: string, progress: int|null, target: int, met: bool|null}>
     */
    public function triggers(): array
    {
        return [
            $this->btcAboveSma50(),
            $this->dexBtcTrend(),
            $this->fearGreed(),
            $this->buyRisingTrades(),
        ];
    }

    /**
     * @return array{key: string, label: string, value: string, progress: int|null, target: int, met: bool|null}
     */
    private function btcAboveSma50(): array
    {
        $row = ['key' => 'btc_sma50', 'label' => 'BTC harian di atas SMA50', 'target' => self::BTC_DAYS];

        try {
            $list = Http::timeout(15)->get('https://api.bybit.id/v5/market/kline', ['category' => 'spot', 'symbol' => 'BTCUSDT', 'interval' => 'D', 'limit' => 90])
                ->throw()->json('result.list') ?? [];
        } catch (Throwable $e) {
            return [...$row, 'value' => 'tidak terbaca: '.$e->getMessage(), 'progress' => null, 'met' => null];
        }

        $today = CarbonImmutable::now('UTC')->startOfDay()->getTimestamp() * 1000;
        $closes = collect($list)->filter(fn (array $k): bool => (int) $k[0] < $today)   // closed days only
            ->sortBy(fn (array $k): int => (int) $k[0])->map(fn (array $k): float => (float) $k[4])->values()->all();

        if (count($closes) < 50) {
            return [...$row, 'value' => 'candle harian kurang', 'progress' => null, 'met' => null];
        }

        $streak = 0;
        for ($i = count($closes) - 1; $i >= 49; $i--) {
            if ($closes[$i] <= array_sum(array_slice($closes, $i - 49, 50)) / 50) {
                break;
            }
            $streak++;
        }
        $last = count($closes) - 1;
        $sma = array_sum(array_slice($closes, $last - 49, 50)) / 50;

        return [...$row, 'value' => sprintf('close %s, SMA50 %s (%+.1f%%)', number_format($closes[$last], 0, ',', '.'), number_format($sma, 0, ',', '.'), ($closes[$last] / $sma - 1) * 100),
            'progress' => $streak, 'met' => $streak >= self::BTC_DAYS];
    }

    /**
     * Days (UTC) counted back from yesterday on which most analyses with a BTC trend had it bullish.
     *
     * @return array{key: string, label: string, value: string, progress: int|null, target: int, met: bool|null}
     */
    private function dexBtcTrend(): array
    {
        $from = CarbonImmutable::now('UTC')->startOfDay()->subDays(self::DEX_TREND_DAYS);
        $days = [];

        foreach (Analysis::query()->where('created_at', '>=', $from)->where('created_at', '<', $from->addDays(self::DEX_TREND_DAYS))
            ->select(['id', 'created_at', 'market_context'])->lazyById(2000) as $analysis) {
            $trend = $analysis->market_context['benchmarks']['BTC']['trend_4h'] ?? null;
            if ($trend === null) {
                continue;
            }
            $day = $analysis->created_at->toDateString();
            $days[$day]['all'] = ($days[$day]['all'] ?? 0) + 1;
            $days[$day]['bullish'] = ($days[$day]['bullish'] ?? 0) + ($trend === 'bullish' ? 1 : 0);
        }

        $streak = 0;
        $shares = [];
        for ($d = 1; $d <= self::DEX_TREND_DAYS; $d++) {
            $day = CarbonImmutable::now('UTC')->startOfDay()->subDays($d)->toDateString();
            $share = isset($days[$day]) ? $days[$day]['bullish'] / $days[$day]['all'] : 0.0;
            $shares[] = (int) round($share * 100).'%';
            if ($share <= 0.5) {
                break;
            }
            $streak++;
        }

        return ['key' => 'dex_btc_trend', 'label' => 'Tren 4 jam BTC naik di analisa DEX', 'target' => self::DEX_TREND_DAYS,
            'value' => 'porsi bullish kemarin '.($shares[0] ?? '-'), 'progress' => $streak, 'met' => $streak >= self::DEX_TREND_DAYS];
    }

    /**
     * @return array{key: string, label: string, value: string, progress: int|null, target: int, met: bool|null}
     */
    private function fearGreed(): array
    {
        $row = ['key' => 'fear_greed', 'label' => 'Fear & Greed di atas 50', 'target' => self::FEAR_GREED_DAYS];

        try {
            $data = Http::timeout(15)->get('https://api.alternative.me/fng/', ['limit' => 30])->throw()->json('data') ?? [];
        } catch (Throwable $e) {
            return [...$row, 'value' => 'tidak terbaca: '.$e->getMessage(), 'progress' => null, 'met' => null];
        }

        $values = collect($data)->sortByDesc(fn (array $x): int => (int) $x['timestamp'])->map(fn (array $x): int => (int) $x['value'])->values();
        if ($values->isEmpty()) {
            return [...$row, 'value' => 'kosong', 'progress' => null, 'met' => null];
        }

        $streak = 0;
        foreach ($values as $value) {
            if ($value <= 50) {
                break;
            }
            $streak++;
        }

        return [...$row, 'value' => 'hari ini '.$values->first(), 'progress' => $streak, 'met' => $streak >= self::FEAR_GREED_DAYS];
    }

    /**
     * @return array{key: string, label: string, value: string, progress: int|null, target: int, met: bool|null}
     */
    private function buyRisingTrades(): array
    {
        $trades = ShadowSignal::query()->where('rule', ShadowSignal::BUY_WATCH)->whereNotNull('net_r')
            ->with('analysis:id,market_context')->get(['id', 'analysis_id'])
            ->filter(fn (ShadowSignal $s): bool => ($s->analysis?->market_context['benchmarks']['BTC']['trend_4h'] ?? null) === 'bullish')
            ->count();

        return ['key' => 'buy_btc_rising', 'label' => 'Bayangan Buy saat tren 4 jam BTC naik (selesai)', 'target' => self::BUY_RISING_TRADES,
            'value' => "{$trades} trade", 'progress' => $trades, 'met' => $trades >= self::BUY_RISING_TRADES];
    }
}
