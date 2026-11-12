<?php

namespace App\Console\Commands;

use App\Services\Analysis\ShadowSignalEvaluator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:shadow-signals')]
#[Description('Hitung hasil sinyal bayangan v6 (Strong Buy yang ditahan aturan holder, dan sinyal asli sebagai pembanding) dari candle 15 menit')]
class MarketShadowSignalsCommand extends Command
{
    public function handle(ShadowSignalEvaluator $evaluator): int
    {
        $result = $evaluator->run();

        $this->components->twoColumnDetail('Selesai dihitung', (string) $result['evaluated']);
        $this->components->twoColumnDetail('Masih berjalan', (string) $result['still_open']);

        return self::SUCCESS;
    }
}
