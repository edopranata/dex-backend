<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/**
 * The login details of an account created by an admin, or a new temporary password from an admin.
 * Sent right away (not queued) so the admin sees a delivery problem immediately.
 */
class TemporaryPassword extends AppEmail
{
    public function __construct(public string $password, public Carbon $expiresAt, public string $admin, public bool $isNew) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->email(
            $this->isNew ? 'Akun '.config('app.name').' Anda' : 'Password sementara baru '.config('app.name'),
            'temporary-password',
            [
                'isNew' => $this->isNew,
                'name' => $notifiable->name,
                'email' => $notifiable->email,
                'admin' => $this->admin,
                'password' => $this->password,
                'expiresAt' => $this->wib($this->expiresAt),
                'loginUrl' => $this->appUrl('/login'),
            ],
        );
    }
}
