<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Link to switch 2FA off, requested from the login screen after the password was accepted.
 */
class TwoFactorResetLink extends AppEmail
{
    public function __construct(public string $token, public int $minutes) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->email('Reset 2FA '.config('app.name'), 'two-factor-reset', [
            'name' => $notifiable->name,
            'email' => $notifiable->email,
            'url' => $this->appUrl('/reset-2fa').'?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]),
            'minutes' => $this->minutes,
        ]);
    }
}
