<?php

namespace App\Services\Analysis;

use App\Models\Candle;
use App\Models\Signal;
use App\Models\Token;
use Illuminate\Support\Collection;

/**
 * Replays the signal rules over historical candles, using only what was known at each candle's close.
 *
 * Only the technical score has history. The other components are held at their typical live values
 * (NEUTRAL_SCORES), so this tests entries, stops and targets, not whale, sentiment or security data.
 * Fills are conservative: a candle touching both stop and target counts as the stop, a stop that gaps
 * fills at the open, and an entry made inside a candle cannot also reach a target in that candle.
 */
class Backtester
{
    /** Median component scores of the live analyses up to 31 Oct 2026. */
    public const NEUTRAL_SCORES = ['onchain' => 85, 'whale' => 50, 'sentiment' => 44, 'fundamental' => 69];

    private const HOUR = 3600;

    public function __construct(private TechnicalAnalyzer $technical, private SignalResult $results) {}

    /**
     * Technical analysis at every closed candle since $since. Done once per token, because it does not
     * depend on the scenario being tested.
     *
     * @return array{token: Token, candles: list<Candle>, analyses: array<int, array{score: int, rsi: float|null, trend: string|null, atr: float, swing_low: float}>}
     */
    public function prepare(Token $token, int $since): array
    {
        $config = config('market.analysis');
        $candles = $this->candles($token, $config['timeframe']);
        $trendCandles = $this->candles($token, $config['trend_timeframe']);
        $trendSeconds = 4 * self::HOUR;
        $analyses = [];
        $trendEnd = 0;

        foreach ($candles as $index => $candle) {
            $closedAt = $candle->open_time + self::HOUR;

            // Only higher timeframe candles that had already closed at this moment.
            while ($trendEnd < count($trendCandles) && $trendCandles[$trendEnd]->open_time + $trendSeconds <= $closedAt) {
                $trendEnd++;
            }

            if ($candle->open_time < $since || $index + 1 < TechnicalAnalyzer::MIN_CANDLES) {
                continue;
            }

            $analysis = $this->technical->analyze(
                new Collection(array_slice($candles, max(0, $index - 249), min($index + 1, 250))),
                new Collection(array_slice($trendCandles, max(0, $trendEnd - 120), min($trendEnd, 120))),
                $candle->close,
            );

            if ($analysis['available'] && $analysis['atr']) {
                $analyses[$index] = [
                    'score' => $analysis['score'],
                    'rsi' => $analysis['indicators']['rsi'] ?? null,
                    'trend' => $analysis['indicators']['trend_higher_tf'] ?? null,
                    'atr' => $analysis['atr'],
                    'swing_low' => $analysis['swing_low'],
                ];
            }
        }

        return ['token' => $token, 'candles' => $candles, 'analyses' => $analyses];
    }

    /**
     * Trades of one scenario on one prepared token. Like live, a token has one open signal at a time.
     *
     * @param  array{token: Token, candles: list<Candle>, analyses: array<int, array<string, mixed>>}  $prepared
     * @param  array{require_trend?: string, max_rsi?: float, min_stop_pct?: float, sl_atr_min?: float, tp_r_multiples?: list<float>}  $scenario
     * @return list<array<string, mixed>>
     */
    public function simulate(array $prepared, array $scenario): array
    {
        $config = config('market.analysis');
        $candles = $prepared['candles'];
        $trades = [];
        $busyUntil = -1;

        foreach ($prepared['analyses'] as $index => $analysis) {
            if ($index <= $busyUntil || ! isset($candles[$index + 1]) || ! $this->isBuy($analysis['score'], $config)) {
                continue;
            }

            if (! $this->passesGates($analysis, $scenario)) {
                continue;
            }

            $price = $candles[$index]->close;
            $levels = TradeLevels::calculate(
                $price,
                $analysis['atr'],
                $analysis['swing_low'],
                $scenario['tp_r_multiples'] ?? $config['tp_r_multiples'],
                $scenario['sl_atr_min'] ?? $config['sl_atr_min'],
                $config['sl_atr_max'],
            );

            if (isset($scenario['min_stop_pct']) && ($price - $levels['stop_loss']) / $price * 100 < $scenario['min_stop_pct']) {
                continue;
            }

            $outcome = $this->follow($candles, $index, $levels, $config);
            $busyUntil = $outcome['last_index'];

            if ($outcome['result'] === null) {
                continue;
            }

            $signal = new Signal([...$levels, 'entry_price' => $outcome['entry_price']]);
            $result = $this->results->calculate($signal, $outcome['exit_price'], $outcome['tp_hit'], $prepared['token']->liquidity_usd);

            $trades[] = [
                'token' => $prepared['token']->symbol,
                'signal_at' => $candles[$index]->open_time + self::HOUR,
                'closed_at' => $outcome['closed_at'],
                'result' => $outcome['result'],
                'tp_hit' => $outcome['tp_hit'],
                'entry_price' => $outcome['entry_price'],
                'exit_price' => $outcome['exit_price'],
                'stop_pct' => round(($outcome['entry_price'] - $levels['stop_loss']) / $outcome['entry_price'] * 100, 2),
                'gross_r' => $result['gross_r'],
                'net_r' => $result['net_r'],
                'cost_r' => $result['cost_r'],
            ];
        }

        return $trades;
    }

