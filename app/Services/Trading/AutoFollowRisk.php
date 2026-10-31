<?php

namespace App\Services\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Signal;
use App\Models\User;

/**
 * Risk rules of the auto-follow paper account: a limit on open positions (backtest: 3 positions keep
 * the worst drawdown near 15R, against 41R without a limit) and a position size that loses a fixed
 * share of equity at the stop, capped so the order stays small against equity and pool liquidity.
 */
class AutoFollowRisk
{
    public function __construct(private PortfolioSummary $portfolio) {}

    /**
     * Open positions plus limit buys still waiting for their entry; 0 means no limit.
     */
    public function hasRoom(User $account): bool
    {
        $limit = (int) config('market.autofollow.max_open_positions');

        if ($limit <= 0) {
            return true;
        }

        $waiting = $account->orders()->where('side', OrderSide::Buy)->where('status', OrderStatus::Pending)->count();

        return $account->positions()->where('quantity', '>', 0)->count() + $waiting < $limit;
    }

    /**
     * Order size in USD, or null when the capped size is below the minimum order.
     */
    public function amountFor(User $account, Signal $signal): ?float
    {
        $config = config('market.autofollow');
        $riskPct = (float) $config['risk_pct'];

        if ($riskPct <= 0) {
            return (float) $config['amount_usd'];
        }

        $entry = (float) $signal->entry_high;
        $stopDistance = $entry > 0 ? ($entry - (float) $signal->stop_loss) / $entry : 0.0;

        if ($stopDistance <= 0) {
            return null;
        }

        $equity = $this->portfolio->for($account)['equity_usd'];
        $liquidity = (float) $signal->token->liquidity_usd;

        $amount = min(
            $equity * $riskPct / 100 / $stopDistance,
            $equity * (float) $config['max_position_pct'] / 100,
            $account->balance_usd,
            $liquidity > 0 ? $liquidity * (float) $config['max_liquidity_pct'] / 100 : INF,
        );

        return $amount >= (float) config('market.simulator.min_order_usd') ? round($amount, 2) : null;
    }
}
