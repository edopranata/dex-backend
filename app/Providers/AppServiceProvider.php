<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Admin\SettingsStore;
use App\Services\Admin\TaskRecorder;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $settings = $this->app->make(SettingsStore::class);
        $settings->apply();

        // Queue workers are long-lived: pick up settings changed in the admin panel before each job.
        Queue::before(fn () => $settings->apply());

        Gate::define('admin', fn (User $user): bool => (bool) $user->is_admin);

        Event::listen(ScheduledTaskFinished::class, [TaskRecorder::class, 'finished']);
        Event::listen(ScheduledTaskFailed::class, [TaskRecorder::class, 'failed']);
        Event::listen(ScheduledTaskSkipped::class, [TaskRecorder::class, 'skipped']);
    }
}
