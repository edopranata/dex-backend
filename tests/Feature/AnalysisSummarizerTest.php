<?php

use App\Enums\Recommendation;
use App\Models\Analysis;
use App\Models\Token;
use App\Services\Ai\AiClient;
use App\Services\Analysis\AnalysisSummarizer;

beforeEach(function () {
    $this->token = Token::factory()->create(['symbol' => 'GANG']);
    $this->ai = $this->mock(AiClient::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('lastProvider')->andReturn('gemini');
    });
});

/**
 * One analysis carrying the AI summary "lama", the given minutes ago.
 */
function summarizedAnalysis(Token $token, int $minutesAgo, int $score): Analysis
{
    return Analysis::factory()->for($token)->create([
        'recommendation' => Recommendation::StrongBuy, 'final_score' => $score, 'summary' => 'lama', 'summary_provider' => 'gemini', 'created_at' => now()->subMinutes($minutesAgo),
    ]);
}

function summarizeAt(Token $token, int $score, Analysis $previous): array
{
    return app(AnalysisSummarizer::class)->summarize($token, Recommendation::StrongBuy, [
        'final_score' => $score, 'scores' => ['technical' => 90], 'levels' => [], 'reasons' => [], 'news_headlines' => [],
    ], $previous);
}

test('a recent summary is reused while the score stays close to the one it was written with', function () {
    summarizedAnalysis($this->token, 30, 80);
    $previous = summarizedAnalysis($this->token, 15, 81);
    $this->ai->shouldNotReceive('json');

    expect(summarizeAt($this->token, 82, $previous))->toBe(['summary' => 'lama', 'provider' => 'gemini']);
});

test('a summary is written again once the score moved five points since it was written, like GANG on 10 Nov', function () {
    // Written at 74, then reused while the score crept up a little at a time.
    foreach ([[90, 74], [75, 78], [60, 77], [45, 78], [30, 80], [15, 83]] as [$minutesAgo, $score]) {
        $previous = summarizedAnalysis($this->token, $minutesAgo, $score);
    }
    $this->ai->shouldReceive('json')->once()->andReturn(['summary' => 'baru']);

    expect(summarizeAt($this->token, 84, $previous))->toBe(['summary' => 'baru', 'provider' => 'gemini']);
});

test('the summary age counts from when it was written, not from the analysis before', function () {
    foreach (range(240, 15, 15) as $minutesAgo) {
        $previous = summarizedAnalysis($this->token, $minutesAgo, 80);
    }
    $this->ai->shouldReceive('json')->once()->andReturn(['summary' => 'baru']);

    expect(summarizeAt($this->token, 80, $previous)['summary'])->toBe('baru');
});

test('a changed recommendation always gets a new summary', function () {
    $previous = summarizedAnalysis($this->token, 15, 80);
    $this->ai->shouldReceive('json')->once()->andReturn(['summary' => 'baru']);

    $result = app(AnalysisSummarizer::class)->summarize($this->token, Recommendation::Buy, [
        'final_score' => 80, 'scores' => ['technical' => 90], 'levels' => [], 'reasons' => [], 'news_headlines' => [],
    ], $previous);

    expect($result['summary'])->toBe('baru');
});
