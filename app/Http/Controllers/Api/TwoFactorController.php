<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Users turn two-factor authentication on and off themselves. Every change asks for the password.
 */
class TwoFactorController extends Controller
{
    /**
     * Step 1: create a secret and show the QR code. Not active until confirmed with a code.
     */
    public function store(Request $request, TwoFactorAuthenticator $twoFactor): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['password' => '2FA sudah aktif.']);
        }

        $secret = $twoFactor->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $url = $twoFactor->otpauthUrl($user, $secret);

        return response()->json(['secret' => $secret, 'otpauth_url' => $url, 'qr_svg' => $twoFactor->qrCodeSvg($url)]);
    }

    /**
     * Step 2: the first code from the authenticator app proves it was set up correctly.
     */
    public function confirm(Request $request, TwoFactorAuthenticator $twoFactor): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => 'Mulai ulang pengaktifan 2FA.']);
        }

        if (! $twoFactor->verify($user, $request->string('code'))) {
            throw ValidationException::withMessages(['code' => 'Kode tidak cocok. Pastikan jam di HP sudah benar lalu coba kode berikutnya.']);
        }

        $codes = $twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();

        return response()->json(['recovery_codes' => $codes]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        return response()->json(['message' => '2FA dinonaktifkan.']);
    }

    public function recoveryCodes(Request $request, TwoFactorAuthenticator $twoFactor): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 422, '2FA belum aktif.');

        $codes = $twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return response()->json(['recovery_codes' => $codes]);
    }
}
