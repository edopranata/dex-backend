<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Selling a position in thirds could leave a rounding remainder that kept it open and took up a slot
 * of the auto-follow limits. The remainder is worth nothing, so closing it moves no money.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('positions')
            ->where('quantity', '>', 0)
            ->where('quantity', '<', 0.000001)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('orders')
                ->whereColumn('orders.user_id', 'positions.user_id')
                ->whereColumn('orders.token_id', 'positions.token_id')
                ->where('orders.side', 'sell')
                ->where('orders.status', 'pending'))
            ->update(['quantity' => 0, 'avg_price' => 0]);
    }

    public function down(): void
    {
        // The remainders were meaningless, so there is nothing worth restoring.
    }
};
