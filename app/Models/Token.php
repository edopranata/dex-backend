<?php

namespace App\Models;

use Database\Factories\TokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'chain_id', 'pair_address', 'token_address', 'symbol', 'name', 'quote_symbol', 'dex_id',
    'image_url', 'url', 'price_usd', 'price_change_m5', 'price_change_h1', 'price_change_h6',
    'price_change_h24', 'volume_h24', 'liquidity_usd', 'fdv', 'market_cap', 'txns_h24_buys',
    'txns_h24_sells', 'pair_created_at', 'price_updated_at', 'is_active', 'is_pinned', 'source', 'discovered_at',
])]
class Token extends Model
{
    /** @use HasFactory<TokenFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_usd' => 'float',
            'price_change_m5' => 'float',
            'price_change_h1' => 'float',
            'price_change_h6' => 'float',
            'price_change_h24' => 'float',
            'volume_h24' => 'float',
            'liquidity_usd' => 'float',
            'fdv' => 'float',
            'market_cap' => 'float',
            'txns_h24_buys' => 'integer',
            'txns_h24_sells' => 'integer',
            'pair_created_at' => 'datetime',
            'price_updated_at' => 'datetime',
            'is_active' => 'boolean',
            'is_pinned' => 'boolean',
            'discovered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Token $token): void {
            $token->slug ??= static::slugFor($token->symbol, $token->chain_id);
        });
    }

    /**
     * Readable, unique URL key such as "JOB-SOL"; a symbol already used on the chain gets "-2", "-3", ...
     */
    public static function slugFor(string $symbol, string $chainId): string
    {
        $symbol = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]+/', '', Str::ascii($symbol)), 0, 20)) ?: 'TOKEN';
        $base = $symbol.'-'.Str::upper(config("market.chains.{$chainId}.short", $chainId));

        $slug = $base;
        for ($suffix = 2; static::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Token pages are addressed by slug; numeric ids keep working for old links.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Token
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        return ctype_digit((string) $value)
            ? static::query()->find($value)
            : static::query()->where('slug', Str::upper((string) $value))->first();
    }

    /**
     * @param  Builder<Token>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return HasMany<Candle, $this>
     */
    public function candles(): HasMany
    {
        return $this->hasMany(Candle::class);
    }

    /**
     * @return HasMany<TokenSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(TokenSnapshot::class);
    }

    /**
     * @return HasMany<WhaleTrade, $this>
     */
    public function whaleTrades(): HasMany
    {
        return $this->hasMany(WhaleTrade::class);
    }

    /**
     * @return BelongsToMany<NewsItem, $this>
     */
    public function newsItems(): BelongsToMany
    {
        return $this->belongsToMany(NewsItem::class);
    }

    /**
     * @return HasMany<TokenSecurityCheck, $this>
     */
    public function securityChecks(): HasMany
    {
        return $this->hasMany(TokenSecurityCheck::class);
    }

    /**
     * @return HasOne<TokenSecurityCheck, $this>
     */
    public function latestSecurityCheck(): HasOne
    {
        return $this->hasOne(TokenSecurityCheck::class)->latestOfMany('checked_at');
    }

    /**
     * @return HasMany<Analysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }

    /**
     * @return HasOne<Analysis, $this>
     */
    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(Analysis::class)->latestOfMany();
    }

    /**
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    /**
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<WatchlistEvent, $this>
     */
    public function watchlistEvents(): HasMany
    {
        return $this->hasMany(WatchlistEvent::class);
    }

    public function isPriceStale(int $maxAgeSeconds): bool
    {
        return $this->price_usd === null
            || $this->price_updated_at === null
            || $this->price_updated_at->lt(now()->subSeconds($maxAgeSeconds));
    }
}
