<?php

namespace App\Console\Commands;

use App\Models\TaskRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/**
 * Called by deploy.sh before it builds and replaces containers (16 Nov 2026, owner's request): waits for a quiet
 * moment, so the scheduler is never replaced right when a task is due. Quiet means no admin task is running and no
 * scheduled task other than the frequent ones (every 15 s or 5 minutes) is due within the margin. Gives up after
 * --wait seconds so a deploy is delayed, never blocked.
 *
 * Since 28 Nov 2026 the market-data queue no longer has to be empty: GeckoTerminal's limit keeps it busy about 14 of
 * every 15 minutes (measured 28 Nov: one quiet moment of 30-45 s in 16 minutes), so every deploy waited the full
 * 10 minutes. Waiting jobs stay in Redis, and horizon is replaced last with a 90 s stop grace period, so the job
 * running then finishes and the cycle resumes a few seconds later.
 */
#[Signature('app:deploy-window {--wait=600 : Paling lama menunggu sekian detik} {--margin=90 : Tidak ada jadwal penting dalam sekian detik ke depan}')]
#[Description('Tunggu celah sepi (tidak ada task admin, tidak ada jadwal dekat) sebelum deploy')]
class DeployWindowCommand extends Command
{
    public function handle(Schedule $schedule): int
    {
        $deadline = time() + max(0, (int) $this->option('wait'));
        $margin = (int) $this->option('margin');

        do {
            $reason = $this->busy($schedule, $margin);

            if ($reason === null) {
                $queued = Queue::size((string) config('market.market_data_queue'));
                $this->components->info("Celah deploy: tidak ada jadwal dekat ({$queued} job di antrean market-data, lanjut setelah horizon diganti).");

                return self::SUCCESS;
            }

            if (time() >= $deadline) {
                $this->components->warn("Tidak ada celah dalam batas waktu ({$reason}); deploy tetap lanjut.");

                return self::FAILURE;
            }

            sleep(5);
        } while (true);
    }

    /**
     * Why now is not a good moment, or null when it is.
     */
    public function busy(Schedule $schedule, int $margin): ?string
    {
        if (TaskRun::query()->where('status', 'running')->exists()) {
            return 'ada task admin berjalan';
        }

        $now = CarbonImmutable::now();
        $soon = collect($schedule->events())
            ->reject(fn (Event $event): bool => self::frequent($event))
            ->map(fn (Event $event) => CarbonImmutable::instance($event->nextRunDate($now, 0, true)))
            ->filter(fn (CarbonImmutable $at): bool => $at->getTimestamp() - $now->getTimestamp() < $margin)
            ->count();

        return $soon > 0 ? "{$soon} jadwal jatuh tempo dalam {$margin} detik" : null;
    }

    /**
     * Tasks due so often that no window avoids them, and harmless to miss once (every minute: Token Baru samples).
     */
    private static function frequent(Event $event): bool
    {
        return ($event->repeatSeconds ?? null) !== null || in_array($event->expression, ['* * * * *', '*/5 * * * *'], true);
    }
}
