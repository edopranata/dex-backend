<?php

namespace App\Console\Commands;

use App\Jobs\DiscoverNewPairs;
use App\Services\NewPairs\NewPairRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('newpairs:discover {--sync : Jalankan langsung tanpa queue}')]
#[Description('Token Baru: ambil pool terbaru GeckoTerminal (new_pools) per jaringan; hanya pencatatan')]
class NewPairsDiscoverCommand extends Command
{
    public function handle(NewPairRegistry $registry): int
    {
        if (! config('market.new_pairs.enabled')) {
            $this->components->warn('Token Baru dinonaktifkan.');

            return self::SUCCESS;
        }

        if (! $this->option('sync')) {
            DiscoverNewPairs::dispatch();
            $this->components->info('Pool baru masuk antrean.');

            return self::SUCCESS;
        }

        $result = $registry->fromGeckoTerminal();
        $this->components->info("Pool baru: {$result['found']} ditemukan, {$result['added']} dicatat, {$result['failed']} jaringan gagal.");

        return self::SUCCESS;
    }
}
