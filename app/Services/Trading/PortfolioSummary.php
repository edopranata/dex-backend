<?php

namespace App\Services\Trading;

use App\Enums\OrderStatus;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Equity and P&L figures shared by the portfolio page, the sidebar and Telegram.
 */
class PortfolioSummary
{
    /**
     * @param  Collection<int, Position>|null  $positions  Open positions with their token, when already loaded.
     * @return array{balance_usd: float, holdings_value_usd: float, equity_usd: float, starting_balance: float, total_pnl: float, total_pnl_pct: float, unrealized_pnl: float, realized_pnl: float, fees_paid: float, trades: int, open_positions: int, pending_orders: int}
     */
    public function for(User $user, ?Collection $positions = null): array
    {
        $positions ??= $user->positions()->with('token')->where('quantity', '>', 0)->get();

        $holdingsValue = (float) $positions->sum(fn (Position $p): float => $p->quantity * (float) $p->token->price_usd);
        $costBasis = (float) $positions->sum(fn (Position $p): float => $p->quantity * $p->avg_price);
        $equity = $user->balance_usd + $holdingsValue;
        $startingBalance = (float) config('market.simulator.starting_balance');
        $filled = $user->orders()->where('status', OrderStatus::Filled);

        return [
            'balance_usd' => $user->balance_usd,
            'holdings_value_usd' => $holdingsValue,
            'equity_usd' => $equity,
            'starting_balance' => $startingBalance,
            'total_pnl' => $equity - $startingBalance,
            'total_pnl_pct' => ($equity - $startingBalance) / $startingBalance * 100,
            'unrealized_pnl' => $holdingsValue - $costBasis,
            'realized_pnl' => (float) $user->positions()->sum('realized_pnl'),
            'fees_paid' => (float) (clone $filled)->sum('fee_usd'),
            'trades' => (clone $filled)->count(),
            'open_positions' => $positions->count(),
            'pending_orders' => $user->orders()->where('status', OrderStatus::Pending)->count(),
        ];
    }
}
