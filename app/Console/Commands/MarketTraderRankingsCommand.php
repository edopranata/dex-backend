<?php

namespace App\Console\Commands;

use App\Services\Analysis\TraderRankingCollector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:trader-rankings')]
#[Description('Catat daftar trader paling untung dari Birdeye (Fase R, hanya pencatatan)')]
class MarketTraderRankingsCommand extends Command
{
    public function handle(TraderRankingCollector $collector): int
    {
        $result = $collector->run();

        if ($result['skipped'] !== null) {
            $this->components->warn(match ($result['skipped']) {
                'no_key' => 'Dilewati: Birdeye API key belum diisi (Admin → Pengaturan → Sumber data).',
                'disabled' => 'Dilewati: pencatatan daftar trader dinonaktifkan.',
                default => 'Berhenti: anggaran request Birdeye bulan ini habis.',
            });
        }

        $this->components->twoColumnDetail('Daftar tercatat', (string) $result['lists']);
        $this->components->twoColumnDetail('Baris wallet', (string) $result['wallets']);
        $this->components->twoColumnDetail('Daftar gagal', (string) $result['failed']);

        return self::SUCCESS;
    }
}
