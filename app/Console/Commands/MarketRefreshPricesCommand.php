<?php

namespace App\Console\Commands;

use App\Models\Signal;
use App\Models\Token;
use App\Services\Analysis\SignalTracker;
use App\Services\MarketData\PriceCrossCheck;
use App\Services\MarketData\PriceService;
use App\Services\Trading\TradingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('market:prices')]
#[Description('Perbarui harga real-time, eksekusi order pending, dan lacak hasil sinyal')]
class MarketRefreshPricesCommand extends Command
{
    public function handle(PriceService $prices, TradingService $trading, SignalTracker $tracker, PriceCrossCheck $crossCheck): int
    {
        $tokens = $prices->refresh(Token::query()->active()->get());

        $filled = $trading->matchPendingOrders($tokens);

        // A token without a recent earlier check waits for its next one, like its orders do.
        $confirmed = $tokens->filter(fn (Token $token): bool => $token->price_usd !== null && $token->confirmingPrice() !== null)->keyBy('id');

        // A level reached on the feed is checked against GeckoTerminal first, as orders are.
        Signal::query()->open()->whereIn('token_id', $confirmed->keys())->each(function (Signal $signal) use ($tracker, $crossCheck, $confirmed): void {
            $token = $confirmed[$signal->token_id];

            $tracker->track($signal, (float) $token->price_usd, $token->confirmingPrice(), fn (): ?float => $crossCheck->priceToActOn($token)?->price);
        });

        // After tracking, so a signal that closed on this check frees its auto-follow lots straight away.
        $lots = $trading->closeFinishedSignalLots();

        $this->components->info("{$tokens->count()} harga diperbarui, {$filled} order tereksekusi, {$lots} lot auto-follow ditutup.");

        return self::SUCCESS;
    }
}
