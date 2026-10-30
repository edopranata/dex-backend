<?php

namespace App\Models;

use App\Enums\Recommendation;
use Database\Factories\AnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'token_id', 'timeframe', 'recommendation', 'final_score', 'technical_score', 'onchain_score',
    'whale_score', 'sentiment_score', 'fundamental_score', 'price', 'entry_low', 'entry_high',
    'stop_loss', 'take_profit_1', 'take_profit_2', 'take_profit_3', 'indicators', 'reasons',
    'summary', 'summary_provider',
])]
class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory, MassPrunable;

    /**
     * Keep analyses that produced a signal so signal history stays intact.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(14))->whereDoesntHave('signals');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recommendation' => Recommendation::class,
            'final_score' => 'integer',
            'technical_score' => 'integer',
            'onchain_score' => 'integer',
            'whale_score' => 'integer',
            'sentiment_score' => 'integer',
            'fundamental_score' => 'integer',
            'price' => 'float',
            'entry_low' => 'float',
            'entry_high' => 'float',
            'stop_loss' => 'float',
            'take_profit_1' => 'float',
            'take_profit_2' => 'float',
            'take_profit_3' => 'float',
            'indicators' => 'array',
            'reasons' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Token, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    /**
     * @return HasOne<AnalysisEvaluation, $this>
     */
    public function evaluation(): HasOne
    {
        return $this->hasOne(AnalysisEvaluation::class);
    }

    /**
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }
}
