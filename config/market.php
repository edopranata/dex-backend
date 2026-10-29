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
        // Matikan di server publik; buat akun dengan `php artisan app:create-user`.
        'registration_enabled' => (bool) env('REGISTRATION_ENABLED', true),
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

    'chains' => [
        'solana' => ['label' => 'Solana', 'geckoterminal' => 'solana', 'goplus' => 'solana'],
        'ethereum' => ['label' => 'Ethereum', 'geckoterminal' => 'eth', 'goplus' => '1'],
        'base' => ['label' => 'Base', 'geckoterminal' => 'base', 'goplus' => '8453'],
        'bsc' => ['label' => 'BNB Chain', 'geckoterminal' => 'bsc', 'goplus' => '56'],
        'arbitrum' => ['label' => 'Arbitrum', 'geckoterminal' => 'arbitrum', 'goplus' => '42161'],
        'polygon' => ['label' => 'Polygon', 'geckoterminal' => 'polygon_pos', 'goplus' => '137'],
        'optimism' => ['label' => 'Optimism', 'geckoterminal' => 'optimism', 'goplus' => '10'],
        'avalanche' => ['label' => 'Avalanche', 'geckoterminal' => 'avax', 'goplus' => '43114'],
    ],

    // API publik GeckoTerminal dibatasi per IP; naikkan jika memakai paket berbayar.
    'geckoterminal_requests_per_minute' => (int) env('GECKOTERMINAL_RPM', 10),

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
        'provider' => env('AI_PROVIDER', 'none'),
        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            // Alias "latest" mengikuti versi terbaru, jadi tidak pensiun seperti model bernomor.
            'model' => env('GEMINI_MODEL', 'gemini-flash-lite-latest'),
        ],
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        ],
        // Ringkasan AI dibuat ulang jika rekomendasi berubah atau ringkasan lebih tua dari ini.
        'summary_ttl_minutes' => 180,
        // Anggaran request agar tidak melewati free tier; request di atasnya memakai cadangan (template/kamus).
        'max_requests_per_minute' => (int) env('AI_MAX_REQUESTS_PER_MINUTE', 5),
        'max_requests_per_day' => (int) env('AI_MAX_REQUESTS_PER_DAY', 250),
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
    'telegram' => [
        'enabled' => (bool) env('TELEGRAM_ENABLED', false),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'link_code_ttl_minutes' => 15,
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
        'sl_atr_min' => 1.0,
        'sl_atr_max' => 3.0,
        'signal_entry_expiry_hours' => 12,
        'signal_max_hours' => 72,
    ],

];
