<?php

namespace App\Services\MarketData;

use App\Models\Token;
use Illuminate\Support\Collection;

/**
 * The market benchmarks from config('market.benchmarks'): main assets followed for comparison, never traded.
 * They stay out of the Market, but their candles are kept current with the Market's.
 */
class BenchmarkTokens
{
    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys((array) config('market.benchmarks'));
    }

    /**
     * The benchmark's token, or null when it is not configured or not stored yet.
     */
    public function find(string $name): ?Token
    {
        $definition = config("market.benchmarks.{$name}");

        if (! is_array($definition)) {
            return null;
        }

        $query = Token::query()->where('chain_id', $definition['chain']);

        return isset($definition['pair_address'])
            ? $query->where('pair_address', $definition['pair_address'])->first()
            : $query->where('symbol', $definition['symbol'])->orderByDesc('liquidity_usd')->first();
    }

    /**
     * Every benchmark token, creating the ones configured with an address that do not exist yet.
     *
     * @return Collection<string, Token>
     */
    public function ensure(): Collection
    {
        return collect($this->names())
            ->mapWithKeys(fn (string $name): array => [$name => $this->find($name) ?? $this->create($name)])
            ->filter();
    }

    private function create(string $name): ?Token
    {
        $definition = config("market.benchmarks.{$name}");

        if (! isset($definition['pair_address'], $definition['token_address'])) {
            return null;
        }

        return Token::query()->create([
            'chain_id' => $definition['chain'],
            'pair_address' => $definition['pair_address'],
            'token_address' => $definition['token_address'],
            'symbol' => $definition['symbol'],
            'name' => $definition['name'] ?? $definition['symbol'],
            'quote_symbol' => $definition['quote_symbol'] ?? null,
            'dex_id' => $definition['dex_id'] ?? null,
            'is_active' => false,
            'is_pinned' => false,
            'source' => 'benchmark',
        ]);
    }
}
