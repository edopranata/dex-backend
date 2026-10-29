<?php

namespace App\Services\MarketData;

use App\Models\Token;

class CandleSyncService
{
    public function __construct(private GeckoTerminalClient $geckoTerminal) {}

    /**
     * Fetch and upsert candles for one timeframe. Returns the number of candles received.
     */
    public function sync(Token $token, string $timeframe, int $limit = 300): int
    {
        $network = config("market.chains.{$token->chain_id}.geckoterminal");

        if ($network === null) {
            return 0;
        }

        $candles = $this->geckoTerminal->ohlcv($network, $token->pair_address, $token->token_address, $timeframe, $limit);

        if ($candles === []) {
            return 0;
        }

        $rows = array_map(fn (array $candle): array => [
            'token_id' => $token->id,
            'timeframe' => $timeframe,
            ...$candle,
        ], $candles);

        foreach (array_chunk($rows, 500) as $chunk) {
            $token->candles()->upsert($chunk, ['token_id', 'timeframe', 'open_time'], ['open', 'high', 'low', 'close', 'volume']);
        }

        return count($rows);
    }
}
