<?php

namespace App\Services\Strategies;

use App\Models\StrategyAccount;
use App\Models\StrategyPosition;
use App\Models\Token;
use App\Services\Trading\TradingService;
use Illuminate\Support\Facades\DB;

/**
 * Paper fills of the strategy accounts at DEX costs: every swap pays the simulator fee (market.simulator.fee_rate), the
 * price impact of its size against the pool (TradingService::priceImpact) and the chain's gas per swap
 * (market.autofollow.gas_usd). Sizes follow the auto-follow accounts: with a stop, the loss at the stop including
 * costs is risk_pct of equity; without, a share of equity; at most max_position_pct of equity and max_liquidity_pct of
 * the pool.
 */
class StrategyBroker
{
    /** Smallest swap the accounts make (USD). */
    private const MIN_USD = 10.0;

    /**
     * A market entry at `price`, or a pending limit entry; null when it cannot be sized.
     *
     * @param  array<string, mixed>  $entry  As Strategy::entry() returns it.
     */
    public function open(StrategyAccount $account, Token $token, array $entry, float $price, float $equity, int $checkedUntil): ?StrategyPosition
    {
        if (isset($entry['limit'])) {
            return $this->placeLimit($account, $token, $entry, $equity, $checkedUntil);
        }

        $stop = isset($entry['stop']) ? (float) $entry['stop'] : null;
        if ($price <= 0 || ($stop !== null && ($stop <= 0 || $stop >= $price))) {
            return null;  // the price already went through the stop
        }

        $target = match (true) {
            isset($entry['take_profit_r']) && $stop !== null => $price + $entry['take_profit_r'] * ($price - $stop),
            isset($entry['take_profit_pct']) => $price * (1 + $entry['take_profit_pct']),
            isset($entry['take_profit']) => (float) $entry['take_profit'],
            default => null,
        };

        $notional = $stop !== null
            ? $this->riskNotional($token, $price, $stop, $equity)
            : (float) ($entry['share'] ?? config('market.strategy_accounts.stopless_share')) * $equity;
        $notional = $this->capped($account, $token, $notional, $stop !== null ? $equity : null);

        if ($notional < self::MIN_USD) {
            return null;
        }

        return DB::transaction(function () use ($account, $token, $entry, $price, $stop, $target, $notional, $checkedUntil): StrategyPosition {
            $costs = $this->swapCost($token, $notional);
            $quantity = $notional / $price;
            $account->update(['cash' => $account->cash - $notional - $costs, 'fees_paid' => $account->fees_paid + $costs]);

            return StrategyPosition::query()->create([
                'strategy_account_id' => $account->id,
                'token_id' => $token->id,
                'status' => StrategyPosition::OPEN,
                'quantity' => $quantity,
                'remaining' => $quantity,
                'entry_price' => $price,
                'cost_basis' => $notional + $costs,
                'stop' => $stop,
                'take_profit' => $target,
                // The stop and risk at entry are kept for R per trade, since rules may move the stop later.
                'state' => [...($entry['state'] ?? []), ...($stop === null ? [] : ['initial_stop' => $stop, 'initial_risk' => $quantity * ($price - $stop)])],
                'entry_reason' => mb_substr((string) $entry['reason'], 0, 255),
                'opened_at' => now(),
                'checked_until' => $checkedUntil,
                'costs' => $costs,
                'net_pnl' => -$costs,
            ]);
        });
    }

