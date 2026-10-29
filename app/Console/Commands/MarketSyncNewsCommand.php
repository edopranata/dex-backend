<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeNewsSentiment;
use App\Services\MarketData\NewsSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:news {--sync : Analisis sentimen langsung tanpa queue}')]
#[Description('Ambil berita dari RSS lalu analisis sentimennya')]
class MarketSyncNewsCommand extends Command
{
    public function handle(NewsSyncService $news): int
    {
        $created = $news->sync();

        $this->option('sync') ? AnalyzeNewsSentiment::dispatchSync() : AnalyzeNewsSentiment::dispatch();

        $this->components->info("{$created->count()} berita baru.");

        return self::SUCCESS;
    }
}
