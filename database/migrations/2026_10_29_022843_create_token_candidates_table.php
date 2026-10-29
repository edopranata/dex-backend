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
        Schema::create('token_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('chain_id', 32);
            $table->string('pair_address', 128);
            $table->string('token_address', 128);
            $table->string('symbol', 64);
            $table->string('name');
            $table->string('quote_symbol', 64)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->json('sources');
            $table->decimal('price_usd', 36, 18)->nullable();
            $table->decimal('price_change_h1', 12, 2)->nullable();
            $table->decimal('price_change_h6', 12, 2)->nullable();
            $table->decimal('price_change_h24', 12, 2)->nullable();
            $table->decimal('volume_h24', 24, 2)->nullable();
            $table->decimal('liquidity_usd', 24, 2)->nullable();
            $table->decimal('fdv', 24, 2)->nullable();
            $table->unsignedInteger('buys_h24')->nullable();
            $table->unsignedInteger('sells_h24')->nullable();
            $table->unsignedInteger('buyers_h24')->nullable();
            $table->unsignedInteger('sellers_h24')->nullable();
            $table->unsignedSmallInteger('news_mentions')->default(0);
            $table->decimal('whale_buy_usd', 24, 2)->nullable();
            $table->decimal('whale_sell_usd', 24, 2)->nullable();
            $table->unsignedSmallInteger('whale_trades')->nullable();
            $table->unsignedSmallInteger('whale_buy_wallets')->nullable();
            $table->timestamp('whale_checked_at')->nullable();
            $table->unsignedTinyInteger('security_score')->nullable();
            $table->boolean('is_honeypot')->nullable();
            $table->json('security_flags')->nullable();
            $table->timestamp('security_checked_at')->nullable();
            $table->unsignedTinyInteger('trend_score')->default(0);
            $table->unsignedTinyInteger('attention_score')->default(0);
            $table->unsignedTinyInteger('whale_score')->nullable();
            $table->unsignedTinyInteger('discovery_score')->nullable()->index();
            $table->string('status', 16)->default('candidate')->index();
            $table->json('reasons');
            $table->timestamp('pair_created_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

            $table->unique(['chain_id', 'pair_address']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_candidates');
    }
};
