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
        Schema::create('tokens', function (Blueprint $table) {
            $table->id();
            $table->string('chain_id', 32);
            $table->string('pair_address', 128);
            $table->string('token_address', 128);
            $table->string('symbol', 64);
            $table->string('name');
            $table->string('quote_symbol', 64)->nullable();
            $table->string('dex_id', 64)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->decimal('price_usd', 36, 18)->nullable();
            $table->decimal('price_change_m5', 12, 2)->nullable();
            $table->decimal('price_change_h1', 12, 2)->nullable();
            $table->decimal('price_change_h6', 12, 2)->nullable();
            $table->decimal('price_change_h24', 12, 2)->nullable();
            $table->decimal('volume_h24', 24, 2)->nullable();
            $table->decimal('liquidity_usd', 24, 2)->nullable();
            $table->decimal('fdv', 24, 2)->nullable();
            $table->decimal('market_cap', 24, 2)->nullable();
            $table->unsignedInteger('txns_h24_buys')->nullable();
            $table->unsignedInteger('txns_h24_sells')->nullable();
            $table->timestamp('pair_created_at')->nullable();
            $table->timestamp('price_updated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['chain_id', 'pair_address']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tokens');
    }
};
