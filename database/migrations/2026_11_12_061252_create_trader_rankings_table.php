<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trader_rankings', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('chain_id', 32);
            $table->string('wallet', 128);
            $table->string('period', 16);
            $table->string('sort_by', 24);
            $table->unsignedSmallInteger('list_rank');
            $table->decimal('pnl_usd', 24, 2)->nullable();
            $table->decimal('realized_pnl_usd', 24, 2)->nullable();
            $table->decimal('unrealized_pnl_usd', 24, 2)->nullable();
            $table->decimal('volume_usd', 24, 2)->nullable();
            $table->unsignedInteger('trade_count')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->date('seen_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['source', 'chain_id', 'period', 'sort_by', 'seen_on', 'wallet'], 'trader_rankings_list_wallet');
            $table->index(['chain_id', 'wallet'], 'trader_rankings_wallet');
            $table->index('seen_on', 'trader_rankings_seen');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trader_rankings');
    }
};
