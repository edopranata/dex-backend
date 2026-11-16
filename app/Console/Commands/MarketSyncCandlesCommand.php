<?php

namespace App\Console\Commands;

use App\Jobs\SyncTokenCandles;
use App\Models\Candle;
use App\Models\Token;
use App\Services\MarketData\BenchmarkTokens;
use App\Services\MarketData\GeckoTerminalClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('market:candles {--timeframe=* : Ambil langsung timeframe ini (15m, 1h, 4h, 1d). Tanpa opsi: ambil 15m dan rakit 1h, 4h, 1d} {--out-of-market : Giliran token yang sudah keluar Market: yang bersinyal baru tiap beberapa jam, lainnya yang candle-nya paling lama duluan} {--sync : Jalankan langsung tanpa queue}')]
#[Description('Ambil candle OHLCV dari GeckoTerminal')]
class MarketSyncCandlesCommand extends Command
{
    public function handle(BenchmarkTokens $benchmarks): int
    {
        $timeframes = array_values(array_intersect($this->option('timeframe'), array_keys(GeckoTerminalClient::TIMEFRAMES)));

        foreach ($this->tokens($benchmarks) as $token) {
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
     * The Market's tokens with the market benchmarks, or this hour's turn of the tokens that left it: first those
     * with a recent signal whose 15m candles are older than recent_refresh_hours, then the others whose 15m
     * candles are oldest.
     *
     * @return Collection<int, Token>
     */
    private function tokens(BenchmarkTokens $benchmarks): Collection
    {
        if (! $this->option('out-of-market')) {
            return Token::query()->active()->get()->merge($benchmarks->ensure()->values()->all());
        }

        $outOfMarket = fn () => Token::query()
            ->everInMarket()
            ->where('is_active', false)
            ->addSelect(['latest_15m' => Candle::query()->selectRaw('max(open_time)')->whereColumn('token_id', 'tokens.id')->where('timeframe', '15m')])
            ->orderByRaw('latest_15m is null desc')
            ->orderBy('latest_15m');

        $recentIds = $outOfMarket()->withRecentSignal()->pluck('id');
        $fresh = now()->subHours((int) config('market.candles.recent_refresh_hours'))->getTimestamp();
        $recent = $outOfMarket()
            ->whereIn('id', $recentIds)
            ->whereDoesntHave('candles', fn ($candles) => $candles->where('timeframe', '15m')->where('open_time', '>=', $fresh))
            ->limit((int) config('market.candles.recent_per_hour'))
            ->get();

        return $recent->merge($outOfMarket()
            ->whereNotIn('id', $recentIds)
            ->limit((int) config('market.candles.out_of_market_per_hour'))
            ->get());
    }
}
