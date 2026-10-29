# DEX Simulator

Simulator trading kripto dengan **harga real** dari DEX (Solana, Ethereum, Base, BSC, Arbitrum, Polygon, Optimism, Avalanche) dan **uang virtual**. Sistem menganalisis tiap token secara berkala (teknikal, keamanan on-chain, whale, sentimen berita, kesehatan pasar), memberi rekomendasi beserta entry, stop loss, dan take profit 1–3, lalu melacak hasil setiap sinyal agar Anda bisa menilai akurasinya sebelum memakai uang sungguhan.

> Ini alat latihan dan riset. Sinyal adalah keluaran algoritma, bukan nasihat keuangan.

## Repository

Proyek terdiri dari dua repository yang di-clone berdampingan:

```
dex/
├── backend/   github.com/edopranata/dex-backend   (repo ini)
│   ├── app/ … Laravel 13 API (Sanctum session, scheduler, queue, MySQL, Redis)
│   ├── docker/          Dockerfile (target frontend/app/web), Nginx, PHP config
│   ├── deploy/          server-setup.sh, deploy.sh, ci-deploy.sh, backup.sh, edge/ (Cloudflare Tunnel)
│   ├── docs/            Analisis dokumen API & panduan deploy VPS
│   ├── compose.yaml     Stack production (web, app, queue, scheduler, migrate, mysql, redis)
│   ├── .github/         CI: test lalu deploy otomatis ke VPS
│   └── dev.sh           Menjalankan seluruh stack untuk development
└── frontend/  github.com/edopranata/dex-frontend  (Vue 3 + Vite + Tailwind 4 + Pinia + lightweight-charts)
```

## Menjalankan di lokal

Prasyarat: PHP 8.3+, Composer, Node 22+, MySQL 8+, Redis.

```bash
# 1. Backend
cd backend
composer install
cp .env.example .env            # lalu isi DB_PASSWORD, dan API key AI jika ada
php artisan key:generate
php artisan migrate --seed      # membuat user demo + 9 token awal
php artisan market:bootstrap    # isi data awal (±5 menit karena rate limit GeckoTerminal)

# 2. Frontend
cd ../frontend
npm install

# 3. Jalankan semuanya (API, queue, scheduler, frontend)
cd ../backend
./dev.sh
```

Buka http://localhost:5173 lalu login dengan `demo@dex.test` / `password` (user demo hanya dibuat di environment lokal).

### Satu domain: frontend disajikan oleh Laravel

```bash
cd frontend && npm run build:laravel   # build Vue ke backend/public (index.php Laravel tidak disentuh)
cd ../backend && php artisan serve      # http://localhost:8000 → frontend + API sekaligus
```

Semua path selain `/api/*`, `/sanctum/*`, dan `/up` mengembalikan `index.html`, sehingga URL Vue Router (misalnya `/markets`) tetap bisa dibuka langsung. Saat development, `npm run dev` (Vite di port 5173) tetap dipakai karena mendukung hot reload.

## Deploy

Alur: **commit & push ke GitHub → GitHub Actions menjalankan tes/build → SSH ke VPS → `deploy/deploy.sh`** (pull kedua repo, build image Docker, ganti container, migrasi otomatis).

Panduan lengkap (SSH key, setup VPS, secrets GitHub, akses via IP lalu Cloudflare Tunnel): [docs/DEPLOY_VPS.md](docs/DEPLOY_VPS.md).

## Sumber data

| Data | Sumber | Biaya |
|---|---|---|
| Harga real-time, likuiditas, volume, FDV | DEX Screener | Gratis (300 req/menit) |
| Candle OHLCV & transaksi whale | GeckoTerminal | Gratis (~10 req/menit per IP) |
| Keamanan kontrak, holder, LP, honeypot | GoPlus Security | Gratis |
| Berita | RSS CoinDesk, Cointelegraph, Decrypt, The Block | Gratis |
| Sentimen pasar umum | Fear & Greed Index (alternative.me) | Gratis |
| Discovery: trending pool DEX + pembeli/penjual unik | GeckoTerminal `trending_pools` | Gratis |
| Discovery: koin paling banyak dicari | CoinGecko `search/trending` | Gratis (Demo key opsional) |
| Discovery: token yang di-boost (iklan) | DEX Screener `token-boosts` | Gratis |
| Sentimen berita & ringkasan AI | Gemini atau OpenAI (opsional) | Gemini ada kuota gratis |

Tanpa API key AI, sentimen memakai kamus kata dan ringkasan memakai template, jadi aplikasi tetap berjalan penuh.

## Jadwal otomatis (cron)

