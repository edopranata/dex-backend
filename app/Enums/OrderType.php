<?php

namespace App\Enums;

enum OrderType: string
{
    case Market = 'market';
    case Limit = 'limit';
    case StopLoss = 'stop_loss';
    case TakeProfit = 'take_profit';

    /**
     * Whether a pending order of this type should fill at the given price.
     */
    public function isTriggered(OrderSide $side, float $triggerPrice, float $price): bool
    {
        return match ($this) {
            self::Market => true,
            self::Limit => $side === OrderSide::Buy ? $price <= $triggerPrice : $price >= $triggerPrice,
            self::StopLoss => $price <= $triggerPrice,
            self::TakeProfit => $price >= $triggerPrice,
        };
    }
}
