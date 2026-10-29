<?php

namespace App\Http\Resources;

use App\Models\WatchlistEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WatchlistEvent
 */
class WatchlistEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'reason' => $this->reason,
            'score' => $this->score,
            'created_at' => $this->created_at,
            'token' => [
                'id' => $this->token->id,
                'symbol' => $this->token->symbol,
                'chain_id' => $this->token->chain_id,
                'image_url' => $this->token->image_url,
                'is_active' => $this->token->is_active,
            ],
        ];
    }
}
