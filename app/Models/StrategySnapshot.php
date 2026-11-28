<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Hourly equity of a strategy account (cash plus open positions at the last price), for its drawdown.
 */
#[Fillable(['strategy_account_id', 'equity', 'cash', 'taken_at'])]
class StrategySnapshot extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['equity' => 'float', 'cash' => 'float', 'taken_at' => 'datetime'];
    }
}
