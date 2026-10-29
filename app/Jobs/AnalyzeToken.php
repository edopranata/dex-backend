<?php

namespace App\Jobs;

use App\Models\Token;
use App\Services\Analysis\SignalEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeToken implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(public Token $token) {}

    public function uniqueId(): string
    {
        return (string) $this->token->id;
    }

    public function handle(SignalEngine $engine): void
    {
        $engine->analyze($this->token->refresh());
    }
}
