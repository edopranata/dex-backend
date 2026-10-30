<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use App\Services\Notifications\MailSettings;
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
        return response()->json([
            'registration_enabled' => config('market.simulator.registration_enabled'),
            'password_reset_enabled' => app(MailSettings::class)->isEnabled(),
        ]);
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

    /**
     * Step 1. Users with 2FA are not logged in yet: the session remembers who passed the password
     * check for five minutes, and the code goes to twoFactorChallenge().
     */
    public function login(LoginRequest $request): UserResource|JsonResponse
    {
        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials($request->only('email'));

        if (! $user instanceof User || $user->is_system || ! $provider->validateCredentials($user, $request->only('password'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('login.two_factor', [
                'id' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires_at' => now()->addMinutes(5)->getTimestamp(),
            ]);

            return response()->json(['two_factor' => true]);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return new UserResource($user);
    }

    /**
     * Step 2 for 2FA users: a code from the authenticator app, or one recovery code.
     */
    public function twoFactorChallenge(Request $request, TwoFactorAuthenticator $twoFactor): UserResource
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $pending = $request->session()->get('login.two_factor');
        $user = is_array($pending) && $pending['expires_at'] >= now()->getTimestamp() ? User::find($pending['id']) : null;

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget('login.two_factor');

            throw ValidationException::withMessages(['code' => 'Sesi login kedaluwarsa. Masukkan email dan password lagi.']);
        }

        $valid = filled($data['code'] ?? null)
            ? $twoFactor->verify($user, $data['code'])
            : $twoFactor->useRecoveryCode($user, (string) $data['recovery_code']);

        if (! $valid) {
            throw ValidationException::withMessages([filled($data['code'] ?? null) ? 'code' : 'recovery_code' => 'Kode tidak valid.']);
        }

        $request->session()->forget('login.two_factor');
        Auth::guard('web')->login($user, (bool) $pending['remember']);
        $request->session()->regenerate();

        return new UserResource($user);
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
