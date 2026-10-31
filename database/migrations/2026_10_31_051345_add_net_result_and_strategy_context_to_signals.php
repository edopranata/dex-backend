<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signal results after fees and slippage, and what the strategy and the market looked like when
     * a signal was made, so strategy versions and market conditions can be compared later.
     */
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->decimal('net_r', 8, 2)->nullable()->after('result_r');
            $table->decimal('cost_r', 8, 2)->nullable()->after('net_r');
            $table->string('strategy_version', 40)->nullable()->after('cost_r')->index();
        });

        Schema::table('analyses', function (Blueprint $table) {
            $table->string('strategy_version', 40)->nullable()->after('timeframe');
            $table->json('market_context')->nullable()->after('strategy_version');
        });

        // Everything so far is the baseline the next versions are compared with.
        DB::table('signals')->update(['strategy_version' => 'v0']);
        DB::table('analyses')->update(['strategy_version' => 'v0']);

        Artisan::call('market:signals-recalculate');
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn(['net_r', 'cost_r', 'strategy_version']);
        });

        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn(['strategy_version', 'market_context']);
        });
    }
};
