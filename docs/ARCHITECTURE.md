# Arsitektur DEX Simulator

Hanya yang sudah dibangun. Rencana ada di [ROADMAP.md](ROADMAP.md). Kalau dokumen ini berbeda dengan kode, kode yang benar, dan dokumen perlu diperbaiki. Skema database ada di `database/migrations`.

## Gambaran

```text
Pengunjung -> Cloudflare Tunnel -> web (nginx + SPA Vue) -> app, app2 (Laravel API, PHP-FPM)
                                                               |
DEX Screener, GeckoTerminal, GoPlus, Jupiter, Birdeye,  <- scheduler, horizon -> MySQL 8.4, Redis 7
CoinGecko, RSS, AI provider, Telegram
```

- Dua repo: `dex-backend` (Laravel 13, PHP 8.4, Horizon, Pest) dan `dex-frontend` (Vue 3, TypeScript, Tailwind 4). Frontend dibangun ke dalam image Laravel saat deploy.
- Container (`compose.yaml`): `web`, `app` dan `app2` (dua PHP-FPM supaya situs tetap menjawab saat deploy), `horizon`, `scheduler`, `migrate` (sekali jalan saat deploy), `mysql`, `redis`, `edge` (tunnel).
- Antrean: `default` (3 proses: analisa, Telegram, keamanan, berita) dan `market-data` (1 proses: semua permintaan GeckoTerminal, supaya batas per IP terjaga).

## Jadwal (`routes/console.php`)

| Tugas | Kapan (UTC) | Isi |
|---|---|---|
| `market:prices` | Tiap 15 detik | Harga DEX Screener, pelacakan order dan sinyal |
| `market:candles`, `market:whales` | Menit 0, 15, 30, 45 | Candle 15 menit (1h, 4h, 1d dibangun darinya) untuk token di Market dan acuan pasar (BTC lewat cbBTC di Base, SOL, WETH; `config/market.php` → `benchmarks`), dan transaksi whale dari GeckoTerminal |
| `market:candles --out-of-market` | Menit 9, 24, 39, 54 (seperempat jatah per putaran) | Candle token yang sudah keluar Market: yang punya sinyal atau sinyal bayangan 14 hari terakhir diambil lagi bila datanya lebih lama dari 2 jam (maks 40 per jam); sisanya 10 per jam, yang paling lama duluan |
| `market:analyze` | Menit 11, 26, 41, 56 | Analisa, rekomendasi, sinyal, dan sinyal bayangan |
| `market:evaluate` | Menit 7, 22, 37, 52 | Harga 1, 4, dan 24 jam setelah tiap analisa |
| `market:discover` | Menit 12, 42 | Discovery dan pencatatan sumber |
| `app:deploy-window` | Dipanggil `deploy.sh` sebelum build | Menunggu tidak ada task admin dan tidak ada jadwal dalam 90 detik (maks 10 menit, lalu deploy tetap lanjut). Antrean `market-data` boleh berisi sejak 28 Nov: job menunggu di Redis selama horizon diganti. Celah jadwal per jam: menit 01-05, 13, 16-18, 27-28, 31-35, 43, 46-48, 57-58. Tugas tiap 15 detik, tiap menit, dan tiap 5 menit tidak dihitung |
| `newpairs:track` | Tiap menit (di latar belakang) | Token Baru: pool muda dari discovery, snapshot DEX Screener semua pool yang dipantau, candle 5 menit, trade kertas, satu cek GoPlus. Lihat bagian Token Baru |
| `newpairs:discover` | Menit 20, 50 | Token Baru: GeckoTerminal `new_pools` per jaringan (antrean `market-data`) |
| `market:watchlist` | Tiap jam, menit 40 | Market otomatis: keluarkan atau ganti token |
| `market:shadow-signals` | Tiap jam, menit 50 | Hasil sinyal bayangan dari candle tersimpan; `v7_rule` juga keluar di analisa pertama yang tidak lagi Buy (`ShadowSignal::EXITS_ON_DOWNGRADE`). Bayangan yang sudah masuk tetapi belum selesai tetap `pending`, dengan `entry_price`, `entered_at`, dan `max_tp_hit` sudah terisi (daftar menampilkannya "Berjalan") |
| `market:news` | Tiap jam, menit 10 | Berita dan sentimen |
| `market:security` | Tiap 6 jam, menit 15; token di luar Market 03.35 | Cek GoPlus |
| `app:market-turn-check` | 01.05 | Pemicu uji ulang saat pasar berubah (`docs/UJI_ULANG_PASAR_NAIK.md` repo bersama, `MarketTurn`): BTC harian di atas SMA50 (candle harian Bybit Indonesia), tren 4 jam BTC di analisa DEX, Fear & Greed (alternative.me), dan bayangan Buy saat BTC naik. Telegram ke admin hanya saat pemicu baru terpenuhi; status tersimpan di cache `market-turn:met`, sumber yang gagal dibaca mempertahankan status lama. `--dry` hanya menampilkan (dipakai audit rutin) |
| `market:snapshots` | Tiap jam | Snapshot token |
| `market:equity-snapshot` | Tiap 15 menit | Ekuitas akun auto-follow |
| `app:telegram-daily-summary`, `app:heartbeat`, `horizon:snapshot` | Tiap jam / 5 menit | Ringkasan harian, Healthchecks, statistik Horizon |
| `model:prune`, `queue:prune-failed` | 03.00, 03.30 | Hapus data lama (analisa 90 hari kecuali yang punya sinyal, sumber discovery 60 hari, daftar trader 180 hari, candle 5 menit Token Baru 60 hari) dan job gagal lama |

