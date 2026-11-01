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

        // A token without a recent earlier check waits for its next one, like its orders do.
        $confirmed = $tokens->filter(fn (Token $token): bool => $token->price_usd !== null && $token->confirmingPrice() !== null)->keyBy('id');

        Signal::query()->open()->whereIn('token_id', $confirmed->keys())->each(
            fn (Signal $signal) => $tracker->track($signal, (float) $confirmed[$signal->token_id]->price_usd, $confirmed[$signal->token_id]->confirmingPrice()),
        );

        $this->components->info("{$tokens->count()} harga diperbarui, {$filled} order tereksekusi.");

        return self::SUCCESS;
    }
}
