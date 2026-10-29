<?php

namespace App\Jobs;

use App\Models\Token;
use App\Services\MarketData\TokenSecurityService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckTokenSecurity implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public Token $token) {}

    public function uniqueId(): string
    {
        return (string) $this->token->id;
    }

    public function handle(TokenSecurityService $security): void
    {
        $security->check($this->token);
    }
}
