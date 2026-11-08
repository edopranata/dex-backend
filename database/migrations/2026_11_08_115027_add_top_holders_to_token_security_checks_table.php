<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ten largest holders as GoPlus lists them, and the share of the largest one that could sell
     * (not locked, a contract, a burn address or the token's own pool), to measure holder dominance.
     */
    public function up(): void
    {
        Schema::table('token_security_checks', function (Blueprint $table) {
            $table->json('top_holders')->nullable()->after('top10_percent');
            $table->decimal('top1_percent', 8, 2)->nullable()->after('top_holders');
            $table->decimal('creator_percent', 8, 2)->nullable()->after('top1_percent');
            $table->decimal('owner_percent', 8, 2)->nullable()->after('creator_percent');
        });
    }

    public function down(): void
    {
        Schema::table('token_security_checks', function (Blueprint $table) {
            $table->dropColumn(['top_holders', 'top1_percent', 'creator_percent', 'owner_percent']);
        });
    }
};
