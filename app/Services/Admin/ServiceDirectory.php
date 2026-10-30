<?php

namespace App\Services\Admin;

use App\Console\Commands\HeartbeatCommand;
use App\Http\Controllers\HealthController;
use App\Models\ServiceNote;
use App\Services\Ai\AiClient;
use App\Services\Notifications\MailSettings;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Every third-party service the app depends on, for Admin → General Information: what it is for,
 * where its dashboard lives, what it costs, where it is configured, and whether it is working.
 * Admins add their own note per service (which account, plan, renewal date).
 */
class ServiceDirectory
{
    public function __construct(private AiClient $ai, private MailSettings $mail, private BackupService $backups, private UptimeRobot $uptimeRobot) {}

    /**
     * @return list<array{key: string, label: string, services: list<array<string, mixed>>}>
     */
    public function groups(): array
    {
        $notes = ServiceNote::query()->pluck('note', 'service');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: (string) config('app.url');
        $uptimeMonitor = $this->uptimeRobot->healthMonitor();

        $groups = [
            [
                'key' => 'infrastructure',
                'label' => 'Server & infrastruktur',
                'services' => [
                    $this->service('vps', 'VPS (vpsindonesia)', 'Server production: Docker berisi app, MySQL, Redis, Horizon dan scheduler.',
                        'Sewa bulanan; perpanjang sebelum 29 Nov 2026', [],
                        $this->status('info', 'Production'), 'SSH: ssh dex-vps · folder /opt/dex'),
                    $this->service('cloudflare', 'Cloudflare (DNS & Tunnel)', 'Domain tune-in.trade, HTTPS, dan Tunnel ke server tanpa port terbuka.',
                        'Gratis', [
                            ['label' => 'Dashboard', 'url' => 'https://dash.cloudflare.com'],
                            ['label' => 'Zero Trust (Tunnel)', 'url' => 'https://one.dash.cloudflare.com'],
                        ],
                        $this->status('ok', 'Aktif', $host), 'Tunnel: /opt/edge di server'),
                    $this->service('r2', 'Cloudflare R2', 'Salinan backup database harian di luar VPS.',
                        'Gratis s.d. 10 GB/bulan', [['label' => 'R2', 'url' => 'https://dash.cloudflare.com/?to=/:account/r2/overview']],
                        $this->r2Status(), '/etc/dex/offsite.env di server (root)'),
                    $this->service('github', 'GitHub', 'Kode aplikasi dan deploy otomatis ke server (GitHub Actions) setiap push ke main.',
                        'Gratis', [
                            ['label' => 'dex-backend', 'url' => 'https://github.com/edopranata/dex-backend'],
                            ['label' => 'dex-frontend', 'url' => 'https://github.com/edopranata/dex-frontend'],
                            ['label' => 'Actions', 'url' => 'https://github.com/edopranata/dex-backend/actions'],
                        ],
                        $this->status('info', 'Deploy otomatis'), 'Secrets repo: Settings → Secrets and variables → Actions'),
                ],
            ],
            [
                'key' => 'monitoring',
                'label' => 'Monitoring & notifikasi',
                'services' => [
                    $this->service('uptimerobot', 'UptimeRobot', 'Memantau '.url('/health').' dari luar tiap 5 menit; peringatan ke Telegram saat situs, database, Redis atau scheduler mati.',
                        'Gratis (50 monitor, interval 5 menit)', [
                            ['label' => 'Dashboard', 'url' => 'https://dashboard.uptimerobot.com'],
                            ...($uptimeMonitor ? [['label' => 'Monitor', 'url' => "https://dashboard.uptimerobot.com/monitors/{$uptimeMonitor['id']}"]] : []),
                            ['label' => '/health', 'url' => url('/health')],
                        ],
                        $this->uptimeStatus(), 'Monitor & alert: dashboard UptimeRobot · read-only API key: Admin → Pengaturan → Monitoring'),
                    $this->service('healthchecks', 'Healthchecks.io', 'Heartbeat dari server tiap 5 menit (database, Redis, scheduler, Horizon) dan dari backup harian. Diam = server mati.',
                        'Gratis (20 cek)', [['label' => 'Dashboard', 'url' => 'https://healthchecks.io/projects/']],
                        $this->heartbeatStatus(), 'Admin → Pengaturan → Monitoring; ping backup di /etc/dex/offsite.env'),
                    $this->service('telegram', 'Telegram Bot', 'Notifikasi sinyal, TP/SL, ringkasan harian, dan peringatan operasional.',
                        'Gratis', [['label' => '@BotFather', 'url' => 'https://t.me/BotFather']],
                        config('market.telegram.enabled') && filled(config('market.telegram.bot_token'))
                            ? $this->status('ok', 'Aktif')
                            : $this->status('off', 'Belum diatur'),
                        'Admin → Pengaturan → Bot Telegram'),
                ],
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'services' => [
                    $this->service('email', 'Resend (SMTP)', 'Password sementara untuk pengguna baru, lupa password, reset 2FA, dan notifikasi keamanan.',
                        'Gratis 3.000 email/bulan (100/hari)', [
                            ['label' => 'Email terkirim', 'url' => 'https://resend.com/emails'],
                            ['label' => 'Domain', 'url' => 'https://resend.com/domains'],
                            ['label' => 'API keys', 'url' => 'https://resend.com/api-keys'],
                        ],
                        $this->mail->isEnabled()
                            ? $this->status('ok', 'Aktif via '.$this->mail->provider(), (string) config('mail.from.address'))
                            : $this->status('off', 'Belum diatur'),
                        'Admin → Pengaturan → Email (SMTP)'),
                ],
            ],
            [
                'key' => 'ai',
                'label' => 'AI',
                'services' => [
                    $this->service('gemini', 'Google Gemini', 'Sentimen berita dan ringkasan analisis.', 'Gratis (kuota harian)',
                        [['label' => 'API key', 'url' => 'https://aistudio.google.com/apikey'], ['label' => 'AI Studio', 'url' => 'https://aistudio.google.com']],
                        $this->aiStatus('gemini'), 'Admin → Pengaturan → AI'),
                    $this->service('xai', 'xAI Grok', 'Cadangan AI saat Gemini terkena limit atau error.', 'Berbayar per token (kredit prabayar)',
                        [['label' => 'Console', 'url' => 'https://console.x.ai']],
                        $this->aiStatus('xai'), 'Admin → Pengaturan → AI'),
                    $this->service('openai', 'OpenAI', 'Alternatif provider AI (opsional).', 'Berbayar per token',
                        [['label' => 'API keys', 'url' => 'https://platform.openai.com/api-keys']],
                        $this->aiStatus('openai'), 'Admin → Pengaturan → AI'),
                ],
            ],
            [
                'key' => 'data',
                'label' => 'Data pasar & berita (tanpa akun)',
                'services' => [
                    $this->service('dexscreener', 'DEX Screener', 'Harga, pair, likuiditas, dan token trending.', 'Gratis, tanpa akun',
                        [['label' => 'Situs', 'url' => 'https://dexscreener.com'], ['label' => 'Dokumentasi API', 'url' => 'https://docs.dexscreener.com']],
                        $this->status('info', 'Publik'), null),
                    $this->service('geckoterminal', 'GeckoTerminal', 'Candle OHLCV dan transaksi whale.', 'Gratis, ±'.config('market.geckoterminal_requests_per_minute').' request/menit per IP',
                        [['label' => 'Situs', 'url' => 'https://www.geckoterminal.com'], ['label' => 'Dokumentasi API', 'url' => 'https://apiguide.geckoterminal.com']],
                        $this->status('info', 'Publik'), 'Batas request: Admin → Pengaturan → Sumber data'),
                    $this->service('goplus', 'GoPlus Security', 'Cek keamanan token: honeypot, pajak jual/beli, risiko rug pull.', 'Gratis, tanpa akun',
                        [['label' => 'Situs', 'url' => 'https://gopluslabs.io']],
                        $this->status('info', 'Publik'), null),
                    $this->service('coingecko', 'CoinGecko', 'Token trending untuk halaman Temukan.', 'Gratis (Demo API key opsional)',
                        [['label' => 'Developer dashboard', 'url' => 'https://www.coingecko.com/en/developers/dashboard']],
                        filled(config('market.discovery.coingecko_key')) ? $this->status('ok', 'Demo API key') : $this->status('info', 'Publik tanpa key'),
                        'Admin → Pengaturan → Sumber data'),
                    $this->service('fear-greed', 'Fear & Greed Index', 'Sentimen pasar kripto secara umum.', 'Gratis, tanpa akun',
                        [['label' => 'alternative.me', 'url' => 'https://alternative.me/crypto/fear-and-greed-index/']],
                        $this->status('info', 'Publik'), null),
                    $this->service('news', 'Berita (RSS)', 'Berita kripto untuk sentimen: '.implode(', ', array_keys(config('market.news.feeds', []))).'.', 'Gratis, tanpa akun',
                        collect(config('market.news.feeds', []))->map(fn (string $feed, string $name): array => ['label' => $name, 'url' => $feed])->values()->all(),
                        $this->status('info', 'Publik'), null),
                ],
            ],
        ];

        return array_map(fn (array $group): array => [
            ...$group,
            'services' => array_map(fn (array $service): array => [...$service, 'note' => $notes[$service['key']] ?? null], $group['services']),
        ], $groups);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return collect($this->groups())->flatMap(fn (array $group): array => array_column($group['services'], 'key'))->all();
    }

