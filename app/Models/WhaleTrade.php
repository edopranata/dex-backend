<?php

namespace App\Models;

use App\Enums\OrderSide;
use Database\Factories\WhaleTradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['token_id', 'external_id', 'tx_hash', 'wallet', 'side', 'amount_usd', 'token_amount', 'price_usd', 'traded_at'])]
class WhaleTrade extends Model
{
    /** @use HasFactory<WhaleTradeFactory> */
    use HasFactory, MassPrunable;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('traded_at', '<', now()->subDays(60));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'amount_usd' => 'float',
            'token_amount' => 'float',
            'price_usd' => 'float',
            'traded_at' => 'datetime',
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
