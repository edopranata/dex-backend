<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/**
 * Security notice after any password change, so a takeover does not go unnoticed.
 */
class PasswordChanged extends AppEmail implements ShouldQueue
{
    public function __construct(public Carbon $changedAt) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->email('Password '.config('app.name').' Anda diganti', 'password-changed', [
            'name' => $notifiable->name,
            'email' => $notifiable->email,
            'changedAt' => $this->wib($this->changedAt),
            'forgotUrl' => $this->appUrl('/forgot-password').'?'.http_build_query(['email' => $notifiable->email]),
        ]);
    }
}