| Perintah | Frekuensi | Fungsi |
|---|---|---|
| `market:prices` | 15 detik | Harga terbaru, eksekusi order limit/SL/TP, lacak sinyal |
| `market:candles` | 10 menit (15m, 1h), per jam (4h, 1d) | Candle dari GeckoTerminal |
| `market:whales` | 10 menit | Transaksi besar 24 jam terakhir |
| `market:analyze` | 15 menit | Skor gabungan, rekomendasi, entry/SL/TP |
| `market:discover` | 30 menit | Temukan token trending + whale, yang terbaik otomatis masuk Market |
| `market:watchlist` | per jam | Keluarkan token tidak dikunci yang skornya menurun seminggu |
| `market:news` | per jam | Berita RSS + analisis sentimen |
| `market:security` | 6 jam | Cek kontrak via GoPlus |
| `market:snapshots` | per jam | Riwayat likuiditas (deteksi rug pull) |
| `model:prune` | harian | Hapus data lama |

Semua perintah bisa dijalankan manual, misalnya `php artisan market:analyze PEPE --sync` atau `php artisan market:add solana <alamat-token>`.

## Cara kerja rekomendasi

Skor akhir 0–100 = Teknikal 35% + On-chain 20% + Whale 15% + Sentimen 15% + Fundamental 15% (atur di `backend/config/market.php`).

- **≥ 72 Strong Buy, ≥ 60 Buy, ≥ 42 Hold, ≥ 30 Sell, sisanya Strong Sell.**
- Honeypot → selalu **Hindari**. Likuiditas turun > 40% dalam 24 jam → **Strong Sell**.
- Buy hanya keluar jika teknikal ≥ 50 dan skor keamanan ≥ 40.
- Entry = harga sekarang sampai −0,5 ATR. Stop loss = di bawah swing low, dibatasi 1–3 ATR. TP1/TP2/TP3 = 1,5R / 2,5R / 4R.
- Setiap sinyal Buy dilacak (halaman **Sinyal**): win rate, total R, dan TP yang tercapai.

## Discovery: token yang ditemukan sistem

Selain token yang Anda input, sistem mencari sendiri setiap 30 menit (halaman **Temukan**):

1. **Kumpulkan kandidat** dari trending pool GeckoTerminal (Solana, Base, Ethereum, BSC), koin trending di pencarian CoinGecko, dan token yang di-boost di DEX Screener.
2. **Saring**: likuiditas ≥ $50 ribu, volume 24 jam ≥ $100 ribu, umur pair ≥ 24 jam, bukan stablecoin/aset besar.
3. **Skor awal**: tren (momentum harga, rasio beli/jual, pembeli unik vs penjual unik; kenaikan parabolik > 150% justru dikurangi) dan atensi (trending di berapa sumber + jumlah berita).
4. **Cek mendalam 8 teratas**: keamanan kontrak (GoPlus) dan arus dana whale 24 jam (GeckoTerminal).
5. **Skor discovery** = Tren 30% + Atensi 15% + Whale 35% + Keamanan 20%. Token yang hanya muncul dari boost berbayar dikurangi 15 poin.
6. **Otomatis masuk Market** (maks 3 per putaran) jika skor ≥ 65, whale net beli, keamanan ≥ 60, dan berasal dari trending organik. Token ini lalu dianalisis seperti token lain (entry/SL/TP).

### Market yang mengelola diri sendiri

- **Kapasitas** = `SIM_MAX_TOKENS` (default 20, dibatasi rate limit GeckoTerminal). Saat penuh, kandidat kuat **menggantikan** token terlemah (skor analisis < 50) yang tidak dikunci dan tidak dipakai.
- **Keluar otomatis** (dicek tiap jam, `market:watchlist`): token yang tidak dikunci, tidak ada di portofolio, tanpa order pending dan sinyal terbuka, jika
  - rata-rata skor harian turun ≥ 8 poin dalam 7 hari, turun di ≥ 60% hari, dan skor terakhir < 60 (butuh minimal 3 hari data); atau
  - rekomendasi terakhir **Hindari** / **Strong Sell** (honeypot, likuiditas ditarik): keluar segera.
- **Kunci (📌)**: token yang Anda tambahkan sendiri dan token awal otomatis dikunci sehingga tidak pernah dikeluarkan otomatis. Lepas kunci di halaman Market jika ingin token itu ikut dikelola otomatis.
- Token yang baru keluar tidak dimasukkan lagi selama 3 hari (mencegah keluar-masuk berulang).
- Setiap token masuk/keluar dicatat beserta alasannya di panel **Aktivitas Market**. Grafik "Skor 7 hari" menunjukkan arah skor tiap token.

Atur di `backend/config/market.php` bagian `discovery` dan `watchlist`, atau lewat `.env`: `DISCOVERY_AUTO_TRACK`, `SIM_MAX_TOKENS`, `COINGECKO_DEMO_API_KEY`. Jalankan manual: `php artisan market:discover --sync` dan `php artisan market:watchlist`.

## Tes

```bash
cd backend && php artisan test --compact
```

Dokumentasi lanjutan: [docs/ANALISA_DOKUMEN.md](docs/ANALISA_DOKUMEN.md), [docs/DEPLOY_VPS.md](docs/DEPLOY_VPS.md), dokumen awal [docs/api_requirements_phases.md](docs/api_requirements_phases.md).
