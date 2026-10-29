<?php

namespace App\Http\Resources;

use App\Models\Analysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Analysis
 */
class AnalysisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token_id' => $this->token_id,
            'timeframe' => $this->timeframe,
            'recommendation' => $this->recommendation,
            'recommendation_label' => $this->recommendation->label(),
            'final_score' => $this->final_score,
            'scores' => [
                'technical' => $this->technical_score,
                'onchain' => $this->onchain_score,
                'whale' => $this->whale_score,
                'sentiment' => $this->sentiment_score,
                'fundamental' => $this->fundamental_score,
            ],
            'price' => $this->price,
            'levels' => $this->entry_low === null ? null : [
                'entry_low' => $this->entry_low,
                'entry_high' => $this->entry_high,
                'stop_loss' => $this->stop_loss,
                'take_profit_1' => $this->take_profit_1,
                'take_profit_2' => $this->take_profit_2,
                'take_profit_3' => $this->take_profit_3,
            ],
            'indicators' => $this->when($request->routeIs('tokens.show'), $this->indicators),
            'reasons' => $this->when($request->routeIs('tokens.show'), $this->reasons),
            'summary' => $this->summary,
            'summary_provider' => $this->summary_provider,
            'token' => new TokenResource($this->whenLoaded('token')),
            'created_at' => $this->created_at,
        ];
    }
}
