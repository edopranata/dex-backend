<?php

namespace App\Jobs;

use App\Services\Analysis\TokenDiscoveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DiscoverTokens implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue(config('market.market_data_queue'));
    }

    public int $uniqueFor = 900;

    public function handle(TokenDiscoveryService $discovery): void
    {
        $discovery->run();
    }
}
