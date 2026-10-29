<?php

use App\Models\Token;
use App\Services\MarketData\TokenSecurityService;
use Illuminate\Support\Facades\Http;

test('honeypot evm token scores zero', function () {
    $result = app(TokenSecurityService::class)->scoreEvm(['is_honeypot' => '1', 'is_open_source' => '1']);

    expect($result['score'])->toBe(0)
        ->and($result['is_honeypot'])->toBeTrue();
});

test('clean evm token keeps a perfect score', function () {
    $result = app(TokenSecurityService::class)->scoreEvm([
        'is_honeypot' => '0', 'is_open_source' => '1', 'buy_tax' => '0', 'sell_tax' => '0', 'holder_count' => '50000',
    ]);

    expect($result['score'])->toBe(100)
        ->and($result['flags'])->toBe([]);
});

test('high sell tax and concentrated holders are penalized', function () {
    $result = app(TokenSecurityService::class)->scoreEvm([
        'is_open_source' => '1',
        'sell_tax' => '0.15',
        'holders' => [['address' => '0xabc', 'percent' => '0.70', 'is_locked' => 0]],
    ]);

    expect($result['score'])->toBe(55)
        ->and(collect($result['flags'])->pluck('level')->all())->toContain('danger');
});

test('solana mint and freeze authority are penalized', function () {
    $result = app(TokenSecurityService::class)->scoreSolana([
        'mintable' => ['status' => '1'],
        'freezable' => ['status' => '1'],
    ]);

    expect($result['score'])->toBe(55);
});

test('check stores a security record from goplus', function () {
    $token = Token::factory()->create(['chain_id' => 'ethereum', 'token_address' => '0xToken']);

    Http::fake([
        'api.gopluslabs.io/*' => Http::response(['code' => 1, 'result' => ['0xtoken' => [
            'is_honeypot' => '0', 'is_open_source' => '1', 'holder_count' => '1234', 'buy_tax' => '0.01', 'sell_tax' => '0.02',
        ]]]),
    ]);

    $check = app(TokenSecurityService::class)->check($token);

    expect($check->risk_score)->toBe(100)
        ->and($check->holder_count)->toBe(1234)
        ->and($check->sell_tax)->toEqual(2.0);
});
