<?php

namespace App\Console\Commands;

use App\Services\MarketData\TokenRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('market:add {chain : Chain id DEX Screener, mis. solana, ethereum, base, bsc} {address : Alamat pair atau alamat token}')]
#[Description('Tambahkan token ke daftar pantauan')]
class MarketAddTokenCommand extends Command
{
    public function handle(TokenRegistry $registry): int
    {
        try {
            $token = $registry->add($this->argument('chain'), $this->argument('address'));
        } catch (ValidationException $e) {
            $this->components->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->components->info("{$token->symbol}/{$token->quote_symbol} di {$token->chain_id} dipantau (pair {$token->pair_address}).");

        return self::SUCCESS;
    }
}
