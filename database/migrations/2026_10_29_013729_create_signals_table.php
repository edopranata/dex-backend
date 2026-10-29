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
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->string('recommendation', 16);
            $table->string('status', 16)->default('pending')->index();
            $table->decimal('entry_low', 36, 18);
            $table->decimal('entry_high', 36, 18);
            $table->decimal('stop_loss', 36, 18);
            $table->decimal('take_profit_1', 36, 18);
            $table->decimal('take_profit_2', 36, 18);
            $table->decimal('take_profit_3', 36, 18);
            $table->decimal('entry_price', 36, 18)->nullable();
            $table->decimal('exit_price', 36, 18)->nullable();
            $table->unsignedTinyInteger('max_tp_hit')->default(0);
            $table->decimal('result_r', 8, 2)->nullable();
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