Tugas penting bisa dijalankan manual dari Admin → Tugas terjadwal; daftar yang diizinkan ada di `RunAdminCommand::ALLOWED`.

## Bagian kode utama

| Bagian | Isi |
|---|---|
| `app/Services/MarketData` | Klien penyedia data, sinkron candle, token acuan pasar (`BenchmarkTokens`), whale, berita, cek keamanan, harga dengan cek silang |
| `app/Services/Analysis` | Lima analyzer, `SignalEngine`, `MarketContext` dan `BtcRelation` (konteks pasar dan hubungan token dengan BTC per analisa), `SignalTracker`, `TradeLevels`, `TokenDiscoveryService`, `WatchlistManager`, laporan (termasuk `TpCoins`: token yang kena TP1 dengan kondisi saat entry, baris sinyal selesai di-cache permanen `tp-coins:v{versi}:{id}`), backtest, sinyal bayangan (aturan `accepted`, `top10_holders`, `high_score`, `buy_watch`), daftar trader. Sejak v7 `SignalEngine` menutup sinyal versi berjalan lewat `SignalTracker::closeOnDowngrade` (status `downgraded`) saat analisa tidak lagi Buy |
| `app/Services/Ai` | `AiClient` (rantai empat provider, kuota per menit dan per hari) dan sentimen berita |
| `app/Services/Notifications` | Bot Telegram per pengguna, alur beli lewat tombol, email |
| `app/Services/Admin` | Pengaturan panel (`SettingsRegistry`, nilai di tabel `settings` menimpa `.env`), backup, log, sistem, container |
| `app/Services/Trading` | Risiko auto-follow |

## Deploy

- Push ke `main` → GitHub Actions: backend menjalankan tes, frontend menjalankan build. Perubahan yang hanya menyentuh `docs/` atau `README.md` di backend tidak memicu deploy.
- Deploy memakai kunci SSH yang dibatasi ke `deploy/ci-deploy.sh`. Workflow mengirim `deploy <repo> <commit>`, dan server hanya memajukan repo itu sampai commit yang lolos (sejak 12 Nov 2026). `deploy/deploy.sh` membangun image, migrasi, lalu mengganti container satu per satu.
- Rincian server, backup, dan pemulihan: [DEPLOY_VPS.md](DEPLOY_VPS.md).

## Keandalan

- Auto-start saat boot (`dex.service`), watchdog `/up` tiap 2 menit, restart container otomatis, reboot keamanan 04.00 WIB.
- Backup harian 02.30 WIB (14 hari) dan salinan ke Cloudflare R2.
- Pemantauan: UptimeRobot ke `/health`, Healthchecks.io (`dex-heartbeat`, `dex-backup`), dan status container di Admin.

## Login lewat Akun tune-in.trade (di produksi sejak 23 Nov 2026, cara masuk masih `local`)

Klien OAuth2 untuk akun pusat ([rancangan](../../../docs/RANCANGAN_AKUN_PUSAT.md)). Kodenya sama di keenam aplikasi; yang berbeda hanya `app_key` di `config/central_account.php`; DEX juga tidak pernah mengikat akun sistem (`is_system`) dan memberi user baru saldo virtual awal seperti `app:create-user`.

