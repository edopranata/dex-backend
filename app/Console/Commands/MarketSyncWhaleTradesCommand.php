<?php

namespace App\Console\Commands;

use App\Jobs\SyncTokenWhaleTrades;
use App\Models\Token;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:whales {--sync : Jalankan langsung tanpa queue}')]
#[Description('Ambil transaksi whale dari GeckoTerminal')]
class MarketSyncWhaleTradesCommand extends Command
{
    public function handle(): int
    {
        foreach (Token::query()->active()->get() as $token) {
            if (! $this->option('sync')) {
                SyncTokenWhaleTrades::dispatch($token);

                continue;
            }

            try {
                SyncTokenWhaleTrades::dispatchSync($token);
            } catch (Throwable $e) {
                $this->components->warn("{$token->symbol}: {$e->getMessage()}");
            }
        }

        $this->components->info('Sinkronisasi whale '.($this->option('sync') ? 'selesai.' : 'masuk antrean.'));

        return self::SUCCESS;
    }
}
