<?php

namespace App\Services\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only view of the UptimeRobot monitors (API v2 getMonitors) for Admin → General Information.
 * Cached for 5 minutes (checks run every 5 minutes; the free plan allows 10 API requests a minute).
 */
class UptimeRobot
{
    private const ENDPOINT = 'https://api.uptimerobot.com/v2/getMonitors';

    /** UptimeRobot monitor status codes. */
    private const STATUSES = [0 => 'paused', 1 => 'pending', 2 => 'up', 8 => 'down', 9 => 'down'];

    public function isConfigured(): bool
    {
        return filled(config('market.monitoring.uptimerobot_key'));
    }

    /**
     * @return array{ok: bool, error: string|null, monitors: list<array{id: int, name: string, url: string, status: string, uptime: array{day: float|null, week: float|null, month: float|null}, response_ms: int|null, last_down: array{at: string, duration_seconds: int, reason: string|null}|null}>}|null
     */
    public function monitors(): ?array
    {
        $key = (string) config('market.monitoring.uptimerobot_key');

        if ($key === '') {
            return null;
        }

        // The key is part of the cache key, so a new key is used right away.
        return Cache::remember('uptimerobot:monitors:'.hash('sha256', $key), now()->addMinutes(5), function () use ($key): array {
            try {
                $response = Http::asForm()->timeout(10)->post(self::ENDPOINT, [
                    'api_key' => $key,
                    'format' => 'json',
                    'custom_uptime_ratios' => '1-7-30',
                    'response_times' => 1,
                    'response_times_limit' => 1,
                    'logs' => 1,
                    'logs_limit' => 10,
                ])->throw()->json();
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200), 'monitors' => []];
            }

            if (($response['stat'] ?? null) !== 'ok') {
                return ['ok' => false, 'error' => (string) ($response['error']['message'] ?? 'Respons UptimeRobot tidak dikenali'), 'monitors' => []];
            }

            return ['ok' => true, 'error' => null, 'monitors' => array_map(fn (array $monitor): array => $this->normalize($monitor), $response['monitors'] ?? [])];
        });
    }

    /**
     * The monitor that watches our /health endpoint (or the first one).
     *
     * @return array<string, mixed>|null
     */
    public function healthMonitor(): ?array
    {
        $monitors = $this->monitors()['monitors'] ?? [];

        return collect($monitors)->first(fn (array $monitor): bool => str_contains($monitor['url'], '/health')) ?? ($monitors[0] ?? null);
    }

    /**
     * @param  array<string, mixed>  $monitor
     * @return array<string, mixed>
     */
    private function normalize(array $monitor): array
    {
        $ratios = array_map(fn (string $ratio): ?float => is_numeric($ratio) ? round((float) $ratio, 2) : null, explode('-', (string) ($monitor['custom_uptime_ratio'] ?? '')));
        $down = collect($monitor['logs'] ?? [])->first(fn (array $log): bool => (int) ($log['type'] ?? 0) === 1);

        return [
            'id' => (int) $monitor['id'],
            'name' => (string) ($monitor['friendly_name'] ?? ''),
            'url' => (string) ($monitor['url'] ?? ''),
            'status' => self::STATUSES[(int) ($monitor['status'] ?? 1)] ?? 'pending',
            'uptime' => ['day' => $ratios[0] ?? null, 'week' => $ratios[1] ?? null, 'month' => $ratios[2] ?? null],
            'response_ms' => isset($monitor['average_response_time']) ? (int) round((float) $monitor['average_response_time']) : null,
            'last_down' => $down === null ? null : [
                'at' => Carbon::createFromTimestamp((int) $down['datetime'])->toIso8601String(),
                'duration_seconds' => (int) ($down['duration'] ?? 0),
                'reason' => $down['reason']['detail'] ?? null,
            ],
        ];
    }
}
