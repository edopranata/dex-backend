<?php

namespace App\Models;

use Database\Factories\NewPairFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A pool younger than 24 hours, recorded for Token Baru (26 Nov 2026). Separate from Token: it never reaches the
 * Market, signals, orders or auto-follow.
 */
#[Fillable([
    'chain_id', 'pair_address', 'token_address', 'symbol', 'name', 'quote_symbol', 'dex_id', 'url', 'source',
    'pool_created_at', 'first_seen_at', 'first_price_usd', 'first_liquidity_usd', 'price_usd', 'high_price_usd',
    'low_price_usd', 'liquidity_usd', 'fdv', 'volume_m5', 'volume_h1', 'volume_h24', 'buys_m5', 'sells_m5',
    'price_change_m5', 'price_change_h1', 'last_sample_at', 'status', 'end_reason', 'ended_at', 'security_checked_at',
    'security_attempts', 'security_score', 'is_honeypot', 'buy_tax', 'sell_tax', 'top10_percent', 'security_flags',
])]
class NewPair extends Model
{
    /** @use HasFactory<NewPairFactory> */
    use HasFactory;

    public const TRACKING = 'tracking';

    public const DONE = 'done';

    /**
     * @return HasMany<NewPairBar, $this>
     */
    public function bars(): HasMany
    {
        return $this->hasMany(NewPairBar::class);
    }

    /**
     * @return HasMany<NewPairTrade, $this>
     */
    public function trades(): HasMany
    {
        return $this->hasMany(NewPairTrade::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeTracking(Builder $query): void
    {
        $query->where('status', self::TRACKING);
    }

    /**
     * Pool age, counted from the pool's creation (or from when it was first seen when the creation time is unknown).
     */
    public function ageMinutes(?Carbon $at = null): float
    {
        return ($this->pool_created_at ?? $this->first_seen_at)->diffInSeconds($at ?? now(), true) / 60;
    }

    /**
     * Security is settled once checked, or after the last failed attempt (then it stays unknown).
     */
    public function securitySettled(): bool
    {
        return $this->security_checked_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pool_created_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_sample_at' => 'datetime',
            'ended_at' => 'datetime',
            'security_checked_at' => 'datetime',
            'first_price_usd' => 'float',
            'first_liquidity_usd' => 'float',
            'price_usd' => 'float',
            'high_price_usd' => 'float',
            'low_price_usd' => 'float',
            'liquidity_usd' => 'float',
            'fdv' => 'float',
            'volume_m5' => 'float',
            'volume_h1' => 'float',
            'volume_h24' => 'float',
            'buys_m5' => 'integer',
            'sells_m5' => 'integer',
            'price_change_m5' => 'float',
            'price_change_h1' => 'float',
            'security_attempts' => 'integer',
            'security_score' => 'integer',
            'is_honeypot' => 'boolean',
            'buy_tax' => 'float',
            'sell_tax' => 'float',
            'top10_percent' => 'float',
            'security_flags' => 'array',
        ];
    }
}
