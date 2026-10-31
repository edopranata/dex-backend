<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WhaleTradeResource;
use App\Models\WhaleTrade;
use App\Services\MarketData\WalletDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request, WalletDirectory $directory): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:128'],
            'chain' => ['sometimes', 'nullable', 'string', 'max:40'],
            'hide_bots' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['trades', 'tokens', 'buys', 'sells', 'volume_usd', 'last_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $all = $directory->all();
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        $rows = $all
            ->when($search !== '', fn ($rows) => $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['wallet']), $search)))
            ->when($filters['chain'] ?? null, fn ($rows, $chain) => $rows->where('chain', $chain))
            ->when($request->boolean('hide_bots'), fn ($rows) => $rows->where('likely_bot', false))
            ->sortBy($filters['sort'] ?? 'last_at', SORT_REGULAR, ($filters['direction'] ?? 'desc') === 'desc')
            ->values();

        $page = (int) ($filters['page'] ?? 1);

        return response()->json([
            'data' => $rows->forPage($page, self::PER_PAGE)->values(),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / self::PER_PAGE)), 'total' => $rows->count()],
            'summary' => ['wallets' => $all->count(), 'likely_bots' => $all->where('likely_bot', true)->count()],
            'chains' => $all->unique('chain')->map(fn (array $row): array => ['id' => $row['chain'], 'label' => $row['chain_label']])->sortBy('label')->values(),
        ]);
    }

    public function show(Request $request, string $chain, string $wallet, WalletDirectory $directory): JsonResponse
    {
        $summary = $directory->find($chain, $wallet);

        abort_if($summary === null, 404, 'Wallet tidak ditemukan.');

        $trades = WhaleTrade::query()
            ->with('token')
            ->where('wallet', $wallet)
            ->whereHas('token', fn ($query) => $query->where('chain_id', $chain))
            ->latest('traded_at')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'wallet' => $summary,
            'tokens' => $directory->tokens($chain, $wallet),
            'trades' => WhaleTradeResource::collection($trades)->response()->getData(true),
        ]);
    }
}
