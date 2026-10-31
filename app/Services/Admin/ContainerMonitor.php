<?php

namespace App\Services\Admin;

use App\Models\ContainerMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Container status and resource use, sampled on the host by deploy/monitor.sh: the app itself has no
 * access to Docker, so a compromised app cannot control the server.
 */
class ContainerMonitor
{
    /** Services that are meant to run once and stop. */
    private const ONE_SHOT = ['migrate'];

    private const HOST_CACHE_KEY = 'monitoring.host';

    private const STATUS_ORDER = ['down' => 0, 'warn' => 1, 'ok' => 2, 'done' => 3];

    /**
     * Seconds between samples, chosen in Admin > Pengaturan and passed back to the sampler.
     */
    public function intervalSeconds(): int
    {
        return (int) config('market.monitoring.container_interval_seconds');
    }

    /**
     * Store one sample. Both inputs are lines of JSON, as printed by docker inspect and docker stats.
     *
     * @param  array<string, int|float>  $host
     */
    public function record(string $inspect, string $stats, array $host): void
    {
        $sampledAt = now();
        $usage = collect($this->jsonLines($stats))->keyBy('Name');

        $rows = collect($this->jsonLines($inspect))->map(function (array $container) use ($usage, $sampledAt): array {
            $name = ltrim((string) ($container['name'] ?? ''), '/');

            return [
                'sampled_at' => $sampledAt,
                'name' => $name,
                'project' => ($container['project'] ?? '') ?: null,
                'service' => ($container['service'] ?? '') ?: null,
                'state' => (string) ($container['state'] ?? 'unknown'),
                'health' => $container['health'] ?? null,
                'exit_code' => (int) ($container['exit_code'] ?? 0),
                'restarts' => (int) ($container['restarts'] ?? 0),
                'started_at' => $this->startedAt($container['started_at'] ?? null),
                'cpu_percent' => $this->percent($usage[$name]['CPUPerc'] ?? null),
                'memory_mb' => $this->memoryMb($usage[$name]['MemUsage'] ?? null),
            ];
        })->filter(fn (array $row): bool => $row['name'] !== '');

        ContainerMetric::query()->insert($rows->all());
        Cache::put(self::HOST_CACHE_KEY, $host, now()->addHour());
    }

    /**
     * The latest sample of every container, with an hour of per-minute averages.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $interval = $this->intervalSeconds();
        $lastSample = ContainerMetric::query()->max('sampled_at');

        $history = $lastSample === null ? collect() : ContainerMetric::query()->toBase()
            ->where('sampled_at', '>=', now()->subHour())
            ->orderBy('sampled_at')
            ->get(['name', 'sampled_at', 'cpu_percent', 'memory_mb'])
            ->groupBy('name');

        $containers = $lastSample === null ? collect() : ContainerMetric::query()->where('sampled_at', $lastSample)->get()
            ->map(fn (ContainerMetric $metric): array => [
                'name' => $metric->name,
                'project' => $metric->project,
                'service' => $metric->service,
                ...$this->status($metric),
                'exit_code' => $metric->exit_code,
                'restarts' => $metric->restarts,
                'started_at' => $metric->started_at?->toIso8601String(),
                'cpu_percent' => $metric->cpu_percent,
                'memory_mb' => $metric->memory_mb,
                'history' => $this->perMinute($history[$metric->name] ?? collect()),
            ])
            ->sortBy(fn (array $c): array => [self::STATUS_ORDER[$c['status']], $c['project'] === 'dex' ? 0 : 1, $c['name']])
            ->values();

        return [
            'interval_seconds' => $interval,
            'last_sampled_at' => $lastSample === null ? null : Carbon::parse($lastSample)->toIso8601String(),
            // Three missed samples: the sampler or the host has a problem, the numbers are old.
            'stale' => $lastSample === null || Carbon::parse($lastSample)->lt(now()->subSeconds($interval * 3)),
            'host' => Cache::get(self::HOST_CACHE_KEY),
            'containers' => $containers,
        ];
    }

    /**
     * @return array{status: string, label: string}
     */
    private function status(ContainerMetric $metric): array
    {
        return match (true) {
            $metric->state === 'running' && $metric->health === 'unhealthy' => ['status' => 'down', 'label' => 'Tidak sehat'],
            $metric->state === 'running' && $metric->health === 'starting' => ['status' => 'warn', 'label' => 'Memulai'],
            $metric->state === 'running' && $metric->health === 'healthy' => ['status' => 'ok', 'label' => 'Sehat'],
            $metric->state === 'running' => ['status' => 'ok', 'label' => 'Berjalan'],
            $metric->state === 'restarting' => ['status' => 'warn', 'label' => 'Restart'],
            $metric->state === 'paused' => ['status' => 'warn', 'label' => 'Dijeda'],
            $metric->state === 'created' => ['status' => 'warn', 'label' => 'Belum jalan'],
            $metric->exit_code === 0 && in_array($metric->service, self::ONE_SHOT, true) => ['status' => 'done', 'label' => 'Selesai'],
            default => ['status' => 'down', 'label' => "Mati (kode {$metric->exit_code})"],
        };
    }

    /**
     * @param  iterable<object>  $samples
     * @return list<array{t: int, cpu: float|null, memory: float|null}>
     */
    private function perMinute(iterable $samples): array
    {
        return collect($samples)
            ->groupBy(fn (object $sample): int => (int) floor(Carbon::parse($sample->sampled_at)->getTimestamp() / 60))
            ->map(fn ($minute, int $key): array => [
                't' => $key * 60,
                'cpu' => $minute->avg('cpu_percent') === null ? null : round((float) $minute->avg('cpu_percent'), 2),
                'memory' => $minute->avg('memory_mb') === null ? null : round((float) $minute->avg('memory_mb'), 1),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLines(string $text): array
    {
        return collect(preg_split('/\R/', trim($text)) ?: [])
            ->map(fn (string $line): mixed => json_decode($line, true))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->values()
            ->all();
    }

    private function startedAt(?string $value): ?Carbon
    {
        // Docker reports year 0001 for a container that never started.
        if ($value === null || str_starts_with($value, '0001-')) {
            return null;
        }

        return rescue(fn (): Carbon => Carbon::parse(substr($value, 0, 19).'Z')->setTimezone(config('app.timezone')), null, report: false);
    }

    private function percent(?string $value): ?float
    {
        return $value !== null && is_numeric(rtrim($value, '%')) ? (float) rtrim($value, '%') : null;
    }

    /**
     * The used part of docker stats' "47.61MiB / 5.787GiB", in MiB.
     */
    private function memoryMb(?string $value): ?float
    {
        if ($value === null || ! preg_match('/^([\d.]+)\s*([KMGT]?)i?B/i', $value, $match)) {
            return null;
        }

        $power = ['' => -2, 'K' => -1, 'M' => 0, 'G' => 1, 'T' => 2][strtoupper($match[2])];

        return round((float) $match[1] * 1024 ** $power, 1);
    }
}
