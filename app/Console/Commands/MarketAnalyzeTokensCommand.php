<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeToken;
use App\Models\Token;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:analyze {token? : ID atau simbol token (default: semua)} {--sync : Jalankan langsung tanpa queue}')]
#[Description('Hitung skor gabungan, rekomendasi, entry, stop loss dan take profit')]
class MarketAnalyzeTokensCommand extends Command
{
    public function handle(): int
    {
        $query = Token::query()->active();

        if ($filter = $this->argument('token')) {
            $query->where(fn ($q) => $q->whereKey($filter)->orWhere('symbol', $filter));
        }

        foreach ($query->get() as $token) {
            if (! $this->option('sync')) {
                AnalyzeToken::dispatch($token);

                continue;
            }

            try {
                AnalyzeToken::dispatchSync($token);
            } catch (Throwable $e) {
                $this->components->warn("{$token->symbol}: {$e->getMessage()}");

                continue;
            }

            $analysis = $token->latestAnalysis()->first();
            $this->line(sprintf('  %-10s %-12s skor %3d', $token->symbol, $analysis?->recommendation->value, $analysis?->final_score));
        }

        return self::SUCCESS;
    }
}