- `GET /auth/redirect` membuat `state` dan PKCE (S256), lalu mengarahkan browser ke `https://tune-in.trade/oauth/authorize`. `GET /auth/callback` memeriksa `state`, menukar `code`, dan memanggil `/api/me` lewat alamat publik `https://tune-in.trade` (bawaan `ACCOUNT_INTERNAL_URL` sejak 24 Nov 2026; jaringan Docker `edge` tidak dipakai, alasannya di `account/backend/docs/DECISIONS.md`).
- User lokal dicari lewat `users.account_id`, lalu lewat email yang sama persis dengan `account_id` masih kosong (diikat sekali dan dicatat di log), lalu dibuat baru. `is_admin` mengikuti peran dari Akun setiap login dan setiap pemeriksaan ulang. Login lewat Akun menghapus kewajiban ganti password sementara. Email yang sudah terikat ke akun lain ditolak (`account_error=conflict`).
- Sesi dari Akun tidak memakai "ingat saya". Token Akun disimpan terenkripsi di cache dengan id acak di sesi, supaya request paralel tidak menimpa refresh token yang baru diputar. `CentralAccountGuard` (grup `api`) bertanya ulang ke Akun paling lama tiap 10 menit (`ACCOUNT_RECHECK_MINUTES`). Kalau token dicabut ("Keluar dari semua" di Akun) atau akses hilang, sesi berakhir dengan 401 `account_session_ended`. Kalau Akun tidak bisa dihubungi, sesi tetap jalan dan dicoba lagi semenit kemudian.
- Cara masuk (`central_account.mode`) diatur di Admin → Pengaturan → Akun tune-in.trade atau dengan `php artisan app:auth-mode local|both|central`:
  - `local`: login lama saja (nilai awal).
  - `both`: tombol "Masuk lewat tune-in.trade" ditambah login lama.
  - `central`: hanya lewat Akun. Login lama, lupa password, dan reset 2FA lokal ditutup (403 `central_login_only`), dan sesi dari login lama berakhir.

  Tanpa `ACCOUNT_CLIENT_ID` dan `ACCOUNT_CLIENT_SECRET` di `.env`, apa pun pilihannya yang berlaku `local`, jadi panel tidak bisa mengunci semua orang di luar.
- Frontend: tombol di halaman login. Mode `central` langsung meneruskan ke Akun, kecuali baru keluar atau ada `account_error`. Service worker PWA tidak melayani `/auth/redirect` dan `/auth/callback`, dan Vite dev meneruskan keduanya ke backend.

## Grafik candle (sejak 24 Nov 2026)

`CandleChart.vue` di frontend memakai KLineChart 10.1.0 (Apache-2.0), atas permintaan pemilik supaya indikator dan gambar bisa ditambah sendiri seperti di grafik bursa. TradingView Advanced Charts (dipakai Binance dan Bybit) tidak dipakai karena TradingView tidak memberikannya untuk aplikasi pribadi atau di balik login. Candle masuk lewat `setDataLoader` (hanya candle yang sudah dimuat halaman, tanpa halaman lebih lama); pembaruan polling dan harga live diteruskan lewat `subscribeBar`, jadi zoom dan geser tetap. Garis entry, SL, dan TP (`levelLine`) serta panah transaksi (`tradeMarker`) adalah overlay terkunci; kotak (`rectBox`), lingkaran (`circleShape`), dan teks (`textNote`) dibuat sendiri karena KLineChart hanya punya figurnya. Indikator `WILLY` (sejak 25 Nov 2026) meniru "The Willy" TradingView: Williams %R (bawaan 21) dengan EMA-nya (bawaan 13) dan garis -20/-80; rumusnya di `src/lib/willy.ts`, dicocokkan dengan TradingView di ETHUSDT 4 jam. Indikator pilihan (bawaan EMA 20/50 dan VOL; RSI bawaan 14) dan gambar disimpan di localStorage browser: indikator untuk semua grafik, gambar per instrumen (`drawingKey`) sehingga tampil di semua timeframe. Waktu dalam WIB, angka kecil dilipat seperti 0.0₄1234. Grafik ekuitas dan return tetap memakai lightweight-charts. Tinggi kartu grafik bisa ditarik dari garis di bawah grafik (300-1.600 px, klik dua kali kembali ke awal) dan disimpan di browser (`chart:height:v1`).

## Sidebar (sejak 24 Nov 2026)

