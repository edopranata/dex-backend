<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The market feed asks every 30 seconds for the whale trades recorded since its last visit.
     */
    public function up(): void
    {
        Schema::table('whale_trades', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('whale_trades', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
