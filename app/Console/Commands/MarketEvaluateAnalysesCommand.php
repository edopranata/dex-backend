<?php

namespace App\Console\Commands;

use App\Services\Analysis\AnalysisEvaluator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:evaluate {--days=3 : Analisis berapa hari terakhir yang mulai dicatat}')]
#[Description('Catat analisis baru dan isi pergerakan harga 1j/4j/24j sesudahnya (untuk statistik kinerja)')]
class MarketEvaluateAnalysesCommand extends Command
{
    public function handle(AnalysisEvaluator $evaluator): int
    {
        $recorded = $evaluator->record((int) $this->option('days'));
        $evaluated = $evaluator->evaluate();

        $this->components->info("{$recorded} analisis mulai dicatat, {$evaluated} evaluasi diperbarui.");

        return self::SUCCESS;
    }
}