    /**
     * Fills a pending limit at `price` (the limit, or the open when the candle opened below it), with the quantity
     * planned from the limit and the stop when it was placed, as FutureSim's swing Fibonacci does.
     */
    public function fillLimit(StrategyPosition $position, float $price, int $at): bool
    {
        $account = $position->account;
        $token = $position->token;
        $notional = min($position->quantity * $price, max(0.0, $account->cash - $this->gas($token)));

        if ($notional < self::MIN_USD || $position->stop === null || $position->stop >= $price) {
            $position->update(['status' => StrategyPosition::CANCELLED, 'closed_at' => now()->setTimestamp($at), 'exit_reason' => 'Order limit batal: tidak bisa diisi']);

            return false;
        }

        DB::transaction(function () use ($position, $account, $token, $price, $notional, $at): void {
            $costs = $this->swapCost($token, $notional);
            $quantity = $notional / $price;
            $account->update(['cash' => $account->cash - $notional - $costs, 'fees_paid' => $account->fees_paid + $costs]);
            $position->fill([
                'status' => StrategyPosition::OPEN,
                'quantity' => $quantity,
                'remaining' => $quantity,
                'entry_price' => $price,
                'cost_basis' => $notional + $costs,
                'state' => [...$position->state, 'filled_at' => $at, 'initial_stop' => $position->stop, 'initial_risk' => $quantity * ($price - $position->stop)],
                'opened_at' => now()->setTimestamp($at),
                'costs' => $costs,
                'net_pnl' => -$costs,
            ])->save();
        });

        return true;
    }

    /**
     * Buys more at `price` (martingale adds); the entry becomes the average.
     */
    public function add(StrategyPosition $position, float $price, float $notional): bool
    {
        $account = $position->account;
        $token = $position->token;
        $notional = min($notional, $token->liquidity_usd > 0 ? (float) config('market.strategy_accounts.max_liquidity_pct') / 100 * $token->liquidity_usd : $notional);
        $costs = $this->swapCost($token, $notional);

        if ($notional < self::MIN_USD || $account->cash < $notional + $costs) {
            return false;
        }

        DB::transaction(function () use ($position, $account, $price, $notional, $costs): void {
            $quantity = $notional / $price;
            $total = $position->remaining + $quantity;
            $account->update(['cash' => $account->cash - $notional - $costs, 'fees_paid' => $account->fees_paid + $costs]);
            $position->fill([
                'quantity' => $position->quantity + $quantity,
                'entry_price' => ($position->remaining * $position->entry_price + $quantity * $price) / $total,
                'remaining' => $total,
                'cost_basis' => $position->cost_basis + $notional + $costs,
                'costs' => $position->costs + $costs,
                'net_pnl' => $position->net_pnl - $costs,
            ])->save();
        });

        return true;
    }

    /**
     * Sells a share of what is left at `price`.
     */
    public function close(StrategyPosition $position, float $fraction, float $price, string $reason, ?int $at = null): void
    {
        DB::transaction(function () use ($position, $fraction, $price, $reason, $at): void {
            $account = StrategyAccount::query()->lockForUpdate()->findOrFail($position->strategy_account_id);
            $quantity = $fraction >= 1 ? $position->remaining : $position->remaining * $fraction;
            $proceeds = $quantity * $price;
            $costs = $this->swapCost($position->token, $proceeds);
            $gross = $position->gross_pnl + $quantity * ($price - $position->entry_price);
            $remaining = $position->remaining - $quantity;
            $closed = $remaining <= $position->quantity * 1e-9;

            $account->update(['cash' => $account->cash + $proceeds - $costs, 'fees_paid' => $account->fees_paid + $costs]);
            $position->fill([
                'remaining' => $closed ? 0.0 : $remaining,
                'gross_pnl' => $gross,
                'costs' => $position->costs + $costs,
                'net_pnl' => $gross - $position->costs - $costs,
                'status' => $closed ? StrategyPosition::CLOSED : $position->status,
                'closed_at' => $closed ? now()->setTimestamp($at ?? now()->getTimestamp()) : null,
                'exit_reason' => $closed ? $reason : $position->exit_reason,
                'state' => [...$position->state, 'sales' => [...($position->state['sales'] ?? []), ['at' => $at ?? now()->getTimestamp(), 'price' => $price, 'quantity' => $quantity, 'reason' => $reason]]],
            ])->save();
        });
    }

    /**
     * Fee, price impact and gas of one swap of `usd`.
     */
    public function swapCost(Token $token, float $usd): float
    {
        return $usd * ((float) config('market.simulator.fee_rate') + TradingService::priceImpact($usd, $token->liquidity_usd)) + $this->gas($token);
    }