Di desktop (lg ke atas) sidebar punya tiga mode dari tombol di kiri toolbar (`SidebarToggle`, `useSidebar`): penuh, mini (ikon saja, nama sebagai tooltip), dan sembunyi (logo pindah ke toolbar; ringkasan ekuitas tampil di toolbar karena ringkasan portofolio di sidebar ikut tersembunyi). Pilihan disimpan di browser (`sidebar:v1`). Di layar kecil menu tetap laci geser dengan nama lengkap; semua aturan mini hanya berlaku di `lg:`.

## Token Baru (sejak 26 Nov 2026, hanya pencatatan)

Menu Token Baru (`/new-pairs`, `NewPairsView.vue`; API `GET /api/new-pairs`, `/api/new-pairs/summary`, `/api/new-pairs/trades`) mencatat pool berumur di bawah 24 jam untuk menilai ulang strategi New Token Hunter (`dex/new_token_hunter/`) ke depan. Terpisah dari Market: tabel sendiri (`new_pairs`, `new_pair_bars`, `new_pair_trades`), tanpa sinyal, order, atau auto-follow. Aturan dan angka di `config/market.php` → `new_pairs`; kode di `app/Services/NewPairs/`.

- **Sumber.** Pair muda yang dilihat discovery Market dan ditolak karena umurnya (`token_candidates`, tanpa permintaan tambahan), dan GeckoTerminal `new_pools` Solana, BSC, Base dua kali sejam (3 permintaan, sebagian besar kurva pump.fun beberapa ribu dolar). Dicatat bila likuiditas minimal $10 ribu, belum dikenal, dan daftar pantau belum penuh (150 pool).
- **Sampel tiap menit.** DEX Screener, satu permintaan per 30 pool per jaringan: harga, likuiditas, volume 5 menit/1 jam/24 jam, jumlah beli dan jual 5 menit. Candle 5 menit dibangun dari sampel (open/high/low/close adalah harga sampel, jadi ekor di antara sampel terlewat; volume dan transaksi angka bergulir 5 menit DEX Screener di sampel terakhir). Perubahan harga dibatasi ±1 miliar persen karena DEX Screener kadang memberi angka ekstrem di trade pertama.
- **Keamanan.** Satu cek GoPlus per menit untuk pool tertua yang belum dicek, sampai 3 percobaan; pool tanpa data GoPlus tetap "tanpa data".
- **Trade kertas**, satu per strategi per pool, entry di harga sampel saat umur pool 5 menit-24 jam:
  - `first_seen`: setiap pool, di sampel pertama (pembanding).
  - `early_liquidity` (A): dalam 1 jam sejak terlihat, likuiditas ≥ $10 ribu, volume 5 menit ≥ $2.000 dan 1 jam ≥ $10.000, ≥ 20 transaksi 5 menit, beli/jual ≥ 1,2, naik 5 menit < 30%.
  - `breakout` (B) dan `pullback` (C): di candle 5 menit yang baru tutup, aturan uji 26 Nov (12 candle sebelumnya; breakout juga butuh beli > jual). VWAP pullback dihitung dari candle sejak pool terlihat.
  - A, B, dan C hanya entry bila GoPlus sudah menjawab dengan skor dan bukan honeypot, dan pajak jual ≤ 10%.
  - Exit: sepertiga di TP +20/+40/+70%, stop -15% (ke entry setelah TP1) diisi di harga sampel, maksimal 24 jam, atau likuiditas tinggal 20% dari saat entry. Hasil bersih dikurangi biaya 2% pulang-pergi.
- **Selesai dipantau** saat umur pool lewat 24 jam dan trade-nya sudah tutup, saat likuiditas tinggal 20% dari awal tanpa trade terbuka, atau saat DEX Screener tidak memberi data 60 menit (trade terbuka ditutup di harga terakhir).
- `newpairs:track` berjalan tiap menit di latar belakang tanpa antrean, jadi `app:deploy-window` tidak menunggunya. Bisa dimatikan dengan `NEW_PAIRS_ENABLED=false`.

## Seleksi akun (sejak 28 Nov 2026)

- `AccountSelection` menilai akun auto-follow (`User::AUTO_FOLLOW_ACCOUNTS`) dengan batas `market.selection` (bisa diubah di Admin → Pengaturan) dari lot `AccountReport::lots` milik versi `StrategyVersion::current()`. Angka semua versi ikut dikirim sebagai pembanding.
- R per lot sama dengan laporan akun. Menang > +0,05R, impas ±0,05R (tetap di pembagi win rate). Drawdown dari snapshot ekuitas akun plus ekuitas sekarang (`PerformanceReport::maxDrawdownPct`).
- API `GET /api/reports/selection`, halaman `SelectionView` (menu Seleksi akun), baris "Seleksi akun" di ringkasan harian Telegram.

