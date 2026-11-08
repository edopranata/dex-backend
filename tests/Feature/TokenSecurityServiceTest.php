<?php

use App\Jobs\CheckTokenSecurity;
use App\Models\Token;
use App\Models\TokenSecurityCheck;
use App\Services\Analysis\OnchainAnalyzer;
use App\Services\MarketData\GoPlusRefusedException;
use App\Services\MarketData\TokenSecurityService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

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

function goPlusHolders(Token $token, array $data): void
{
    Http::fake(['api.gopluslabs.io/*' => Http::response(['code' => 1, 'result' => [strtolower($token->token_address) => ['is_open_source' => '1', 'holder_count' => '5000', ...$data]]])]);
}

test('the ten largest holders are stored with what each address is, and the largest one that can sell', function () {
    $token = Token::factory()->create(['chain_id' => 'bsc', 'token_address' => '0xToken', 'pair_address' => '0xPair']);
    goPlusHolders($token, [
        'creator_percent' => '0.05',
        'owner_percent' => '0',
        'dex' => [['name' => 'PancakeV3', 'pair' => '0xotherpool']],
        'holders' => [
            ['address' => '0xPAIR', 'percent' => '0.30', 'is_locked' => 0, 'is_contract' => 1],
            ['address' => '0xlocker', 'percent' => '0.20', 'is_locked' => 1, 'is_contract' => 0, 'tag' => 'PinkLock'],
            ['address' => '0xotherpool', 'percent' => '0.10', 'is_locked' => 0, 'is_contract' => 0],
            ['address' => '0x000000000000000000000000000000000000dead', 'percent' => '0.08', 'is_locked' => 0, 'is_contract' => 0],
            ['address' => '0xwhale', 'percent' => '0.0712', 'is_locked' => 0, 'is_contract' => 0],
        ],
    ]);

    $check = app(TokenSecurityService::class)->check($token);
    $holders = collect($check->top_holders)->keyBy('address');

    expect($check->top_holders)->toHaveCount(5)
        ->and($holders['0xPAIR'])->toMatchArray(['percent' => 30.0, 'is_pool' => true, 'is_contract' => true])
        ->and($holders['0xlocker'])->toMatchArray(['is_locked' => true, 'tag' => 'PinkLock'])
        ->and($holders['0xotherpool']['is_pool'])->toBeTrue()
        ->and($holders['0x000000000000000000000000000000000000dead']['is_burn'])->toBeTrue()
        ->and($check->top1_percent)->toEqual(7.12)
        ->and($check->creator_percent)->toEqual(5.0)
        ->and($check->owner_percent)->toEqual(0.0);
});

test('on solana an unlocked wallet counts as a holder that can sell', function () {
    $token = Token::factory()->create(['chain_id' => 'solana', 'token_address' => 'MintAddress']);
    goPlusHolders($token, ['holders' => [
        ['account' => 'Streamflow', 'percent' => '0.40', 'is_locked' => 1, 'tag' => 'streamflow'],
        ['account' => 'Team', 'percent' => '0.65', 'is_locked' => 0, 'tag' => ''],
    ]]);

    $check = app(TokenSecurityService::class)->check($token);

    expect($check->top1_percent)->toEqual(65.0)
        ->and($check->top_holders[1])->toMatchArray(['address' => 'Team', 'is_contract' => null, 'tag' => null])
        ->and($check->creator_percent)->toBeNull();
});

test('a goplus refusal is tried again later, then only logged so a new token goes on', function () {
    $token = Token::factory()->create(['chain_id' => 'bsc', 'token_address' => '0xToken']);
    Http::fake(['api.gopluslabs.io/*' => Http::response(['code' => 4029, 'message' => 'too many requests', 'result' => null])]);

    expect(fn () => app(TokenSecurityService::class)->check($token))->toThrow(GoPlusRefusedException::class, '4029');

    $job = (new CheckTokenSecurity($token))->withFakeQueueInteractions();
    $job->handle(app(TokenSecurityService::class));
    $job->assertReleased(60);

    Log::spy();
    $last = Mockery::mock(CheckTokenSecurity::class.'[attempts]', [$token])->makePartial();
    $last->shouldReceive('attempts')->andReturn(3);
    $last->handle(app(TokenSecurityService::class));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'gagal') && str_contains($message, '4029'))->once();
    expect(TokenSecurityCheck::count())->toBe(0);
});

test('checks are spread out, the market every six hours and tokens that left once a day', function () {
    Queue::fake();
    $tokens = Token::factory()->count(3)->create(['is_active' => true]);
    $left = Token::factory()->create(['is_active' => false]);
    $left->watchlistEvents()->create(['action' => 'added', 'reason' => 'Masuk.']);
    Token::factory()->create(['is_active' => false]);

    $this->artisan('market:security')->assertSuccessful();

    Queue::assertPushed(CheckTokenSecurity::class, 3);
    $delays = collect(Queue::pushed(CheckTokenSecurity::class))->map(fn (CheckTokenSecurity $job): int => (int) round(now()->diffInSeconds($job->delay)))->sort()->values()->all();
    expect($delays)->toBe([0, 10, 20]);

    Queue::fake();
    $this->artisan('market:security', ['--out-of-market' => true])->assertSuccessful();

    Queue::assertPushed(CheckTokenSecurity::class, fn (CheckTokenSecurity $job): bool => $job->token->is($left));
    Queue::assertPushed(CheckTokenSecurity::class, 1);

    $events = collect(app(Schedule::class)->events())->map(fn ($event): string => $event->command.' @ '.$event->expression);
    expect($events->contains(fn (string $e): bool => str_contains($e, 'market:security --out-of-market') && str_ends_with($e, '35 3 * * *')))->toBeTrue();
});

test('a dominant holder is explained and recorded in the analysis without changing the score', function () {
    $token = Token::factory()->create();
    $check = TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 80, 'flags' => [], 'top1_percent' => 72.5, 'checked_at' => now()]);

    $result = app(OnchainAnalyzer::class)->analyze($token);

    $check->update(['top1_percent' => 5]);
    $calm = app(OnchainAnalyzer::class)->analyze($token);

    expect($result['metrics']['top1_percent'])->toEqual(72.5)
        ->and(collect($result['reasons'])->contains(fn (string $r): bool => str_contains($r, '72.5%') && str_contains($r, 'dominan')))->toBeTrue()
        ->and($result['score'])->toBe($calm['score']);
});
