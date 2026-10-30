<?php

namespace App\Console\Commands;

use App\Services\Admin\HealthCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('app:heartbeat')]
#[Description('Kirim heartbeat ke Healthchecks.io (gagal jika database, Redis, scheduler atau Horizon bermasalah)')]
class HeartbeatCommand extends Command
{
    public const LAST_PING = 'heartbeat:last-ping';

    public function handle(HealthCheck $health): int
    {
        $url = (string) config('market.monitoring.heartbeat_url');

        if ($url === '') {
            return self::SUCCESS;
        }

        $checks = $health->run(withHorizon: true);
        $failed = array_keys(array_filter($checks, fn (bool $ok): bool => ! $ok));
        $body = $failed === [] ? 'ok' : 'Bermasalah: '.implode(', ', $failed);

        try {
            // A failure report goes to <url>/fail; Healthchecks.io shows the body in its log and alert.
            Http::timeout(10)->retry(2, 2000)->withBody($body, 'text/plain')->post(rtrim($url, '/').($failed === [] ? '' : '/fail'))->throw();
            $sent = true;
            $error = null;
        } catch (Throwable $e) {
            $sent = false;
            $error = mb_substr($e->getMessage(), 0, 200);
        }

        Cache::put(self::LAST_PING, ['at' => now()->toIso8601String(), 'sent' => $sent, 'healthy' => $failed === [], 'detail' => $error ?? $body], now()->addDay());
        $sent ? $this->components->info("Heartbeat: {$body}") : $this->components->error("Heartbeat tidak terkirim: {$error}");

        return $sent ? self::SUCCESS : self::FAILURE;
    }
}
