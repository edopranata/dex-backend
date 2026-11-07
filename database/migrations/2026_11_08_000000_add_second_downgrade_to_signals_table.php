<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shadow exit that ignores one-off flips: the second analysis in a row after entry that is not bullish.
        Schema::table('signals', function (Blueprint $table) {
            $table->timestamp('second_downgraded_at')->nullable()->after('downgrade_tp_hit');
            $table->decimal('second_downgrade_price', 36, 18)->nullable()->after('second_downgraded_at');
            $table->unsignedTinyInteger('second_downgrade_tp_hit')->nullable()->after('second_downgrade_price');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn(['second_downgraded_at', 'second_downgrade_price', 'second_downgrade_tp_hit']);
        });
    }
};
