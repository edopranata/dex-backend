<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/**
 * Security notice after 2FA was switched off by email link or by an admin.
 */
class TwoFactorDisabled extends AppEmail implements ShouldQueue
{
    public function __construct(public Carbon $disabledAt, public bool $byAdmin) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->email('2FA '.config('app.name').' dimatikan', 'two-factor-disabled', [
            'name' => $notifiable->name,
            'email' => $notifiable->email,
            'disabledAt' => $this->wib($this->disabledAt),
            'byAdmin' => $this->byAdmin,
            'securityUrl' => $this->appUrl('/profile/keamanan'),
        ]);
    }
}
