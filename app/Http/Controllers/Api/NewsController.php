<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsItemResource;
use App\Models\NewsItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class NewsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'sentiment' => ['sometimes', Rule::in(['bullish', 'neutral', 'bearish'])],
            'token_id' => ['sometimes', 'integer'],
        ]);

        return NewsItemResource::collection(
            NewsItem::query()
                ->with('tokens')
                ->when($filters['sentiment'] ?? null, fn ($q, $label) => $q->where('sentiment_label', $label))
                ->when($filters['token_id'] ?? null, fn ($q, $id) => $q->whereRelation('tokens', 'tokens.id', $id))
                ->latest('published_at')
                ->paginate(30),
        );
    }
}
