<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->withCount(['positions' => fn ($q) => $q->where('quantity', '>', 0), 'orders'])
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'balance_usd', 'is_admin', 'is_system', 'created_at', 'two_factor_confirmed_at', 'telegram_chat_id'])
            ->map(fn (User $user): array => [
                ...$user->only('id', 'name', 'email', 'balance_usd', 'is_admin', 'is_system', 'created_at', 'positions_count', 'orders_count'),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'telegram_linked' => $user->telegram_chat_id !== null,
            ]);

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'is_admin' => ['boolean'],
        ]);

        $user = User::create([...$data, 'is_admin' => $data['is_admin'] ?? false, 'balance_usd' => config('market.simulator.starting_balance')]);

        return response()->json(['data' => $user->only('id', 'name', 'email', 'balance_usd', 'is_admin')], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', Password::defaults()],
            'is_admin' => ['sometimes', 'boolean'],
            'disable_two_factor' => ['sometimes', 'accepted'],
        ]);

        if ($user->is($request->user()) && array_key_exists('is_admin', $data) && ! $data['is_admin']) {
            throw ValidationException::withMessages(['is_admin' => 'Anda tidak bisa mencabut status admin akun sendiri.']);
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        // For a user who lost their phone and recovery codes.
        if (isset($data['disable_two_factor'])) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
            unset($data['disable_two_factor']);
        }

        $user->update($data);

        return response()->json(['data' => $user->only('id', 'name', 'email', 'balance_usd', 'is_admin')]);
    }

    public function destroy(Request $request, User $user): Response
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Anda tidak bisa menghapus akun sendiri.']);
        }

        $user->delete();

        return response()->noContent();
    }
}
