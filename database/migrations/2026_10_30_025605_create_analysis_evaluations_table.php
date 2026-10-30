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
        // What happened to the price after each analysis. Kept long after the (heavy) analyses are pruned.
        Schema::create('analysis_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();
            $table->string('recommendation', 16);
            $table->unsignedTinyInteger('final_score');
            $table->json('scores');
            $table->decimal('price', 36, 18);
            $table->timestamp('analyzed_at')->index();
            $table->decimal('return_1h', 12, 4)->nullable();
            $table->decimal('return_4h', 12, 4)->nullable();
            $table->decimal('return_24h', 12, 4)->nullable();
            $table->decimal('max_up_24h', 12, 4)->nullable();
            $table->decimal('max_down_24h', 12, 4)->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['recommendation', 'analyzed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_evaluations');
    }
};
