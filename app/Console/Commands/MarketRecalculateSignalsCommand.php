<?php

namespace App\Console\Commands;

use App\Models\Signal;
use App\Services\Analysis\SignalResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:signals-recalculate')]
#[Description('Hitung ulang hasil R kotor & bersih semua sinyal yang sudah selesai (dari harga keluar sebenarnya, fee, dan slippage)')]
class MarketRecalculateSignalsCommand extends Command
{
    public function handle(SignalResult $results): int
    {
        $count = 0;

        Signal::query()->with('token')->whereNotNull('result_r')->whereNotNull('entry_price')->whereNotNull('exit_price')->chunkById(200, function ($signals) use ($results, &$count): void {
            foreach ($signals as $signal) {
                $result = $results->calculate($signal, (float) $signal->exit_price, (int) $signal->max_tp_hit, $signal->token?->liquidity_usd);

                // Bookkeeping only: must not trigger the notifications of a status change.
                Signal::withoutEvents(fn () => $signal->update(['result_r' => $result['gross_r'], 'net_r' => $result['net_r'], 'cost_r' => $result['cost_r']]));
                $count++;
            }
        });

        $this->components->info("{$count} sinyal dihitung ulang.");

        return self::SUCCESS;
    }
}
