<?php

use App\Models\Token;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->string('slug', 40)->nullable()->unique()->after('symbol');
        });

        // Oldest tokens first, so they get the slug without a numeric suffix.
        DB::table('tokens')->orderBy('id')->select(['id', 'symbol', 'chain_id'])->each(function (object $token): void {
            DB::table('tokens')->where('id', $token->id)->update(['slug' => Token::slugFor($token->symbol, $token->chain_id)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
