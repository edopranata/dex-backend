<?php

namespace App\Enums;

enum Recommendation: string
{
    case StrongBuy = 'STRONG_BUY';
    case Buy = 'BUY';
    case Hold = 'HOLD';
    case Sell = 'SELL';
    case StrongSell = 'STRONG_SELL';
    case Avoid = 'AVOID';

    public function isBullish(): bool
    {
        return in_array($this, [self::StrongBuy, self::Buy], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::StrongBuy => 'Strong Buy',
            self::Buy => 'Buy',
            self::Hold => 'Hold',
            self::Sell => 'Sell',
            self::StrongSell => 'Strong Sell',
            self::Avoid => 'Hindari',
        };
    }
}
