<?php

namespace App\Console\Commands;

use App\Jobs\SyncTokenCandles;
use App\Models\Token;
use App\Services\MarketData\GeckoTerminalClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:candles {--timeframe=* : Ambil langsung timeframe ini (15m, 1h, 4h, 1d). Tanpa opsi: ambil 15m dan rakit 1h, 4h, 1d} {--sync : Jalankan langsung tanpa queue}')]
#[Description('Ambil candle OHLCV dari GeckoTerminal')]
class MarketSyncCandlesCommand extends Command
{
    public function handle(): int
    {
        $timeframes = array_values(array_intersect($this->option('timeframe'), array_keys(GeckoTerminalClient::TIMEFRAMES)));

        foreach (Token::query()->active()->get() as $token) {
            if (! $this->option('sync')) {
                SyncTokenCandles::dispatch($token, $timeframes);

                continue;
            }

            try {
                SyncTokenCandles::dispatchSync($token, $timeframes);
            } catch (Throwable $e) {
                $this->components->warn("{$token->symbol}: {$e->getMessage()}");
            }
        }

        $what = $timeframes === [] ? '15m, lalu 1h/4h/1d dirakit' : implode(', ', $timeframes);
        $this->components->info("Sinkronisasi candle [{$what}] ".($this->option('sync') ? 'selesai.' : 'masuk antrean.'));

        return self::SUCCESS;
    }
}
