<?php

namespace App\Http\Resources;

use App\Models\TokenSecurityCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TokenSecurityCheck
 */
class TokenSecurityCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'risk_score' => $this->risk_score,
            'holder_count' => $this->holder_count,
            'top10_percent' => $this->top10_percent,
            'lp_locked_percent' => $this->lp_locked_percent,
            'is_honeypot' => $this->is_honeypot,
            'buy_tax' => $this->buy_tax,
            'sell_tax' => $this->sell_tax,
            'flags' => $this->flags,
            'checked_at' => $this->checked_at,
        ];
    }
}
