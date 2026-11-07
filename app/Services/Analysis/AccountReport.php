<?php

namespace App\Services\Analysis;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\AutoFollowDecision;
use App\Models\EquitySnapshot;
use App\Models\Order;
use App\Models\Signal;
use App\Models\User;
use App\Services\Trading\PortfolioSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One auto-follow account as a whole: its own lots (one per signal it bought), judged on what the account
 * really earned after fees and slippage, next to what happened to every signal it follows.
 *
 * A lot's R is its realized P&L over the risk at entry: the filled quantity times the distance from the
 * fill price to the signal's stop loss.
 */
class AccountReport
{
    /** How a closed lot ended, in the order the report lists them. */
    public const EXITS = ['all_targets', 'breakeven', 'stop_loss', 'signal_closed', 'data_closure', 'other'];

    public function __construct(private PortfolioSummary $portfolio) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $key, ?string $version): array
    {
        $definition = User::AUTO_FOLLOW_ACCOUNTS[$key];
        $account = User::autoFollower($key);
        $lots = $this->lots($account, $version);
        $closed = $lots->where('status', 'closed')->values();
        $summary = $this->summarize($closed);
        $starting = $this->portfolio->for($account)['starting_balance'];
        $drawdown = PerformanceReport::maxDrawdownPct(
            EquitySnapshot::query()->where('user_id', $account->id)->orderBy('sampled_at')->get(['equity_usd']),
            $starting,
        );

        return [
            'key' => $key,
            'label' => $definition['label'],
            'version' => $version,
            'summary' => $summary,
            'criteria' => $this->criteria($summary, $drawdown),
            'funnel' => $this->funnel($account, $definition['recommendations'], $version, $lots),
            'exits' => $this->exits($closed),
            'weeks' => $this->weeks($closed),
            'lots' => $lots->sortByDesc('bought_at')->values()->all(),
        ];
    }

    /**
     * Every buy the account made for a signal, with what has been sold from it so far.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lots(User $account, ?string $version): Collection
    {
        $buys = Order::query()
            ->where('user_id', $account->id)
            ->where('side', OrderSide::Buy)
            ->where('status', OrderStatus::Filled)
            ->whereNotNull('signal_id')
            ->with(['signal', 'token'])
            ->get()
            ->filter(fn (Order $buy): bool => $version === null || $buy->signal?->strategy_version === $version);

        // Exits hang off their buy; a data closure was sold for the signal without one.
        $sells = Order::query()
            ->where('user_id', $account->id)
            ->where('side', OrderSide::Sell)
            ->whereIn('signal_id', $buys->pluck('signal_id'))
            ->get()
            ->groupBy(fn (Order $sell): string => $sell->parent_id !== null ? "buy:{$sell->parent_id}" : "signal:{$sell->signal_id}");

        return $buys->map(fn (Order $buy): array => $this->lot($buy, collect([
            ...$sells->get("buy:{$buy->id}", collect()),
            ...$sells->get("signal:{$buy->signal_id}", collect()),
        ])))->values();
    }

    /**
     * @param  Collection<int, Order>  $exits
     * @return array<string, mixed>
     */
    private function lot(Order $buy, Collection $exits): array
    {
        $signal = $buy->signal;
        $filled = $exits->where('status', OrderStatus::Filled);
        $sold = (float) $filled->sum('filled_quantity');
        $remaining = max(0.0, (float) $buy->filled_quantity - $sold);
        $open = $exits->contains('status', OrderStatus::Pending) || $remaining > (float) $buy->filled_quantity * 1e-6;
        $pnl = (float) $filled->sum('realized_pnl');
        $risk = (float) $buy->filled_quantity * ((float) $buy->filled_price - (float) $signal->stop_loss);
        $targets = $filled->where('type', OrderType::TakeProfit)->count();
        $closedAt = $open ? null : $filled->sortBy('filled_at')->last()?->filled_at;
        $price = $buy->token?->price_usd;

        return [
            'id' => $buy->id,
            'token' => ['symbol' => $buy->token?->symbol, 'slug' => $buy->token?->slug],
            'signal' => [
                'id' => $signal->id,
                'recommendation' => $signal->recommendation->value,
                'version' => $signal->strategy_version,
                'status' => $signal->status->value,
                'net_r' => $signal->net_r,
            ],
            'status' => $open ? 'open' : 'closed',
            'bought_at' => $buy->filled_at?->toIso8601String(),
            'buy_price' => (float) $buy->filled_price,
            'amount_usd' => (float) $buy->total_usd,
            'targets_hit' => $targets,
            'exit' => $open ? null : $this->exitOf($buy, $filled, $targets),
            'closed_at' => $closedAt?->toIso8601String(),
            'exit_price' => $sold > 0 ? $filled->sum(fn (Order $o): float => (float) $o->filled_quantity * (float) $o->filled_price) / $sold : null,
            'pnl_usd' => round($pnl, 2),
            'r' => $open || $risk <= 0 ? null : round($pnl / $risk, 2),
            'hours' => $buy->filled_at === null ? null : round($buy->filled_at->diffInMinutes($closedAt ?? now()) / 60, 1),
            'remaining' => $open ? $remaining : 0.0,
            // An estimate: against the buy's fill price, before the selling costs.
            'unrealized_usd' => $open && $price !== null ? round($remaining * ($price - (float) $buy->filled_price), 2) : null,
        ];
    }

    /**
     * @param  Collection<int, Order>  $filled
     */
    private function exitOf(Order $buy, Collection $filled, int $targets): string
    {
        if ($filled->contains(fn (Order $o): bool => str_starts_with((string) $o->note, 'Ditutup otomatis: sinyal'))) {
            return 'signal_closed';
        }

        if ($filled->contains(fn (Order $o): bool => str_contains((string) $o->note, 'Penutupan data'))) {
            return 'data_closure';
        }

        $stop = $filled->firstWhere('type', OrderType::StopLoss);

        if ($stop !== null) {
            return $targets > 0 && (float) $stop->trigger_price >= (float) $buy->filled_price * (1 - 1e-9) ? 'breakeven' : 'stop_loss';
        }

        return $targets >= 3 ? 'all_targets' : 'other';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lots  Closed lots.
     * @return array<string, mixed>
     */
    private function summarize(Collection $lots): array
    {
        $count = $lots->count();
        $wins = $lots->filter(fn (array $lot): bool => $lot['pnl_usd'] > 0)->count();
        $rs = $lots->pluck('r')->filter(fn ($r): bool => $r !== null);
        $gains = $rs->filter(fn (float $r): bool => $r > 0)->sum();
        $losses = abs($rs->filter(fn (float $r): bool => $r < 0)->sum());
        $signalRs = $lots->pluck('signal.net_r')->filter(fn ($r): bool => $r !== null);

        return [
            'lots' => $count,
            'wins' => $wins,
            'win_rate' => $count ? round($wins / $count * 100, 1) : null,
            'avg_r' => $rs->isNotEmpty() ? round((float) $rs->avg(), 2) : null,
            'total_r' => round((float) $rs->sum(), 2),
            'profit_factor' => $losses > 0 ? round($gains / $losses, 2) : null,
            'pnl_usd' => round((float) $lots->sum('pnl_usd'), 2),
            'signal_total_r' => round((float) $signalRs->sum(), 2),
            'avg_hours' => $count ? round((float) $lots->avg('hours'), 1) : null,
        ];
    }

    /**
     * The phase 2 criteria, on the account's own lots.
     *
     * @param  array<string, mixed>  $summary
     * @return list<array{label: string, target: string, value: string, passed: bool, progress?: float}>
     */
    private function criteria(array $summary, ?float $drawdownPct): array
    {
        $count = $summary['lots'];
        $enough = $count >= PerformanceReport::MIN_SIGNALS;
        $r = fn (?float $value): string => $value === null ? '–' : sprintf('%+.2fR', $value);

        return [
            ['label' => 'Lot selesai', 'target' => '≥ '.PerformanceReport::MIN_SIGNALS, 'value' => (string) $count, 'passed' => $enough, 'progress' => min(1.0, $count / PerformanceReport::MIN_SIGNALS)],
            ['label' => 'Rata-rata per lot', 'target' => '> +'.PerformanceReport::MIN_AVG_R.'R', 'value' => $r($summary['avg_r']), 'passed' => $enough && $summary['avg_r'] > PerformanceReport::MIN_AVG_R],
            ['label' => 'Total R akun', 'target' => 'Positif', 'value' => $r($summary['total_r']), 'passed' => $enough && $summary['total_r'] > 0],
            ['label' => 'Profit factor', 'target' => '> '.PerformanceReport::MIN_PROFIT_FACTOR, 'value' => $summary['profit_factor'] === null ? '–' : (string) $summary['profit_factor'], 'passed' => $enough && $summary['profit_factor'] > PerformanceReport::MIN_PROFIT_FACTOR],
            ['label' => 'Drawdown ekuitas', 'target' => '< '.PerformanceReport::MAX_DRAWDOWN_PCT.'%', 'value' => $drawdownPct === null ? '–' : "{$drawdownPct}%", 'passed' => $enough && $drawdownPct !== null && $drawdownPct < PerformanceReport::MAX_DRAWDOWN_PCT],
        ];
    }

    /**
     * From every signal the account follows to the lots it closed with a profit.
     *
     * @param  list<string>  $recommendations
     * @param  Collection<int, array<string, mixed>>  $lots
     * @return array<string, mixed>
     */
    private function funnel(User $account, array $recommendations, ?string $version, Collection $lots): array
    {
        $signals = Signal::query()
            ->whereIn('recommendation', $recommendations)
            ->where('created_at', '>=', $account->created_at)
            ->when($version !== null, fn ($query) => $query->where('strategy_version', $version))
            ->get(['id', 'status', 'net_r']);
        $ids = $signals->pluck('id');
        $buys = Order::query()->where('user_id', $account->id)->where('side', OrderSide::Buy)->whereIn('signal_id', $ids)->get(['signal_id', 'status'])->keyBy('signal_id');
        $decisions = AutoFollowDecision::query()->where('user_id', $account->id)->whereIn('signal_id', $ids)->get()->keyBy('signal_id');

        $skipped = $signals->filter(fn (Signal $s): bool => ! $buys->has($s->id) && $decisions->get($s->id)?->decision === AutoFollowDecision::SKIPPED);
        $missed = $skipped->filter(fn (Signal $s): bool => $s->net_r !== null);
        $closed = $lots->where('status', 'closed');

        return [
            'signals' => $signals->count(),
            'bought' => $buys->count(),
            'filled' => $buys->where('status', OrderStatus::Filled)->count(),
            'waiting' => $buys->where('status', OrderStatus::Pending)->count(),
            'cancelled' => $buys->whereIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->count(),
            'skipped' => $skipped->count(),
            'skipped_by_reason' => $skipped->countBy(fn (Signal $s): string => (string) $decisions[$s->id]->reason)->all(),
            'unknown' => $signals->count() - $buys->count() - $skipped->count(),
            'open_lots' => $lots->where('status', 'open')->count(),
            'closed_lots' => $closed->count(),
            'winning_lots' => $closed->filter(fn (array $lot): bool => $lot['pnl_usd'] > 0)->count(),
            'missed_signals' => $missed->count(),
            'missed_r' => round((float) $missed->sum('net_r'), 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lots  Closed lots.
     * @return list<array<string, mixed>>
     */
    private function exits(Collection $lots): array
    {
        return collect(self::EXITS)
            ->map(function (string $exit) use ($lots): array {
                $group = $lots->where('exit', $exit);

                return [
                    'exit' => $exit,
                    'lots' => $group->count(),
                    'total_r' => round((float) $group->sum('r'), 2),
                    'pnl_usd' => round((float) $group->sum('pnl_usd'), 2),
                ];
            })
            ->filter(fn (array $row): bool => $row['lots'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lots  Closed lots.
     * @return list<array<string, mixed>>
     */
    private function weeks(Collection $lots): array
    {
        $timezone = (string) config('market.backup.timezone');
        $cumulative = 0.0;
        $weeks = [];

        $byWeek = $lots->sortBy('closed_at')
            ->groupBy(fn (array $lot): string => Carbon::parse($lot['closed_at'])->setTimezone($timezone)->startOfWeek(Carbon::MONDAY)->toDateString());

        foreach ($byWeek as $start => $group) {
            $summary = $this->summarize($group);
            $cumulative += $summary['pnl_usd'];
            $weeks[] = ['week_start' => $start, ...$summary, 'cumulative_pnl_usd' => round($cumulative, 2)];
        }

        return array_reverse($weeks);
    }
}
