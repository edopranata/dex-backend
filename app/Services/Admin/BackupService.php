<?php

namespace App\Services\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Gzipped MySQL dumps. Scheduled backups come from the host cron (deploy/backup.sh) into the same
 * folder, which is mounted into the app container; admins can download them and create a manual
 * backup at most once per cooldown period (counting any backup, scheduled or manual).
 */
class BackupService
{
    public const FILENAME_PATTERN = '/^dex-\d{4}-\d{2}-\d{2}-\d{4,6}\.sql\.gz$/';

    public function directory(): string
    {
        $directory = config('market.backup.path');
        File::ensureDirectoryExists($directory, 0700);

        return $directory;
    }

    /**
     * @return list<array{name: string, size: int, created_at: string}>
     */
    public function list(): array
    {
        return collect(File::files($this->directory()))
            ->filter(fn ($file): bool => preg_match(self::FILENAME_PATTERN, $file->getFilename()) === 1)
            ->sortByDesc(fn ($file): int => $file->getMTime())
            ->map(fn ($file): array => [
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'created_at' => Carbon::createFromTimestamp($file->getMTime())->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function lastBackupAt(): ?Carbon
    {
        $latest = $this->list()[0] ?? null;

        return $latest ? Carbon::parse($latest['created_at']) : null;
    }

    /**
     * Result of the last upload to R2 by deploy/backup.sh, or null before off-site copies were set up.
     *
     * @return array{ok: bool, file: string, size: int, at: string, error?: string}|null
     */
    public function offsiteStatus(): ?array
    {
        return $this->statusFile('offsite-status.json');
    }

    /**
     * Result of the last deploy/restore-check.sh run, or null if a restore was never tested.
     *
     * @return array{ok: bool, file: string, source: string, at: string, seconds: int, tables: int, live_tables: int, migrations: int, live_migrations: int, users: int, tokens: int, orders: int}|null
     */
    public function restoreCheck(): ?array
    {
        return $this->statusFile('restore-check.json');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function statusFile(string $name): ?array
    {
        $path = $this->directory().'/'.$name;
        $status = is_file($path) ? json_decode((string) File::get($path), true) : null;

        return is_array($status) ? $status : null;
    }

    /**
     * When the next manual backup is allowed, or null if allowed now.
     */
    public function manualAvailableAt(): ?Carbon
    {
        $last = $this->lastBackupAt();
        $next = $last?->copy()->addMinutes(config('market.backup.manual_cooldown_minutes'));

        return $next !== null && $next->isFuture() ? $next : null;
    }

    /**
     * Dump the database with mariadb-dump and gzip it.
     *
     * @return array{name: string, size: int, created_at: string}
     *
     * @throws BackupCooldownException
     */
    public function create(bool $enforceCooldown = true): array
    {
        if ($enforceCooldown && ($availableAt = $this->manualAvailableAt()) !== null) {
            throw new BackupCooldownException($availableAt);
        }

        $connection = config('database.connections.'.config('database.default'));

        if (($connection['driver'] ?? null) !== 'mysql' && ($connection['driver'] ?? null) !== 'mariadb') {
            throw new RuntimeException('Backup hanya didukung untuk MySQL/MariaDB.');
        }

        // Same local time as the host cron (deploy/backup.sh) so file names sort and read consistently.
        $name = 'dex-'.now()->setTimezone(config('market.backup.timezone'))->format('Y-m-d-His').'.sql.gz';
        $path = $this->directory().'/'.$name;

        $process = Process::fromShellCommandline(
            'mariadb-dump --single-transaction --quick --no-tablespaces --skip-ssl --routines '
            .'-h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" | gzip -9 > "$OUT"',
            null,
            [
                'DB_HOST' => $connection['host'],
                'DB_PORT' => (string) $connection['port'],
                'DB_USER' => $connection['username'],
                'DB_NAME' => $connection['database'],
                'MYSQL_PWD' => (string) $connection['password'],
                'OUT' => $path,
            ],
            null,
            600,
        );
        $process->run();

        if (! $process->isSuccessful() || ! is_file($path) || filesize($path) < 100) {
            File::delete($path);

            throw new RuntimeException('Backup gagal: '.trim($process->getErrorOutput()));
        }

        chmod($path, 0600);

        return ['name' => $name, 'size' => filesize($path), 'created_at' => now()->toIso8601String()];
    }

    public function path(string $name): string
    {
        if (preg_match(self::FILENAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Nama file backup tidak valid.');
        }

        $path = $this->directory().'/'.$name;

        if (! is_file($path)) {
            throw new InvalidArgumentException('File backup tidak ditemukan.');
        }

        return $path;
    }

    public function delete(string $name): void
    {
        File::delete($this->path($name));
    }

    /**
     * Delete backups older than the retention period. Returns the number removed.
     */
    public function prune(int $keepDays): int
    {
        $removed = 0;

        foreach ($this->list() as $backup) {
            if (Carbon::parse($backup['created_at'])->lt(now()->subDays($keepDays))) {
                $this->delete($backup['name']);
                $removed++;
            }
        }

        return $removed;
    }
}
