<?php

namespace App\Jobs;

use App\Models\Token;
use App\Services\MarketData\CandleSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTokenCandles implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /**
     * @param  list<string>  $timeframes
     */
    public function __construct(public Token $token, public array $timeframes) {}

    public function uniqueId(): string
    {
        return $this->token->id.':'.implode(',', $this->timeframes);
    }

    public function handle(CandleSyncService $candles): void
    {
        foreach ($this->timeframes as $timeframe) {
            $candles->sync($this->token, $timeframe);
        }
    }
}
