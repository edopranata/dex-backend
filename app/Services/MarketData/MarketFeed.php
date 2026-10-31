<?php

namespace App\Services\MarketData;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SignalStatus;
use App\Enums\WatchlistAction;
use App\Models\Order;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Models\WatchlistEvent;
use App\Models\WhaleTrade;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the app shows around every page: the running text of open buy signals, and short notices about
 * what happened since the last visit. Rows are chosen by the time they were recorded, because whale
 * trades arrive in batches long after they were traded.
 */
class MarketFeed
{
    private const TICKER_LIMIT = 20;

    /** A tab that was hidden for hours should not replay everything. */
    private const MAX_LOOKBACK_MINUTES = 15;

    private const MAX_EVENTS = 12;

    private const MAX_WHALE_GROUPS = 4;

    /**
     * Open buy signals, strongest first, one per token.
     *
     * @return list<array<string, mixed>>
     */
    public function ticker(): array
    {
        return Signal::query()
            ->open()
            ->with(['token', 'analysis:id,final_score'])
            ->whereHas('token', fn ($query) => $query->active())
            ->latest()
            ->get()
            ->filter(fn (Signal $signal): bool => $signal->recommendation->isBullish())
            ->unique('token_id')
            ->sortBy(fn (Signal $signal): int => $signal->recommendation->value === 'STRONG_BUY' ? 0 : 1)
            ->take(self::TICKER_LIMIT)
            ->values()
            ->map(fn (Signal $signal): array => [
                'id' => $signal->id,
                'recommendation' => $signal->recommendation,
                'status' => $signal->status,
                'score' => $signal->analysis?->final_score,
                'token' => [
                    'id' => $signal->token->id,
                    'slug' => $signal->token->slug,
                    'symbol' => $signal->token->symbol,
                    'image_url' => $signal->token->image_url,
                    'price_usd' => $signal->token->price_usd,
                    'change_h24' => $signal->token->price_change_h24,
                ],
            ])
            ->all();
    }

    /**
     * Events recorded from $since up to, but not including, $until, so the next window continues
     * exactly where this one ends.
     *
     * @return list<array<string, mixed>>
     */
    public function events(User $user, Carbon $since, Carbon $until): array
    {
        $since = $since->max($until->copy()->subMinutes(self::MAX_LOOKBACK_MINUTES));

        return collect()
            ->merge($this->orders($user, $since, $until))
            ->merge($this->signals($since, $until))
            ->merge($this->watchlist($since, $until))
            ->merge($this->whales($since, $until))
            ->take(self::MAX_EVENTS)
            ->values()
            ->all();
    }

    /**
     * Automatic fills of the user's own orders. Market orders are not included: the user just placed them.
     */
    private function orders(User $user, Carbon $since, Carbon $until): Collection
    {
        return $user->orders()
            ->with('token')
            ->where('status', OrderStatus::Filled)
            ->where('type', '!=', OrderType::Market)
            ->where('filled_at', '>=', $since)
            ->where('filled_at', '<', $until)
            ->latest('filled_at')
            ->get()
            ->map(fn (Order $order): array => $this->event(
                "order:{$order->id}",
                'order',
                $order->type === OrderType::StopLoss ? 'down' : 'up',
                match ($order->type) {
                    OrderType::StopLoss => "Stop loss tereksekusi: {$order->token->symbol}",
                    OrderType::TakeProfit => "Take profit tereksekusi: {$order->token->symbol}",
                    default => "Order limit terisi: {$order->token->symbol}",
                },
                TelegramNotifier::usd((float) $order->total_usd).' di harga '.TelegramNotifier::price((float) $order->filled_price),
                $order->token,
            ));
    }

