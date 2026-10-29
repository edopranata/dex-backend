<?php

namespace App\Providers;

use App\Listeners\NotifyAdminsOfLongQueueWait;
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

        Event::listen(LongWaitDetected::class, NotifyAdminsOfLongQueueWait::class);
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
