<?php

namespace App\Models;

use App\Enums\WatchlistAction;
use App\Observers\WatchlistEventObserver;
use Database\Factories\WatchlistEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['token_id', 'action', 'reason', 'score'])]
#[ObservedBy(WatchlistEventObserver::class)]
class WatchlistEvent extends Model
{
    /** @use HasFactory<WatchlistEventFactory> */
    use HasFactory, MassPrunable;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(60));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => WatchlistAction::class,
            'score' => 'integer',
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
