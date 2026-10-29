<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Http;

/**
 * Token security data (honeypot, taxes, mint/freeze authority, holders, LP locks).
 */
class GoPlusClient
{
    /**
     * @return array<string, mixed>|null
     */
    public function tokenSecurity(string $goPlusChain, string $tokenAddress): ?array
    {
        $path = $goPlusChain === 'solana'
            ? '/solana/token_security'
            : "/token_security/{$goPlusChain}";

        $result = Http::baseUrl('https://api.gopluslabs.io/api/v1')
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 1000, throw: false)
            ->get($path, ['contract_addresses' => $tokenAddress])
            ->throw()
            ->json('result') ?? [];

        foreach ($result as $address => $data) {
            if (strcasecmp((string) $address, $tokenAddress) === 0) {
                return $data;
            }
        }

        return null;
    }
}
