<?php

namespace App\Console\Commands;

use App\Models\Candle;
use App\Models\Token;
use App\Services\Analysis\Backtester;
use App\Services\Analysis\StrategyVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('market:backtest {--days=90 : Panjang periode} {--scenario=* : Nama skenario (default: semua)} {--token=* : Simbol token (default: semua)}')]
#[Description('Uji aturan sinyal pada candle historis; 2/3 periode awal untuk memilih aturan, 1/3 akhir untuk membuktikan')]
class MarketBacktestCommand extends Command
{
    /**
     * One rule per scenario, so each effect can be judged alone against the baseline.
     *
     * @var array<string, array<string, mixed>>
     */
    public const SCENARIOS = [
        'baseline' => [],
        'trend-4h' => ['require_trend' => 'bullish'],
        'rsi<55' => ['max_rsi' => 55.0],
        'rsi<65' => ['max_rsi' => 65.0],
        'stop>=4%' => ['min_stop_pct' => 4.0],
        'sl-2atr' => ['sl_atr_min' => 2.0],
        'tp-1-2-3' => ['tp_r_multiples' => [1.0, 2.0, 3.0]],
        'trend+stop' => ['require_trend' => 'bullish', 'min_stop_pct' => 4.0],
        'trend+2atr' => ['require_trend' => 'bullish', 'sl_atr_min' => 2.0],
    ];

    public function handle(Backtester $backtester): int
    {
        $days = (int) $this->option('days');
        $since = now()->subDays($days)->timestamp;
        $split = $since + (int) round($days * 2 / 3) * 86400;
        $names = $this->option('scenario') ?: array_keys(self::SCENARIOS);
        $tokens = Token::query()->when($this->option('token'), fn ($q, $symbols) => $q->whereIn('symbol', $symbols))->orderBy('id')->get()
            ->filter(fn (Token $token): bool => Candle::query()->where('token_id', $token->id)->where('timeframe', config('market.analysis.timeframe'))->exists());

        $this->components->info(sprintf('%d token, %d hari. Pengembangan sampai %s, pengujian sesudahnya.', $tokens->count(), $days, date('Y-m-d', $split)));

        $prepared = [];
        $this->withProgressBar($tokens, function (Token $token) use ($backtester, $since, &$prepared): void {
            $prepared[] = $backtester->prepare($token, $since);
        });
        $this->newLine(2);

        $report = ['generated_at' => now()->toIso8601String(), 'strategy_version' => StrategyVersion::current(), 'days' => $days, 'split_at' => $split, 'neutral_scores' => Backtester::NEUTRAL_SCORES, 'scenarios' => []];
        $rows = [];

        foreach ($names as $name) {
            $trades = [];

            foreach ($prepared as $token) {
                array_push($trades, ...$backtester->simulate($token, self::SCENARIOS[$name] ?? []));
            }

            $development = Backtester::summarize(array_values(array_filter($trades, fn (array $t): bool => $t['signal_at'] < $split)));
            $test = Backtester::summarize(array_values(array_filter($trades, fn (array $t): bool => $t['signal_at'] >= $split)));
            $report['scenarios'][$name] = ['rule' => self::SCENARIOS[$name] ?? [], 'all' => Backtester::summarize($trades), 'development' => $development, 'test' => $test, 'trades' => $trades];

            $rows[] = [$name, ...$this->cells($development), ...$this->cells($test), $report['scenarios'][$name]['all']['max_drawdown_r']];
        }

        $this->table(['Skenario', 'Dev n', 'win%', 'avgR', 'PF', 'Uji n', 'win%', 'avgR', 'PF', 'DD R'], $rows);

        $path = storage_path('app/backtests/'.now()->format('Y-m-d-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT));
        $this->line("Detail: {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<mixed>
     */
    private function cells(array $summary): array
    {
        return [$summary['trades'], $summary['win_rate'] ?? '-', $summary['avg_r'] ?? '-', $summary['profit_factor'] ?? '-'];
    }
}
