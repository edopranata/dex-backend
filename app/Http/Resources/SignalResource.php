<?php

namespace App\Http\Resources;

use App\Models\Signal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Signal
 */
class SignalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token_id' => $this->token_id,
            'recommendation' => $this->recommendation,
            'status' => $this->status,
            'entry_low' => $this->entry_low,
            'entry_high' => $this->entry_high,
            'stop_loss' => $this->stop_loss,
            'take_profit_1' => $this->take_profit_1,
            'take_profit_2' => $this->take_profit_2,
            'take_profit_3' => $this->take_profit_3,
            'entry_price' => $this->entry_price,
            'exit_price' => $this->exit_price,
            'max_tp_hit' => $this->max_tp_hit,
            'result_r' => $this->result_r,
            'entered_at' => $this->entered_at,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
            'token' => new TokenResource($this->whenLoaded('token')),
        ];
    }
}
