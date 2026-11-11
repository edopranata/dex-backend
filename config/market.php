<?php

// Opsi per model untuk kedua slot Groq. GPT-OSS berpikir dulu dan token itu ikut dihitung; "low" cukup untuk
// sentimen & ringkasan. Qwen di Groq bisa tanpa berpikir sama sekali (uji 11 Nov: 0 token berpikir, <1 detik).
$groqRequestByModel = [
    'openai/gpt-oss' => ['reasoning_effort' => 'low', 'include_reasoning' => false],
    'qwen/' => ['reasoning_effort' => 'none'],
];

return [

    // Vue app built by `npm run build:laravel` (served for every non-API path).
    'spa_index' => public_path('index.html'),

    /*
    |--------------------------------------------------------------------------
    | Simulator
    |--------------------------------------------------------------------------
    |
    | Uang virtual. Fee meniru fee swap DEX (Uniswap/Raydium ~0.25-0.3%).
    | Slippage dihitung dari likuiditas pool (model constant product).
    |
    */

    'simulator' => [
        'starting_balance' => (float) env('SIM_STARTING_BALANCE', 10000),
        'fee_rate' => (float) env('SIM_FEE_RATE', 0.003),
        'max_tokens' => (int) env('SIM_MAX_TOKENS', 20),
        'min_order_usd' => 1,
        // Harga lebih tua dari ini dianggap basi dan diambil ulang sebelum order dieksekusi.
        'max_price_age_seconds' => 30,
        // Dua pengecekan harga berurutan harus setuju sebelum order terisi; jeda lebih lama berarti menunggu lagi.
        'confirmation_max_gap_seconds' => 120,
        // Saat harga DEX Screener membuat order atau sinyal terisi, harganya dicek ke pool yang sama di
        // GeckoTerminal (sumber grafik). Beda lebih dari tolerance_pct: yang dipakai harga GeckoTerminal.
        // Audit 10 Nov 2026: 4 dari 149 isi jauh di luar candle (STANK -12%, FLOAT +8-10%); feed hanya 4
        // digit sehingga 139 isi wajar juga memakai harga yang sama dua kali, jadi itu tidak bisa dipakai.
        'cross_check' => [
            'enabled' => (bool) env('PRICE_CROSS_CHECK', true),
            'tolerance_pct' => 5.0,
            // Harga pembanding dipakai ulang selama ini, supaya satu token tidak menghabiskan jatah GeckoTerminal.
            'cache_seconds' => 45,
            // Paling lama menunggu giliran request; lewat dari ini order ditahan sampai pengecekan berikutnya.
            'max_wait_seconds' => 8,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Jaringan
    |--------------------------------------------------------------------------
    |
    | Key = chainId versi DEX Screener. Nilai = id jaringan di GeckoTerminal
    | dan GoPlus. GoPlus untuk Solana memakai endpoint terpisah.
    |
    */

    // short: kode jaringan di URL halaman token, misalnya /tokens/JOB-SOL.
    'chains' => [
        'solana' => ['label' => 'Solana', 'short' => 'SOL', 'geckoterminal' => 'solana', 'goplus' => 'solana'],
        'ethereum' => ['label' => 'Ethereum', 'short' => 'ETH', 'geckoterminal' => 'eth', 'goplus' => '1'],
        'base' => ['label' => 'Base', 'short' => 'BASE', 'geckoterminal' => 'base', 'goplus' => '8453'],
        'bsc' => ['label' => 'BNB Chain', 'short' => 'BSC', 'geckoterminal' => 'bsc', 'goplus' => '56'],
        'arbitrum' => ['label' => 'Arbitrum', 'short' => 'ARB', 'geckoterminal' => 'arbitrum', 'goplus' => '42161'],
        'polygon' => ['label' => 'Polygon', 'short' => 'POL', 'geckoterminal' => 'polygon_pos', 'goplus' => '137'],
        'optimism' => ['label' => 'Optimism', 'short' => 'OP', 'geckoterminal' => 'optimism', 'goplus' => '10'],
        'avalanche' => ['label' => 'Avalanche', 'short' => 'AVAX', 'geckoterminal' => 'avax', 'goplus' => '43114'],
    ],

    // API publik GeckoTerminal dibatasi per IP; naikkan jika memakai paket berbayar.
    'geckoterminal_requests_per_minute' => (int) env('GECKOTERMINAL_RPM', 10),

    // Candle: hanya 15m yang diambil rutin; 1h, 4h, dan 1d dirakit dari 15m. Uji 7 Nov pada 5.164 analisa:
    // skor teknikal sama persis dengan candle GeckoTerminal, jadi versi strategi tidak berubah.
    // Cek keamanan dan holder lewat GoPlus. Jeda antar-token supaya tidak ditolak (20 sekaligus: 3 terakhir ditolak).
    'security' => [
        'spacing_seconds' => (int) env('SECURITY_SPACING_SECONDS', 10),
    ],

    'candles' => [
        // Tiap siklus: 96 candle terakhir (24 jam), supaya koreksi data GeckoTerminal ikut terbawa. Jumlah
        // candle tidak menambah permintaan; yang dihitung batas GeckoTerminal adalah permintaannya.
        'sync_limit' => 96,
        // Token baru atau kembali ke Market: 15m paling jauh 7 hari (672 candle, di bawah batas 1.000).
        'backfill_days' => 7,
        // Bila celah lebih panjang dari itu, 1h, 4h, dan 1d diambil langsung sekali.
        'history_limit' => 300,
        'built' => ['1h', '4h', '1d'],
        // 15m menjadi sumber, jadi disimpan lebih lama dari timeframe lain yang dulu diambil sendiri.
        'keep_15m_days' => 90,
        // Token yang sudah keluar Market tetap diambil 15m-nya, bergiliran: sekian token per jam, yang candle-nya
        // paling lama duluan. Tanpa batas jumlah token; data tetap utuh selama giliran tiap token di bawah
        // backfill_days.
        'out_of_market_per_hour' => (int) env('CANDLES_OUT_OF_MARKET_PER_HOUR', 10),
    ],

    // Jobs that call GeckoTerminal run on their own queue (worker "queue-market"), so analysis
    // and Telegram notifications on the default queue never wait behind the API rate limit.
    'market_data_queue' => 'market-data',

    // Pair dengan likuiditas di bawah ini disembunyikan dari hasil pencarian (banyak token spam).
    'min_search_liquidity_usd' => 20000,

    /*
    |--------------------------------------------------------------------------
    | Whale tracker
    |--------------------------------------------------------------------------
    |
    | Ambang whale per token = clamp(likuiditas * ratio, floor, min_usd).
    | $10.000 di pool $50.000 itu sangat besar, jadi ambang menyesuaikan pool.
    |
    */

    'whale' => [
        'min_usd' => (float) env('WHALE_MIN_USD', 10000),
        'floor_usd' => (float) env('WHALE_FLOOR_USD', 1000),
        'liquidity_ratio' => 0.02,
        'lookback_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery: token ditemukan otomatis, bukan diinput user
    |--------------------------------------------------------------------------
    |
    | Sumber kandidat: GeckoTerminal trending pools (per jaringan), CoinGecko
    | trending search, dan DEX Screener boosts (iklan berbayar, bobot kecil).
    | Kandidat yang lolos filter dicek mendalam (GoPlus + transaksi whale),
    | lalu yang terbaik otomatis dipantau dan dianalisis seperti token lain.
    |
    */

    'discovery' => [
        'networks' => ['solana', 'base', 'ethereum', 'bsc'],
        'trending_duration' => '6h',
        'use_coingecko_trending' => true,
        'use_dexscreener_boosts' => true,
        'coingecko_key' => env('COINGECKO_DEMO_API_KEY'),
        'min_liquidity_usd' => 50_000,
        'min_volume_h24_usd' => 100_000,
        'min_age_hours' => 24,
        // Kandidat yang dicek mendalam per putaran (tiap cek whale = 1 request GeckoTerminal).
        'deep_check_limit' => 8,
        'security_recheck_hours' => 6,
        'whale_recheck_minutes' => 60,
        'min_security_score' => 50,
        'weights' => [
            'trend' => 0.30,
            'attention' => 0.15,
            'whale' => 0.35,
            'security' => 0.20,
        ],
        'auto_track' => (bool) env('DISCOVERY_AUTO_TRACK', true),
        'auto_track_min_score' => 65,
        // Token baru maksimal per putaran discovery, supaya Market tidak berganti terlalu cepat.
        'max_new_per_run' => 3,
        // Pair yang dihargai dalam token lain (STANK/STONK, Dacity/TTWO) membawa gerak token itu ke harga USD.
        // Bila token yang sama punya pair dengan quote ini dan likuiditas cukup, pair itu yang dipakai.
        'standard_quotes' => ['SOL', 'WSOL', 'WETH', 'ETH', 'WBNB', 'BNB', 'USDC', 'USDT', 'USD1', 'DAI', 'USDBC'],
        'excluded_symbols' => [
            'USDC', 'USDT', 'DAI', 'USDE', 'FDUSD', 'PYUSD', 'USD1', 'USDS', 'TUSD', 'USDG', 'EURC',
            'WETH', 'ETH', 'WBTC', 'CBBTC', 'BTC', 'SOL', 'WSOL', 'WBNB', 'BNB', 'STETH', 'WSTETH', 'JITOSOL', 'MSOL',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Watchlist otomatis (daftar Market)
    |--------------------------------------------------------------------------
    |
    | Token yang tidak dikunci (pin) dan tidak dipakai (posisi, order pending,
    | sinyal terbuka) dikeluarkan jika skornya menurun selama seminggu. Saat
    | Market penuh (SIM_MAX_TOKENS), kandidat discovery yang kuat menggantikan
    | token terlemah. Token yang ditambahkan manual otomatis dikunci.
    |
    */

    'watchlist' => [
        'decline_window_days' => 7,
        // Butuh minimal sekian hari data skor sebelum menilai tren.
        'min_history_days' => 3,
        // Total penurunan rata-rata skor harian (awal vs akhir periode).
        'min_decline_points' => 8,
        // Porsi hari yang skornya turun dibanding hari sebelumnya.
        'min_down_day_ratio' => 0.6,
        // Token dengan skor terkini di atas ini tidak dikeluarkan walau sedang turun.
        'keep_if_score_at_least' => 60,
        // Saat Market penuh, hanya token dengan skor di bawah ini yang boleh digantikan.
        'replace_below_score' => 50,
        // Token yang baru masuk tidak boleh digantikan selama ini, supaya sempat dianalisis.
        'replace_grace_hours' => 24,
        // Token yang baru dikeluarkan tidak dimasukkan lagi selama ini.
        're_add_cooldown_days' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup database
    |--------------------------------------------------------------------------
    |
    | Backup terjadwal dibuat cron di host (deploy/backup.sh, memakai container
    | MySQL). Folder backup host di-mount ke sini sehingga admin bisa melihat,
    | mengunduh, dan membuat backup manual (dengan jeda) dari aplikasi.
    |
    */

    'backup' => [
        'path' => env('BACKUP_PATH', storage_path('app/backups')),
        'manual_cooldown_minutes' => (int) env('BACKUP_COOLDOWN_MINUTES', 60),
        // Zona waktu nama file (sama dengan cron di host).
        'timezone' => env('BACKUP_TIMEZONE', 'Asia/Jakarta'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Berita (RSS gratis; CryptoPanic sudah tidak punya paket gratis)
    |--------------------------------------------------------------------------
    */

    'news' => [
        'feeds' => [
            'CoinDesk' => 'https://www.coindesk.com/arc/outboundfeeds/rss/',
            'Cointelegraph' => 'https://cointelegraph.com/rss',
            'Decrypt' => 'https://decrypt.co/feed',
            'The Block' => 'https://www.theblock.co/rss.xml',
        ],
        'lookback_hours' => 48,
        'sentiment_batch_size' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI (opsional). Tanpa API key, sentimen memakai kamus kata sederhana.
    |--------------------------------------------------------------------------
    */

    'ai' => [
        // Provider utama dipakai dulu; cadangan dipakai saat utama dijeda (limit, kuota, error) atau anggarannya habis.
        'provider' => env('AI_PROVIDER', 'none'),
        'fallback_provider' => env('AI_FALLBACK_PROVIDER', 'none'),
        // Cadangan kedua: dipakai bila utama dan cadangan pertama sama-sama dijeda atau anggarannya habis.
        'fallback_provider_2' => env('AI_FALLBACK_PROVIDER_2', 'none'),
        // Cadangan ketiga: dipakai bila tiga provider sebelumnya dijeda atau anggarannya habis.
        'fallback_provider_3' => env('AI_FALLBACK_PROVIDER_3', 'none'),
        // Bagian anggaran harian tiap provider yang hanya boleh dipakai sentimen berita, karena sentimen ikut
        // menentukan skor. Ringkasan berhenti memakai provider itu begitu sisanya tinggal bagian ini
        // (11 Nov: ketiga provider habis pukul 16.41 WIB oleh ringkasan, sentimen jatuh ke kamus kata).
        'sentiment_reserve_percent' => (int) env('AI_SENTIMENT_RESERVE_PERCENT', 20),
        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            // Alias "latest" mengikuti versi terbaru, jadi tidak pensiun seperti model bernomor.
            'model' => env('GEMINI_MODEL', 'gemini-flash-lite-latest'),
            // Anggaran agar tidak melewati free tier; request di atasnya pindah ke cadangan atau template/kamus.
            // AI Studio 11 Nov, Gemini 3.5 Flash Lite gratis: 15 request/menit, 500/hari; sisanya ruang aman.
            'max_per_minute' => (int) env('GEMINI_MAX_PER_MINUTE', env('AI_MAX_REQUESTS_PER_MINUTE', 12)),
            'max_per_day' => (int) env('GEMINI_MAX_PER_DAY', env('AI_MAX_REQUESTS_PER_DAY', 450)),
            // Kuota harian Google dihitung ulang tengah malam waktu Pasifik (15.00 WIB, 16.00 WIB saat musim panas AS).
            'daily_reset_timezone' => 'America/Los_Angeles',
        ],
        // Slot Gemini kedua dengan key yang sama: kuota gratis Google dihitung per model, jadi model lain punya
        // kuota sendiri. AI Studio 11 Nov: Gemma 4 26B/31B 30 request/menit, 16K token/menit, 14.400/hari.
        // Uji 11 Nov: 31B menjawab 503 (permintaan tinggi), 26B menjawab dalam 1,5 detik dengan thinking minimal.
        'gemini_2' => [
            'key_from' => 'gemini',
            'model' => env('GEMINI_2_MODEL', 'gemma-4-26b-a4b-it'),
            // 16K token/menit dibagi ±1.800 token per request.
            'max_per_minute' => (int) env('GEMINI_2_MAX_PER_MINUTE', 6),
            'max_per_day' => (int) env('GEMINI_2_MAX_PER_DAY', 1000),
            'daily_reset_timezone' => 'America/Los_Angeles',
            // Tanpa ini Gemma 4 berpikir ±500 token dan 13 detik dulu; thinkingBudget tidak didukung Gemma.
            'request_by_model' => [
                'gemma-4' => ['thinkingConfig' => ['thinkingLevel' => 'minimal']],
            ],
        ],
        // Groq juga menghitung kuota gratis per model (11 Nov: 30 request/menit, 1.000/hari, 8K token/menit,
        // 200K token/hari untuk gpt-oss-120b, gpt-oss-20b, dan qwen3.8-27b). Yang lebih dulu habis adalah token.
        // Uji 11 Nov dengan prompt produksi: sentimen 20 berita ±1.550-1.650 token, ringkasan ±650-750 token.
        'groq' => [
            'key' => env('GROQ_API_KEY'),
            'base_url' => 'https://api.groq.com/openai/v1',
            // Model produksi dengan batas publik; Llama di Groq kini tertulis "contact sales".
            // Uji 11 Nov: 120B lebih dekat ke skor Gemini dan ringkasannya lebih hati-hati daripada 20B.
            'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
            // 8K token/menit muat ±4 request sentimen.
            'max_per_minute' => (int) env('GROQ_MAX_PER_MINUTE', 4),
            // 200K token/hari dibagi ±1.650 token (kasus terburuk: semuanya sentimen).
            'max_per_day' => (int) env('GROQ_MAX_PER_DAY', 120),
            'request_by_model' => $groqRequestByModel,
        ],
        // Slot Groq kedua dengan key yang sama dan kuota model ini sendiri.
        'groq_2' => [
            'key_from' => 'groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => env('GROQ_2_MODEL', 'openai/gpt-oss-20b'),
            'max_per_minute' => (int) env('GROQ_2_MAX_PER_MINUTE', 4),
            'max_per_day' => (int) env('GROQ_2_MAX_PER_DAY', 120),
            'request_by_model' => $groqRequestByModel,
        ],
        'openrouter' => [
            'key' => env('OPENROUTER_API_KEY'),
            'base_url' => 'https://openrouter.ai/api/v1',
            // "openrouter/free" memilih model gratis yang sedang tersedia dan mendukung JSON. Gemma gratis dilayani
            // Google AI Studio, yang ikut sibuk saat Gemini sibuk (HTTP 429 pada uji 8 Nov), jadi tidak dipakai.
            'model' => env('OPENROUTER_MODEL', 'openrouter/free'),
            // Akun tanpa pembelian kredit: 20 request/menit dan 50/hari untuk semua model gratis.
            'max_per_minute' => (int) env('OPENROUTER_MAX_PER_MINUTE', 10),
            'max_per_day' => (int) env('OPENROUTER_MAX_PER_DAY', 45),
            // Batas harian model gratis dihitung ulang pukul 00.00 UTC.
            'daily_reset_timezone' => 'UTC',
            // Hanya diarahkan ke penyedia yang benar-benar mendukung mode JSON.
            'request' => ['provider' => ['require_parameters' => true]],
            // Identitas aplikasi yang disarankan OpenRouter; tanpa data pribadi.
            'headers' => ['HTTP-Referer' => 'https://dexsim.tune-in.trade', 'X-Title' => 'DEX Simulator'],
        ],
        'xai' => [
            'key' => env('XAI_API_KEY'),
            'base_url' => 'https://api.x.ai/v1',
            // Non-reasoning: tanpa token "berpikir" yang ikut ditagih, cukup untuk sentimen & ringkasan.
            'model' => env('XAI_MODEL', 'grok-4.20-0309-non-reasoning'),
            // Berbayar per token: batas harian sekaligus membatasi biaya.
            'max_per_minute' => (int) env('XAI_MAX_PER_MINUTE', 30),
            'max_per_day' => (int) env('XAI_MAX_PER_DAY', 100),
        ],
        // Model lokal lewat Ollama, khusus development: VPS tidak bisa mengakses laptop dan tidak punya GPU.
        'ollama' => [
            'enabled' => env('APP_ENV') === 'local',
            'key' => 'ollama', // Wajib ada untuk klien; Ollama mengabaikannya.
            'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434/v1'),
            'model' => env('OLLAMA_MODEL', 'gemma4:12b'),
            'max_per_minute' => (int) env('OLLAMA_MAX_PER_MINUTE', 30),
            'max_per_day' => (int) env('OLLAMA_MAX_PER_DAY', 5000),
            'timeout' => (int) env('OLLAMA_TIMEOUT', 180),
            // Tanpa mode thinking: jawaban JSON pendek jauh lebih cepat.
            'request' => ['reasoning_effort' => 'none'],
        ],
        'mistral' => [
            'key' => env('MISTRAL_API_KEY'),
            'base_url' => 'https://api.mistral.ai/v1',
            // Alias "latest" mengikuti versi terbaru dan mendukung mode JSON.
            'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
            // Paket gratis Experiment: sekitar 1 request per detik; batas harian ini batas aplikasi sendiri.
            'max_per_minute' => (int) env('MISTRAL_MAX_PER_MINUTE', 20),
            'max_per_day' => (int) env('MISTRAL_MAX_PER_DAY', 500),
        ],
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'base_url' => 'https://api.openai.com/v1',
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'max_per_minute' => (int) env('OPENAI_MAX_PER_MINUTE', 30),
            'max_per_day' => (int) env('OPENAI_MAX_PER_DAY', 250),
        ],
        // Ringkasan AI dibuat ulang jika rekomendasi berubah, ringkasan lebih tua dari ini (dihitung sejak ditulis),
        // atau skor akhir bergeser sebanyak ini atau lebih sejak ringkasan ditulis.
        'summary_ttl_minutes' => 180,
        // Ringkasan AI hanya untuk Buy dan Strong Buy; rekomendasi lain memakai template. HOLD memakan sekitar
        // separuh ringkasan AI (8-11 Nov), padahal tidak ada keputusan beli di sana.
        'summary_ai_bullish_only' => (bool) env('AI_SUMMARY_BULLISH_ONLY', true),
        'summary_refresh_score_change' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analisis & sinyal
    |--------------------------------------------------------------------------
    */

    /*
    | Notifikasi Telegram. Bot diatur admin (Admin → Pengaturan → Telegram); tiap pengguna
    | menghubungkan chat-nya sendiri dengan kode unik di Profil → Telegram.
    */
    // Akun sistem yang otomatis mengikuti SETIAP sinyal baru dengan nominal tetap (limit + SL/TP sinyal),
    // supaya win rate & P&L aplikasi terukur apa adanya, termasuk fee dan slippage.
    'autofollow' => [
        'enabled' => (bool) env('AUTO_FOLLOW_ENABLED', true),
        // Nominal tetap saat risk_pct = 0; juga ukuran acuan biaya pada hasil R bersih sinyal.
        'amount_usd' => (float) env('AUTO_FOLLOW_AMOUNT_USD', 100),
        // Maksimal posisi terbuka + order beli menunggu (0 = tanpa batas).
        // v5 (12 Nov): 4 posisi x risiko 0,5% = total risiko terbuka 2% (sebelumnya 3 x 1%).
        'max_open_positions' => (int) env('AUTO_FOLLOW_MAX_OPEN', 4),
        // Rugi terealisasi per hari (WIB), dalam % ekuitas, sebelum pembelian baru dijeda (0 = tanpa batas).
        'max_daily_loss_pct' => (float) env('AUTO_FOLLOW_MAX_DAILY_LOSS_PCT', 3.0),
        // Rugi saat kena stop, dalam % ekuitas.
        'risk_pct' => (float) env('AUTO_FOLLOW_RISK_PCT', 0.5),
        'max_position_pct' => 25.0,
        // Order dibatasi % likuiditas pool supaya slippage tetap kecil.
        'max_liquidity_pct' => 0.25,
        // Gas per swap (USD) yang dibayar akun auto-follow per jaringan, di atas fee 0,3%. Pada 8 Nov 2026 swap
        // di Ethereum sekitar $0,04 (Etherscan, 0,054 gwei) dan di BNB Chain $0,007 (BscScan); Solana di bawah
        // 1 sen. Ethereum dibuat lebih tinggi karena gas-nya bisa melonjak berkali lipat saat ramai.
        'gas_usd' => [
            'solana' => (float) env('AUTO_FOLLOW_GAS_SOLANA', 0.01),
            'bsc' => (float) env('AUTO_FOLLOW_GAS_BSC', 0.01),
            'ethereum' => (float) env('AUTO_FOLLOW_GAS_ETHEREUM', 0.25),
            'base' => (float) env('AUTO_FOLLOW_GAS_BASE', 0.01),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring eksternal
    |--------------------------------------------------------------------------
    |
    | heartbeat_url: URL ping Healthchecks.io (https://hc-ping.com/<uuid>). Diping
    | app:heartbeat tiap 5 menit; jika ping berhenti (server/scheduler mati) atau
    | melapor /fail (database, Redis, Horizon bermasalah), Healthchecks.io mengirim
    | peringatan ke Telegram. Endpoint /health dipantau dari luar oleh UptimeRobot.
    |
    */

    'monitoring' => [
        'heartbeat_url' => env('HEARTBEAT_URL'),
        // Read-only API key UptimeRobot: status, uptime & downtime monitor tampil di Admin → General Information.
        'uptimerobot_key' => env('UPTIMEROBOT_API_KEY'),
        // Scheduler dianggap mati jika tidak ada tugas terjadwal selesai selama ini.
        'scheduler_stale_minutes' => 10,
        // Pemantauan container dari host (deploy/monitor.sh). String: pilihan dropdown di Admin → Pengaturan.
        'container_interval_seconds' => (string) env('MONITOR_INTERVAL_SECONDS', '15'),
        'container_token' => env('MONITOR_TOKEN'),
        'container_retention_days' => 7,
    ],

    // Running text dan notifikasi melayang: whale di bawah nilai ini tidak dinotifikasi.
    'feed' => [
        'whale_min_usd' => (int) env('FEED_WHALE_MIN_USD', 100000),
    ],

    'telegram' => [
        'enabled' => (bool) env('TELEGRAM_ENABLED', false),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'link_code_ttl_minutes' => 15,
        // Tombol nominal (USD) di bawah pesan sinyal untuk beli langsung dari Telegram.
        'buy_presets' => [25, 50, 100],
        // Bawaan preferensi tiap pengguna (bisa diubah sendiri di Profil → Notifikasi).
        'defaults' => [
            'signals' => true,
            'signal_updates' => true,
            'risk' => true,
            'watchlist' => false,
            'system' => true,
            'daily_summary' => true,
            // BUY = Buy & Strong Buy, STRONG_BUY = hanya Strong Buy.
            'signal_min' => 'BUY',
            'daily_summary_hour' => 21,
            // Jam tenang (WIB): pesan non-darurat masuk tanpa bunyi. Sama = nonaktif.
            'quiet_start_hour' => 23,
            'quiet_end_hour' => 6,
        ],
    ],

    'analysis' => [
        // Naikkan saat logika strategi berubah (perubahan bobot/ambang otomatis menambah hash di versi).
        'strategy_version' => 'v5',
        'timeframe' => '1h',
        'trend_timeframe' => '4h',
        'weights' => [
            'technical' => 0.35,
            'onchain' => 0.20,
            'whale' => 0.15,
            'sentiment' => 0.15,
            'fundamental' => 0.15,
        ],
        // Skor akhir minimum per rekomendasi (dicek dari atas).
        'thresholds' => [
            'STRONG_BUY' => 72,
            'BUY' => 60,
            'HOLD' => 42,
            'SELL' => 30,
        ],
        // Skor on-chain di bawah ini = token berisiko, tidak pernah direkomendasikan beli.
        'min_onchain_score' => 40,
        // Likuiditas turun lebih dari ini dalam 24 jam = peringatan rug pull.
        'rug_liquidity_drop_pct' => 40,
        // Target dalam kelipatan risiko (R = entry - stop loss).
        'tp_r_multiples' => [1.5, 2.5, 4.0],
        // Jarak stop (%) di bawah ini tidak jadi sinyal: biaya memakan hasilnya (backtest 90 hari: -126R dari 464 trade).
        'min_stop_pct' => (float) env('SIGNAL_MIN_STOP_PCT', 4.0),
        'sl_atr_min' => 1.0,
        'sl_atr_max' => 3.0,
        'signal_entry_expiry_hours' => 12,
        'signal_max_hours' => 72,
        // Catatan bayangan calon aturan v5 (keluar saat rekomendasi tidak lagi Buy/Strong Buy), tanpa mengubah
        // trading. Hanya sinyal yang entry setelah waktu ini (UTC) yang terpantau penuh sejak awal.
        'shadow_exit_since' => env('SIGNAL_SHADOW_EXIT_SINCE', '2026-11-07 13:00:00'),
        // Varian "dua kali berturut-turut tidak Buy" baru tercatat sejak deploy-nya.
        'shadow_second_since' => env('SIGNAL_SHADOW_SECOND_SINCE', '2026-11-07 16:45:00'),
        // v4: Buy (bukan Strong Buy) ditahan bila harga sudah naik lebih dari ini dalam 24 jam, atau entry kurang dari
        // sekian persen di bawah puncak 24 jam; di v3 sinyal seperti itu kebanyakan langsung kena stop. Backtest yang
        // hanya memakai masa token di Market (4 Nov): keduanya +0,11R PF 1,26, v3 -0,24R PF 0,62. Null = nonaktif.
        'buy_max_rise_24h_pct' => 15.0,
        'buy_min_below_high_24h_pct' => 5.0,
        // v5 (review 12 Nov): hanya rekomendasi ini yang membuat sinyal; Buy tetap dianalisa dan tampil sebagai pantauan.
        // Semua sinyal tertutup s.d. 12 Nov: Buy -0,46R/sinyal (n=114, rugi di setiap periode), Strong Buy +0,07R (n=43).
        'signal_recommendations' => ['STRONG_BUY'],
    ],

];