    /**
     * "5 menit yang lalu": the app locale is English, this page is Indonesian.
     */
    private function ago(string $iso): string
    {
        return Carbon::parse($iso)->locale('id')->diffForHumans();
    }

    /**
     * @param  list<array{label: string, url: string}>  $links
     * @param  array{state: string, label: string, detail: string|null}  $status
     * @return array<string, mixed>
     */
    private function service(string $key, string $name, string $purpose, string $plan, array $links, array $status, ?string $configuredIn): array
    {
        return ['key' => $key, 'name' => $name, 'purpose' => $purpose, 'plan' => $plan, 'links' => $links, 'status' => $status, 'configured_in' => $configuredIn];
    }

    /**
     * @param  'ok'|'warn'|'error'|'off'|'info'  $state
     * @return array{state: string, label: string, detail: string|null}
     */
    private function status(string $state, string $label, ?string $detail = null): array
    {
        return ['state' => $state, 'label' => $label, 'detail' => $detail];
    }

    /**
     * @return array{state: string, label: string, detail: string|null}
     */
    private function r2Status(): array
    {
        $offsite = $this->backups->offsiteStatus();

        return match (true) {
            $offsite === null => $this->status('off', 'Belum diatur'),
            ! $offsite['ok'] => $this->status('error', 'Upload gagal', $offsite['error'] ?? null),
            Carbon::parse($offsite['at'])->lt(now()->subHours(36)) => $this->status('warn', 'Terlambat', 'Upload terakhir '.$this->ago($offsite['at'])),
            default => $this->status('ok', 'Aman', trim(($offsite['bucket'] ?? '').' · upload '.$this->ago($offsite['at']), ' ·')),
        };
    }

