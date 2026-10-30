<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Lupa password" link. Queued so the response time of the public endpoint does not reveal
 * whether the email belongs to an account.
 */
class ResetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/reset-password').'?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]);
        $minutes = config('auth.passwords.users.expire');
        $app = config('app.name');

        return (new MailMessage)
            ->subject("Reset password {$app}")
            ->greeting("Halo {$notifiable->name},")
            ->line('Kami menerima permintaan reset password untuk akun Anda.')
            ->action('Buat password baru', $url)
            ->line("Link ini berlaku {$minutes} menit.")
            ->line('Jika Anda tidak memintanya, abaikan email ini; password Anda tidak berubah.')
            ->salutation("Salam,\n{$app}");
    }
}
