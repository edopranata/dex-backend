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
     * @param  list<string>  $timeframes  Fetched directly from GeckoTerminal; none keeps the token current the
     *                                    usual way (15m fetched, higher timeframes built from it).
     * @param  int|null  $quarters  How many 15m candles to fetch instead of what the gap needs (admin button).
     */
    public function __construct(public Token $token, public array $timeframes = [], public ?int $quarters = null)
    {
        $this->onQueue(config('market.market_data_queue'));
    }

    public function uniqueId(): string
    {
        return $this->token->id.':'.($this->timeframes === [] ? 'refresh'.($this->quarters ?? '') : implode(',', $this->timeframes));
    }

    public function handle(CandleSyncService $candles): void
    {
        if ($this->timeframes === []) {
            $candles->refresh($this->token, $this->quarters);

            return;
        }

        foreach ($this->timeframes as $timeframe) {
            $candles->sync($this->token, $timeframe);
        }
    }
}
