<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an admin creates an account: a link to set the user's own password.
 */
class InviteUser extends Notification
{
    use Queueable;

    public function __construct(public string $token, public string $invitedBy) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/reset-password').'?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email, 'invite' => 1]);
        $app = config('app.name');

        return (new MailMessage)
            ->subject("Undangan ke {$app}")
            ->greeting("Halo {$notifiable->name},")
            ->line("{$this->invitedBy} membuatkan akun {$app} untuk Anda dengan email {$notifiable->email}.")
            ->line('Klik tombol di bawah untuk membuat password Anda sendiri, lalu masuk.')
            ->action('Buat password', $url)
            ->line('Link ini berlaku 3 hari. Jika sudah kedaluwarsa, minta admin mengirim ulang undangan.')
            ->salutation("Salam,\n{$app}");
    }
}
