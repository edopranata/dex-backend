<?php

namespace App\Enums;

enum SignalStatus: string
{
    /** Waiting for price to enter the entry zone. */
    case Pending = 'pending';
    /** Entry zone reached; tracking SL/TP. */
    case Active = 'active';
    case TakeProfit = 'take_profit';
    case StopLoss = 'stop_loss';
    /** Price fell to the stop loss before entering. */
    case Invalidated = 'invalidated';
    case Expired = 'expired';

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Active], true);
    }
}
