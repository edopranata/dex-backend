<?php

namespace App\Console\Commands;

use App\Models\Signal;
use App\Models\Token;
use App\Services\Analysis\SignalTracker;
use App\Services\MarketData\PriceService;
use App\Services\Trading\TradingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:prices')]
#[Description('Perbarui harga real-time, eksekusi order pending, dan lacak hasil sinyal')]
class MarketRefreshPricesCommand extends Command
{
    public function handle(PriceService $prices, TradingService $trading, SignalTracker $tracker): int
    {
        $tokens = $prices->refresh(Token::query()->active()->get());

        $filled = $trading->matchPendingOrders($tokens);

        $priceById = $tokens->pluck('price_usd', 'id');

        Signal::query()->open()->whereIn('token_id', $priceById->filter()->keys())->each(
            fn (Signal $signal) => $tracker->track($signal, (float) $priceById[$signal->token_id]),
        );

        $this->components->info("{$tokens->count()} harga diperbarui, {$filled} order tereksekusi.");

        return self::SUCCESS;
    }
}
