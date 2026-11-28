<?php

namespace App\Services\Strategies\Rules;

use App\Models\StrategyPosition;
use App\Models\Token;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\StrategyContext;

/**
 * The long side of FutureSim's btc_trend: hold the traded benchmark while the signal benchmark's daily close is above its
 * SMA50, sold when it closes below; decided when the UTC day closes. A DEX swap cannot go short, so the account is in
 * cash while FutureSim's is short. No stop; the position is stopless_share of equity, as FutureSim's.
 */
class TrendLong extends BaseStrategy
{
    /** The benchmark the account buys (config market.benchmarks). */
    protected const TRADED = 'BTC';

    /** The benchmark whose daily close against its SMA50 decides. */
    protected const SIGNAL = 'BTC';

    public function timeframe(): string
    {
        return '1d';
    }

    public function maxHours(): int
    {
        return 0;
    }

    public function tokens(array $eligible, StrategyContext $context): array
    {
        $token = $context->benchmark(static::TRADED);

        return $token === null ? [] : [$token];
    }

    public function entry(Token $token, StrategyContext $context): ?array
    {
        return $context->aboveDailySma50(static::SIGNAL) === true
            ? ['reason' => static::SIGNAL.' close harian di atas SMA50']
            : null;
    }

    public function manage(StrategyPosition $position, StrategyContext $context): ?array
    {
        return $context->dayClosed() && $context->aboveDailySma50(static::SIGNAL) === false
            ? ['fraction' => 1.0, 'reason' => static::SIGNAL.' turun ke bawah SMA50']
            : null;
    }
}
