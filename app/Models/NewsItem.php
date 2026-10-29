<?php

namespace App\Models;

use Database\Factories\NewsItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'source', 'title', 'url', 'url_hash', 'summary', 'published_at',
    'sentiment_score', 'sentiment_label', 'sentiment_provider', 'analyzed_at',
])]
class NewsItem extends Model
{
    /** @use HasFactory<NewsItemFactory> */
    use HasFactory, MassPrunable;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('published_at', '<', now()->subDays(30));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'sentiment_score' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Token, $this>
     */
    public function tokens(): BelongsToMany
    {
        return $this->belongsToMany(Token::class);
    }
}
