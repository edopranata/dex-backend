<?php

namespace App\Models;

use App\Enums\CandidateStatus;
use Database\Factories\TokenCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'token_id', 'chain_id', 'pair_address', 'token_address', 'symbol', 'name', 'quote_symbol', 'image_url', 'url',
    'sources', 'price_usd', 'price_change_h1', 'price_change_h6', 'price_change_h24', 'volume_h24', 'liquidity_usd',
    'fdv', 'buys_h24', 'sells_h24', 'buyers_h24', 'sellers_h24', 'news_mentions', 'whale_buy_usd', 'whale_sell_usd',
    'whale_trades', 'whale_buy_wallets', 'whale_checked_at', 'security_score', 'is_honeypot', 'security_flags',
    'security_checked_at', 'trend_score', 'attention_score', 'whale_score', 'discovery_score', 'status', 'reasons',
    'pair_created_at', 'first_seen_at', 'last_seen_at',
])]
class TokenCandidate extends Model
{
    /** @use HasFactory<TokenCandidateFactory> */
    use HasFactory, MassPrunable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'candidate',
        'news_mentions' => 0,
        'trend_score' => 0,
        'attention_score' => 0,
    ];

    /**
     * Forget candidates that stopped trending a week ago.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('last_seen_at', '<', now()->subDays(7))->whereNull('token_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CandidateStatus::class,
            'sources' => 'array',
            'reasons' => 'array',
            'security_flags' => 'array',
            'price_usd' => 'float',
            'price_change_h1' => 'float',
            'price_change_h6' => 'float',
            'price_change_h24' => 'float',
            'volume_h24' => 'float',
            'liquidity_usd' => 'float',
            'fdv' => 'float',
            'buys_h24' => 'integer',
            'sells_h24' => 'integer',
            'buyers_h24' => 'integer',
            'sellers_h24' => 'integer',
            'news_mentions' => 'integer',
            'whale_buy_usd' => 'float',
            'whale_sell_usd' => 'float',
            'whale_trades' => 'integer',
            'whale_buy_wallets' => 'integer',
            'security_score' => 'integer',
            'is_honeypot' => 'boolean',
            'trend_score' => 'integer',
            'attention_score' => 'integer',
            'whale_score' => 'integer',
            'discovery_score' => 'integer',
            'whale_checked_at' => 'datetime',
            'security_checked_at' => 'datetime',
            'pair_created_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Token, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }
}
