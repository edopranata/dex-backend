<?php

namespace App\Services\Notifications;

/**
 * Whether outgoing email is set up (Admin → Pengaturan → Email) and through which provider.
 */
class MailSettings
{
    public function isEnabled(): bool
    {
        return config('mail.default') === 'smtp'
            && filled(config('mail.mailers.smtp.host'))
            && filled(config('mail.from.address'));
    }

    /**
     * Provider name for the admin panel, derived from the SMTP host.
     */
    public function provider(): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $host = (string) config('mail.mailers.smtp.host');

        return match (true) {
            str_contains($host, 'resend.com') => 'Resend',
            str_contains($host, 'brevo.com'), str_contains($host, 'sendinblue.com') => 'Brevo',
            default => $host,
        };
    }
}
