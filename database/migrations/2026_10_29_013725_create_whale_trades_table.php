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
        Schema::create('whale_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->unique();
            $table->string('tx_hash', 128);
            $table->string('wallet', 128)->nullable();
            $table->string('side', 4);
            $table->decimal('amount_usd', 24, 2);
            $table->decimal('token_amount', 40, 12)->nullable();
            $table->decimal('price_usd', 36, 18)->nullable();
            $table->timestamp('traded_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whale_trades');
    }
};
