<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Notifications\PasswordChanged;
use App\Services\Auth\TemporaryPasswords;
use App\Services\Notifications\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Settings each user manages for themselves: profile, password, 2FA status, Telegram and notifications.
 */
class ProfileController extends Controller
{
    public function show(Request $request, TelegramClient $telegram): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'two_factor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_left' => count($user->two_factor_recovery_codes ?? []),
            ],
            'telegram' => [
                'available' => $telegram->isActive(),
                'linked' => $user->hasTelegram(),
                'username' => $user->telegram_username,
                'linked_at' => $user->telegram_linked_at?->toIso8601String(),
            ],
            'notifications' => $user->notificationSettings(),
        ]);
    }

    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $user->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]));

        return new UserResource($user);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ], ['password.different' => 'Password baru harus berbeda dari password sekarang.']);

        $user = $request->user();
        $user->update(['password' => $data['password']]);
        app(TemporaryPasswords::class)->clear($user);
        $user->notify(new PasswordChanged(now()));

        return response()->json(['message' => 'Password diganti.', 'data' => new UserResource($user)]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $bool = ['required', 'boolean'];
        $hour = ['required', 'integer', 'min:0', 'max:23'];

        $data = $request->validate([
            'signals' => $bool,
            'signal_updates' => $bool,
            'risk' => $bool,
            'watchlist' => $bool,
            'system' => $bool,
            'daily_summary' => $bool,
            'signal_min' => ['required', Rule::in(['BUY', 'STRONG_BUY'])],
            'daily_summary_hour' => $hour,
            'quiet_start_hour' => $hour,
            'quiet_end_hour' => $hour,
        ]);

        $user = $request->user();
        $user->update(['notification_preferences' => array_map(fn ($v) => is_numeric($v) && ! is_bool($v) ? (int) $v : $v, $data)]);

        return response()->json(['notifications' => $user->notificationSettings()]);
    }
}