## Akun strategi dari FutureSim (sejak 28 Nov 2026)

- Permintaan pemilik 28 Nov 2026: strategi akun FutureSim juga ada di DEX dan masuk Seleksi akun. Pilihan pemilik: semua strategi long, semua token yang punya candle, posisi kertas dengan biaya DEX.
- Tabel `strategy_accounts`, `strategy_positions` (pending, open, closed, cancelled), dan `strategy_snapshots`, terpisah dari pengguna dan order simulator. Daftar akun di `config/market.php` → `strategy_accounts.strategies`: 14 strategi plus `hold_btc` sebagai pembanding.
- Rule di `app/Services/Strategies/Rules`:
  - salinan versi long SpotSim (`ThreeLayer*`, `EngulfingFourHour`, `TtmSqueezeLong`, `Willy*`, `IchimokuLong`);
  - salinan FutureSim (`SwingFibonacciLong` sisi long, `MartingaleLong`, `ThreeLayerAnyMarket`);
  - `MartingaleLong` memilih 10 token paling likuid di luar `martingale.exclude_chains` (Ethereum sejak 28 Nov, karena gasnya lebih besar dari target ronde);
  - akun tren long saja (`TrendLong`, `EthBtcTrendLong`, `EthTrendLong`) yang memegang cbBTC/WETH saat di atas SMA50.
- Tidak dipasang: RSI cross SELL (short), sisi short akun tren, pantulan Fibonacci 5 menit (DEX tidak punya candle 5 menit), dan varian target volatilitas.
- `Bars` membaca candle DEX. Candle 1h dan 4h dibangun dari 15m, dan volume dalam USD juga dipakai sebagai turnover. `Indicators` disalin dari SpotSim supaya hitungannya sama dengan sumber strategi.
- Filter BTC > SMA50, ETH > SMA50, dan BTC di atas EMA200 4 jam memakai klines spot Bybit Indonesia (`BybitCloses`, satu permintaan per candle, di-cache), sumber yang sama dengan FutureSim dan `app:market-turn-check`. Alasannya, candle harian cbBTC baru 38 hari pada 28 Nov.
- `strategies:run` dijadwalkan pada :08, :23, dan :38, dan `--snapshot` pada :53. `StrategyRunner` bekerja dalam dua tahap:
  - **Posisi:** tiap candle 15m baru dicek untuk isian order limit (atau kedaluwarsa, atau target tersentuh lebih dulu), stop (diisi di stop, atau di open bila candle dibuka di bawahnya), target, dan tambahan martingale. Setelah itu dicek batas waktu, lalu exit milik rule, sekali per jam tutup.
  - **Entry:** sekali per token per jam tutup, bila candle 1h token jam itu sudah tersimpan (dan candle 4h-nya saat penutupan 4 jam), likuiditasnya minimal `min_liquidity_usd`, dan akun masih punya slot. Token dikerjakan satu per satu supaya candle yang dimuat dipakai bersama semua akun. Token yang candle-nya datang terlambat lebih dari satu jam dilewati, tidak dimasuki terlambat.
- `StrategyBroker`:
  - harga masuk memakai harga live token bila segar (maksimal 10 menit), selain itu close 15m terakhir;
  - tiap swap membayar `market.simulator.fee_rate`, `TradingService::priceImpact`, dan gas `market.autofollow.gas_usd` per jaringan;
  - ukuran posisi dengan stop: rugi di stop termasuk biaya sama dengan `risk_pct` ekuitas, maksimal `max_position_pct` ekuitas dan `max_liquidity_pct` likuiditas pool;
  - ukuran posisi tanpa stop: `stopless_share` × ekuitas;
  - `state.initial_stop` dan `state.initial_risk` disimpan saat masuk.
- API: `GET /api/reports/strategy-accounts` dan `GET /api/reports/strategy-accounts/{key}` untuk halaman Akun strategi. Seleksi akun (`/api/reports/selection`) menambahkan `strategy_accounts` (R = `net_pnl` / `initial_risk`, drawdown dari `strategy_snapshots` ditambah ekuitas sekarang, `started_at`) serta `version_started_at` untuk akun auto-follow.
