<?php

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
        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            // Alias "latest" mengikuti versi terbaru, jadi tidak pensiun seperti model bernomor.
            'model' => env('GEMINI_MODEL', 'gemini-flash-lite-latest'),
            // Anggaran agar tidak melewati free tier; request di atasnya pindah ke cadangan atau template/kamus.
            'max_per_minute' => (int) env('GEMINI_MAX_PER_MINUTE', env('AI_MAX_REQUESTS_PER_MINUTE', 5)),
            'max_per_day' => (int) env('GEMINI_MAX_PER_DAY', env('AI_MAX_REQUESTS_PER_DAY', 250)),
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
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'base_url' => 'https://api.openai.com/v1',
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'max_per_minute' => (int) env('OPENAI_MAX_PER_MINUTE', 30),
            'max_per_day' => (int) env('OPENAI_MAX_PER_DAY', 250),
        ],
        // Ringkasan AI dibuat ulang jika rekomendasi berubah atau ringkasan lebih tua dari ini.
        'summary_ttl_minutes' => 180,
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
        'max_open_positions' => (int) env('AUTO_FOLLOW_MAX_OPEN', 3),
        // Rugi saat kena stop, dalam % ekuitas.
        'risk_pct' => (float) env('AUTO_FOLLOW_RISK_PCT', 1.0),
        'max_position_pct' => 25.0,
        // Order dibatasi % likuiditas pool supaya slippage tetap kecil.
        'max_liquidity_pct' => 0.25,
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
        'strategy_version' => 'v2',
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
    ],

];
