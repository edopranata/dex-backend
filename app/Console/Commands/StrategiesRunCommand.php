<?php

namespace App\Console\Commands;

use App\Services\Strategies\StrategyRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('strategies:run {--snapshot : Juga catat ekuitas per jam tiap akun}')]
#[Description('Jalankan akun strategi dari FutureSim di candle DEX: cek stop, target, order limit, dan tambahan martingale di candle 15 menit, lalu keputusan entry dan exit per jam; tanpa permintaan API')]
class StrategiesRunCommand extends Command
{
    public function handle(StrategyRunner $runner): int
    {
        if (! config('market.strategy_accounts.enabled')) {
            $this->components->info('Akun strategi nonaktif (market.strategy_accounts.enabled).');

            return self::SUCCESS;
        }

        $result = $runner->run();
        $this->components->twoColumnDetail('Keputusan token', (string) $result['decided']);
        $this->components->twoColumnDetail('Entry', (string) $result['entries']);
        $this->components->twoColumnDetail('Exit', (string) $result['exits']);

        if ($this->option('snapshot')) {
            $this->components->twoColumnDetail('Snapshot ekuitas', (string) $runner->snapshot());
        }

        return self::SUCCESS;
    }
}
