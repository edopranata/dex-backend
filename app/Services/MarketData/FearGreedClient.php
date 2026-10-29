<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Crypto Fear & Greed Index from alternative.me (updated daily).
 */
class FearGreedClient
{
    /**
     * @return array{value: int, classification: string, updated_at: int}|null
     */
    public function latest(): ?array
    {
        return Cache::flexible('market:fear-greed', [1800, 7200], function (): ?array {
            try {
                $data = Http::timeout(10)->get('https://api.alternative.me/fng/', ['limit' => 1])->throw()->json('data.0');
            } catch (Throwable) {
                return null;
            }

            return $data === null ? null : [
                'value' => (int) $data['value'],
                'classification' => $data['value_classification'],
                'updated_at' => (int) $data['timestamp'],
            ];
        });
    }
}
