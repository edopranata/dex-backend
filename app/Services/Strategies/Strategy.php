<?php

namespace App\Services\Strategies;

use App\Models\StrategyPosition;
use App\Models\Token;

/**
 * A FutureSim long rule on one DEX strategy account. The runner checks stops, targets and limit entries on every new
 * 15m candle; entry() and manage() are asked once per token for each hour (or day) whose candle has closed and is
 * stored.
 */
interface Strategy
{
    /**
     * '1h' (decided on hour closes; 4h rules check StrategyContext::fourHourClosed) or '1d' (on day closes).
     */
    public function timeframe(): string;

    /**
     * Longest a position may stay open; 0 for no limit.
     */
    public function maxHours(): int;

    /**
     * The tokens the rule looks at, from the eligible ones the runner passes (by default all of them).
     *
     * @param  list<Token>  $eligible
     * @return list<Token>
     */
    public function tokens(array $eligible, StrategyContext $context): array;

    /**
     * An entry on the candle that just closed, or null. Market entries give a stop price (or none) and a target as a
     * multiple of the risk (take_profit_r), a share above (take_profit_pct) or a price (take_profit); limit entries give
     * limit, stop, take_profit, expires_at and cancel_price. share sets the size of a position without a stop.
     *
     * @return array{reason: string, stop?: float, take_profit_r?: float, take_profit_pct?: float, take_profit?: float, limit?: float, expires_at?: int, cancel_price?: float, share?: float, state?: array<string, mixed>}|null
     */
    public function entry(Token $token, StrategyContext $context): ?array;

    /**
     * May move the stop or change the state; returns a share of the remaining position to sell now.
     *
     * @return array{fraction: float, reason: string}|null
     */
    public function manage(StrategyPosition $position, StrategyContext $context): ?array;
}
