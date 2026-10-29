<?php

namespace App\Models;

use Database\Factories\TokenSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['token_id', 'price_usd', 'liquidity_usd', 'volume_h24', 'fdv', 'captured_at'])]
class TokenSnapshot extends Model
{
    /** @use HasFactory<TokenSnapshotFactory> */
    use HasFactory, MassPrunable;

    public $timestamps = false;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('captured_at', '<', now()->subDays(30));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_usd' => 'float',
            'liquidity_usd' => 'float',
            'volume_h24' => 'float',
            'fdv' => 'float',
            'captured_at' => 'datetime',
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
