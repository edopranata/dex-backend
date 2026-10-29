<?php

namespace App\Console\Commands;

use App\Models\Token;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:snapshots')]
#[Description('Simpan snapshot harga & likuiditas per jam (untuk deteksi rug pull)')]
class MarketCaptureSnapshotsCommand extends Command
{
    public function handle(): int
    {
        $tokens = Token::query()->active()->whereNotNull('price_usd')->get();

        foreach ($tokens as $token) {
            $token->snapshots()->create([
                'price_usd' => $token->price_usd,
                'liquidity_usd' => $token->liquidity_usd,
                'volume_h24' => $token->volume_h24,
                'fdv' => $token->fdv,
                'captured_at' => now(),
            ]);
        }

        $this->components->info("{$tokens->count()} snapshot disimpan.");

        return self::SUCCESS;
    }
}