    /**
     * Cash plus the open positions at the token's last price.
     */
    public function equity(StrategyAccount $account): float
    {
        $positions = StrategyPosition::query()->where('strategy_account_id', $account->id)->open()->with('token')->get();

        return $account->cash + $positions->sum(fn (StrategyPosition $position): float => $position->remaining * $this->lastPrice($position->token, $position->entry_price));
    }

    /**
     * The token's live price while it is fresh (Market tokens, every 15 seconds), else the last stored 15m close.
     */
    public function lastPrice(Token $token, float $fallback): float
    {
        if ($token->price_usd > 0 && $token->price_updated_at !== null && $token->price_updated_at->gte(now()->subMinutes(10))) {
            return (float) $token->price_usd;
        }

        $close = $token->candles()->where('timeframe', '15m')->orderByDesc('open_time')->value('close');

        return $close !== null ? (float) $close : $fallback;
    }

    private function placeLimit(StrategyAccount $account, Token $token, array $entry, float $equity, int $checkedUntil): ?StrategyPosition
    {
        $limit = (float) $entry['limit'];
        $stop = (float) $entry['stop'];
        if ($limit <= 0 || $stop <= 0 || $stop >= $limit) {
            return null;
        }

        $notional = $this->capped($account, $token, $this->riskNotional($token, $limit, $stop, $equity), $equity);
        if ($notional < self::MIN_USD) {
            return null;
        }

        return StrategyPosition::query()->create([
            'strategy_account_id' => $account->id,
            'token_id' => $token->id,
            'status' => StrategyPosition::PENDING,
            'quantity' => $notional / $limit,
            'remaining' => 0,
            'entry_price' => $limit,
            'stop' => $stop,
            'take_profit' => $entry['take_profit'] ?? null,
            'state' => [...($entry['state'] ?? []), 'limit' => $limit, 'expires_at' => $entry['expires_at'] ?? null, 'cancel_price' => $entry['cancel_price'] ?? null],
            'entry_reason' => mb_substr((string) $entry['reason'], 0, 255),
            'opened_at' => now(),
            'checked_until' => $checkedUntil,
        ]);
    }

    /**
     * Notional whose loss at the stop, with the fee, price impact and gas of both swaps, is risk_pct of equity.
     */
    private function riskNotional(Token $token, float $price, float $stop, float $equity): float
    {
        $risk = (float) config('market.strategy_accounts.risk_pct') / 100 * $equity - 2 * $this->gas($token);
        $fee = (float) config('market.simulator.fee_rate');
        $notional = $risk / (($price - $stop) / $price);

        // The impact grows with the size, so the size is settled in a few rounds.
        for ($round = 0; $round < 3; $round++) {
            $exit = $notional * $stop / $price;
            $perUsd = ($price - $stop) / $price + $fee + TradingService::priceImpact($notional, $token->liquidity_usd)
                + ($fee + TradingService::priceImpact($exit, $token->liquidity_usd)) * $stop / $price;
            $notional = max(0.0, $risk / $perUsd);
        }

        return $notional;
    }

    /**
     * The size within the cash, the pool share and, for positions with a stop, the share of equity (`equity`).
     */
    private function capped(StrategyAccount $account, Token $token, float $notional, ?float $equity): float
    {
        $caps = [
            $notional,
            // What the cash pays for after this swap's own costs.
            max(0.0, ($account->cash - $this->gas($token)) / (1 + (float) config('market.simulator.fee_rate') + 0.01)),
        ];
        if ($equity !== null) {
            $caps[] = (float) config('market.strategy_accounts.max_position_pct') / 100 * $equity;
        }
        if ($token->liquidity_usd > 0) {
            $caps[] = (float) config('market.strategy_accounts.max_liquidity_pct') / 100 * $token->liquidity_usd;
        }

        return min($caps);
    }

    private function gas(Token $token): float
    {
        return (float) config("market.autofollow.gas_usd.{$token->chain_id}", 0);
    }
}
