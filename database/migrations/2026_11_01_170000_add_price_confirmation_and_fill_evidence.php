<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->decimal('previous_price_usd', 36, 18)->nullable()->after('price_usd');
            $table->timestamp('previous_price_at')->nullable()->after('previous_price_usd');
        });

        // No foreign keys: this has to outlive a portfolio reset, which deletes the orders.
        Schema::create('order_fill_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('token_id')->index();
            $table->string('side', 10);
            $table->string('type', 20);
            $table->string('source', 30);
            $table->string('pair_address', 128)->nullable();
            $table->decimal('trigger_price', 36, 18)->nullable();
            $table->decimal('market_price', 36, 18);
            $table->decimal('filled_price', 36, 18);
            $table->timestamp('price_at')->nullable();
            $table->decimal('previous_price', 36, 18)->nullable();
            $table->timestamp('previous_price_at')->nullable();
            $table->decimal('liquidity_usd', 24, 2)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fill_evidence');

        Schema::table('tokens', function (Blueprint $table) {
            $table->dropColumn(['previous_price_usd', 'previous_price_at']);
        });
    }
};
