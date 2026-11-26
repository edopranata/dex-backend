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
| `app:deploy-window` | Dipanggil `deploy.sh` sebelum build | Menunggu antrean `market-data` kosong, tidak ada task admin, dan tidak ada jadwal dalam 90 detik (maks 10 menit, lalu deploy tetap lanjut). Jendela yang selalu kosong: menit 27-29 dan 57-59 |
| `market:watchlist` | Tiap jam, menit 40 | Market otomatis: keluarkan atau ganti token |
| `market:shadow-signals` | Tiap jam, menit 50 | Hasil sinyal bayangan dari candle tersimpan |
| `market:news` | Tiap jam, menit 10 | Berita dan sentimen |
| `market:security` | Tiap 6 jam, menit 15; token di luar Market 03.35 | Cek GoPlus |
| `app:market-turn-check` | 01.05 | Pemicu uji ulang saat pasar berubah (`docs/UJI_ULANG_PASAR_NAIK.md` repo bersama, `MarketTurn`): BTC harian di atas SMA50 (candle harian Bybit Indonesia), tren 4 jam BTC di analisa DEX, Fear & Greed (alternative.me), dan bayangan Buy saat BTC naik. Telegram ke admin hanya saat pemicu baru terpenuhi; status tersimpan di cache `market-turn:met`, sumber yang gagal dibaca mempertahankan status lama. `--dry` hanya menampilkan (dipakai audit rutin) |
| `market:snapshots` | Tiap jam | Snapshot token |
| `market:equity-snapshot` | Tiap 15 menit | Ekuitas akun auto-follow |
| `app:telegram-daily-summary`, `app:heartbeat`, `horizon:snapshot` | Tiap jam / 5 menit | Ringkasan harian, Healthchecks, statistik Horizon |
| `model:prune`, `queue:prune-failed` | 03.00, 03.30 | Hapus data lama (analisa 90 hari kecuali yang punya sinyal, sumber discovery 60 hari, daftar trader 180 hari) dan job gagal lama |

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
