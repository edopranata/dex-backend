<?php

namespace App\Http\Resources;

use App\Models\WhaleTrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WhaleTrade
 */
class WhaleTradeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token_id' => $this->token_id,
            'tx_hash' => $this->tx_hash,
            'wallet' => $this->wallet,
            'side' => $this->side,
            'amount_usd' => $this->amount_usd,
            'token_amount' => $this->token_amount,
            'price_usd' => $this->price_usd,
            'traded_at' => $this->traded_at,
            'token' => $this->whenLoaded('token', fn (): array => [
                'id' => $this->token->id,
                'symbol' => $this->token->symbol,
                'chain_id' => $this->token->chain_id,
                'image_url' => $this->token->image_url,
            ]),
        ];
    }
}
