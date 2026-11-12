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
| `market:candles`, `market:whales` | Menit 0, 15, 30, 45 | Candle 15 menit (1h, 4h, 1d dibangun darinya) dan transaksi whale dari GeckoTerminal |
| `market:candles --out-of-market` | Tiap jam, menit 20 | Candle token yang sudah keluar Market |
| `market:analyze` | Menit 11, 26, 41, 56 | Analisa, rekomendasi, sinyal, dan sinyal bayangan |
| `market:evaluate` | Menit 7, 22, 37, 52 | Harga 1, 4, dan 24 jam setelah tiap analisa |
| `market:discover` | Menit 25, 55 | Discovery dan pencatatan sumber |
| `market:watchlist` | Tiap jam, menit 40 | Market otomatis: keluarkan atau ganti token |
| `market:shadow-signals` | Tiap jam, menit 50 | Hasil sinyal bayangan dari candle tersimpan |
| `market:news` | Tiap jam, menit 10 | Berita dan sentimen |
| `market:security` | Tiap 6 jam, menit 15; token di luar Market 03.35 | Cek GoPlus |
| `market:trader-rankings` | 00.35 | Daftar trader paling untung Birdeye |
| `market:snapshots` | Tiap jam | Snapshot token |
| `market:equity-snapshot` | Tiap 15 menit | Ekuitas akun auto-follow |
| `app:telegram-daily-summary`, `app:heartbeat`, `horizon:snapshot` | Tiap jam / 5 menit | Ringkasan harian, Healthchecks, statistik Horizon |
| `model:prune`, `queue:prune-failed` | 03.00, 03.30 | Hapus data lama (analisa 90 hari kecuali yang punya sinyal, sumber discovery 60 hari, daftar trader 180 hari) dan job gagal lama |

Tugas penting bisa dijalankan manual dari Admin → Tugas terjadwal; daftar yang diizinkan ada di `RunAdminCommand::ALLOWED`.

## Bagian kode utama

| Bagian | Isi |
|---|---|
| `app/Services/MarketData` | Klien penyedia data, sinkron candle, whale, berita, cek keamanan, harga dengan cek silang |
| `app/Services/Analysis` | Lima analyzer, `SignalEngine`, `SignalTracker`, `TradeLevels`, `TokenDiscoveryService`, `WatchlistManager`, laporan, backtest, sinyal bayangan, daftar trader |
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
