<?php

namespace App\Services\Analysis;

use App\Models\Token;

/**
 * Contract safety, holder growth and liquidity changes (rug pull detection).
 */
class OnchainAnalyzer
{
    /**
     * @return array{score: int, available: bool, is_honeypot: bool, rug_alert: bool, reasons: list<string>, metrics: array<string, float|int|null>}
     */
    public function analyze(Token $token): array
    {
        $reasons = [];
        $latest = $token->securityChecks()->latest('checked_at')->first();

        if ($latest === null) {
            $score = 50;
            $reasons[] = 'Belum ada data keamanan kontrak.';
        } else {
            $score = $latest->risk_score;
            $reasons[] = "Skor keamanan kontrak {$latest->risk_score}/100.";

            foreach (array_slice(array_filter($latest->flags, fn (array $flag): bool => $flag['level'] === 'danger'), 0, 3) as $flag) {
                $reasons[] = $flag['message'];
            }
        }

        $holderGrowth = $this->holderGrowthPercent($token, $latest?->holder_count);

        if ($holderGrowth !== null) {
            if ($holderGrowth > 2) {
                $score += 5;
                $reasons[] = sprintf('Jumlah holder naik %.1f%% dalam ~24 jam.', $holderGrowth);
            } elseif ($holderGrowth < -2) {
                $score -= 10;
                $reasons[] = sprintf('Jumlah holder turun %.1f%% dalam ~24 jam.', abs($holderGrowth));
            }
        }

        $liquidityChange = $this->liquidityChangePercent($token);
        $rugAlert = false;

        if ($liquidityChange !== null) {
            if ($liquidityChange <= -config('market.analysis.rug_liquidity_drop_pct')) {
                $rugAlert = true;
                $score -= 40;
                $reasons[] = sprintf('PERINGATAN: likuiditas turun %.0f%% dalam 24 jam (indikasi rug pull).', abs($liquidityChange));
            } elseif ($liquidityChange < -20) {
                $score -= 15;
                $reasons[] = sprintf('Likuiditas turun %.0f%% dalam 24 jam.', abs($liquidityChange));
            } elseif ($liquidityChange > 20) {
                $score += 5;
                $reasons[] = sprintf('Likuiditas naik %.0f%% dalam 24 jam.', $liquidityChange);
            }
        }

        return [
            'score' => (int) max(0, min(100, $score)),
            'available' => $latest !== null,
            'is_honeypot' => (bool) $latest?->is_honeypot,
            'rug_alert' => $rugAlert,
            'reasons' => $reasons,
            'metrics' => [
                'holder_count' => $latest?->holder_count,
                'holder_growth_pct' => $holderGrowth,
                'liquidity_change_24h_pct' => $liquidityChange,
                'top10_percent' => $latest?->top10_percent,
            ],
        ];
    }

    private function holderGrowthPercent(Token $token, ?int $currentHolders): ?float
    {
        if (! $currentHolders) {
            return null;
        }

        $previous = $token->securityChecks()
            ->where('checked_at', '<=', now()->subHours(20))
            ->whereNotNull('holder_count')
            ->latest('checked_at')
            ->value('holder_count');

        return $previous ? ($currentHolders - $previous) / $previous * 100 : null;
    }

    private function liquidityChangePercent(Token $token): ?float
    {
        $previous = $token->snapshots()
            ->where('captured_at', '<=', now()->subHours(23))
            ->whereNotNull('liquidity_usd')
            ->latest('captured_at')
            ->value('liquidity_usd');

        if (! $previous || ! $token->liquidity_usd) {
            return null;
        }

        return ($token->liquidity_usd - (float) $previous) / (float) $previous * 100;
    }
}
