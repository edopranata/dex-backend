<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * What a fill was based on, for every account including the system ones. Only ever added to: being able
 * to trust it later is the whole point, so a change or a delete is refused.
 */
#[Fillable(['order_id', 'user_id', 'token_id', 'side', 'type', 'source', 'pair_address', 'trigger_price', 'market_price', 'checked_price', 'filled_price', 'price_at', 'previous_price', 'previous_price_at', 'liquidity_usd', 'created_at'])]
class OrderFillEvidence extends Model
{
    public $timestamps = false;

    protected $table = 'order_fill_evidence';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_price' => 'float',
            'market_price' => 'float',
            'checked_price' => 'float',
            'filled_price' => 'float',
            'previous_price' => 'float',
            'liquidity_usd' => 'float',
            'price_at' => 'datetime',
            'previous_price_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $refuse = fn () => throw new LogicException('Bukti pengisian order tidak boleh diubah atau dihapus.');

        static::updating($refuse);
        static::deleting($refuse);
    }
}
