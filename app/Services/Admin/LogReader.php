<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Reads Laravel log files from storage/logs and parses entries (with stack traces).
 */
class LogReader
{
    private const FILENAME_PATTERN = '/^laravel(-\d{4}-\d{2}-\d{2})?\.log$/';

    /**
     * How much of the end of a file is parsed; logs older than this are only available by download.
     */
    private const TAIL_BYTES = 2_000_000;

    /**
     * @return list<array{name: string, size: int, modified_at: int}>
     */
    public function files(): array
    {
        if (! is_dir(storage_path('logs'))) {
            return [];
        }

        return collect(File::files(storage_path('logs')))
            ->filter(fn ($file): bool => preg_match(self::FILENAME_PATTERN, $file->getFilename()) === 1)
            ->sortByDesc(fn ($file): int => $file->getMTime())
            ->map(fn ($file): array => ['name' => $file->getFilename(), 'size' => $file->getSize(), 'modified_at' => $file->getMTime()])
            ->values()
            ->all();
    }

    public function path(string $name): string
    {
        $path = storage_path('logs/'.$name);

        if (preg_match(self::FILENAME_PATTERN, $name) !== 1 || ! is_file($path)) {
            throw new InvalidArgumentException('File log tidak ditemukan.');
        }

        return $path;
    }

    /**
     * Newest entries first.
     *
     * @return array{entries: list<array{time: string, env: string, level: string, message: string, context: string}>, truncated: bool}
     */
    public function entries(string $name, ?string $level = null, ?string $search = null, int $limit = 200): array
    {
        $path = $this->path($name);
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        $offset = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $offset);
        $content = stream_get_contents($handle);
        fclose($handle);

        if ($offset > 0) {
            $content = substr($content, strpos($content, "\n[") ?: 0);
        }

        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T][^\]]+)\] (\w+)\.(\w+): (.*?)(?=^\[\d{4}-\d{2}-\d{2}[ T]|\z)/ms', $content, $matches, PREG_SET_ORDER);

        $entries = [];

        foreach (array_reverse($matches) as [, $time, $env, $entryLevel, $body]) {
            $entryLevel = strtolower($entryLevel);

            if ($level && $entryLevel !== strtolower($level)) {
                continue;
            }

            if ($search && stripos($body, $search) === false) {
                continue;
            }

            $lines = explode("\n", rtrim($body));
            $entries[] = [
                'time' => $time,
                'env' => $env,
                'level' => $entryLevel,
                'message' => mb_substr(array_shift($lines), 0, 2000),
                'context' => mb_substr(implode("\n", $lines), 0, 20000),
            ];

            if (count($entries) >= $limit) {
                break;
            }
        }

        return ['entries' => $entries, 'truncated' => $offset > 0];
    }
}
