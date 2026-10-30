<?php

namespace App\Models;

use App\Enums\Recommendation;
use Database\Factories\AnalysisEvaluationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'analysis_id', 'token_id', 'recommendation', 'final_score', 'scores', 'price', 'analyzed_at',
    'return_1h', 'return_4h', 'return_24h', 'max_up_24h', 'max_down_24h', 'completed_at',
])]
class AnalysisEvaluation extends Model
{
    /** @use HasFactory<AnalysisEvaluationFactory> */
    use HasFactory, MassPrunable;

    /**
     * Horizons in hours, mapped to their column.
     */
    public const HORIZONS = [1 => 'return_1h', 4 => 'return_4h', 24 => 'return_24h'];

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('analyzed_at', '<', now()->subDays(365));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recommendation' => Recommendation::class,
            'final_score' => 'integer',
            'scores' => 'array',
            'price' => 'float',
            'analyzed_at' => 'datetime',
            'return_1h' => 'float',
            'return_4h' => 'float',
            'return_24h' => 'float',
            'max_up_24h' => 'float',
            'max_down_24h' => 'float',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Analysis, $this>
     */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
