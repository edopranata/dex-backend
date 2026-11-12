<?php

namespace App\Services\Analysis;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\EquitySnapshot;
use App\Models\ShadowSignal;
use App\Models\Signal;
use App\Models\User;
use App\Services\Trading\AutoFollowRisk;
use App\Services\Trading\PortfolioSummary;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How the signals and the auto-follow paper account perform, against the Fase 2 criteria that decide
 * whether real money may be used. Signals that never entered have no result and are not counted.
 */
class PerformanceReport
{
    private const MAX_CURVE_POINTS = 300;

    public const MIN_SIGNALS = 30;

    public const MIN_AVG_R = 0.2;

    public const MIN_PROFIT_FACTOR = 1.3;

    public const MAX_DRAWDOWN_PCT = 15.0;

    public function __construct(private PortfolioSummary $portfolio, private AutoFollowRisk $risk) {}

    /**
     * @param  string|null  $version  One strategy version, or null for all of them.
     * @return array<string, mixed>
     */
    public function build(?string $version): array
    {
        $signals = Signal::query()->with('token')
            ->whereNotNull('closed_at')->whereNotNull('entry_price')->whereNotNull('net_r')
            ->when($version !== null, fn ($query) => $query->where('strategy_version', $version))
            ->orderBy('closed_at')
            ->get();

        $summary = $this->summarize($signals);
        $accounts = collect(User::AUTO_FOLLOW_ACCOUNTS)->map(fn (array $definition, string $key): array => $this->autoFollow($key, $definition))->values();
        $autoFollow = $accounts->firstWhere('key', 'all');

        return [
            'version' => $version,
            'current_version' => StrategyVersion::current(),
            // The current version is listed even before it has its first signal.
            'versions' => Signal::query()->whereNotNull('strategy_version')->distinct()->pluck('strategy_version')->push(StrategyVersion::current())->unique()->sortDesc()->values(),
            'summary' => $summary,
            'criteria' => $this->criteria($summary, $autoFollow['max_drawdown_pct']),
            'weeks' => $this->weeks($signals),
            'recommendations' => $signals->groupBy(fn (Signal $signal): string => $signal->recommendation->value)
                ->map(fn (Collection $group, string $recommendation): array => ['recommendation' => $recommendation, ...$this->summarize($group)])
                ->values(),
            // Per network, to help choose which chain's wallet to use with real money.
            'chains' => $signals->groupBy(fn (Signal $signal): string => $signal->token->chain_id)
                ->map(fn (Collection $group, string $chain): array => [
                    'chain' => $chain,
                    'label' => config("market.chains.{$chain}.label", $chain),
                    ...$this->summarize($group),
                    'by_recommendation' => $group->groupBy(fn (Signal $signal): string => $signal->recommendation->value)
                        ->map(fn (Collection $part): array => $this->summarize($part))
                        ->all(),
                ])
                ->sortByDesc('trades')
                ->values(),
            'tokens' => $signals->groupBy('token_id')
                ->map(fn (Collection $group): array => ['symbol' => $group->first()->token->symbol, 'slug' => $group->first()->token->slug, ...$this->summarize($group)])
                ->sortByDesc('total_r')
                ->values(),
            'accounts' => $accounts,
            'shadow_exit' => $this->shadowExit($signals),
            'holder_filter' => $this->holderFilter(),
        ];
    }

