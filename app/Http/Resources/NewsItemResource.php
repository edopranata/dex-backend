<?php

namespace App\Http\Resources;

use App\Models\NewsItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NewsItem
 */
class NewsItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'title' => $this->title,
            'url' => $this->url,
            'summary' => $this->summary,
            'published_at' => $this->published_at,
            'sentiment_score' => $this->sentiment_score,
            'sentiment_label' => $this->sentiment_label,
            'sentiment_provider' => $this->sentiment_provider,
            'tokens' => $this->whenLoaded('tokens', fn () => $this->tokens->map(fn ($token): array => [
                'id' => $token->id,
                'symbol' => $token->symbol,
            ])),
        ];
    }
}
