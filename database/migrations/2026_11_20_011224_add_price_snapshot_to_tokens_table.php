<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fingerprint of the DEX Screener data the price came from. DEX Screener renews a pair's data about every 30
        // seconds (measured 19 Nov 2026), so a refresh often reads the same snapshot again; the earlier price that
        // confirms an order is now the one from a different snapshot.
        Schema::table('tokens', function (Blueprint $table) {
            $table->string('price_snapshot', 32)->nullable()->after('previous_price_at');
        });
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->dropColumn('price_snapshot');
        });
    }
};
