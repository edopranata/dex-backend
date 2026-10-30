<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Base for the app's emails: Markdown views in resources/views/mail on the dark "dex" theme.
 */
abstract class AppEmail extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function email(string $subject, string $view, array $data): MailMessage
    {
        return (new MailMessage)
            ->subject($subject)
            ->markdown("mail.{$view}", ['app' => config('app.name'), ...$data]);
    }

    /**
     * "30 Okt 2026, 19:05" in Jakarta time; the emails say "WIB" after it.
     */
    protected function wib(Carbon $time): string
    {
        return $time->copy()->setTimezone('Asia/Jakarta')->locale('id')->translatedFormat('j M Y, H:i');
    }

    protected function appUrl(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
