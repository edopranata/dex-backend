<?php

namespace App\Console\Commands;

use App\Models\EquitySnapshot;
use App\Models\User;
use App\Services\Trading\PortfolioSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:equity-snapshot')]
#[Description('Catat ekuitas akun-akun Auto-follow untuk kurva ekuitas dan drawdown')]
class MarketEquitySnapshotCommand extends Command
{
    public function handle(PortfolioSummary $portfolio): int
    {
        foreach (array_keys(User::AUTO_FOLLOW_ACCOUNTS) as $key) {
            $account = User::autoFollower($key);
            $summary = $portfolio->for($account);

            EquitySnapshot::query()->create([
                'user_id' => $account->id,
                'equity_usd' => $summary['equity_usd'],
                'balance_usd' => $summary['balance_usd'],
                'holdings_usd' => $summary['holdings_value_usd'],
                'open_positions' => $summary['open_positions'],
                'sampled_at' => now(),
            ]);
        }

        return self::SUCCESS;
    }
}
