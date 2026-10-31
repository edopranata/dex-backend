<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equity of the auto-follow paper account over time: past values cannot be rebuilt from orders,
     * because they depend on the prices of open positions at that moment.
     */
    public function up(): void
    {
        Schema::create('equity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('equity_usd', 16, 2);
            $table->decimal('balance_usd', 16, 2);
            $table->decimal('holdings_usd', 16, 2);
            $table->unsignedInteger('open_positions');
            $table->timestamp('sampled_at');

            $table->index(['user_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equity_snapshots');
    }
};
