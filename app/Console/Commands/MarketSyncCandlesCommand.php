<?php

namespace App\Console\Commands;

use App\Jobs\SyncTokenCandles;
use App\Models\Candle;
use App\Models\Token;
use App\Services\MarketData\GeckoTerminalClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('market:candles {--timeframe=* : Ambil langsung timeframe ini (15m, 1h, 4h, 1d). Tanpa opsi: ambil 15m dan rakit 1h, 4h, 1d} {--out-of-market : Giliran token yang sudah keluar Market, yang candle-nya paling lama duluan} {--sync : Jalankan langsung tanpa queue}')]
#[Description('Ambil candle OHLCV dari GeckoTerminal')]
class MarketSyncCandlesCommand extends Command
{
    public function handle(): int
    {
        $timeframes = array_values(array_intersect($this->option('timeframe'), array_keys(GeckoTerminalClient::TIMEFRAMES)));

        foreach ($this->tokens() as $token) {
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

        $what = ($timeframes === [] ? '15m, lalu 1h/4h/1d dirakit' : implode(', ', $timeframes)).($this->option('out-of-market') ? ', token di luar Market' : '');
        $this->components->info("Sinkronisasi candle [{$what}] ".($this->option('sync') ? 'selesai.' : 'masuk antrean.'));

        return self::SUCCESS;
    }

    /**
     * The Market's tokens, or this hour's turn of the tokens that left it: those whose 15m candles are oldest.
     *
     * @return Collection<int, Token>
     */
    private function tokens(): Collection
    {
        if (! $this->option('out-of-market')) {
            return Token::query()->active()->get();
        }

        return Token::query()
            ->everInMarket()
            ->where('is_active', false)
            ->addSelect(['latest_15m' => Candle::query()->selectRaw('max(open_time)')->whereColumn('token_id', 'tokens.id')->where('timeframe', '15m')])
            ->orderByRaw('latest_15m is null desc')
            ->orderBy('latest_15m')
            ->limit((int) config('market.candles.out_of_market_per_hour'))
            ->get();
    }
}
