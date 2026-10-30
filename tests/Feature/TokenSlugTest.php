<?php

use App\Models\Token;
use App\Models\User;

test('tokens get a readable slug from symbol and chain', function () {
    $token = Token::factory()->create(['symbol' => 'job', 'chain_id' => 'solana']);

    expect($token->slug)->toBe('JOB-SOL');
});

test('a symbol already used on the chain gets a numbered slug', function () {
    Token::factory()->create(['symbol' => 'PEPE', 'chain_id' => 'base']);
    $second = Token::factory()->create(['symbol' => 'PEPE', 'chain_id' => 'base']);
    $otherChain = Token::factory()->create(['symbol' => 'PEPE', 'chain_id' => 'bsc']);

    expect($second->slug)->toBe('PEPE-BASE-2')
        ->and($otherChain->slug)->toBe('PEPE-BSC');
});

test('symbols without url-safe characters still get a slug', function () {
    expect(Token::factory()->create(['symbol' => '$WIF', 'chain_id' => 'solana'])->slug)->toBe('WIF-SOL')
        ->and(Token::factory()->create(['symbol' => '🐸', 'chain_id' => 'solana'])->slug)->toBe('TOKEN-SOL');
});

test('the token page api accepts the slug in any case and the old numeric id', function () {
    $token = Token::factory()->create(['symbol' => 'JOB', 'chain_id' => 'solana']);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/tokens/job-sol')->assertOk()->assertJsonPath('data.id', $token->id);
    $this->actingAs($user)->getJson("/api/tokens/{$token->id}")->assertOk()->assertJsonPath('data.slug', 'JOB-SOL');
    $this->actingAs($user)->getJson('/api/tokens/NOPE-SOL')->assertNotFound();
});
