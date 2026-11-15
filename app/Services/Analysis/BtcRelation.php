<?php

namespace App\Services\Analysis;

use App\Models\Candle;
use App\Models\Token;
use App\Services\MarketData\BenchmarkTokens;
use Illuminate\Support\Facades\Cache;

/**
 * How a token has moved with BTC: correlation and beta of closed 1 hour returns over the last hours, and the
 * 24 hour change of both. Stored with each analysis; the 15 Nov 2026 study (dex/review/hasil-15nov-btc.md)
 * found tokens that follow BTC did worst while BTC fell.
 */
class BtcRelation
{
    public function __construct(private BenchmarkTokens $benchmarks) {}

    /**
     * Null when BTC or the token lacks enough hourly candles, or when the token is the BTC benchmark itself.
     *
     * @return array{corr: float, beta: float, hours: int, token_change_24h: float|null, btc_change_24h: float|null, relative_24h: float|null}|null
     */
    public function measure(Token $token): ?array
    {
        $btcToken = $this->benchmarks->find('BTC');

        if ($btcToken === null || $btcToken->id === $token->id) {
            return null;
        }

        $btc = $this->btcCloses($btcToken);
        $own = $this->closes($token);
        [$tokenReturns, $btcReturns] = [[], []];

        foreach ($own as $openTime => $close) {
            $before = $openTime - 3600;

            if (isset($own[$before], $btc[$openTime], $btc[$before]) && $own[$before] > 0 && $close > 0 && $btc[$before] > 0 && $btc[$openTime] > 0) {
                $tokenReturns[] = log($close / $own[$before]);
                $btcReturns[] = log($btc[$openTime] / $btc[$before]);
            }
        }

        $hours = count($tokenReturns);
        $btcVariance = $this->covariance($btcReturns, $btcReturns);
        $tokenVariance = $this->covariance($tokenReturns, $tokenReturns);

        if ($hours < (int) config('market.analysis.btc_relation_min_hours') || $btcVariance <= 0 || $tokenVariance <= 0) {
            return null;
        }

        $covariance = $this->covariance($tokenReturns, $btcReturns);
        $tokenChange = $this->change24h($own);
        $btcChange = $this->change24h($btc, array_key_last($own));

        return [
            'corr' => round($covariance / sqrt($tokenVariance * $btcVariance), 3),
            'beta' => round($covariance / $btcVariance, 3),
            'hours' => $hours,
            'token_change_24h' => $tokenChange,
            'btc_change_24h' => $btcChange,
            'relative_24h' => $tokenChange !== null && $btcChange !== null ? round($tokenChange - $btcChange, 2) : null,
        ];
    }

    /**
     * BTC's closes, shared by every analysis of one round.
     *
     * @return array<int, float>
     */
    private function btcCloses(Token $btc): array
    {
        return Cache::remember('market:btc-closes:'.intdiv(now()->getTimestamp(), 3600), now()->addMinutes(10), fn (): array => $this->closes($btc));
    }

    /**
     * Closed 1 hour candles, oldest first, long enough for the returns window and a 24 hour change.
     *
     * @return array<int, float>
     */
    private function closes(Token $token): array
    {
        $hours = max((int) config('market.analysis.btc_relation_hours') + 1, 25);
        $until = now()->getTimestamp() - 3600;

        return Candle::query()->where('token_id', $token->id)->where('timeframe', '1h')
            ->where('open_time', '<=', $until)->where('open_time', '>', $until - $hours * 3600)
            ->orderBy('open_time')->pluck('close', 'open_time')
            ->map(fn (mixed $close): float => (float) $close)->all();
    }

    /**
     * @param  array<int, float>  $closes
     */
    private function change24h(array $closes, ?int $at = null): ?float
    {
        $at ??= array_key_last($closes);
        $now = $at !== null ? ($closes[$at] ?? null) : null;
        $before = $at !== null ? ($closes[$at - 86400] ?? null) : null;

        return $now !== null && $before > 0 ? round(($now / $before - 1) * 100, 2) : null;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function covariance(array $a, array $b): float
    {
        $count = count($a);

        if ($count < 2) {
            return 0.0;
        }

        $meanA = array_sum($a) / $count;
        $meanB = array_sum($b) / $count;
        $sum = 0.0;

        foreach ($a as $i => $value) {
            $sum += ($value - $meanA) * ($b[$i] - $meanB);
        }

        return $sum / ($count - 1);
    }
}
