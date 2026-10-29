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
        Schema::create('token_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();
            $table->decimal('price_usd', 36, 18)->nullable();
            $table->decimal('liquidity_usd', 24, 2)->nullable();
            $table->decimal('volume_h24', 24, 2)->nullable();
            $table->decimal('fdv', 24, 2)->nullable();
            $table->timestamp('captured_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_snapshots');
    }
};