    /**
     * v6 comparison from shadow signals, both groups followed on 15m candles by the same method.
     *
     * @return list<array{rule: string, recorded: int, open: int, overlap: int, not_entered: int, trades: int, wins: int, total_r: float, avg_r: float|null}>
     */
    private function holderFilter(): array
    {
        return collect([ShadowSignal::ACCEPTED, ShadowSignal::HELD_BY_HOLDERS])->map(function (string $rule): array {
            $shadows = ShadowSignal::query()->where('rule', $rule)->get(['status', 'net_r']);
            $traded = $shadows->filter(fn (ShadowSignal $shadow): bool => $shadow->net_r !== null);

            return [
                'rule' => $rule,
                'recorded' => $shadows->count(),
                'open' => $shadows->where('status', ShadowSignal::PENDING)->count(),
                'overlap' => $shadows->where('status', ShadowSignal::OVERLAP)->count(),
                'not_entered' => $shadows->where('status', ShadowSignal::NOT_ENTERED)->count(),
                'trades' => $traded->count(),
                'wins' => $traded->filter(fn (ShadowSignal $shadow): bool => $shadow->net_r > 0)->count(),
                'total_r' => round((float) $traded->sum('net_r'), 2),
                'avg_r' => $traded->isEmpty() ? null : round((float) $traded->avg('net_r'), 2),
            ];
        })->all();
    }

    /**
     * Candidate v5 rules measured on the closed signals without changing a trade. Each variant only counts
     * signals entered after its data started being recorded. An exit keeps the targets reached before it and
     * takes the real costs; a signal a rule would not have opened counts as 0R.
     *
     * @param  Collection<int, Signal>  $signals
     * @return list<array{key: string, label: string, since: string, signals: int, affected: int, actual_r: float, shadow_r: float}>
     */
    private function shadowExit(Collection $signals): array
    {
        $since = Carbon::parse((string) config('market.analysis.shadow_exit_since'), 'UTC');
        $secondSince = Carbon::parse((string) config('market.analysis.shadow_second_since'), 'UTC');
        $entered = fn (Carbon $from): Collection => $signals->filter(fn (Signal $signal): bool => $signal->entered_at !== null && $signal->entered_at->gte($from));

        return [
            $this->variant('first_downgrade', 'Keluar saat pertama kali tidak lagi Buy', $since, $entered($since), fn (Signal $s): ?float => $s->downgraded_at !== null && $s->downgraded_at->lte($s->closed_at)
                ? $this->exitR($s, (float) $s->downgrade_price, (int) $s->downgrade_tp_hit)
                : null),
            $this->variant('first_check', 'Keluar bila analisa pertama setelah entry tidak lagi Buy', $since, $entered($since), function (Signal $s): ?float {
                $first = $s->token->analyses()->where('created_at', '>', $s->entered_at)->where('created_at', '<=', $s->closed_at)->oldest()->first(['recommendation', 'price', 'created_at']);

                if ($first === null || $first->recommendation->isBullish()) {
                    return null;
                }

                $taken = $s->downgraded_at?->equalTo($first->created_at) ? (int) $s->downgrade_tp_hit : 0;

                return $this->exitR($s, (float) $first->price, $taken);
            }),
            $this->variant('second_downgrade', 'Keluar setelah dua analisa berturut-turut tidak Buy', $secondSince, $entered($secondSince), fn (Signal $s): ?float => $s->second_downgraded_at !== null && $s->second_downgraded_at->lte($s->closed_at)
                ? $this->exitR($s, (float) $s->second_downgrade_price, (int) $s->second_downgrade_tp_hit)
                : null),
            $this->variant('confirmed_entry', 'Hanya entry yang analisa sebelumnya juga Buy', $since, $entered($since), function (Signal $s): ?float {
                $previous = $s->token->analyses()->where('id', '<', $s->analysis_id)->latest('id')->first(['recommendation', 'created_at']);
                $confirmed = $previous !== null && $previous->recommendation->isBullish() && $previous->created_at->gte($s->created_at->copy()->subMinutes(20));

                return $confirmed ? null : 0.0;
            }),
        ];
    }

