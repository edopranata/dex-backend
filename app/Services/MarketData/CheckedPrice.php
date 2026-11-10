<?php

namespace App\Services\MarketData;

/**
 * The price an order or signal acts on, with what it was checked against.
 */
final readonly class CheckedPrice
{
    /** DEX Screener's price, with GeckoTerminal agreeing (or the check turned off). */
    public const FEED = 'dexscreener';

    /** GeckoTerminal's price, because DEX Screener was too far from it. */
    public const REFERENCE = 'geckoterminal';

    /** DEX Screener's price, because GeckoTerminal could not be asked. */
    public const UNCHECKED = 'dexscreener_unchecked';

    public function __construct(
        public float $price,
        public ?float $reference,
        public string $source,
    ) {}

    public static function feed(float $price): self
    {
        return new self($price, null, self::FEED);
    }

    public function replacedFeed(): bool
    {
        return $this->source === self::REFERENCE;
    }
}
