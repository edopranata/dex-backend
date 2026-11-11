<?php

use App\Models\NewsItem;
use App\Services\Ai\AiClient;
use App\Services\Ai\NewsSentimentService;

test('a bare list of scores, as gemma 4 answers, is read like the items wrapper', function (bool $wrapped) {
    $items = NewsItem::factory()->count(2)->create();
    $this->mock(AiClient::class, function ($mock) use ($wrapped, $items) {
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('lastProvider')->andReturn('gemini_2');
        $mock->shouldReceive('json')->once()->andReturn(
            $wrapped ? ['items' => [['id' => $items[0]->id, 'score' => 85], ['id' => $items[1]->id, 'score' => 15]]] : [['id' => $items[0]->id, 'score' => 85], ['id' => $items[1]->id, 'score' => 15]],
        );
    });

    app(NewsSentimentService::class)->analyze($items);

    expect($items[0]->fresh()->only(['sentiment_score', 'sentiment_provider']))->toBe(['sentiment_score' => 85, 'sentiment_provider' => 'gemini_2'])
        ->and($items[1]->fresh()->sentiment_score)->toBe(15);
})->with(['bare list' => [false], 'items wrapper' => [true]]);
