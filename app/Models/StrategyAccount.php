<?php

namespace App\Models;

use Database\Factories\StrategyAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A paper USD account run by one of FutureSim's long strategies on DEX candles (config market.strategy_accounts).
 * cash holds what is not in positions; fees_paid every swap fee, price impact and gas.
 */
#[Fillable(['key', 'label', 'kind', 'starting_balance', 'cash', 'fees_paid', 'is_active', 'started_at'])]
class StrategyAccount extends Model
{
    /** @use HasFactory<StrategyAccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starting_balance' => 'float',
            'cash' => 'float',
            'fees_paid' => 'float',
            'is_active' => 'boolean',
            'started_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StrategyPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(StrategyPosition::class);
    }

    /**
     * @return HasMany<StrategySnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(StrategySnapshot::class);
    }
}
