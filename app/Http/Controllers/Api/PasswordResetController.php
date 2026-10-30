<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Services\Notifications\MailSettings;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * "Lupa password": the emailed link opens the SPA's /reset-password page.
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
        ]);

        $status = Password::broker('users')->reset(
            ['email' => $data['email'], 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password): void {
                // Their own password now, so a pending temporary password no longer applies.
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                    'temporary_password_expires_at' => null,
                ])->save();
                event(new PasswordReset($user));
                $user->notify(new PasswordChanged(now()));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'Link tidak valid atau sudah kedaluwarsa. Minta link baru.']);
        }

        return response()->json(['message' => 'Password tersimpan. Silakan masuk.']);
    }
}
