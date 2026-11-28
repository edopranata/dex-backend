<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FutureSim's long strategies on DEX candles as paper accounts (owner, 28 Nov 2026): their own cash and positions,
     * apart from the simulator's users and orders.
     */
    public function up(): void
    {
        Schema::create('strategy_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 120);
            // main, shadow, experiment or benchmark, as in FutureSim.
            $table->string('kind', 12);
            $table->decimal('starting_balance', 20, 8);
            $table->decimal('cash', 20, 8);
            $table->decimal('fees_paid', 20, 8)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('started_at');
            $table->timestamps();
        });

        Schema::create('strategy_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_id')->constrained();
            // pending (a limit entry waiting), open, closed or cancelled.
            $table->string('status', 10)->default('open');
            $table->decimal('quantity', 40, 18);
            $table->decimal('remaining', 40, 18);
            $table->decimal('entry_price', 36, 18);
            $table->decimal('cost_basis', 20, 8)->default(0);
            $table->decimal('stop', 36, 18)->nullable();
            $table->decimal('take_profit', 36, 18)->nullable();
            // Strategy state, the stop and risk at entry, martingale adds, a limit order's plan.
            $table->json('state');
            $table->string('entry_reason', 255);
            $table->timestamp('opened_at');
            // Last 15m candle already checked against the stop, target and adds.
            $table->unsignedBigInteger('checked_until');
            $table->timestamp('closed_at')->nullable();
            $table->string('exit_reason', 120)->nullable();
            // Move at the prices (gross), costs (fee, price impact, gas) and the net result.
            $table->decimal('gross_pnl', 20, 8)->default(0);
            $table->decimal('costs', 20, 8)->default(0);
            $table->decimal('net_pnl', 20, 8)->default(0);
            $table->timestamps();
            $table->index(['strategy_account_id', 'status']);
            $table->index(['token_id', 'status']);
        });

        Schema::create('strategy_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('equity', 20, 8);
            $table->decimal('cash', 20, 8);
            $table->timestamp('taken_at');
            $table->index(['strategy_account_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_snapshots');
        Schema::dropIfExists('strategy_positions');
        Schema::dropIfExists('strategy_accounts');
    }
};
