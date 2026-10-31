<?php

namespace App\Services\MarketData;

use App\Models\WhaleTrade;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The wallets seen in whale trades. This only covers big trades on tokens the app follows, so it says
 * nothing about a wallet's full activity or its profit.
 */
class WalletDirectory
{
    private const CACHE_SECONDS = 60;

    /** At this pace a person is unlikely to be behind the wallet. A guess from the pattern, not a fact. */
    private const BOT_MIN_TRADES = 50;

    private const BOT_MIN_TRADES_PER_DAY = 20;

    /**
     * One row per wallet and chain, because the same address can be used on several EVM chains.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        return collect(Cache::remember('wallets:directory', self::CACHE_SECONDS, fn (): array => $this->aggregate()));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $chain, string $wallet): ?array
    {
        return $this->aggregate($chain, $wallet)[0] ?? null;
    }

    /**
     * Per token, biggest volume first.
     *
     * @return list<array<string, mixed>>
     */
    public function tokens(string $chain, string $wallet): array
    {
        return $this->baseQuery($chain, $wallet)
            ->selectRaw('tokens.id, tokens.slug, tokens.symbol, tokens.image_url')
            ->selectRaw($this->totals())
            ->groupBy('tokens.id', 'tokens.slug', 'tokens.symbol', 'tokens.image_url')
            ->orderByDesc('volume_usd')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'slug' => $row->slug,
                'symbol' => $row->symbol,
                'image_url' => $row->image_url,
                'trades' => (int) $row->trades,
                'buys' => (int) $row->buys,
                'sells' => (int) $row->sells,
                'buy_usd' => round((float) $row->buy_usd, 2),
                'sell_usd' => round((float) $row->sell_usd, 2),
                'last_at' => Carbon::parse($row->last_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregate(?string $chain = null, ?string $wallet = null): array
    {
        return $this->baseQuery($chain, $wallet)
            ->selectRaw('whale_trades.wallet, tokens.chain_id')
            ->selectRaw('COUNT(DISTINCT whale_trades.token_id) as tokens')
            ->selectRaw($this->totals())
            ->selectRaw('MIN(whale_trades.traded_at) as first_at')
            ->groupBy('whale_trades.wallet', 'tokens.chain_id')
            ->get()
            ->map(function (object $row): array {
                $trades = (int) $row->trades;
                $first = Carbon::parse($row->first_at);
                $last = Carbon::parse($row->last_at);

                return [
                    'wallet' => $row->wallet,
                    'chain' => $row->chain_id,
                    'chain_label' => config("market.chains.{$row->chain_id}.label", $row->chain_id),
                    'trades' => $trades,
                    'tokens' => (int) $row->tokens,
                    'buys' => (int) $row->buys,
                    'sells' => (int) $row->sells,
                    'buy_usd' => round((float) $row->buy_usd, 2),
                    'sell_usd' => round((float) $row->sell_usd, 2),
                    'volume_usd' => round((float) $row->volume_usd, 2),
                    'first_at' => $first->toIso8601String(),
                    'last_at' => $last->toIso8601String(),
                    'likely_bot' => $trades >= self::BOT_MIN_TRADES
                        && $trades / max(1, $first->diffInDays($last)) >= self::BOT_MIN_TRADES_PER_DAY,
                ];
            })
            ->all();
    }

    private function totals(): string
    {
        return "COUNT(*) as trades,
            SUM(CASE WHEN whale_trades.side = 'buy' THEN 1 ELSE 0 END) as buys,
            SUM(CASE WHEN whale_trades.side = 'sell' THEN 1 ELSE 0 END) as sells,
            SUM(CASE WHEN whale_trades.side = 'buy' THEN whale_trades.amount_usd ELSE 0 END) as buy_usd,
            SUM(CASE WHEN whale_trades.side = 'sell' THEN whale_trades.amount_usd ELSE 0 END) as sell_usd,
            SUM(whale_trades.amount_usd) as volume_usd,
            MAX(whale_trades.traded_at) as last_at";
    }

    private function baseQuery(?string $chain, ?string $wallet): Builder
    {
        return WhaleTrade::query()->toBase()
            ->join('tokens', 'tokens.id', '=', 'whale_trades.token_id')
            ->whereNotNull('whale_trades.wallet')
            ->where('whale_trades.wallet', '!=', '')
            ->when($chain !== null, fn ($query) => $query->where('tokens.chain_id', $chain))
            ->when($wallet !== null, fn ($query) => $query->where('whale_trades.wallet', $wallet));
    }
}
