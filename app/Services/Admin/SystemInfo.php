<?php

namespace App\Services\Admin;

use App\Models\Analysis;
use App\Models\Candle;
use App\Models\NewsItem;
use App\Models\Order;
use App\Models\Signal;
use App\Models\TaskRun;
use App\Models\Token;
use App\Models\TokenCandidate;
use App\Models\User;
use App\Models\WhaleTrade;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class SystemInfo
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $storage = storage_path();

        return [
            'app' => [
                'env' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'php' => PHP_VERSION,
                'laravel' => Application::VERSION,
                'timezone' => config('app.timezone'),
                'url' => config('app.url'),
            ],
            'database' => [
                'driver' => DB::connection()->getDriverName(),
                'size_mb' => $this->databaseSizeMb(),
                'rows' => [
                    'Pengguna' => User::count(),
                    'Token aktif' => Token::query()->active()->count(),
                    'Kandidat discovery' => TokenCandidate::count(),
                    'Candle' => Candle::count(),
                    'Analisis' => Analysis::count(),
                    'Sinyal' => Signal::count(),
                    'Transaksi whale' => WhaleTrade::count(),
                    'Berita' => NewsItem::count(),
                    'Order' => Order::count(),
                ],
            ],
            'queue' => [
                'connection' => config('queue.default'),
                'pending' => rescue(fn (): int => Queue::size() + Queue::size(config('market.market_data_queue')), null, report: false),
                'queues' => [
                    'default' => rescue(fn (): int => Queue::size(), null, report: false),
                    'market_data' => rescue(fn (): int => Queue::size(config('market.market_data_queue')), null, report: false),
                ],
                'failed' => DB::table('failed_jobs')->count(),
            ],
            'redis' => rescue(fn (): array => [
                'memory' => Redis::connection()->info('memory')['used_memory_human'] ?? Redis::connection()->info()['Memory']['used_memory_human'] ?? null,
            ], null, report: false),
            'scheduler' => [
                'last_run_at' => TaskRun::query()->where('trigger', 'schedule')->latest('id')->value('created_at'),
                'failures_24h' => TaskRun::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            ],
            'disk' => [
                'free_gb' => round(disk_free_space($storage) / 1024 ** 3, 1),
                'total_gb' => round(disk_total_space($storage) / 1024 ** 3, 1),
            ],
        ];
    }

    private function databaseSizeMb(): ?float
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $bytes = DB::table('information_schema.tables')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->sum(DB::raw('data_length + index_length'));

        return round($bytes / 1024 ** 2, 1);
    }
}
