<?php

namespace App\Services\Analysis;

use App\Enums\OrderSide;
use App\Models\Token;

/**
 * Net buy/sell pressure from large trades over the lookback window.
 */
class WhaleAnalyzer
{
    /**
     * @return array{score: int, reasons: list<string>, metrics: array{buy_usd: float, sell_usd: float, trades: int, wallets: int, net_usd: float}}
     */
    public function analyze(Token $token): array
    {
        $hours = config('market.whale.lookback_hours');
        $trades = $token->whaleTrades()->where('traded_at', '>=', now()->subHours($hours))->get();

        $buyUsd = (float) $trades->where('side', OrderSide::Buy)->sum('amount_usd');
        $sellUsd = (float) $trades->where('side', OrderSide::Sell)->sum('amount_usd');
        $metrics = [
            'buy_usd' => $buyUsd,
            'sell_usd' => $sellUsd,
            'trades' => $trades->count(),
            'wallets' => $trades->pluck('wallet')->filter()->unique()->count(),
            'net_usd' => $buyUsd - $sellUsd,
        ];

        if ($trades->isEmpty()) {
            return ['score' => 50, 'reasons' => ["Tidak ada transaksi whale dalam {$hours} jam terakhir."], 'metrics' => $metrics];
        }

        $ratio = ($buyUsd - $sellUsd) / ($buyUsd + $sellUsd);
        $score = (int) round(50 + 45 * $ratio);

        $direction = match (true) {
            $ratio > 0.2 => 'akumulasi (whale lebih banyak membeli)',
            $ratio < -0.2 => 'distribusi (whale lebih banyak menjual)',
            default => 'seimbang',
        };

        return [
            'score' => max(0, min(100, $score)),
            'reasons' => [sprintf(
                'Whale %s: beli $%s vs jual $%s dari %d transaksi.',
                $direction,
                number_format($buyUsd),
                number_format($sellUsd),
                $trades->count(),
            )],
            'metrics' => $metrics,
        ];
    }
}
