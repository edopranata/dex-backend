<?php

namespace App\Services\MarketData;

use App\Jobs\AnalyzeToken;
use App\Jobs\CheckTokenSecurity;
use App\Jobs\SyncTokenCandles;
use App\Jobs\SyncTokenWhaleTrades;
use App\Models\Token;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

/**
 * Adds tokens to the tracked list from a pair address or a token address.
 */
class TokenRegistry
{
    public function __construct(private DexScreenerClient $dexScreener) {}

    /**
     * @param  string  $source  manual, seed or discovery
     *
     * @throws ValidationException
     */
    public function add(string $chainId, string $address, string $source = 'manual'): Token
    {
        if (config("market.chains.{$chainId}") === null) {
            throw ValidationException::withMessages(['chain_id' => "Jaringan [{$chainId}] belum didukung."]);
        }

        $existing = Token::query()
            ->where('chain_id', $chainId)
            ->where(fn ($query) => $query->where('pair_address', $address)->orWhere('token_address', $address))
            ->first();

        if ($existing !== null) {
            $existing->update([
                'is_active' => true,
                'source' => $existing->is_active ? $existing->source : $source,
                'is_pinned' => $existing->is_pinned || $source !== 'discovery',
            ]);

            return $existing;
        }

        if (Token::query()->active()->count() >= config('market.simulator.max_tokens')) {
            throw ValidationException::withMessages(['address' => 'Batas jumlah token terpantau sudah tercapai (lihat SIM_MAX_TOKENS).']);
        }

        $pair = $this->dexScreener->pairs($chainId, [$address])[0]
            ?? $this->dexScreener->tokenPairs($chainId, $address)[0]
            ?? null;

        if ($pair === null || ! isset($pair['priceUsd'])) {
            throw ValidationException::withMessages(['address' => 'Pair atau token tidak ditemukan di DEX Screener.']);
        }

        // One token in two pools would be analyzed, signalled and bought twice.
        $sameToken = Token::query()->active()
            ->where('chain_id', $pair['chainId'])
            ->where('token_address', $pair['baseToken']['address'])
            ->where('pair_address', '!=', $pair['pairAddress'])
            ->first();

        if ($sameToken !== null) {
            throw ValidationException::withMessages(['address' => "{$sameToken->symbol} sudah ada di Market lewat pair lain."]);
        }

        return Token::updateOrCreate(
            ['chain_id' => $pair['chainId'], 'pair_address' => $pair['pairAddress']],
            [
                ...DexScreenerClient::toTokenAttributes($pair),
                'is_active' => true,
                'is_pinned' => $source !== 'discovery',
                'source' => $source,
                'discovered_at' => $source === 'discovery' ? now() : null,
            ],
        );
    }

    /**
     * Fetch everything a new token needs, then analyze it (security → candles → whales → analysis).
     */
    public function bootstrap(Token $token): void
    {
        Bus::chain([
            new CheckTokenSecurity($token),
            // Only what is missing: history for a new token, the gap for one that comes back.
            new SyncTokenCandles($token),
            new SyncTokenWhaleTrades($token),
            new AnalyzeToken($token),
        ])->dispatch();
    }
}