    private function signals(Carbon $since, Carbon $until): Collection
    {
        $created = Signal::query()->with(['token', 'analysis:id,final_score'])
            ->where('created_at', '>=', $since)->where('created_at', '<', $until)->latest()->get()
            ->map(fn (Signal $signal): array => $this->event(
                "signal:{$signal->id}:new",
                'signal',
                'up',
                "Sinyal {$signal->recommendation->label()}: {$signal->token->symbol}",
                'Entry di '.TelegramNotifier::price((float) $signal->entry_high).($signal->analysis ? " · skor {$signal->analysis->final_score}" : ''),
                $signal->token,
            ));

        $entered = Signal::query()->with('token')
            ->where('entered_at', '>=', $since)->where('entered_at', '<', $until)->latest('entered_at')->get()
            ->map(fn (Signal $signal): array => $this->event(
                "signal:{$signal->id}:entered",
                'signal',
                'info',
                "Entry tercapai: {$signal->token->symbol}",
                "Sinyal {$signal->recommendation->label()} di ".TelegramNotifier::price((float) $signal->entry_price),
                $signal->token,
            ));

        // Expired and invalidated signals never held a position, so they are not news.
        $closed = Signal::query()->with('token')
            ->whereIn('status', [SignalStatus::TakeProfit, SignalStatus::StopLoss])
            ->where('closed_at', '>=', $since)->where('closed_at', '<', $until)->latest('closed_at')->get()
            ->map(fn (Signal $signal): array => $this->event(
                "signal:{$signal->id}:closed",
                'signal',
                (float) $signal->net_r > 0 ? 'up' : 'down',
                ($signal->status === SignalStatus::TakeProfit ? 'Sinyal kena take profit: ' : 'Sinyal kena stop loss: ').$signal->token->symbol,
                'Hasil bersih '.TelegramNotifier::r((float) $signal->net_r),
                $signal->token,
            ));

        return $created->merge($entered)->merge($closed);
    }

    private function watchlist(Carbon $since, Carbon $until): Collection
    {
        return WatchlistEvent::query()->with('token')
            ->whereIn('action', [WatchlistAction::Added, WatchlistAction::Removed])
            ->where('created_at', '>=', $since)->where('created_at', '<', $until)->latest()->get()
            ->map(fn (WatchlistEvent $event): array => $this->event(
                "watchlist:{$event->id}",
                'market',
                $event->action === WatchlistAction::Added ? 'up' : 'warn',
                ($event->action === WatchlistAction::Added ? 'Masuk Market: ' : 'Keluar dari Market: ').$event->token->symbol,
                mb_strimwidth((string) $event->reason, 0, 110, '…'),
                $event->token,
            ));
    }

    /**
     * Big trades per token and side, because a batch can hold dozens of them.
     */
    private function whales(Carbon $since, Carbon $until): Collection
    {
        return WhaleTrade::query()->with('token')
            ->where('amount_usd', '>=', (float) config('market.feed.whale_min_usd'))
            ->where('created_at', '>=', $since)->where('created_at', '<', $until)
            ->get()
            ->groupBy(fn (WhaleTrade $trade): string => "{$trade->token_id}:{$trade->side->value}")
            ->map(fn (Collection $trades): array => [
                'first' => $trades->sortBy('id')->first(),
                'count' => $trades->count(),
                'total' => (float) $trades->sum('amount_usd'),
            ])
            ->sortByDesc('total')
            ->take(self::MAX_WHALE_GROUPS)
            ->values()
            ->map(function (array $group): array {
                $buying = $group['first']->side->value === 'buy';

                return $this->event(
                    "whale:{$group['first']->id}",
                    'whale',
                    $buying ? 'up' : 'down',
                    ($buying ? 'Whale membeli ' : 'Whale menjual ').$group['first']->token->symbol,
                    "{$group['count']} transaksi · total ".TelegramNotifier::usd($group['total']),
                    $group['first']->token,
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $id, string $category, string $tone, string $title, string $body, Token $token): array
    {
        return [
            'id' => $id,
            'category' => $category,
            'tone' => $tone,
            'title' => $title,
            'body' => $body,
            'token' => ['slug' => $token->slug, 'symbol' => $token->symbol, 'image_url' => $token->image_url],
        ];
    }
}
