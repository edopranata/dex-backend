<?php

namespace App\Services\Admin;

use App\Services\Ai\AiClient;

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
                'sections' => self::aiSections(),
                'fields' => self::withAiSections([
                    ['key' => 'market.ai.provider', 'label' => 'Provider utama', 'type' => 'select', 'options' => self::aiProviders()],
                    ['key' => 'market.ai.fallback_provider', 'label' => 'Provider cadangan 1', 'type' => 'select', 'options' => self::aiProviders(), 'description' => 'Dipakai otomatis saat provider utama terkena limit, kuota habis, atau error.'],
                    ['key' => 'market.ai.fallback_provider_2', 'label' => 'Provider cadangan 2', 'type' => 'select', 'options' => self::aiProviders(), 'description' => 'Dipakai bila provider utama dan cadangan sama-sama dijeda atau batas hariannya habis.'],
                    ['key' => 'market.ai.fallback_provider_3', 'label' => 'Provider cadangan 3', 'type' => 'select', 'options' => self::aiProviders(), 'description' => 'Dipakai bila tiga provider sebelumnya dijeda atau batas hariannya habis.'],
                    ['key' => 'market.ai.gemini.key', 'label' => 'Gemini API key', 'type' => 'secret', 'description' => 'Buat gratis di aistudio.google.com/apikey'],
                    ['key' => 'market.ai.gemini.model', 'label' => 'Model Gemini', 'type' => 'string', 'description' => 'Disarankan gemini-flash-lite-latest (kuota gratis paling longgar, tidak pensiun).'],
                    ['key' => 'market.ai.gemini.max_per_minute', 'label' => 'Gemini: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000, 'description' => 'Sesuaikan dengan batas free tier model (lihat AI Studio → Rate limits). 11 Nov: Flash Lite 15 per menit.'],
                    ['key' => 'market.ai.gemini.max_per_day', 'label' => 'Gemini: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000, 'description' => '11 Nov: Flash Lite 500 per hari, dihitung ulang pukul 15.00 WIB (waktu Pasifik), dan aplikasi menghitung hari yang sama.'],
                    ['key' => 'market.ai.gemini_2.model', 'label' => 'Gemini model kedua', 'type' => 'string', 'description' => 'Disarankan gemma-4-26b-a4b-it (11 Nov: 14.400 request/hari gratis; gemma-4-31b-it sering sibuk).'],
                    ['key' => 'market.ai.gemini_2.max_per_minute', 'label' => 'Gemini model kedua: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000, 'description' => 'Gemma 4 dibatasi 16K token per menit, kira-kira 8 request.'],
                    ['key' => 'market.ai.gemini_2.max_per_day', 'label' => 'Gemini model kedua: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000, 'description' => 'Dihitung ulang pukul 15.00 WIB, sama seperti Gemini.'],
                    ['key' => 'market.ai.groq.key', 'label' => 'Groq API key', 'type' => 'secret', 'description' => 'Dari console.groq.com/keys.'],
                    ['key' => 'market.ai.groq.model', 'label' => 'Model Groq', 'type' => 'string', 'description' => 'Disarankan openai/gpt-oss-120b (uji 11 Nov: paling dekat ke skor Gemini). Daftar model: console.groq.com/docs/models.'],
                    ['key' => 'market.ai.groq.max_per_minute', 'label' => 'Groq: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000, 'description' => 'Batas Groq dihitung juga per token, jadi jangan dinaikkan tanpa melihat halaman Limits di akun Groq.'],
                    ['key' => 'market.ai.groq.max_per_day', 'label' => 'Groq: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000, 'description' => '11 Nov: 200K token per hari per model; satu request sentimen ±1.650 token, ringkasan ±700.'],
                    ['key' => 'market.ai.groq_2.model', 'label' => 'Groq model kedua', 'type' => 'string', 'description' => 'Disarankan openai/gpt-oss-20b, atau qwen/qwen3.8-27b (uji 11 Nov: tercepat, tanpa token berpikir). Harus berbeda dari model Groq pertama.'],
                    ['key' => 'market.ai.groq_2.max_per_minute', 'label' => 'Groq model kedua: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000, 'description' => '8K token per menit, kira-kira 4 request.'],
                    ['key' => 'market.ai.groq_2.max_per_day', 'label' => 'Groq model kedua: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000, 'description' => 'Kuota model ini terpisah dari model Groq pertama.'],
                    ['key' => 'market.ai.openrouter.key', 'label' => 'OpenRouter API key', 'type' => 'secret', 'description' => 'Dari openrouter.ai/settings/keys. Model gratis tidak memakai kredit.'],
                    ['key' => 'market.ai.openrouter.model', 'label' => 'Model OpenRouter', 'type' => 'string', 'description' => 'Disarankan openrouter/free: OpenRouter memilih model gratis yang sedang tersedia. Hindari model gratis Google (Gemma), karena ikut sibuk saat Gemini sibuk.'],
                    ['key' => 'market.ai.openrouter.max_per_minute', 'label' => 'OpenRouter: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000, 'description' => 'Batas OpenRouter untuk model gratis 20 per menit.'],
                    ['key' => 'market.ai.openrouter.max_per_day', 'label' => 'OpenRouter: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000, 'description' => 'Batas OpenRouter untuk model gratis 50 per hari (1.000 bila pernah membeli kredit minimal $10), dihitung ulang pukul 07.00 WIB.'],
                    ['key' => 'market.ai.xai.key', 'label' => 'xAI API key', 'type' => 'secret', 'description' => 'Dari console.x.ai (berbayar per token, isi kredit dulu).'],
                    ['key' => 'market.ai.xai.model', 'label' => 'Model xAI', 'type' => 'string', 'description' => 'Disarankan grok-4.20-0309-non-reasoning (murah, tanpa token reasoning).'],
                    ['key' => 'market.ai.xai.max_per_minute', 'label' => 'xAI: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000],
                    ['key' => 'market.ai.xai.max_per_day', 'label' => 'xAI: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000, 'description' => 'Membatasi biaya: ±1.800 token per request ≈ $0,003.'],
                    ['key' => 'market.ai.mistral.key', 'label' => 'Mistral API key', 'type' => 'secret', 'description' => 'Dari console.mistral.ai/api-keys. Paket gratis Experiment cukup; request di paket itu boleh dipakai Mistral untuk melatih model (bisa dimatikan di pengaturan akun).'],
                    ['key' => 'market.ai.mistral.model', 'label' => 'Model Mistral', 'type' => 'string', 'description' => 'Disarankan mistral-small-latest (mendukung mode JSON).'],
                    ['key' => 'market.ai.mistral.max_per_minute', 'label' => 'Mistral: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000, 'description' => 'Paket gratis sekitar 1 request per detik; lihat Limits di console Mistral.'],
                    ['key' => 'market.ai.mistral.max_per_day', 'label' => 'Mistral: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000],
                    ['key' => 'market.ai.openai.key', 'label' => 'OpenAI API key', 'type' => 'secret'],
                    ['key' => 'market.ai.openai.model', 'label' => 'Model OpenAI', 'type' => 'string'],
                    ['key' => 'market.ai.openai.max_per_minute', 'label' => 'OpenAI: maks. request per menit', 'type' => 'int', 'min' => 1, 'max' => 1000],
                    ['key' => 'market.ai.openai.max_per_day', 'label' => 'OpenAI: maks. request per hari', 'type' => 'int', 'min' => 1, 'max' => 100000],
                    ['key' => 'market.ai.sentiment_reserve_percent', 'label' => 'Cadangan kuota untuk sentimen (%)', 'type' => 'int', 'min' => 0, 'max' => 90, 'description' => 'Bagian batas harian tiap provider yang hanya dipakai sentimen berita, karena sentimen ikut menentukan skor. Ringkasan berhenti memakai provider itu begitu sisanya tinggal bagian ini.'],
                    ['key' => 'market.ai.summary_ai_bullish_only', 'label' => 'Ringkasan AI hanya untuk Buy dan Strong Buy', 'type' => 'bool', 'description' => 'Rekomendasi lain memakai ringkasan template, supaya kuota AI tidak habis untuk token yang tidak dibeli.'],
                    ['key' => 'market.ai.summary_ttl_minutes', 'label' => 'Umur ringkasan AI (menit)', 'type' => 'int', 'min' => 5, 'max' => 1440, 'description' => 'Ringkasan dibuat ulang jika rekomendasi berubah atau lebih tua dari ini.'],
                ]),
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
                    ['key' => 'market.birdeye.key', 'label' => 'Birdeye API key', 'type' => 'secret', 'description' => 'Opsional, untuk mencatat daftar trader paling untung sekali sehari (Fase R). Paket Standard gratis di bds.birdeye.so. Datanya hanya untuk pemilik.'],
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
                    ['key' => 'market.simulator.close_on_sell', 'label' => 'Jual otomatis saat analisa menjadi Sell', 'type' => 'bool', 'description' => 'Posisi semua akun, termasuk auto-follow, dijual di harga pasar begitu analisa token menjadi Sell, Strong Sell, atau Hindari, dan order yang menunggu dibatalkan. Pemilik posisi mendapat pesan Telegram. Hasil sinyal tidak berubah.'],
                ],
            ],
            [
                'key' => 'autofollow',
                'label' => 'Auto-follow (pengukur kinerja)',
                'description' => 'Akun sistem "Auto-follow" membeli setiap sinyal saat harganya masuk zona entry, sesuai batas posisi dan risiko di bawah, lalu memasang SL dan TP1–TP3 sinyal. Hasilnya mengukur kinerja sinyal sebagai portofolio, lengkap dengan biaya, tanpa pilih-pilih.',
                'fields' => [
                    ['key' => 'market.autofollow.enabled', 'label' => 'Ikuti semua sinyal otomatis', 'type' => 'bool'],
                    ['key' => 'market.autofollow.max_open_positions', 'label' => 'Maksimal posisi terbuka', 'type' => 'int', 'min' => 0, 'max' => 100, 'description' => 'Termasuk order beli yang belum terisi. 0 = tanpa batas. Sinyal tetap dicatat walau tidak dibeli.'],
                    ['key' => 'market.autofollow.max_daily_loss_pct', 'label' => 'Batas rugi harian (% ekuitas)', 'type' => 'float', 'min' => 0, 'max' => 50, 'step' => 0.5, 'description' => 'Rugi terealisasi per hari WIB. Setelah tercapai, tidak ada pembelian baru sampai besok; posisi terbuka tetap berjalan dengan TP/SL-nya. 0 = tanpa batas.'],
                    ['key' => 'market.autofollow.risk_pct', 'label' => 'Risiko per sinyal (% ekuitas)', 'type' => 'float', 'min' => 0, 'max' => 10, 'step' => 0.25, 'description' => 'Ukuran posisi dihitung supaya rugi sebesar ini saat kena stop. Dibatasi 25% ekuitas dan 0,25% likuiditas pool. 0 = pakai nominal tetap.'],
                    ['key' => 'market.autofollow.amount_usd', 'label' => 'Nominal tetap (USD), saat risiko 0', 'type' => 'float', 'min' => 1, 'max' => 100000, 'description' => 'Besar tiap pembelian dalam dolar, sama untuk setiap sinyal, dipakai hanya bila risiko per sinyal 0. Juga ukuran acuan biaya (fee dan price impact) pada R bersih sinyal, jadi mengubahnya menggeser R sinyal berikutnya. Saldo awal akun mengikuti "Saldo awal" simulator.'],
                    ['key' => 'market.autofollow.gas_usd.solana', 'label' => 'Gas per swap Solana (USD)', 'type' => 'float', 'min' => 0, 'max' => 100, 'step' => 0.01, 'description' => 'Hanya untuk akun auto-follow per jaringan, di atas fee 0,3%. Solana biasanya di bawah 1 sen.'],
                    ['key' => 'market.autofollow.gas_usd.bsc', 'label' => 'Gas per swap BNB Chain (USD)', 'type' => 'float', 'min' => 0, 'max' => 100, 'step' => 0.01, 'description' => 'Swap pada 8 Nov 2026 sekitar $0,007 (bscscan.com/gastracker).'],
                    ['key' => 'market.autofollow.gas_usd.ethereum', 'label' => 'Gas per swap Ethereum (USD)', 'type' => 'float', 'min' => 0, 'max' => 100, 'step' => 0.01, 'description' => 'Swap pada 8 Nov 2026 sekitar $0,04 (etherscan.io/gastracker), dibuat $0,25 karena bisa melonjak saat jaringan ramai.'],
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
                    ['key' => 'market.analysis.buy_max_rise_24h_pct', 'label' => 'Tahan Buy bila naik dalam 24 jam lebih dari (%)', 'type' => 'float', 'min' => 0, 'max' => 1000, 'step' => 1, 'description' => 'Aturan v4; Strong Buy tidak terpengaruh. 0 = nonaktif. Mengubahnya membuat versi strategi baru.'],
                    ['key' => 'market.analysis.max_top10_holder_pct', 'label' => 'Tahan beli bila 10 holder teratas memegang minimal (% supply)', 'type' => 'float', 'min' => 0, 'max' => 100, 'step' => 1, 'description' => 'Aturan v6, berlaku untuk Buy dan Strong Buy. Tanpa data holder aturan ini dilewati. 0 = nonaktif. Mengubahnya membuat versi strategi baru.'],
                    ['key' => 'market.analysis.buy_min_below_high_24h_pct', 'label' => 'Tahan Buy bila entry kurang dari (%) di bawah puncak 24 jam', 'type' => 'float', 'min' => 0, 'max' => 100, 'step' => 0.5, 'description' => 'Aturan v4; Strong Buy tidak terpengaruh. 0 = nonaktif. Mengubahnya membuat versi strategi baru.'],
                ],
            ],
        ];
    }

    /**
     * Sub-sections of the AI card: the provider chain, how the quota is spent, then one per provider.
     *
     * @return list<array{key: string, label: string, provider?: string, description?: string}>
     */
    private static function aiSections(): array
    {
        $providers = array_map(
            fn (string $provider): array => ['key' => $provider, 'label' => AiClient::PROVIDERS[$provider], 'provider' => $provider],
            ['gemini', 'gemini_2', 'groq', 'groq_2', 'openrouter', 'mistral', 'xai', 'openai'],
        );
        $providers[1]['description'] = 'Memakai Gemini API key, dengan kuota model ini sendiri.';
        $providers[3]['description'] = 'Memakai Groq API key, dengan kuota model ini sendiri.';

        return [
            ['key' => 'chain', 'label' => 'Urutan provider', 'description' => 'Dicoba berurutan; provider berikutnya dipakai saat yang sebelumnya dijeda atau batas hariannya habis.'],
            ['key' => 'usage', 'label' => 'Pemakaian kuota & ringkasan'],
            ...$providers,
        ];
    }

    /**
     * Tags every AI field with its sub-section, and gives provider fields a short label for use under the
     * provider's own heading (the full label stays for messages such as "reset ke .env").
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private static function withAiSections(array $fields): array
    {
        return array_map(function (array $field): array {
            if (preg_match('/^market\.ai\.(provider|fallback_provider(_\d)?)$/', $field['key']) === 1) {
                return [...$field, 'section' => 'chain'];
            }

            if (preg_match('/^market\.ai\.([a-z0-9_]+)\.(key|model|max_per_minute|max_per_day)$/', $field['key'], $match) === 1) {
                return [...$field, 'section' => $match[1], 'short_label' => match ($match[2]) {
                    'key' => 'API key',
                    'model' => 'Model',
                    'max_per_minute' => 'Maks. request per menit',
                    'max_per_day' => 'Maks. request per hari',
                }];
            }

            return [...$field, 'section' => 'usage'];
        }, $fields);
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
            'gemini_2' => 'Google Gemini (model kedua)',
            'groq' => 'Groq',
            'groq_2' => 'Groq (model kedua)',
            'openrouter' => 'OpenRouter',
            'xai' => 'xAI Grok',
            'mistral' => 'Mistral',
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
