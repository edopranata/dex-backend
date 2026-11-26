<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Five-minute bar of a Token Baru pool, built from the DEX Screener samples taken once a minute: open, high, low and
 * close are sampled prices (wicks between samples are missed), and volume and trades are DEX Screener's rolling
 * 5-minute figures at the bar's last sample.
 */
#[Fillable(['new_pair_id', 'open_time', 'open', 'high', 'low', 'close', 'liquidity_usd', 'volume_m5', 'buys_m5', 'sells_m5', 'samples'])]
class NewPairBar extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * @return BelongsTo<NewPair, $this>
     */
    public function newPair(): BelongsTo
    {
        return $this->belongsTo(NewPair::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('open_time', '<', now()->subDays((int) config('market.new_pairs.bars_retention_days'))->getTimestamp());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'open_time' => 'integer',
            'open' => 'float',
            'high' => 'float',
            'low' => 'float',
            'close' => 'float',
            'liquidity_usd' => 'float',
            'volume_m5' => 'float',
            'buys_m5' => 'integer',
            'sells_m5' => 'integer',
            'samples' => 'integer',
        ];
    }
}
