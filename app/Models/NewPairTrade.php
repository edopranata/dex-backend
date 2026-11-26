<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paper trade of one New Token Hunter strategy on one Token Baru pool (at most one per strategy per pool).
 * realized_pct holds the part already sold at take-profit levels, in percent of the whole position.
 */
#[Fillable([
    'new_pair_id', 'strategy', 'status', 'entry_at', 'entry_price', 'stop_price', 'pool_age_minutes', 'liquidity_usd',
    'tp_hit', 'realized_pct', 'high_price', 'low_price', 'exit_at', 'exit_price', 'exit_reason', 'gross_pct', 'net_pct', 'context',
])]
class NewPairTrade extends Model
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /**
     * Labels in the order the page shows them. first_seen is the comparison: buy every pool when first seen.
     */
    public const STRATEGIES = [
        'first_seen' => 'Beli saat pertama terlihat (pembanding)',
        'early_liquidity' => 'A. Early Liquidity',
        'breakout' => 'B. Breakout',
        'pullback' => 'C. Pullback',
    ];

    /**
     * @return BelongsTo<NewPair, $this>
     */
    public function newPair(): BelongsTo
    {
        return $this->belongsTo(NewPair::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_at' => 'datetime',
            'exit_at' => 'datetime',
            'entry_price' => 'float',
            'stop_price' => 'float',
            'pool_age_minutes' => 'integer',
            'liquidity_usd' => 'float',
            'tp_hit' => 'integer',
            'realized_pct' => 'float',
            'high_price' => 'float',
            'low_price' => 'float',
            'exit_price' => 'float',
            'gross_pct' => 'float',
            'net_pct' => 'float',
            'context' => 'array',
        ];
    }
}
