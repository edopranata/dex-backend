<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isLocal()) {
            User::firstOrCreate(['email' => 'demo@dex.test'], [
                'name' => 'Demo Trader',
                'password' => 'password',
                'balance_usd' => config('market.simulator.starting_balance'),
            ]);
        }

        $this->call(MarketTokenSeeder::class);
    }
}
