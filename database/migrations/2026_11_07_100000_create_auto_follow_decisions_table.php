<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What each auto-follow account did with each signal it follows, so a report can show what was
        // skipped and why. No foreign keys: like the fill evidence, it outlives a portfolio reset.
        Schema::create('auto_follow_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('account', 20);
            $table->unsignedBigInteger('signal_id')->index();
            $table->string('decision', 20);
            $table->string('reason', 30)->nullable();
            $table->string('message', 255)->nullable();
            $table->string('source', 10)->default('live');
            $table->timestamp('decided_at');

            $table->unique(['user_id', 'signal_id']);
        });

        // Shadow exit for a possible v5 rule: the first analysis after entry that is no longer bullish.
        Schema::table('signals', function (Blueprint $table) {
            $table->timestamp('downgraded_at')->nullable()->after('closed_at');
            $table->decimal('downgrade_price', 36, 18)->nullable()->after('downgraded_at');
            $table->string('downgrade_recommendation', 16)->nullable()->after('downgrade_price');
            $table->unsignedTinyInteger('downgrade_tp_hit')->nullable()->after('downgrade_recommendation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_follow_decisions');

        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn(['downgraded_at', 'downgrade_price', 'downgrade_recommendation', 'downgrade_tp_hit']);
        });
    }
};
