<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Kirim email tes" in Admin → Pengaturan → Email.
 */
class TestEmail extends AppEmail
{
    public function __construct(public string $provider) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->email('Email tes '.config('app.name'), 'test', [
            'provider' => $this->provider,
            'sentAt' => $this->wib(now()),
            'url' => $this->appUrl(),
        ]);
    }
}
