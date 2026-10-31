<?php

namespace App\Services\Admin;

/**
 * Settings that admins may change from the web panel. Each key is a config path; the stored
 * value overrides the value from config files / .env at runtime.
 *
 * Anything that could lock admins out (APP_URL, Sanctum domains, database credentials)
 * deliberately stays in .env only.
 */
class SettingsRegistry
{
    /**
     * @return list<array{key: string, label: string, description: string, fields: list<array<string, mixed>>}>
     */
    public static function groups(): array
    {
        return [
            [
                'key' => 'ai',
                'label' => 'AI (sentimen berita & ringkasan analisis)',
                'description' => 'Tanpa API key, sentimen memakai kamus kata dan ringkasan memakai template.',
                'fields' => [
                    ['key' => 'market.ai.provider', 'label' => 'Provider utama', 'type' => 'select', 'options' => self::aiProviders()],
                    ['key' => 'market.ai.fallback_provider', 'label' => 'Provider cadangan', 'type' => 'select', 'options' => self::aiProviders(), 'description' => 'Dipakai otomatis saat provider utama terkena limit, kuota habis, atau error.'],
                    ['key' => 'market.ai.gemini.key', 'label' => 'Gemini API key', 'type' => 'secret', 'description' => 'Buat gratis di aistudio.google.com/apikey'],
                    ['key' => 'market.ai.gemini.model', 'label' => 'Model Gemini', 'type' => 'string', 'description' => 'Disarankan gemini-flash-lite-latest (kuota gratis paling longgar, tidak pensiun).'],
                    ['key' => 'market.ai.gemini.max_per_minute', 'label' => 'Gemini: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000, 'description' => 'Sesuaikan dengan batas free tier model (lihat AI Studio → Rate limits).'],
                    ['key' => 'market.ai.gemini.max_per_day', 'label' => 'Gemini: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000],
                    ['key' => 'market.ai.xai.key', 'label' => 'xAI API key', 'type' => 'secret', 'description' => 'Dari console.x.ai (berbayar per token, isi kredit dulu).'],
                    ['key' => 'market.ai.xai.model', 'label' => 'Model xAI', 'type' => 'string', 'description' => 'Disarankan grok-4.20-0309-non-reasoning (murah, tanpa token reasoning).'],
                    ['key' => 'market.ai.xai.max_per_minute', 'label' => 'xAI: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000],
                    ['key' => 'market.ai.xai.max_per_day', 'label' => 'xAI: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000, 'description' => 'Membatasi biaya: ±1.800 token per request ≈ $0,003.'],
                    ['key' => 'market.ai.openai.key', 'label' => 'OpenAI API key', 'type' => 'secret'],
                    ['key' => 'market.ai.openai.model', 'label' => 'Model OpenAI', 'type' => 'string'],
                    ['key' => 'market.ai.openai.max_per_minute', 'label' => 'OpenAI: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000],
                    ['key' => 'market.ai.openai.max_per_day', 'label' => 'OpenAI: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000],
                    ['key' => 'market.ai.summary_ttl_minutes', 'label' => 'Umur ringkasan AI (menit)', 'type' => 'int', 'min' => 5, 'max' => 1440, 'description' => 'Ringkasan dibuat ulang jika rekomendasi berubah atau lebih tua dari ini.'],
                ],
            ],
            [
                'key' => 'telegram',
                'label' => 'Bot Telegram',
                'description' => 'Buat bot di @BotFather, tempel token-nya, aktifkan, lalu Simpan — webhook dipasang otomatis. Setiap pengguna menghubungkan Telegram-nya sendiri dan memilih notifikasinya di Profil.',
                'fields' => [
                    ['key' => 'market.telegram.enabled', 'label' => 'Aktifkan notifikasi Telegram', 'type' => 'bool', 'description' => 'Saklar utama untuk semua pengguna.'],
                    ['key' => 'market.telegram.bot_token', 'label' => 'Bot token', 'type' => 'secret', 'description' => 'Dari @BotFather, bentuknya 123456789:AA…'],
                ],
            ],
            [
                'key' => 'mail',
                'label' => 'Email (SMTP)',
                'description' => 'Untuk password sementara pengguna baru, lupa password, reset 2FA, dan notifikasi keamanan akun. Disarankan Resend (gratis 3.000/bulan): host smtp.resend.com, port 587, username resend, password = API key Resend. Alamat pengirim harus dari domain yang sudah diverifikasi di Resend.',
                'fields' => [
                    ['key' => 'mail.default', 'label' => 'Pengiriman email', 'type' => 'select', 'options' => ['log' => 'Nonaktif (hanya dicatat di log)', 'smtp' => 'SMTP']],
                    ['key' => 'mail.mailers.smtp.host', 'label' => 'SMTP host', 'type' => 'string'],
                    ['key' => 'mail.mailers.smtp.port', 'label' => 'SMTP port', 'type' => 'int', 'min' => 1, 'max' => 65535, 'description' => '587 (STARTTLS) atau 465 (TLS).'],
                    ['key' => 'mail.mailers.smtp.username', 'label' => 'SMTP username', 'type' => 'string'],
                    ['key' => 'mail.mailers.smtp.password', 'label' => 'SMTP password / API key', 'type' => 'secret'],
                    ['key' => 'mail.from.address', 'label' => 'Alamat pengirim', 'type' => 'string', 'rules' => ['email'], 'description' => 'Mis. noreply@tune-in.trade'],
                    ['key' => 'mail.from.name', 'label' => 'Nama pengirim', 'type' => 'string'],
                ],
            ],
            [
                'key' => 'monitoring',
                'label' => 'Monitoring eksternal',
                'description' => 'UptimeRobot memantau https://dexsim.tune-in.trade/health dari luar dan mengirim peringatan ke Telegram; read-only API key-nya hanya untuk menampilkan status di General Information. Healthchecks.io menerima heartbeat dari server tiap 5 menit; jika heartbeat berhenti atau melapor gagal, Anda mendapat peringatan di Telegram.',
                'fields' => [
                    ['key' => 'market.monitoring.uptimerobot_key', 'label' => 'UptimeRobot read-only API key', 'type' => 'secret', 'description' => 'Dari UptimeRobot → Integrations & API → API → Read-only API key. Menampilkan status, uptime 24 jam/7 hari/30 hari, dan downtime terakhir di General Information.'],
                    ['key' => 'market.monitoring.heartbeat_url', 'label' => 'URL ping Healthchecks.io', 'type' => 'secret', 'rules' => ['url', 'starts_with:https://'], 'description' => 'Bentuknya https://hc-ping.com/<uuid>. Atur di Healthchecks: Period 5 menit, Grace 10 menit.'],
                ],
            ],
            [
                'key' => 'containers',
                'label' => 'Pemantauan container',
                'description' => 'Status, CPU, dan memori setiap container tampil di Admin → Container. Riwayat disimpan 7 hari dan tidak ikut backup.',
                'fields' => [
                    ['key' => 'market.monitoring.container_interval_seconds', 'label' => 'Interval pengambilan data', 'type' => 'select', 'options' => self::containerIntervals(), 'description' => 'Interval lebih pendek menambah data: 10 detik sekitar 78 ribu baris per hari, 15 detik 52 ribu, 60 detik 13 ribu. Di atas 60 detik, container yang mati sebentar lalu restart mudah terlewat.'],
                ],
            ],
            [
                'key' => 'feed',
                'label' => 'Running text & notifikasi',
                'description' => 'Setiap pengguna bisa mematikan running text dan tiap jenis notifikasi dari ikon lonceng di toolbar.',
                'fields' => [
                    ['key' => 'market.feed.whale_min_usd', 'label' => 'Notifikasi whale minimal (USD)', 'type' => 'int', 'min' => 10000, 'max' => 10000000, 'description' => 'Per transaksi. Pada 31 Okt 2026, $100.000 berarti sekitar 7 transaksi per jam; yang datang bersamaan digabung per token.'],
                ],
            ],
            [
                'key' => 'data',
                'label' => 'Sumber data',
                'description' => 'Batas request dan ambang data pasar.',
                'fields' => [
                    ['key' => 'market.discovery.coingecko_key', 'label' => 'CoinGecko Demo API key', 'type' => 'secret', 'description' => 'Opsional; lebih stabil dari endpoint publik.'],
                    ['key' => 'market.geckoterminal_requests_per_minute', 'label' => 'GeckoTerminal request/menit', 'type' => 'int', 'min' => 1, 'max' => 500, 'description' => 'Gratis ±10/menit per IP. Naikkan hanya jika memakai paket berbayar.'],
                    ['key' => 'market.whale.min_usd', 'label' => 'Ambang whale maksimum (USD)', 'type' => 'float', 'min' => 100, 'max' => 10000000],
                    ['key' => 'market.whale.floor_usd', 'label' => 'Ambang whale minimum (USD)', 'type' => 'float', 'min' => 10, 'max' => 1000000],
                ],
            ],
            [
                'key' => 'simulator',
                'label' => 'Simulator',
                'description' => 'Saldo awal berlaku untuk user baru dan reset portofolio.',
                'fields' => [
                    ['key' => 'market.simulator.starting_balance', 'label' => 'Saldo awal (USD)', 'type' => 'float', 'min' => 10, 'max' => 100000000],
                    ['key' => 'market.simulator.fee_rate', 'label' => 'Fee swap (desimal, 0.003 = 0,3%)', 'type' => 'float', 'min' => 0, 'max' => 0.05, 'step' => 0.0005],
                    ['key' => 'market.simulator.max_tokens', 'label' => 'Kapasitas Market (token)', 'type' => 'int', 'min' => 1, 'max' => 100, 'description' => 'Dibatasi rate limit GeckoTerminal; ±20 untuk paket gratis.'],
                ],
            ],
            [
                'key' => 'autofollow',
                'label' => 'Auto-follow (pengukur kinerja)',
                'description' => 'Akun sistem "Auto-follow" membeli sinyal baru sesuai batas posisi dan risiko di bawah, memakai aturan sinyal (limit di zona entry, SL, TP1–TP3). Hasilnya mengukur win rate aplikasi tanpa pilih-pilih.',
                'fields' => [
                    ['key' => 'market.autofollow.enabled', 'label' => 'Ikuti semua sinyal otomatis', 'type' => 'bool'],
                    ['key' => 'market.autofollow.max_open_positions', 'label' => 'Maksimal posisi terbuka', 'type' => 'int', 'min' => 0, 'max' => 100, 'description' => 'Termasuk order beli yang menunggu entry. 0 = tanpa batas. Sinyal tetap dicatat walau tidak dibeli.'],
                    ['key' => 'market.autofollow.max_daily_loss_pct', 'label' => 'Batas rugi harian (% ekuitas)', 'type' => 'float', 'min' => 0, 'max' => 50, 'step' => 0.5, 'description' => 'Rugi terealisasi per hari WIB. Setelah tercapai, tidak ada pembelian baru sampai besok; posisi terbuka tetap berjalan dengan TP/SL-nya. 0 = tanpa batas.'],
                    ['key' => 'market.autofollow.risk_pct', 'label' => 'Risiko per sinyal (% ekuitas)', 'type' => 'float', 'min' => 0, 'max' => 10, 'step' => 0.25, 'description' => 'Ukuran posisi dihitung supaya rugi sebesar ini saat kena stop. Dibatasi 25% ekuitas dan 0,25% likuiditas pool. 0 = pakai nominal tetap.'],
                    ['key' => 'market.autofollow.amount_usd', 'label' => 'Nominal tetap (USD), saat risiko 0', 'type' => 'float', 'min' => 1, 'max' => 100000, 'description' => 'Saldo awal akun mengikuti "Saldo awal" simulator.'],
                ],
            ],
            [
                'key' => 'discovery',
                'label' => 'Discovery',
                'description' => 'Penemuan token otomatis dan syarat masuk Market.',
                'fields' => [
                    ['key' => 'market.discovery.auto_track', 'label' => 'Otomatis masuk Market', 'type' => 'bool'],
                    ['key' => 'market.discovery.auto_track_min_score', 'label' => 'Skor minimum masuk Market', 'type' => 'int', 'min' => 0, 'max' => 100],
                    ['key' => 'market.discovery.max_new_per_run', 'label' => 'Token baru maksimum per putaran', 'type' => 'int', 'min' => 0, 'max' => 20],
                    ['key' => 'market.discovery.min_liquidity_usd', 'label' => 'Likuiditas minimum (USD)', 'type' => 'float', 'min' => 0, 'max' => 100000000],
                    ['key' => 'market.discovery.min_volume_h24_usd', 'label' => 'Volume 24 jam minimum (USD)', 'type' => 'float', 'min' => 0, 'max' => 1000000000],
                    ['key' => 'market.discovery.min_age_hours', 'label' => 'Umur pair minimum (jam)', 'type' => 'int', 'min' => 0, 'max' => 8760],
                ],
            ],
            [
                'key' => 'watchlist',
                'label' => 'Market otomatis (watchlist)',
                'description' => 'Kapan token yang tidak dikunci dikeluarkan atau digantikan.',
                'fields' => [
                    ['key' => 'market.watchlist.min_decline_points', 'label' => 'Penurunan skor minimum (poin/7 hari)', 'type' => 'float', 'min' => 1, 'max' => 100],
                    ['key' => 'market.watchlist.keep_if_score_at_least', 'label' => 'Tetap simpan jika skor ≥', 'type' => 'int', 'min' => 0, 'max' => 100],
                    ['key' => 'market.watchlist.replace_below_score', 'label' => 'Boleh digantikan jika skor <', 'type' => 'int', 'min' => 0, 'max' => 100],
                    ['key' => 'market.watchlist.re_add_cooldown_days', 'label' => 'Jeda masuk ulang (hari)', 'type' => 'int', 'min' => 0, 'max' => 60],
                ],
            ],
            [
                'key' => 'analysis',
                'label' => 'Bobot & ambang analisis',
                'description' => 'Bobot harus berjumlah 1,00. Perubahan berlaku pada analisis berikutnya (tiap 15 menit).',
                'fields' => [
                    ['key' => 'market.analysis.weights.technical', 'label' => 'Bobot teknikal', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.05],
                    ['key' => 'market.analysis.weights.onchain', 'label' => 'Bobot on-chain', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.05],
                    ['key' => 'market.analysis.weights.whale', 'label' => 'Bobot whale', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.05],
                    ['key' => 'market.analysis.weights.sentiment', 'label' => 'Bobot sentimen', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.05],
                    ['key' => 'market.analysis.weights.fundamental', 'label' => 'Bobot fundamental', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.05],
                    ['key' => 'market.analysis.thresholds.STRONG_BUY', 'label' => 'Skor Strong Buy ≥', 'type' => 'int', 'min' => 0, 'max' => 100],
                    ['key' => 'market.analysis.thresholds.BUY', 'label' => 'Skor Buy ≥', 'type' => 'int', 'min' => 0, 'max' => 100],
                    ['key' => 'market.analysis.min_stop_pct', 'label' => 'Jarak stop minimum untuk sinyal (%)', 'type' => 'float', 'min' => 0, 'max' => 50, 'step' => 0.5, 'description' => 'Stop lebih sempit dari ini tidak menjadi sinyal, karena fee dan slippage memakan hasilnya. 0 = nonaktif.'],
                    ['key' => 'market.analysis.min_onchain_score', 'label' => 'Skor keamanan minimum untuk Buy', 'type' => 'int', 'min' => 0, 'max' => 100],
                ],
            ],
        ];
    }

    /**
     * Ollama runs on the developer's machine, so it is only offered in local development.
     *
     * @return array<string, string>
     */
    private static function aiProviders(): array
    {
        return [
            'none' => 'Nonaktif',
            'gemini' => 'Google Gemini',
            'xai' => 'xAI Grok',
            'openai' => 'OpenAI',
            ...(config('market.ai.ollama.enabled') ? ['ollama' => 'Ollama (lokal)'] : []),
        ];
    }

    /**
     * Every 5 seconds from 10 to 60.
     *
     * @return array<string, string>
     */
    private static function containerIntervals(): array
    {
        return collect(range(10, 60, 5))
            ->mapWithKeys(fn (int $seconds): array => [(string) $seconds => $seconds === 15 ? '15 detik (disarankan)' : "{$seconds} detik"])
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        $fields = [];

        foreach (self::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $fields[$field['key']] = $field + ['group' => $group['key']];
            }
        }

        return $fields;
    }

    /**
     * Validation rules for one field.
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    public static function rules(array $field): array
    {
        $rules = match ($field['type']) {
            'int' => ['nullable', 'integer', 'min:'.$field['min'], 'max:'.$field['max']],
            'float' => ['nullable', 'numeric', 'min:'.$field['min'], 'max:'.$field['max']],
            'bool' => ['nullable', 'boolean'],
            'select' => ['nullable', 'in:'.implode(',', array_keys($field['options']))],
            'secret' => ['nullable', 'string', 'max:500'],
            default => ['nullable', 'string', 'max:200'],
        };

        return [...$rules, ...($field['rules'] ?? [])];
    }

    public static function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }
}
