<?php

namespace App\Services\Strategies\Rules;

use App\Models\Token;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\StrategyContext;

/**
 * Benchmark as in FutureSim: buy cbBTC (the BTC benchmark pool) once with the whole account and hold it.
 */
class HoldBtc extends BaseStrategy
{
    public function maxHours(): int
    {
        return 0;
    }

    public function tokens(array $eligible, StrategyContext $context): array
    {
        $btc = $context->benchmark('BTC');

        return $btc === null ? [] : [$btc];
    }

    public function entry(Token $token, StrategyContext $context): ?array
    {
        return ['reason' => 'Pembanding: tahan BTC (cbBTC)', 'share' => 1.0, 'state' => ['once_key' => 'hold']];
    }
}
