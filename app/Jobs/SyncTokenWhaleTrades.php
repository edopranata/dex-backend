<?php

namespace App\Jobs;

use App\Models\Token;
use App\Services\MarketData\WhaleSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTokenWhaleTrades implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public Token $token)
    {
        $this->onQueue(config('market.market_data_queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->token->id;
    }

    public function handle(WhaleSyncService $whales): void
    {
        $whales->sync($this->token);
    }
}
