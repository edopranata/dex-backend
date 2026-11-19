<?php

namespace App\Services\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Signal;
use App\Models\User;

/**
 * Risk rules of the auto-follow paper account: a limit on open positions (backtest: 3 positions keep
 * the worst drawdown near 15R, against 41R without a limit), a limit on the loss per day, and a position
 * size that loses a fixed share of equity at the stop, capped so the order stays small against equity
 * and pool liquidity. The loss at the stop includes the swap fee, gas and price impact on the way in and
 * out, as TradingService fills them: without them the loss at a full stop was a median 1.10x the plan
 * (audit 17 Nov 2026, 93 lots). A gap below the stop is not foreseen.
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
     * Profit or loss realized today in local time. Open positions do not count: their stops still decide.
     */
    public function realizedToday(User $account): float
    {
        $today = now(config('market.backup.timezone'))->startOfDay()->setTimezone(config('app.timezone'));

        return (float) $account->orders()->where('status', OrderStatus::Filled)->where('filled_at', '>=', $today)->sum('realized_pnl');
    }

    /**
     * After this share of equity is lost in one day no new positions open until the next day; 0 means no limit.
     */
    public function dailyLossReached(User $account): bool
    {
        $limitPct = (float) config('market.autofollow.max_daily_loss_pct');

        if ($limitPct <= 0) {
            return false;
        }

        return -$this->realizedToday($account) >= $this->portfolio->for($account)['equity_usd'] * $limitPct / 100;
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
        $target = $equity * $riskPct / 100;
        $gas = $account->gasPerSwapUsd($signal->token);

        // The loss grows almost in proportion to the size, so a few corrections land on the target.
        $sized = $target / $stopDistance;
        for ($step = 0; $step < 6; $step++) {
            $loss = $this->lossAtStop($sized, $entry, (float) $signal->stop_loss, $liquidity, $gas);
            if ($loss <= 0) {
                break;
            }
            $sized *= $target / $loss;
        }

        $amount = min(
            $sized,
            $equity * (float) $config['max_position_pct'] / 100,
            $account->balance_usd,
            $liquidity > 0 ? $liquidity * (float) $config['max_liquidity_pct'] / 100 : INF,
        );

        return $amount >= (float) config('market.simulator.min_order_usd') ? round($amount, 2) : null;
    }

    /**
     * USD lost when a buy of `amount` at `entry` is sold whole at `stop`, filled as TradingService does: fee and gas
     * on each swap, and the price impact of the pool on the way in and out.
     */
    public function lossAtStop(float $amount, float $entry, float $stop, float $liquidity, float $gas): float
    {
        $feeRate = (float) config('market.simulator.fee_rate');
        $spent = $amount - ($amount * $feeRate + $gas);
        $quantity = $spent / ($entry / (1 - TradingService::priceImpact($spent, $liquidity)));
        $gross = $quantity * $stop;
        $proceeds = $gross * (1 - TradingService::priceImpact($gross, $liquidity));

        return $amount - ($proceeds - ($proceeds * $feeRate + $gas));
    }
}
