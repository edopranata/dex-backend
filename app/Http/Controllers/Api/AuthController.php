<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Session-based SPA authentication (Sanctum stateful API).
 */
class AuthController extends Controller
{
    /**
     * Public auth settings for the login screen.
     */
    public function options(): JsonResponse
    {
        return response()->json(['registration_enabled' => config('market.simulator.registration_enabled')]);
    }

    public function register(RegisterRequest $request): UserResource
    {
        abort_unless(config('market.simulator.registration_enabled'), 403, 'Pendaftaran ditutup. Hubungi admin untuk dibuatkan akun.');

        $user = User::create([
            ...$request->validated(),
            'balance_usd' => config('market.simulator.starting_balance'),
        ]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return new UserResource($user);
    }

    public function login(LoginRequest $request): UserResource
    {
        if (! Auth::guard('web')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
