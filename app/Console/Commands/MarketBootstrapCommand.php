<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:bootstrap')]
#[Description('Isi data awal: harga, snapshot, keamanan, candle, whale, berita, lalu analisis (tanpa queue)')]
class MarketBootstrapCommand extends Command
{
    public function handle(): int
    {
        $steps = [
            'market:prices' => [],
            'market:snapshots' => [],
            'market:security' => ['--sync' => true],
            'market:candles' => ['--sync' => true],
            'market:whales' => ['--sync' => true],
            'market:news' => ['--sync' => true],
            'market:analyze' => ['--sync' => true],
        ];

        foreach ($steps as $command => $arguments) {
            $this->components->task($command, fn () => $this->call($command, $arguments) === self::SUCCESS);
        }

        return self::SUCCESS;
    }
}
