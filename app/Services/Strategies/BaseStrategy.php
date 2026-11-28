<?php

namespace App\Services\Strategies;

use App\Models\StrategyPosition;
use App\Models\Token;

/**
 * Defaults for the 4h rules: every eligible token, no own exit.
 */
abstract class BaseStrategy implements Strategy
{
    public function timeframe(): string
    {
        return '1h';
    }

    public function tokens(array $eligible, StrategyContext $context): array
    {
        return $eligible;
    }

    public function manage(StrategyPosition $position, StrategyContext $context): ?array
    {
        return null;
    }

    abstract public function entry(Token $token, StrategyContext $context): ?array;
}