    /**
     * @param  Collection<int, Signal>  $observed
     * @param  Closure(Signal): (float|null)  $rule  The R under the rule, or null where the rule changes nothing.
     * @return array{key: string, label: string, since: string, signals: int, affected: int, actual_r: float, shadow_r: float}
     */
    private function variant(string $key, string $label, Carbon $since, Collection $observed, Closure $rule): array
    {
        $affected = 0;
        $shadow = 0.0;

        foreach ($observed as $signal) {
            $r = $signal->entry_price > $signal->stop_loss ? $rule($signal) : null;
            $affected += $r === null ? 0 : 1;
            $shadow += $r ?? (float) $signal->net_r;
        }

        return [
            'key' => $key,
            'label' => $label,
            'since' => $since->toIso8601String(),
            'signals' => $observed->count(),
            'affected' => $affected,
            'actual_r' => round((float) $observed->sum('net_r'), 2),
            'shadow_r' => round($shadow, 2),
        ];
    }

    /**
     * Net R of a signal closed at a price, with the thirds already sold at the targets reached before it.
     */
    private function exitR(Signal $signal, float $price, int $taken): float
    {
        $risk = $signal->entry_price - $signal->stop_loss;
        $gross = (1 - $taken / 3) * ($price - $signal->entry_price) / $risk;

        foreach (array_slice($signal->takeProfits(), 0, $taken) as $target) {
            $gross += ($target - $signal->entry_price) / $risk / 3;
        }

        return $gross - (float) $signal->cost_r;
    }

    /**
     * @param  Collection<int, Signal>  $signals
     * @return array<string, mixed>
     */
    private function summarize(Collection $signals): array
    {
        return Backtester::summarize($signals->map(fn (Signal $signal): array => [
            'net_r' => (float) $signal->net_r,
            'gross_r' => (float) $signal->result_r,
            'cost_r' => (float) $signal->cost_r,
            'token' => $signal->token_id,
            'closed_at' => $signal->closed_at->getTimestamp(),
        ])->values()->all());
    }

