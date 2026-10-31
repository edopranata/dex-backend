<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The wallet page opens one wallet's trades by its address.
     */
    public function up(): void
    {
        Schema::table('whale_trades', function (Blueprint $table) {
            $table->index('wallet');
        });
    }

    public function down(): void
    {
        Schema::table('whale_trades', function (Blueprint $table) {
            $table->dropIndex(['wallet']);
        });
    }
};
