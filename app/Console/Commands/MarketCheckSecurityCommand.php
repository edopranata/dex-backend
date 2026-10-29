<?php

namespace App\Console\Commands;

use App\Jobs\CheckTokenSecurity;
use App\Models\Token;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:security {--sync : Jalankan langsung tanpa queue}')]
#[Description('Cek keamanan kontrak token via GoPlus (honeypot, mint, holder, LP)')]
class MarketCheckSecurityCommand extends Command
{
    public function handle(): int
    {
        foreach (Token::query()->active()->get() as $token) {
            if (! $this->option('sync')) {
                CheckTokenSecurity::dispatch($token);

                continue;
            }

            try {
                CheckTokenSecurity::dispatchSync($token);
            } catch (Throwable $e) {
                $this->components->warn("{$token->symbol}: {$e->getMessage()}");
            }
        }

        $this->components->info('Cek keamanan '.($this->option('sync') ? 'selesai.' : 'masuk antrean.'));

        return self::SUCCESS;
    }
}
