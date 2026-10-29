<?php

namespace Database\Seeders;

use App\Services\MarketData\TokenRegistry;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Starter watchlist. The registry picks the most liquid pair for each token address.
 */
class MarketTokenSeeder extends Seeder
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private const TOKENS = [
        ['solana', 'So11111111111111111111111111111111111111112'],
        ['solana', 'JUPyiwrYJFskUPiHa7hkeR8VUtAeFoSYbKedZNsDvCN'],
        ['solana', 'DezXAZ8z7PnrnRJjz3wXBoRgixCa6xjnB7YaB1pPB263'],
        ['solana', 'EKpQGSJtjMFqKZ9KQanSqYXRcF8fBopzLHYxdM65zcjm'],
        ['ethereum', '0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2'],
        ['ethereum', '0x6982508145454Ce325dDbE47a25d4ec3d2311933'],
        ['base', '0x940181a94A35A4569E4529A3CDfB74e38FD98631'],
        ['base', '0x532f27101965dd16442E59d40670FaF5eBB142E4'],
        ['bsc', '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82'],
    ];

    public function run(TokenRegistry $registry): void
    {
        foreach (self::TOKENS as [$chain, $address]) {
            try {
                $token = $registry->add($chain, $address, 'seed');
                $this->command?->info("  {$token->symbol}/{$token->quote_symbol} ({$chain})");
            } catch (Throwable $e) {
                $this->command?->warn("  Lewati {$address} di {$chain}: {$e->getMessage()}");
            }
        }
    }
}
