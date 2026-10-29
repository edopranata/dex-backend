<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (Google Authenticator, Authy, 1Password…) plus single-use recovery codes.
 */
class TwoFactorAuthenticator
{
    public function __construct(private Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('app.name'), $user->email, $secret);
    }

    public function qrCodeSvg(string $otpauthUrl): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($otpauthUrl);
    }

    /**
     * Check a 6-digit code (±30 seconds of clock drift). Each code works only once.
     */
    public function verify(User $user, string $code, ?string $secret = null): bool
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        $secret ??= $user->two_factor_secret;

        if ($secret === null || preg_match('/^\d{6}$/', $code) !== 1 || ! $this->google2fa->verifyKey($secret, $code, 1)) {
            return false;
        }

        return Cache::add("two-factor:used:{$user->id}:{$code}", true, now()->addMinutes(3));
    }

    /**
     * Accept and burn one recovery code.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $index = array_search(strtoupper(trim($code)), $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return array_map(fn (): string => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)), range(1, 8));
    }
}
