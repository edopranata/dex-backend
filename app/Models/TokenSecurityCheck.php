<?php

namespace App\Models;

use Database\Factories\TokenSecurityCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'token_id', 'provider', 'holder_count', 'top10_percent', 'lp_locked_percent', 'is_honeypot',
    'buy_tax', 'sell_tax', 'liquidity_usd', 'risk_score', 'flags', 'checked_at',
])]
class TokenSecurityCheck extends Model
{
    /** @use HasFactory<TokenSecurityCheckFactory> */
    use HasFactory, MassPrunable;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('checked_at', '<', now()->subDays(30));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'holder_count' => 'integer',
            'top10_percent' => 'float',
            'lp_locked_percent' => 'float',
            'is_honeypot' => 'boolean',
            'buy_tax' => 'float',
            'sell_tax' => 'float',
            'liquidity_usd' => 'float',
            'risk_score' => 'integer',
            'flags' => 'array',
            'checked_at' => 'datetime',
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
