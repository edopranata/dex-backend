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
     * Drop 15m candles after 30 days; higher timeframes are kept for long-term indicators.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('timeframe', '15m')->where('open_time', '<', now()->subDays(30)->timestamp);
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
