<?php

use App\Models\User;

test('each throttled route counts on its own, so using up one does not block another', function () {
    $user = User::factory()->create();

    // Ten wrong current passwords use up the 10-per-minute limit of changing the password.
    foreach (range(1, 10) as $attempt) {
        $this->actingAs($user)->putJson('/api/profile/password', ['current_password' => 'wrong', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])
            ->assertUnprocessable();
    }
    $this->actingAs($user)->putJson('/api/profile/password', ['current_password' => 'wrong', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])
        ->assertTooManyRequests();

    // Setting up 2FA has its own limit and still answers normally.
    $this->actingAs($user)->postJson('/api/profile/two-factor', ['password' => 'wrong'])->assertUnprocessable();
});
