<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The GeckoTerminal price a fill was checked against, so a fill can be judged later without the candles.
     */
    public function up(): void
    {
        Schema::table('order_fill_evidence', function (Blueprint $table) {
            $table->decimal('checked_price', 36, 18)->nullable()->after('market_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_fill_evidence', function (Blueprint $table) {
            $table->dropColumn('checked_price');
        });
    }
};
