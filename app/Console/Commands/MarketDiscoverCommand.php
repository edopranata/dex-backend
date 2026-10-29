<?php

namespace App\Console\Commands;

use App\Jobs\DiscoverTokens;
use App\Services\Analysis\TokenDiscoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:discover {--sync : Jalankan langsung tanpa queue}')]
#[Description('Temukan token trending dengan aktivitas whale, lalu pantau yang terbaik secara otomatis')]
class MarketDiscoverCommand extends Command
{
    public function handle(TokenDiscoveryService $discovery): int
    {
        if (! $this->option('sync')) {
            DiscoverTokens::dispatch();
            $this->components->info('Discovery masuk antrean.');

            return self::SUCCESS;
        }

        $result = $discovery->run();

        $this->components->twoColumnDetail('Kandidat ditemukan', (string) $result['found']);
        $this->components->twoColumnDetail('Lolos filter', (string) $result['candidates']);
        $this->components->twoColumnDetail('Ditolak', (string) $result['rejected']);
        $this->components->twoColumnDetail('Dicek mendalam', (string) $result['deep_checked']);
        $this->components->twoColumnDetail('Masuk Market', implode(', ', $result['added']) ?: '-');
        $this->components->twoColumnDetail('Digantikan', implode(', ', $result['replaced']) ?: '-');

        return self::SUCCESS;
    }
}
