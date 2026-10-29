<?php

namespace App\Providers;

use App\Services\Notifications\TelegramNotifier;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon's own long-wait alert goes to the admins' Telegram instead of mail/Slack.
        Event::listen(function (LongWaitDetected $event): void {
            app(TelegramNotifier::class)->system(
                "Antrean {$event->queue} menunggu terlalu lama",
                "Job tertua sudah menunggu {$event->seconds} detik. Cek /horizon.",
                "horizon-wait:{$event->queue}",
                30,
            );
        });
    }

    /**
     * Register the Horizon gate.
     *
     * Admins of the app only (outside the local environment, where Horizon is open).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null): bool => (bool) $user?->is_admin);
    }
}
