<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Lupa password" link. Queued so the response time of the public endpoint does not reveal
 * whether the email belongs to an account.
 */
class ResetPassword extends AppEmail implements ShouldQueue
{
    public function __construct(public string $token) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->email('Reset password '.config('app.name'), 'reset-password', [
            'name' => $notifiable->name,
            'email' => $notifiable->email,
            'url' => $this->appUrl('/reset-password').'?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]),
            'minutes' => config('auth.passwords.users.expire'),
        ]);
    }
}
