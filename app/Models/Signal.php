<?php

namespace App\Models;

use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Observers\SignalObserver;
use Database\Factories\SignalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'token_id', 'analysis_id', 'recommendation', 'status', 'entry_low', 'entry_high', 'stop_loss',
    'take_profit_1', 'take_profit_2', 'take_profit_3', 'entry_price', 'exit_price', 'max_tp_hit',
    'result_r', 'net_r', 'cost_r', 'strategy_version', 'entered_at', 'closed_at',
])]
#[ObservedBy(SignalObserver::class)]
class Signal extends Model
{
    /** @use HasFactory<SignalFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'max_tp_hit' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recommendation' => Recommendation::class,
            'status' => SignalStatus::class,
            'entry_low' => 'float',
            'entry_high' => 'float',
            'stop_loss' => 'float',
            'take_profit_1' => 'float',
            'take_profit_2' => 'float',
            'take_profit_3' => 'float',
            'entry_price' => 'float',
            'exit_price' => 'float',
            'max_tp_hit' => 'integer',
            'result_r' => 'float',
            'net_r' => 'float',
            'cost_r' => 'float',
            'entered_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Signal>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [SignalStatus::Pending, SignalStatus::Active]);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function takeProfits(): array
    {
        return [$this->take_profit_1, $this->take_profit_2, $this->take_profit_3];
    }

    /**
     * @return BelongsTo<Token, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    /**
     * @return BelongsTo<Analysis, $this>
     */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
