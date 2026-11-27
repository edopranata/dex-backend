<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v7 beside v8 (owner, 27 Nov 2026): the `v7_rule` shadow is recorded from this release on; this fills in the Strong
 * Buys analyzed since v8 went live (26 Nov 2026 23.01 WIB) that v7 would have traded, so the comparison starts with v8.
 * The analyses keep their levels and score; the liquidity for the costs is the token's current one, as the analysis
 * does not store it. The evaluator then follows them like any new shadow.
 */
return new class extends Migration
{
    public function up(): void
    {
        $limit = (int) config('market.analysis.strong_buy_max_score');
        $known = DB::table('shadow_signals')->where('rule', 'v7_rule')->pluck('analysis_id')->flip();

        DB::table('analyses')
            ->join('tokens', 'tokens.id', '=', 'analyses.token_id')
            ->where('analyses.created_at', '>=', '2026-11-26 16:01:00')
            ->where('analyses.recommendation', 'STRONG_BUY')
            ->whereNotNull('analyses.stop_loss')
            ->whereNotNull('analyses.strategy_version')
            ->when($limit > 0, fn ($query) => $query->where('analyses.final_score', '<', $limit))
            ->orderBy('analyses.id')
            ->select(['analyses.id', 'analyses.token_id', 'analyses.strategy_version', 'analyses.final_score', 'analyses.entry_low', 'analyses.entry_high',
                'analyses.stop_loss', 'analyses.take_profit_1', 'analyses.take_profit_2', 'analyses.take_profit_3', 'analyses.created_at', 'tokens.liquidity_usd'])
            ->chunk(500, function ($analyses) use ($known): void {
                $rows = $analyses->reject(fn ($analysis): bool => $known->has($analysis->id))->map(fn ($analysis): array => [
                    'token_id' => $analysis->token_id,
                    'analysis_id' => $analysis->id,
                    'rule' => 'v7_rule',
                    'recommendation' => 'STRONG_BUY',
                    'strategy_version' => $analysis->strategy_version,
                    'metric' => $analysis->final_score,
                    'entry_low' => $analysis->entry_low,
                    'entry_high' => $analysis->entry_high,
                    'stop_loss' => $analysis->stop_loss,
                    'take_profit_1' => $analysis->take_profit_1,
                    'take_profit_2' => $analysis->take_profit_2,
                    'take_profit_3' => $analysis->take_profit_3,
                    'liquidity_usd' => $analysis->liquidity_usd,
                    'status' => 'pending',
                    'created_at' => $analysis->created_at,
                    'updated_at' => $analysis->created_at,
                ])->values()->all();

                if ($rows !== []) {
                    DB::table('shadow_signals')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('shadow_signals')->where('rule', 'v7_rule')->where('created_at', '<', '2026-11-28 00:00:00')->delete();
    }
};
