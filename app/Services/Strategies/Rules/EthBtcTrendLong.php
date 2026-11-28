<?php

namespace App\Services\Strategies\Rules;

/**
 * The long side of FutureSim's eth_btc_trend: hold WETH while BTC's daily close is above its SMA50.
 */
class EthBtcTrendLong extends TrendLong
{
    protected const TRADED = 'ETH';

    protected const SIGNAL = 'BTC';
}
