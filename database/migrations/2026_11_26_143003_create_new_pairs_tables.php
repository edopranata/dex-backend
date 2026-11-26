<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token Baru (26 Nov 2026): pools younger than 24 hours, recorded with their own 5-minute bars and paper trades.
 * Recording only and separate from the Market tokens: nothing here changes signals, orders or auto-follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_pairs', function (Blueprint $table) {
            $table->id();
            $table->string('chain_id', 32);
            $table->string('pair_address', 128);
            $table->string('token_address', 128);
            $table->string('symbol', 64);
            $table->string('name')->nullable();
            $table->string('quote_symbol', 64)->nullable();
            $table->string('dex_id', 64)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('source', 32);
            $table->timestamp('pool_created_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->decimal('first_price_usd', 36, 18)->nullable();
            $table->decimal('first_liquidity_usd', 24, 2)->nullable();
            $table->decimal('price_usd', 36, 18)->nullable();
            $table->decimal('high_price_usd', 36, 18)->nullable();
            $table->decimal('low_price_usd', 36, 18)->nullable();
            $table->decimal('liquidity_usd', 24, 2)->nullable();
            $table->decimal('fdv', 24, 2)->nullable();
            $table->decimal('volume_m5', 24, 2)->nullable();
            $table->decimal('volume_h1', 24, 2)->nullable();
            $table->decimal('volume_h24', 24, 2)->nullable();
            $table->unsignedInteger('buys_m5')->nullable();
            $table->unsignedInteger('sells_m5')->nullable();
            $table->decimal('price_change_m5', 12, 2)->nullable();
            $table->decimal('price_change_h1', 12, 2)->nullable();
            $table->timestamp('last_sample_at')->nullable();
            $table->string('status', 16)->default('tracking')->index();
            $table->string('end_reason', 32)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('security_checked_at')->nullable();
            $table->unsignedTinyInteger('security_attempts')->default(0);
            $table->unsignedTinyInteger('security_score')->nullable();
            $table->boolean('is_honeypot')->nullable();
            $table->decimal('buy_tax', 8, 2)->nullable();
            $table->decimal('sell_tax', 8, 2)->nullable();
            $table->decimal('top10_percent', 8, 2)->nullable();
            $table->json('security_flags')->nullable();
            $table->timestamps();

            $table->unique(['chain_id', 'pair_address']);
            $table->index('first_seen_at');
        });

        Schema::create('new_pair_bars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_pair_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('open_time');
            $table->decimal('open', 36, 18);
            $table->decimal('high', 36, 18);
            $table->decimal('low', 36, 18);
            $table->decimal('close', 36, 18);
            $table->decimal('liquidity_usd', 24, 2)->nullable();
            $table->decimal('volume_m5', 24, 2)->nullable();
            $table->unsignedInteger('buys_m5')->nullable();
            $table->unsignedInteger('sells_m5')->nullable();
            $table->unsignedTinyInteger('samples')->default(1);

            $table->unique(['new_pair_id', 'open_time']);
            $table->index('open_time');
        });

        Schema::create('new_pair_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_pair_id')->constrained()->cascadeOnDelete();
            $table->string('strategy', 32);
            $table->string('status', 16)->default('open')->index();
            $table->timestamp('entry_at');
            $table->decimal('entry_price', 36, 18);
            $table->decimal('stop_price', 36, 18);
            $table->unsignedInteger('pool_age_minutes')->nullable();
            $table->decimal('liquidity_usd', 24, 2)->nullable();
            $table->unsignedTinyInteger('tp_hit')->default(0);
            $table->decimal('realized_pct', 10, 4)->default(0);
            $table->decimal('high_price', 36, 18);
            $table->decimal('low_price', 36, 18);
            $table->timestamp('exit_at')->nullable();
            $table->decimal('exit_price', 36, 18)->nullable();
            $table->string('exit_reason', 32)->nullable();
            $table->decimal('gross_pct', 10, 2)->nullable();
            $table->decimal('net_pct', 10, 2)->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->unique(['new_pair_id', 'strategy']);
            $table->index(['strategy', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_pair_trades');
        Schema::dropIfExists('new_pair_bars');
        Schema::dropIfExists('new_pairs');
    }
};
