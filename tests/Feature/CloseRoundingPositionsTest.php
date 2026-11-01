<?php

use App\Models\Order;
use App\Models\Position;
use App\Models\Token;
use App\Models\User;

test('closing rounding leftovers leaves real positions and anything still being sold alone', function () {
    $user = User::factory()->create();
    $dust = Position::factory()->for($user)->for(Token::factory()->create())->create(['quantity' => 0.000000002998, 'avg_price' => 0.008]);
    $real = Position::factory()->for($user)->for(Token::factory()->create())->create(['quantity' => 318.04, 'avg_price' => 3.19]);
    $pendingToken = Token::factory()->create();
    $waiting = Position::factory()->for($user)->for($pendingToken)->create(['quantity' => 0.0000001, 'avg_price' => 2]);
    Order::factory()->for($user, 'user')->for($pendingToken)->create(['side' => 'sell', 'status' => 'pending']);

    (require database_path('migrations/2026_11_01_160000_close_positions_left_open_by_rounding.php'))->up();

    expect((float) $dust->refresh()->quantity)->toEqual(0.0)
        ->and((float) $dust->avg_price)->toEqual(0.0)
        ->and((float) $real->refresh()->quantity)->toEqual(318.04)
        ->and((float) $waiting->refresh()->quantity)->toBeGreaterThan(0.0);
});
