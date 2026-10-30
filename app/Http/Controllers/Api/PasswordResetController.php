<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\MailSettings;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * "Lupa password" and the link from an invitation email; both end on the SPA's /reset-password page.
 */
class PasswordResetController extends Controller
{
    /**
     * Always answers the same way, so the form cannot be used to find out which emails have accounts.
     */
    public function forgot(Request $request, MailSettings $mail): JsonResponse
    {
        abort_unless($mail->isEnabled(), 503, 'Email belum diaktifkan. Hubungi admin untuk reset password.');

        $email = $request->validate(['email' => ['required', 'email']])['email'];
        $user = User::query()->where('email', $email)->first();

        if ($user !== null && ! $user->is_system) {
            Password::broker('users')->sendResetLink(['email' => $user->email]);
        }

        return response()->json(['message' => 'Jika email terdaftar, link reset password sudah dikirim. Cek juga folder spam.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'invite' => ['sometimes', 'boolean'],
        ]);

        $status = Password::broker(($data['invite'] ?? false) ? 'invites' : 'users')->reset(
            ['email' => $data['email'], 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'Link tidak valid atau sudah kedaluwarsa. Minta link baru.']);
        }

        return response()->json(['message' => 'Password tersimpan. Silakan masuk.']);
    }
}
