<?php

namespace App\Jobs;

use App\Services\NewPairs\NewPairRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Token Baru: GeckoTerminal new_pools, on the market-data queue so it takes its turn with the other GeckoTerminal jobs.
 */
class DiscoverNewPairs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $uniqueFor = 1500;

    public function __construct()
    {
        $this->onQueue(config('market.market_data_queue'));
    }

    public function handle(NewPairRegistry $registry): void
    {
        $registry->fromGeckoTerminal();
    }
}
