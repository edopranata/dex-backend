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
        Schema::create('token_security_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('goplus');
            $table->unsignedBigInteger('holder_count')->nullable();
            $table->decimal('top10_percent', 8, 2)->nullable();
            $table->decimal('lp_locked_percent', 8, 2)->nullable();
            $table->boolean('is_honeypot')->nullable();
            $table->decimal('buy_tax', 8, 2)->nullable();
            $table->decimal('sell_tax', 8, 2)->nullable();
            $table->decimal('liquidity_usd', 24, 2)->nullable();
            $table->unsignedTinyInteger('risk_score');
            $table->json('flags');
            $table->timestamp('checked_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_security_checks');
    }
};
