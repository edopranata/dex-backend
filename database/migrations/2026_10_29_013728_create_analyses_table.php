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
        Schema::create('analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();
            $table->string('timeframe', 8);
            $table->string('recommendation', 16);
            $table->unsignedTinyInteger('final_score');
            $table->unsignedTinyInteger('technical_score');
            $table->unsignedTinyInteger('onchain_score');
            $table->unsignedTinyInteger('whale_score');
            $table->unsignedTinyInteger('sentiment_score');
            $table->unsignedTinyInteger('fundamental_score');
            $table->decimal('price', 36, 18);
            $table->decimal('entry_low', 36, 18)->nullable();
            $table->decimal('entry_high', 36, 18)->nullable();
            $table->decimal('stop_loss', 36, 18)->nullable();
            $table->decimal('take_profit_1', 36, 18)->nullable();
            $table->decimal('take_profit_2', 36, 18)->nullable();
            $table->decimal('take_profit_3', 36, 18)->nullable();
            $table->json('indicators');
            $table->json('reasons');
            $table->text('summary')->nullable();
            $table->string('summary_provider', 16)->nullable();
            $table->timestamps();

            $table->index(['token_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analyses');
    }
};