    /**
     * Win rate, expectancy, profit factor and drawdown of a list of trades, in net R.
     *
     * @param  list<array<string, mixed>>  $trades
     * @return array{trades: int, win_rate: float|null, avg_r: float|null, total_r: float, gross_total_r: float, avg_cost_r: float|null, profit_factor: float|null, max_drawdown_r: float, tokens: int}
     */
    public static function summarize(array $trades): array
    {
        $net = array_column($trades, 'net_r');
        $count = count($net);
        $wins = array_filter($net, fn (float $r): bool => $r > 0);
        $losses = abs(array_sum(array_filter($net, fn (float $r): bool => $r < 0)));

        usort($trades, fn (array $a, array $b): int => $a['closed_at'] <=> $b['closed_at']);
        $equity = 0.0;
        $peak = 0.0;
        $drawdown = 0.0;

        foreach ($trades as $trade) {
            $equity += $trade['net_r'];
            $peak = max($peak, $equity);
            $drawdown = max($drawdown, $peak - $equity);
        }

        return [
            'trades' => $count,
            'win_rate' => $count ? round(count($wins) / $count * 100, 1) : null,
            'avg_r' => $count ? round(array_sum($net) / $count, 2) : null,
            'total_r' => round(array_sum($net), 2),
            'gross_total_r' => round(array_sum(array_column($trades, 'gross_r')), 2),
            'avg_cost_r' => $count ? round(array_sum(array_column($trades, 'cost_r')) / $count, 2) : null,
            'profit_factor' => $losses > 0 ? round(array_sum($wins) / $losses, 2) : null,
            'max_drawdown_r' => round($drawdown, 2),
            'tokens' => count(array_unique(array_column($trades, 'token'))),
        ];
    }

    /**
     * Would the live engine call this a buy? Same weights and threshold, with the other components neutral.
     *
     * @param  array<string, mixed>  $config
     */
    private function isBuy(int $technicalScore, array $config): bool
    {
        $final = $technicalScore * $config['weights']['technical'];

        foreach (self::NEUTRAL_SCORES as $component => $score) {
            $final += $score * $config['weights'][$component];
        }

        return $technicalScore >= 50 && (int) round($final) >= $config['thresholds']['BUY'];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $scenario
     */
    private function passesGates(array $analysis, array $scenario): bool
    {
        if (isset($scenario['require_trend']) && $analysis['trend'] !== $scenario['require_trend']) {
            return false;
        }

        return ! isset($scenario['max_rsi']) || ($analysis['rsi'] !== null && $analysis['rsi'] < $scenario['max_rsi']);
    }

    /**
     * Walk the candles after a signal. A null result means it never entered or the data ended first.
     *
     * @param  list<Candle>  $candles
     * @param  array<string, float>  $levels
     * @param  array<string, mixed>  $config
     * @return array{result: string|null, entry_price: float|null, exit_price: float|null, tp_hit: int, closed_at: int|null, last_index: int}
     */
    private function follow(array $candles, int $signalIndex, array $levels, array $config): array
    {
        $targets = [$levels['take_profit_1'], $levels['take_profit_2'], $levels['take_profit_3']];
        $signalAt = $candles[$signalIndex]->open_time + self::HOUR;
        $entryExpiry = $signalAt + $config['signal_entry_expiry_hours'] * self::HOUR;
        $entry = null;
        $enteredAt = null;
        $tpHit = 0;
        $last = $signalIndex;

        for ($i = $signalIndex + 1; $i < count($candles); $i++) {
            $candle = $candles[$i];
            $last = $i;
            $canReachTarget = true;

            if ($entry === null) {
                if ($candle->open_time >= $entryExpiry || $candle->open <= $levels['stop_loss']) {
                    return $this->notEntered($i);
                }

                if ($candle->open <= $levels['entry_high']) {
                    $entry = $candle->open;
                } elseif ($candle->low <= $levels['entry_high']) {
                    $entry = $levels['entry_high'];
                    $canReachTarget = false;
                } else {
                    continue;
                }

                $enteredAt = $candle->open_time;
            }

            $stop = $tpHit > 0 ? $entry : $levels['stop_loss'];

            if ($candle->low <= $stop) {
                return $this->closed($tpHit > 0 ? 'breakeven' : 'stop', $entry, min($candle->open, $stop), $tpHit, $candle->open_time + self::HOUR, $i);
            }

            if ($canReachTarget) {
                while ($tpHit < 3 && $candle->high >= $targets[$tpHit]) {
                    $tpHit++;
                }

                if ($tpHit === 3) {
                    return $this->closed('take_profit', $entry, $targets[2], 3, $candle->open_time + self::HOUR, $i);
                }
            }

            if ($candle->open_time + self::HOUR >= $enteredAt + $config['signal_max_hours'] * self::HOUR) {
                return $this->closed('expired', $entry, $candle->close, $tpHit, $candle->open_time + self::HOUR, $i);
            }
        }

        return ['result' => null, 'entry_price' => null, 'exit_price' => null, 'tp_hit' => 0, 'closed_at' => null, 'last_index' => $last];
    }

    /**
     * @return array{result: string|null, entry_price: float|null, exit_price: float|null, tp_hit: int, closed_at: int|null, last_index: int}
     */
    private function closed(string $result, float $entry, float $exit, int $tpHit, int $closedAt, int $index): array
    {
        return ['result' => $result, 'entry_price' => $entry, 'exit_price' => $exit, 'tp_hit' => $tpHit, 'closed_at' => $closedAt, 'last_index' => $index];
    }

    /**
     * @return array{result: string|null, entry_price: float|null, exit_price: float|null, tp_hit: int, closed_at: int|null, last_index: int}
     */
    private function notEntered(int $index): array
    {
        return ['result' => null, 'entry_price' => null, 'exit_price' => null, 'tp_hit' => 0, 'closed_at' => null, 'last_index' => $index];
    }

    /**
     * @return list<Candle>
     */
    private function candles(Token $token, string $timeframe): array
    {
        return Candle::query()->where('token_id', $token->id)->where('timeframe', $timeframe)->orderBy('open_time')->get()->all();
    }
}
