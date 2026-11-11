<?php

namespace App\Jobs;

use App\Models\Token;
use App\Services\MarketData\GoPlusRefusedException;
use App\Services\MarketData\TokenSecurityService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class CheckTokenSecurity implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /** A lock left by a worker stopped mid-job must not block the token's checks for good. */
    public int $uniqueFor = 3600;

    public function __construct(public Token $token) {}

    public function uniqueId(): string
    {
        return (string) $this->token->id;
    }

    /**
     * A refusal (usually GoPlus's request limit) or no answer at all (GoPlus Solana timed out for hours on
     * 11 Nov) is tried again later. After the last try it is only logged, so a new token's chain of jobs
     * (security, candles, whales, analysis) still goes on and an outage does not fill the failed jobs.
     */
    public function handle(TokenSecurityService $security): void
    {
        try {
            if ($security->check($this->token) === null) {
                Log::info("Cek keamanan {$this->token->symbol}: GoPlus tidak punya data token ini.");
            }
        } catch (GoPlusRefusedException|ConnectionException $e) {
            if ($this->attempts() < $this->tries) {
                $this->release(60 * $this->attempts());

                return;
            }

            Log::warning("Cek keamanan {$this->token->symbol} gagal: ".mb_substr($e->getMessage(), 0, 200));
        }
    }
}
