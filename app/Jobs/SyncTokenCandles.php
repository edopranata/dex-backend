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
     * How many 15m candles to fetch instead of what the gap needs (admin button). A declared default rather than
     * a promoted parameter: a job queued before this property existed is unserialized without calling the
     * constructor, and must still run (8 Nov: seven queued jobs failed on it during a deploy).
     */
    public ?int $quarters = null;

    /**
     * @param  list<string>  $timeframes  Fetched directly from GeckoTerminal; none keeps the token current the
     *                                    usual way (15m fetched, higher timeframes built from it).
     */
    public function __construct(public Token $token, public array $timeframes = [], ?int $quarters = null)
    {
        $this->quarters = $quarters;
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
