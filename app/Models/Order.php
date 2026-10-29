<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'token_id', 'signal_id', 'side', 'type', 'status', 'amount_usd', 'quantity',
    'trigger_price', 'filled_price', 'filled_quantity', 'total_usd', 'fee_usd', 'slippage_pct',
    'realized_pnl', 'note', 'filled_at',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'amount_usd' => 'float',
            'quantity' => 'float',
            'trigger_price' => 'float',
            'filled_price' => 'float',
            'filled_quantity' => 'float',
            'total_usd' => 'float',
            'fee_usd' => 'float',
            'slippage_pct' => 'float',
            'realized_pnl' => 'float',
            'filled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Token, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    /**
     * @return BelongsTo<Signal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }
}