    /**
     * @return array{state: string, label: string, detail: string|null}
     */
    private function uptimeStatus(): array
    {
        if ($this->uptimeRobot->isConfigured()) {
            return $this->uptimeRobotStatus();
        }

        $last = Cache::get(HealthController::LAST_MONITOR_CHECK);

        return match (true) {
            ! is_array($last) => $this->status('off', 'Belum terdeteksi', 'Belum ada cek dari UptimeRobot dalam 24 jam'),
            ! $last['ok'] => $this->status('error', 'Cek terakhir gagal', $this->ago($last['at'])),
            Carbon::parse($last['at'])->lt(now()->subMinutes(15)) => $this->status('warn', 'Cek terhenti', 'Terakhir '.$this->ago($last['at'])),
            default => $this->status('ok', 'Memantau', 'Cek terakhir '.$this->ago($last['at'])),
        };
    }

    /**
     * Status straight from the UptimeRobot API: up/down, uptime ratios, response time and the last outage.
     *
     * @return array{state: string, label: string, detail: string|null}
     */
    private function uptimeRobotStatus(): array
    {
        $result = $this->uptimeRobot->monitors();
        $monitor = $this->uptimeRobot->healthMonitor();

        if (! ($result['ok'] ?? false)) {
            return $this->status('warn', 'API gagal', $result['error'] ?? null);
        }

        if ($monitor === null) {
            return $this->status('off', 'Belum ada monitor', 'Buat monitor HTTP(s) ke '.url('/health'));
        }

        $percent = fn (?float $value): string => $value === null ? '–' : rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',').'%';
        $detail = "Uptime 24 jam {$percent($monitor['uptime']['day'])} · 7 hari {$percent($monitor['uptime']['week'])} · 30 hari {$percent($monitor['uptime']['month'])}";

        if ($monitor['response_ms'] !== null) {
            $detail .= " · respons {$monitor['response_ms']} ms";
        }

        if ($monitor['last_down'] !== null) {
            $detail .= ' · down terakhir '.$this->ago($monitor['last_down']['at'])
                .' ('.CarbonInterval::seconds($monitor['last_down']['duration_seconds'])->cascade()->locale('id')->forHumans(['parts' => 2]).')';
        }

        return match ($monitor['status']) {
            'up' => $this->status('ok', 'Up', $detail),
            'down' => $this->status('error', 'Down', $detail),
            'paused' => $this->status('warn', 'Dijeda', $detail),
            default => $this->status('info', 'Menunggu cek pertama', $detail),
        };
    }

    /**
     * @return array{state: string, label: string, detail: string|null}
     */
    private function heartbeatStatus(): array
    {
        if (blank(config('market.monitoring.heartbeat_url'))) {
            return $this->status('off', 'Belum diatur');
        }

        $last = Cache::get(HeartbeatCommand::LAST_PING);

        return match (true) {
            ! is_array($last) => $this->status('warn', 'Menunggu ping pertama', 'Dikirim tiap 5 menit'),
            ! $last['sent'] => $this->status('error', 'Ping tidak terkirim', $last['detail']),
            ! $last['healthy'] => $this->status('error', 'Melapor gagal', $last['detail']),
            default => $this->status('ok', 'Aktif', 'Ping terakhir '.$this->ago($last['at'])),
        };
    }

    /**
     * @return array{state: string, label: string, detail: string|null}
     */
    private function aiStatus(string $provider): array
    {
        $status = collect($this->ai->status()['providers'])->firstWhere('provider', $provider);

        if ($status === null) {
            return filled(config("market.ai.{$provider}.key"))
                ? $this->status('info', 'Key tersimpan, tidak dipakai', 'Pilih sebagai provider utama/cadangan')
                : $this->status('off', 'Belum diatur');
        }

        $role = $status['role'] === 'primary' ? 'Utama' : 'Cadangan';

        return $status['paused_until'] !== null
            ? $this->status('warn', "{$role} · dijeda", $status['reason'])
            : $this->status('ok', $role, "{$status['model']} · {$status['requests_today']}/{$status['max_per_day']} request hari ini");
    }
}
