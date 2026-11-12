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
        Schema::create('discovery_sightings', function (Blueprint $table) {
            $table->id();
            $table->string('chain_id', 32);
            $table->string('token_address', 128);
            $table->string('symbol', 64)->nullable();
            $table->string('source', 32);
            $table->unsignedSmallInteger('list_rank')->nullable();
            $table->decimal('organic_score', 6, 2)->nullable();
            $table->timestamp('seen_at');

            $table->index(['chain_id', 'token_address', 'seen_at'], 'discovery_sightings_token_seen');
            $table->index('seen_at', 'discovery_sightings_seen');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovery_sightings');
    }
};
