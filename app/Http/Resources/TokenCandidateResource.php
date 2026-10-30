<?php

namespace App\Http\Resources;

use App\Models\TokenCandidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TokenCandidate
 */
class TokenCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token_id' => $this->token_id,
            'chain_id' => $this->chain_id,
            'chain_label' => config("market.chains.{$this->chain_id}.label", $this->chain_id),
            'pair_address' => $this->pair_address,
            'token_address' => $this->token_address,
            'symbol' => $this->symbol,
            'name' => $this->name,
            'quote_symbol' => $this->quote_symbol,
            'image_url' => $this->image_url,
            'url' => $this->url,
            'sources' => $this->sources,
            'price_usd' => $this->price_usd,
            'price_change' => ['h1' => $this->price_change_h1, 'h6' => $this->price_change_h6, 'h24' => $this->price_change_h24],
            'volume_h24' => $this->volume_h24,
            'liquidity_usd' => $this->liquidity_usd,
            'fdv' => $this->fdv,
            'txns_h24' => ['buys' => $this->buys_h24, 'sells' => $this->sells_h24, 'buyers' => $this->buyers_h24, 'sellers' => $this->sellers_h24],
            'news_mentions' => $this->news_mentions,
            'whale' => $this->whale_checked_at === null ? null : [
                'buy_usd' => $this->whale_buy_usd,
                'sell_usd' => $this->whale_sell_usd,
                'trades' => $this->whale_trades,
                'buy_wallets' => $this->whale_buy_wallets,
                'checked_at' => $this->whale_checked_at,
            ],
            'security' => $this->security_checked_at === null ? null : [
                'score' => $this->security_score,
                'is_honeypot' => $this->is_honeypot,
                'flags' => $this->security_flags,
                'checked_at' => $this->security_checked_at,
            ],
            'scores' => [
                'trend' => $this->trend_score,
                'attention' => $this->attention_score,
                'whale' => $this->whale_score,
                'security' => $this->security_score,
                'discovery' => $this->discovery_score,
            ],
            'status' => $this->status,
            'reasons' => $this->reasons,
            'pair_created_at' => $this->pair_created_at,
            'first_seen_at' => $this->first_seen_at,
            'last_seen_at' => $this->last_seen_at,
            'token' => $this->whenLoaded('token', fn () => $this->token ? [
                'id' => $this->token->id,
                'slug' => $this->token->slug,
                'is_active' => $this->token->is_active,
                'recommendation' => $this->token->latestAnalysis?->recommendation,
                'final_score' => $this->token->latestAnalysis?->final_score,
            ] : null),
        ];
    }
}
