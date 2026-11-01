<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PositionResource;
use App\Models\User;
use App\Services\Analysis\PerformanceReport;
use App\Services\Analysis\StrategyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private const ORDERS_PER_PAGE = 20;

    /**
     * Performance of one strategy version: the current one by default, or "all" for every version.
     */
    public function performance(Request $request, PerformanceReport $report): JsonResponse
    {
        $version = $request->validate(['version' => ['sometimes', 'string', 'max:40']])['version'] ?? StrategyVersion::current();

        return response()->json($report->build($version === 'all' ? null : $version));
    }

    /**
     * What one auto-follow account holds and has done. Read-only: these accounts are the test itself, so
     * nothing here can cancel an order or reset a balance.
     */
    public function accountPortfolio(string $key): JsonResponse
    {
        $account = User::autoFollower($key);

        return response()->json([
            'positions' => PositionResource::collection($account->positions()->with('token')->where('quantity', '>', 0)->get()),
            'pending' => OrderResource::collection($account->orders()->with('token')->where('status', OrderStatus::Pending)->latest()->latest('id')->get()),
            'orders' => OrderResource::collection($account->orders()->with('token')->where('status', '!=', OrderStatus::Pending)->latest()->latest('id')->paginate(self::ORDERS_PER_PAGE))->response()->getData(true),
        ]);
    }
}
