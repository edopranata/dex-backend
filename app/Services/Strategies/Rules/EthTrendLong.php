<?php

namespace App\Services\Strategies\Rules;

/**
 * The long side of FutureSim's eth_trend: hold WETH while its own daily close is above its SMA50.
 */
class EthTrendLong extends TrendLong
{
    protected const TRADED = 'ETH';

    protected const SIGNAL = 'ETH';
}