    /**
     * Calendar weeks in local time, Monday first, newest first.
     *
     * @param  Collection<int, Signal>  $signals
     * @return list<array<string, mixed>>
     */
    private function weeks(Collection $signals): array
    {
        $timezone = (string) config('market.backup.timezone');
        $cumulative = 0.0;
        $weeks = [];

        $byWeek = $signals->groupBy(fn (Signal $signal): string => $signal->closed_at->copy()->setTimezone($timezone)->startOfWeek(Carbon::MONDAY)->toDateString());

        foreach ($byWeek as $start => $group) {
            $summary = $this->summarize($group);
            $cumulative += $summary['total_r'];
            $weeks[] = ['week_start' => $start, ...$summary, 'cumulative_r' => round($cumulative, 2)];
        }

        return array_reverse($weeks);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array<string, mixed>>
     */
    private function criteria(array $summary, ?float $drawdownPct): array
    {
        $count = $summary['trades'];

        // Averages from a handful of signals are noise, so they only count once there are enough.
        $enough = $count >= self::MIN_SIGNALS;

        return [
            ['label' => 'Sinyal selesai', 'target' => '≥ '.self::MIN_SIGNALS, 'value' => (string) $count, 'passed' => $enough],
            ['label' => 'Rata-rata per sinyal', 'target' => '> +'.self::MIN_AVG_R.'R', 'value' => $this->r($summary['avg_r']), 'passed' => $enough && $summary['avg_r'] > self::MIN_AVG_R],
            ['label' => 'Total R bersih', 'target' => 'Positif', 'value' => $this->r($summary['total_r']), 'passed' => $enough && $summary['total_r'] > 0],
            ['label' => 'Profit factor', 'target' => '> '.self::MIN_PROFIT_FACTOR, 'value' => $summary['profit_factor'] === null ? '–' : (string) $summary['profit_factor'], 'passed' => $enough && $summary['profit_factor'] > self::MIN_PROFIT_FACTOR],
            ['label' => 'Drawdown Auto-follow', 'target' => '< '.self::MAX_DRAWDOWN_PCT.'%', 'value' => $drawdownPct === null ? '–' : "{$drawdownPct}%", 'passed' => $enough && $drawdownPct !== null && $drawdownPct < self::MAX_DRAWDOWN_PCT],
        ];
    }

    private function r(?float $value): string
    {
        return $value === null ? '–' : sprintf('%+.2fR', $value);
    }

    /**
     * @param  array{label: string, recommendations: list<string>, chains?: list<string>}  $definition
     * @return array<string, mixed>
     */
    private function autoFollow(string $key, array $definition): array
    {
        $account = User::autoFollower($key);
        $summary = $this->portfolio->for($account);
        $snapshots = EquitySnapshot::query()->where('user_id', $account->id)->orderBy('sampled_at')->get(['equity_usd', 'sampled_at']);
        $drawdown = self::maxDrawdownPct($snapshots, $summary['starting_balance']);

        $step = max(1, (int) ceil($snapshots->count() / self::MAX_CURVE_POINTS));
        $curve = $snapshots->filter(fn (EquitySnapshot $snapshot, int $index): bool => $index % $step === 0 || $index === $snapshots->count() - 1)
            ->map(fn (EquitySnapshot $snapshot): array => ['t' => $snapshot->sampled_at->getTimestamp(), 'equity' => $snapshot->equity_usd])
            ->values();

        return [
            'key' => $key,
            'label' => $definition['label'],
            'recommendations' => $definition['recommendations'],
            'chains' => $definition['chains'] ?? null,
            'started_at' => $account->created_at?->toIso8601String(),
            ...$summary,
            ...$this->closedSignals($account),
            'max_drawdown_pct' => $drawdown,
            'curve_started_at' => $snapshots->first()?->sampled_at?->toIso8601String(),
            'curve' => $curve,
            'limits' => [
                'max_open_positions' => (int) config('market.autofollow.max_open_positions'),
                'risk_pct' => (float) config('market.autofollow.risk_pct'),
                'max_daily_loss_pct' => (float) config('market.autofollow.max_daily_loss_pct'),
            ],
            'realized_today' => round($this->risk->realizedToday($account), 2),
            'daily_loss_reached' => $this->risk->dailyLossReached($account),
            'has_room' => $this->risk->hasRoom($account),
        ];
    }

    /**
     * The deepest fall from a peak of the equity curve, in percent. The account starts at its starting
     * balance, so that is the first peak. Null without any snapshot.
     *
     * @param  iterable<EquitySnapshot>  $snapshots  Oldest first.
     */
    public static function maxDrawdownPct(iterable $snapshots, float $startingBalance): ?float
    {
        $peak = $startingBalance;
        $drawdown = null;

        foreach ($snapshots as $snapshot) {
            $peak = max($peak, $snapshot->equity_usd);
            $drawdown = max($drawdown ?? 0.0, ($peak - $snapshot->equity_usd) / $peak * 100);
        }

        return $drawdown === null ? null : round($drawdown, 2);
    }

    /**
     * Results of the signals this account really bought, and that have finished. A win is what the
     * account itself earned on the signal, because its own size and costs apply.
     *
     * @return array{closed_signals: int, wins: int, win_rate: float|null, avg_r: float|null, total_r: float}
     */
    private function closedSignals(User $account): array
    {
        $filled = $account->orders()->where('status', OrderStatus::Filled)->whereNotNull('signal_id')->get(['signal_id', 'side', 'realized_pnl']);
        $bought = $filled->where('side', OrderSide::Buy)->pluck('signal_id')->unique();

        $signals = Signal::query()->whereIn('id', $bought)->whereNotNull('closed_at')->whereNotNull('net_r')->get(['id', 'net_r']);
        $wins = $signals->filter(fn (Signal $signal): bool => (float) $filled->where('signal_id', $signal->id)->sum('realized_pnl') > 0)->count();
        $count = $signals->count();

        return [
            'closed_signals' => $count,
            'wins' => $wins,
            'win_rate' => $count ? round($wins / $count * 100, 1) : null,
            'avg_r' => $count ? round((float) $signals->avg('net_r'), 2) : null,
            'total_r' => round((float) $signals->sum('net_r'), 2),
        ];
    }
}
