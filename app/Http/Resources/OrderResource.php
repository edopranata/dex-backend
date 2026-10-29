<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token_id' => $this->token_id,
            'signal_id' => $this->signal_id,
            'side' => $this->side,
            'type' => $this->type,
            'status' => $this->status,
            'amount_usd' => $this->amount_usd,
            'quantity' => $this->quantity,
            'trigger_price' => $this->trigger_price,
            'filled_price' => $this->filled_price,
            'filled_quantity' => $this->filled_quantity,
            'total_usd' => $this->total_usd,
            'fee_usd' => $this->fee_usd,
            'slippage_pct' => $this->slippage_pct,
            'realized_pnl' => $this->realized_pnl,
            'note' => $this->note,
            'filled_at' => $this->filled_at,
            'created_at' => $this->created_at,
            'token' => new TokenResource($this->whenLoaded('token')),
        ];
    }
}
