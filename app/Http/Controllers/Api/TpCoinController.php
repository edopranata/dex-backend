<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analysis\TpCoins;
use Illuminate\Http\JsonResponse;

class TpCoinController extends Controller
{
    public function __invoke(TpCoins $coins): JsonResponse
    {
        return response()->json($coins->build());
    }
}
