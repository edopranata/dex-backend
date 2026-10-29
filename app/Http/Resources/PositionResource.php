<?php

namespace App\Http\Resources;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Position
 */
class PositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $price = (float) $this->token->price_usd;
        $value = $this->quantity * $price;
        $cost = $this->quantity * $this->avg_price;

        return [
            'id' => $this->id,
            'token_id' => $this->token_id,
            'quantity' => $this->quantity,
            'avg_price' => $this->avg_price,
            'current_price' => $price,
            'value_usd' => $value,
            'cost_usd' => $cost,
            'unrealized_pnl' => $value - $cost,
            'unrealized_pnl_pct' => $cost > 0 ? ($value - $cost) / $cost * 100 : 0,
            'realized_pnl' => $this->realized_pnl,
            'token' => new TokenResource($this->token),
        ];
    }
}
