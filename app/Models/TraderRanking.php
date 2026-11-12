<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One wallet's place on a provider's list of most profitable traders on one day. Recording only (Fase R): the
 * lists are kept to measure whether wallets stay on them, before anything follows such wallets.
 */
#[Fillable(['source', 'chain_id', 'wallet', 'period', 'sort_by', 'list_rank', 'pnl_usd', 'realized_pnl_usd', 'unrealized_pnl_usd', 'volume_usd', 'trade_count', 'score', 'seen_on', 'created_at'])]
class TraderRanking extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('seen_on', '<', now()->subDays((int) config('market.radar.retention_days'))->toDateString());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seen_on' => 'date',
            'created_at' => 'datetime',
            'list_rank' => 'integer',
            'trade_count' => 'integer',
            'pnl_usd' => 'float',
            'realized_pnl_usd' => 'float',
            'unrealized_pnl_usd' => 'float',
            'volume_usd' => 'float',
            'score' => 'float',
        ];
    }
}
