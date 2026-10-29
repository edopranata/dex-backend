<?php

namespace App\Http\Resources;

use App\Models\Token;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Token
 */
class TokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chain_id' => $this->chain_id,
            'chain_label' => config("market.chains.{$this->chain_id}.label", $this->chain_id),
            'pair_address' => $this->pair_address,
            'token_address' => $this->token_address,
            'symbol' => $this->symbol,
            'name' => $this->name,
            'quote_symbol' => $this->quote_symbol,
            'dex_id' => $this->dex_id,
            'image_url' => $this->image_url,
            'url' => $this->url,
            'price_usd' => $this->price_usd,
            'price_change' => [
                'm5' => $this->price_change_m5,
                'h1' => $this->price_change_h1,
                'h6' => $this->price_change_h6,
                'h24' => $this->price_change_h24,
            ],
            'volume_h24' => $this->volume_h24,
            'liquidity_usd' => $this->liquidity_usd,
            'fdv' => $this->fdv,
            'market_cap' => $this->market_cap,
            'txns_h24' => ['buys' => $this->txns_h24_buys, 'sells' => $this->txns_h24_sells],
            'pair_created_at' => $this->pair_created_at,
            'price_updated_at' => $this->price_updated_at,
            'source' => $this->source,
            'is_pinned' => $this->is_pinned,
            'added_at' => $this->addedAt(),
            'is_new' => $this->isNew(),
            'score_history' => $this->when($this->resource->offsetExists('score_history'), fn () => $this->resource->score_history),
            'score_trend' => $this->when($this->resource->offsetExists('score_trend'), fn () => $this->resource->score_trend),
            'discovered_at' => $this->discovered_at,
            'latest_analysis' => new AnalysisResource($this->whenLoaded('latestAnalysis')),
            'latest_security' => new TokenSecurityCheckResource($this->whenLoaded('latestSecurityCheck')),
        ];
    }

    /**
     * When the token last entered the Market list (falls back to its creation time).
     */
    private function addedAt(): ?Carbon
    {
        $lastAdded = $this->resource->getAttribute('last_added_at');

        return $lastAdded !== null ? Carbon::parse($lastAdded) : $this->created_at;
    }

    /**
     * Entered the list in the last 24 hours, by a logged addition or by discovery.
     */
    private function isNew(): bool
    {
        $lastAdded = $this->resource->getAttribute('last_added_at');
        $enteredAt = $lastAdded !== null
            ? Carbon::parse($lastAdded)
            : ($this->source === 'discovery' ? $this->discovered_at : null);

        return $enteredAt !== null && $enteredAt->gt(now()->subDay());
    }
}
