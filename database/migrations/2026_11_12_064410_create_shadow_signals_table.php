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
        Schema::create('shadow_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('analysis_id')->index();
            $table->unsignedBigInteger('signal_id')->nullable()->index();
            $table->string('rule', 32);
            $table->string('recommendation', 16);
            $table->string('strategy_version', 40);
            $table->decimal('metric', 10, 2)->nullable();
            $table->decimal('entry_low', 36, 18);
            $table->decimal('entry_high', 36, 18);
            $table->decimal('stop_loss', 36, 18);
            $table->decimal('take_profit_1', 36, 18);
            $table->decimal('take_profit_2', 36, 18);
            $table->decimal('take_profit_3', 36, 18);
            $table->decimal('liquidity_usd', 24, 2)->nullable();
            $table->string('status', 16)->default('pending');
            $table->decimal('entry_price', 36, 18)->nullable();
            $table->decimal('exit_price', 36, 18)->nullable();
            $table->unsignedTinyInteger('max_tp_hit')->default(0);
            $table->decimal('net_r', 8, 2)->nullable();
            $table->decimal('cost_r', 8, 2)->nullable();
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['rule', 'status'], 'shadow_signals_rule_status');
            $table->index(['token_id', 'rule', 'created_at'], 'shadow_signals_token_rule');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shadow_signals');
    }
};
