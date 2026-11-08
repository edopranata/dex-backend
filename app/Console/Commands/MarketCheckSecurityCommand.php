<?php

namespace App\Console\Commands;

use App\Jobs\CheckTokenSecurity;
use App\Models\Token;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:security {--sync : Jalankan langsung tanpa queue} {--out-of-market : Token yang pernah di Market tetapi sudah keluar}')]
#[Description('Cek keamanan kontrak dan holder token via GoPlus (honeypot, mint, holder, LP)')]
class MarketCheckSecurityCommand extends Command
{
    /**
     * Requests are spread out: GoPlus refused the last three of twenty sent at once, every time.
     */
    public function handle(): int
    {
        $tokens = $this->option('out-of-market')
            ? Token::query()->everInMarket()->where('is_active', false)->orderBy('id')->get()
            : Token::query()->active()->orderBy('id')->get();
        $spacing = (int) config('market.security.spacing_seconds');

        foreach ($tokens->values() as $index => $token) {
            if (! $this->option('sync')) {
                CheckTokenSecurity::dispatch($token)->delay(now()->addSeconds($index * $spacing));

                continue;
            }

            try {
                CheckTokenSecurity::dispatchSync($token);
            } catch (Throwable $e) {
                $this->components->warn("{$token->symbol}: {$e->getMessage()}");
            }
        }

        $this->components->info(sprintf('Cek keamanan %d token %s.', $tokens->count(), $this->option('sync') ? 'selesai' : "masuk antrean, satu tiap {$spacing} detik"));

        return self::SUCCESS;
    }
}
