<?php

namespace App\Services\Analysis;

use App\Models\EquitySnapshot;
use App\Models\Signal;
use App\Models\User;
use App\Services\Trading\AutoFollowRisk;
use App\Services\Trading\PortfolioSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How the signals and the auto-follow paper account perform, against the Fase 2 criteria that decide
 * whether real money may be used. Signals that never entered have no result and are not counted.
 */
class PerformanceReport
{
    private const MAX_CURVE_POINTS = 300;

    private const MIN_SIGNALS = 30;

    private const MIN_AVG_R = 0.2;

    private const MIN_PROFIT_FACTOR = 1.3;

    private const MAX_DRAWDOWN_PCT = 15.0;

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
        $autoFollow = $this->autoFollow();

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
            'tokens' => $signals->groupBy('token_id')
                ->map(fn (Collection $group): array => ['symbol' => $group->first()->token->symbol, 'slug' => $group->first()->token->slug, ...$this->summarize($group)])
                ->sortByDesc('total_r')
                ->values(),
            'auto_follow' => $autoFollow,
        ];
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
     * @return array<string, mixed>
     */
    private function autoFollow(): array
    {
        $account = User::autoFollower();
        $summary = $this->portfolio->for($account);
        $snapshots = EquitySnapshot::query()->where('user_id', $account->id)->orderBy('sampled_at')->get(['equity_usd', 'sampled_at']);

        // The account starts at its starting balance, so that is the first peak.
        $peak = $summary['starting_balance'];
        $drawdown = null;

        foreach ($snapshots as $snapshot) {
            $peak = max($peak, $snapshot->equity_usd);
            $drawdown = max($drawdown ?? 0.0, ($peak - $snapshot->equity_usd) / $peak * 100);
        }

        $step = max(1, (int) ceil($snapshots->count() / self::MAX_CURVE_POINTS));
        $curve = $snapshots->filter(fn (EquitySnapshot $snapshot, int $index): bool => $index % $step === 0 || $index === $snapshots->count() - 1)
            ->map(fn (EquitySnapshot $snapshot): array => ['t' => $snapshot->sampled_at->getTimestamp(), 'equity' => $snapshot->equity_usd])
            ->values();

        return [
            ...$summary,
            'max_drawdown_pct' => $drawdown === null ? null : round($drawdown, 2),
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
}
