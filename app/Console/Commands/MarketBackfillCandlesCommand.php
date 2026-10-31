<?php

namespace App\Console\Commands;

use App\Models\Token;
use App\Services\MarketData\GeckoTerminalClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:backfill-candles {--days=90 : Mundur berapa hari} {--timeframe=* : Default 1h dan 4h} {--token=* : Simbol token (default: semua, termasuk yang sudah keluar Market)}')]
#[Description('Ambil histori candle untuk backtest (mengikuti batas request GeckoTerminal)')]
class MarketBackfillCandlesCommand extends Command
{
    private const PAGE = 1000;

    public function handle(GeckoTerminalClient $geckoTerminal): int
    {
        $timeframes = $this->option('timeframe') ?: ['1h', '4h'];
        $cutoff = now()->subDays((int) $this->option('days'))->timestamp;
        $tokens = Token::query()->when($this->option('token'), fn ($q, $symbols) => $q->whereIn('symbol', $symbols))->orderBy('id')->get();

        foreach ($tokens as $token) {
            $network = config("market.chains.{$token->chain_id}.geckoterminal");

            if ($network === null) {
                continue;
            }

            foreach ($timeframes as $timeframe) {
                try {
                    $count = $this->backfill($geckoTerminal, $token, $network, $timeframe, $cutoff);
                    $this->line("{$token->symbol} {$timeframe}: {$count} candle");
                } catch (Throwable $e) {
                    $this->components->warn("{$token->symbol} {$timeframe} gagal: {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }

    private function backfill(GeckoTerminalClient $geckoTerminal, Token $token, string $network, string $timeframe, int $cutoff): int
    {
        $before = null;
        $total = 0;

        do {
            $candles = $geckoTerminal->ohlcv($network, $token->pair_address, $token->token_address, $timeframe, self::PAGE, $before);

            if ($candles === []) {
                break;
            }

            $rows = array_map(fn (array $candle): array => ['token_id' => $token->id, 'timeframe' => $timeframe, ...$candle], $candles);

            foreach (array_chunk($rows, 500) as $chunk) {
                $token->candles()->upsert($chunk, ['token_id', 'timeframe', 'open_time'], ['open', 'high', 'low', 'close', 'volume']);
            }

            $total += count($rows);
            $before = $candles[0]['open_time'];
        } while (count($candles) === self::PAGE && $before > $cutoff);

        return $total;
    }
}
