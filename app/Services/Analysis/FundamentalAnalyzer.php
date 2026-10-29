<?php

namespace App\Services\Analysis;

use App\Models\Token;

/**
 * Market health for DEX tokens: liquidity depth, turnover, pair age, valuation and order flow.
 */
class FundamentalAnalyzer
{
    /**
     * @return array{score: int, reasons: list<string>, metrics: array<string, float|int|null>}
     */
    public function analyze(Token $token): array
    {
        $liquidity = (float) $token->liquidity_usd;
        $turnover = $liquidity > 0 ? (float) $token->volume_h24 / $liquidity : null;
        $ageDays = $token->pair_created_at?->diffInDays(now(), true);
        $fdvRatio = $token->fdv && $liquidity > 0 ? $token->fdv / $liquidity : null;
        $txns = (int) $token->txns_h24_buys + (int) $token->txns_h24_sells;
        $buyRatio = $txns > 0 ? $token->txns_h24_buys / $txns : null;

        $parts = [];
        $reasons = [];

        $parts[] = [0.35, match (true) {
            $liquidity < 20_000 => 15,
            $liquidity < 100_000 => 35,
            $liquidity < 500_000 => 55,
            $liquidity < 2_000_000 => 70,
            $liquidity < 10_000_000 => 80,
            default => 90,
        }];
        $reasons[] = 'Likuiditas $'.number_format($liquidity).($liquidity < 100_000 ? ' (tipis, slippage tinggi).' : '.');

        if ($turnover !== null) {
            $parts[] = [0.25, match (true) {
                $turnover < 0.05 => 30,
                $turnover < 0.3 => 55,
                $turnover <= 3 => 80,
                $turnover <= 10 => 60,
                default => 35,
            }];
            $reasons[] = sprintf('Volume 24j %.2fx likuiditas%s', $turnover, match (true) {
                $turnover < 0.05 => ' (sepi peminat).',
                $turnover > 10 => ' (sangat spekulatif / kemungkinan wash trading).',
                default => '.',
            });
        }

        if ($ageDays !== null) {
            $parts[] = [0.20, match (true) {
                $ageDays < 1 => 15,
                $ageDays < 7 => 35,
                $ageDays < 30 => 55,
                $ageDays < 180 => 70,
                default => 85,
            }];
            $reasons[] = $ageDays < 7
                ? sprintf('Pair baru berumur %.1f hari (risiko tinggi).', $ageDays)
                : sprintf('Pair berumur %d hari.', (int) $ageDays);
        }

        if ($fdvRatio !== null) {
            $parts[] = [0.10, match (true) {
                $fdvRatio < 10 => 80,
                $fdvRatio < 50 => 65,
                $fdvRatio < 200 => 45,
                default => 25,
            }];
        }

        if ($buyRatio !== null) {
            $parts[] = [0.10, (int) max(20, min(80, $buyRatio * 100))];
            $reasons[] = sprintf('Transaksi 24j: %d%% beli / %d%% jual.', round($buyRatio * 100), round((1 - $buyRatio) * 100));
        }

        $totalWeight = array_sum(array_column($parts, 0));
        $score = array_sum(array_map(fn (array $part): float => $part[0] * $part[1], $parts)) / $totalWeight;

        return [
            'score' => (int) round($score),
            'reasons' => $reasons,
            'metrics' => [
                'liquidity_usd' => $liquidity,
                'turnover' => $turnover,
                'age_days' => $ageDays,
                'fdv_liquidity_ratio' => $fdvRatio,
                'buy_ratio' => $buyRatio,
            ],
        ];
    }
}
