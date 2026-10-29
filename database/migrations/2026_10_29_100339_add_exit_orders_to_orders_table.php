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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('signal_id')->constrained('orders')->nullOnDelete();
            $table->decimal('take_profit_price', 36, 18)->nullable()->after('trigger_price');
            $table->decimal('stop_loss_price', 36, 18)->nullable()->after('take_profit_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['take_profit_price', 'stop_loss_price']);
        });
    }
};
