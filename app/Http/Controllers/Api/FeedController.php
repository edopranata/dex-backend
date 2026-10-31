<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MarketData\MarketFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FeedController extends Controller
{
    /**
     * The first call, without "since", only starts the clock: logging in should not replay old events.
     */
    public function index(Request $request, MarketFeed $feed): JsonResponse
    {
        $since = $request->validate(['since' => ['sometimes', 'integer', 'min:0']])['since'] ?? null;
        $until = now()->startOfSecond();

        return response()->json([
            'server_time' => $until->getTimestamp(),
            'ticker' => $feed->ticker(),
            'events' => $since === null ? [] : $feed->events($request->user(), Carbon::createFromTimestamp((int) $since, $until->getTimezone()), $until),
        ]);
    }
}
