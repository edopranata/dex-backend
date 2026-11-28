<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One position of a strategy account on one token: a limit entry still waiting (pending), held (open), sold (closed),
 * or a limit that never filled (cancelled).
 */
#[Fillable([
    'strategy_account_id', 'token_id', 'status', 'quantity', 'remaining', 'entry_price', 'cost_basis', 'stop', 'take_profit',
    'state', 'entry_reason', 'opened_at', 'checked_until', 'closed_at', 'exit_reason', 'gross_pnl', 'costs', 'net_pnl',
])]
class StrategyPosition extends Model
{
    public const PENDING = 'pending';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'remaining' => 'float',
            'entry_price' => 'float',
            'cost_basis' => 'float',
            'stop' => 'float',
            'take_profit' => 'float',
            'state' => 'array',
            'opened_at' => 'datetime',
            'checked_until' => 'integer',
            'closed_at' => 'datetime',
            'gross_pnl' => 'float',
            'costs' => 'float',
            'net_pnl' => 'float',
        ];
    }

    /**
     * @param  Builder<StrategyPosition>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::OPEN);
    }

    /**
     * @return BelongsTo<StrategyAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(StrategyAccount::class, 'strategy_account_id');
    }

    /**
     * @return BelongsTo<Token, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }
}
