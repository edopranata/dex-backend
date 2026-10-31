<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'equity_usd', 'balance_usd', 'holdings_usd', 'open_positions', 'sampled_at'])]
class EquitySnapshot extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'equity_usd' => 'float',
            'balance_usd' => 'float',
            'holdings_usd' => 'float',
            'open_positions' => 'integer',
            'sampled_at' => 'datetime',
        ];
    }
}
