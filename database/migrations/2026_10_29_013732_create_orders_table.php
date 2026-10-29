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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('side', 4);
            $table->string('type', 16);
            $table->string('status', 16)->default('pending')->index();
            $table->decimal('amount_usd', 24, 8)->nullable();
            $table->decimal('quantity', 40, 12)->nullable();
            $table->decimal('trigger_price', 36, 18)->nullable();
            $table->decimal('filled_price', 36, 18)->nullable();
            $table->decimal('filled_quantity', 40, 12)->nullable();
            $table->decimal('total_usd', 24, 8)->nullable();
            $table->decimal('fee_usd', 24, 8)->nullable();
            $table->decimal('slippage_pct', 10, 4)->nullable();
            $table->decimal('realized_pnl', 24, 8)->nullable();
            $table->string('note')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
