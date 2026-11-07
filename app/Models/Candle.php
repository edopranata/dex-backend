<?php

namespace App\Models;

use Database\Factories\CandleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['token_id', 'timeframe', 'open_time', 'open', 'high', 'low', 'close', 'volume'])]
class Candle extends Model
{
    /** @use HasFactory<CandleFactory> */
    use HasFactory, MassPrunable;

    public $timestamps = false;

    /**
     * Drop old 15m candles; higher timeframes are kept for long-term indicators. 15m is the source the others
     * are built from, so it is kept for months rather than weeks.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('timeframe', '15m')->where('open_time', '<', now()->subDays((int) config('market.candles.keep_15m_days'))->timestamp);
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
            'volume' => 'float',
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
