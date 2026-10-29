<?php

namespace App\Console\Commands;

use App\Services\Analysis\WatchlistManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:watchlist')]
#[Description('Keluarkan token Market yang tidak dikunci, tidak dipakai, dan skornya menurun seminggu terakhir')]
class MarketPruneWatchlistCommand extends Command
{
    public function handle(WatchlistManager $watchlist): int
    {
        $removed = $watchlist->prune();

        $this->components->info($removed === [] ? 'Tidak ada token yang dikeluarkan.' : 'Dikeluarkan: '.implode(', ', $removed));

        return self::SUCCESS;
    }
}
