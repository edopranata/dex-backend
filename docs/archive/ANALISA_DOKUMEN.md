# Analisis Dokumen `api_requirements_phases.md`

Dokumen awal sudah punya arah yang benar (bertahap: simulator → teknikal & whale → berita & AI → on-chain & scoring). Namun beberapa API **tidak bisa melakukan apa yang diharapkan**, dan ada beberapa celah arsitektur. Semua temuan di bawah sudah diuji langsung ke API pada 29 Oktober 2026 dan solusinya sudah diterapkan di kode.

## 1. Temuan per API

| API di dokumen | Rencana di dokumen | Kenyataan | Pengganti yang dipakai |
|---|---|---|---|
| **DEX Screener** | Harga, grafik candlestick, riwayat transaksi untuk whale tracker | Hanya memberi harga, likuiditas, volume, jumlah transaksi buy/sell. **Tidak ada data candle (OHLCV) dan tidak ada daftar transaksi per wallet.** | Tetap dipakai untuk harga real-time. Candle dan whale diambil dari **GeckoTerminal**. |
| **TAAPI.io** | RSI/MACD siap pakai | Paket gratis sangat terbatas dan berbasis simbol bursa (CEX); token DEX kecil umumnya tidak tersedia. | **Dihitung sendiri** di server dari candle GeckoTerminal (RSI, EMA 20/50/200, MACD, Bollinger, ATR, volume). Gratis dan tanpa batas. |
| **CryptoPanic** | Berita gratis | Endpoint gratis/developer **sudah dihapus**. API menjawab: *valid paths are growth, growth_weekly, enterprise* (semuanya berbayar). | **RSS** CoinDesk, Cointelegraph, Decrypt, The Block (gratis, teruji). |
| **Etherscan / Solscan** | Jumlah holder, dompet developer, rug check | Jumlah/daftar holder di Etherscan termasuk endpoint berbayar (PRO); Solscan API juga berbayar; keduanya beda format per chain. | **GoPlus Security** (gratis, satu API untuk EVM & Solana): honeypot, pajak, mint/freeze authority, owner, top holder, LP terkunci, jumlah holder. |
| **Birdeye** | Alternatif untuk Solana | Valid, tapi tidak dibutuhkan karena GeckoTerminal sudah mencakup Solana. | Tidak dipakai (bisa ditambahkan nanti). |
| **Gemini / OpenAI** | Skor sentimen 1–100 per berita | Valid. | Dipakai, **opsional**. Berita dikirim per batch 20 judul dalam 1 request (hemat kuota). Tanpa key, sentimen memakai kamus kata. AI juga menulis ringkasan analisis berbahasa Indonesia. |

Tambahan yang tidak ada di dokumen tetapi penting:

- **GeckoTerminal**: candle 15m/1h/4h/1d dan transaksi 24 jam dengan filter nilai USD minimum (cocok untuk whale tracker). Batas resminya 30 req/menit, tetapi **uji nyata hanya ~11 request lalu diblokir (HTTP 429)**. Aplikasi membatasi 10 req/menit (variabel `GECKOTERMINAL_RPM`) dan menunggu otomatis bila kena 429.
- **Fear & Greed Index**: sentimen pasar kripto secara umum, gratis.

## 2. Rekomendasi API gratis (dicek 29 Oktober 2026)

**Sudah dipakai di aplikasi (tanpa API key):**

| Kebutuhan | API | Batas gratis | Catatan |
|---|---|---|---|
| Harga real-time, likuiditas, volume | DEX Screener | 300 req/menit | Tidak ada candle maupun daftar transaksi |
| Candle OHLCV, transaksi whale, trending pool | GeckoTerminal | ~10 req/menit per IP (hasil uji) | Dokumentasi menyebut 30/menit, kenyataannya lebih ketat |
| Koin paling banyak dicari (atensi internet) | CoinGecko `search/trending` | Publik; Demo key gratis ~10.000 call/bulan | Dipanggil 2×/jam, jauh di bawah kuota |
| Token boost & profil | DEX Screener `token-boosts`, `token-profiles` | 60 req/menit | Boost = iklan berbayar; hanya dipakai sebagai sinyal lemah |
| Keamanan kontrak, holder, honeypot | GoPlus Security | Gratis, tanpa key | EVM & Solana dalam satu API |
| Berita | RSS CoinDesk, Cointelegraph, Decrypt, The Block | Tanpa batas resmi | CryptoPanic & CoinDesk Data API sudah berbayar |
| Sentimen pasar | Fear & Greed (alternative.me) | Gratis | Update harian |
| Sentimen AI & ringkasan | Gemini (kuota gratis) / OpenAI | Tergantung model | Opsional |

**Alternatif gratis untuk dikembangkan nanti (perlu daftar API key):**

| API | Free tier | Kegunaan |
|---|---|---|
| Helius (Solana) | 1 juta kredit/bulan, 10 req/detik RPC | Riwayat transaksi wallet whale Solana, webhook saat wallet tertentu bertransaksi |
| Etherscan API V2 | ±3–5 req/detik, 100 ribu/hari, satu key untuk banyak chain EVM | Transfer besar token ERC-20; free tier berkurang sejak Juli 2026 (maks 1.000 record per request, sebagian chain berbayar) |
| CoinGecko Demo key | ~10.000 call/bulan | Lebih stabil daripada endpoint publik; isi `COINGECKO_DEMO_API_KEY` |
| Arkham | Ada akses API gratis (menurut ulasan pihak ketiga) | Label wallet (exchange, fund, market maker) |

**Sudah tidak gratis / tidak disarankan:** CryptoPanic (paket gratis dihapus), CoinDesk Data/CryptoCompare News (free tier berakhir Mei 2026), Whale Alert API (berbayar), Bitquery streaming (mulai $49/bulan), TAAPI.io (paket gratis sangat terbatas). Info free tier Birdeye dan Moralis belum bisa dipastikan; cek halaman harga masing-masing sebelum dipakai.

## 3. Celah arsitektur di dokumen

1. **"Vue melakukan fetching ke DEX Screener setiap 3–5 detik"**
   Jika setiap browser memanggil API langsung, rate limit cepat habis dan, yang lebih penting, **harga eksekusi order akan berasal dari browser** sehingga bisa dimanipulasi. Di implementasi ini hanya server yang mengambil harga (setiap 15 detik). Vue mengambil dari API Laravel sendiri, dan order selalu dieksekusi dengan harga yang diambil server.

2. **Simulasi tanpa biaya dan slippage**
   Trading DEX sungguhan kena fee swap dan *price impact*. Pada token likuiditas kecil, kerugian dari slippage sering lebih besar dari pergerakan harga. Simulator ini memakai fee 0,3% dan rumus AMM *constant product* (x·y=k) berdasarkan likuiditas pool. Hasilnya mendekati pengalaman nyata dan melatih kebiasaan memperhatikan likuiditas.

3. **Whale = transaksi di atas $10.000 (tetap)**
   Untuk pool $50 ribu, transaksi $10 ribu itu sangat besar; untuk pool $50 juta itu kecil. Ambang di sini menyesuaikan pool: `clamp(likuiditas × 2%, $1.000, $10.000)`.

4. **"Fundamental" untuk token DEX**
   Token DEX (terutama memecoin) tidak punya laporan keuangan. Yang relevan adalah kedalaman likuiditas, rasio volume/likuiditas, umur pair, rasio FDV/likuiditas, dan rasio transaksi beli/jual. Itulah yang dihitung di skor fundamental.

5. **Tidak ada pengukuran akurasi sinyal**
   Dokumen berhenti di "sinyal muncul di dashboard". Padahal sebelum memakai uang sungguhan, yang perlu diketahui adalah **apakah sinyalnya menguntungkan**. Setiap sinyal Buy di sini dilacak otomatis (entry → TP/SL/kedaluwarsa) dan hasilnya dicatat dalam satuan R. Halaman **Sinyal** menampilkan win rate dan total R.

6. **Rug pull hanya dari jumlah holder**
   Holder bertambah tidak menjamin aman. Yang ditambahkan di sini: snapshot likuiditas per jam (penurunan > 40% dalam 24 jam memicu Strong Sell), deteksi honeypot (rekomendasi selalu Hindari), dan cek mint/freeze authority, pajak, serta persentase LP yang dikunci.

7. **AI menilai sentimen hanya dari judul**
   Hasilnya cenderung bias ke netral dan mudah salah konteks. Di sini sentimen hanya berbobot 15%, dan berita yang tidak menyebut token tertentu hanya dihitung setengah bobot sebagai sentimen pasar umum.

## 4. Pemetaan fase dokumen ke implementasi

| Fase | Status | Lokasi kode utama |
|---|---|---|
| 1. Simulator dasar | Selesai | `backend/app/Services/Trading/TradingService.php`, `frontend/src/components/OrderPanel.vue`, `CandleChart.vue` |
| 2. Teknikal & whale | Selesai | `backend/app/Services/Analysis/TechnicalAnalyzer.php`, `Indicators.php`, `MarketData/WhaleSyncService.php` |
| 3. Berita & sentimen AI | Selesai | `MarketData/NewsSyncService.php`, `Ai/NewsSentimentService.php`, `Ai/AiClient.php` |
| 4. On-chain & scoring | Selesai | `MarketData/TokenSecurityService.php`, `Analysis/OnchainAnalyzer.php`, `Analysis/SignalEngine.php`, `Analysis/SignalTracker.php` |
| Tambahan: discovery otomatis | Selesai | `Analysis/TokenDiscoveryService.php`, `MarketData/CoinGeckoClient.php`, `frontend/src/views/DiscoverView.vue` |

## 5. Keterbatasan yang perlu diketahui

- **Hanya posisi long (spot).** Sinyal Sell berarti "keluar/hindari", bukan short.
- **Pelacakan SL/TP memakai harga tiap 15 detik**, bukan setiap tick. Sumbu (wick) yang sangat cepat bisa terlewat, baik pada order simulasi maupun pelacakan sinyal.
- **Data whale GeckoTerminal** maksimal 300 transaksi terbaru per pool dalam 24 jam. Pool yang sangat ramai bisa terpotong.
- **Bobot skor adalah titik awal**, belum dioptimasi. Kumpulkan rekam jejak beberapa minggu, lalu sesuaikan bobot di `config/market.php` berdasarkan data.
- Kapasitas gratis GeckoTerminal cukup untuk sekitar **20 token**. Lebih dari itu perlu paket berbayar CoinGecko atau interval yang lebih jarang.

## 6. Saran pengembangan berikutnya

1. Notifikasi Telegram saat sinyal baru, TP tercapai, atau peringatan rug pull.
2. Laravel Reverb (websocket) menggantikan polling 5 detik di frontend.
3. Backtest: jalankan `SignalEngine` terhadap candle historis untuk menguji bobot sebelum dipakai live.
4. Data Binance (gratis) untuk koin besar (BTC/ETH/SOL), termasuk funding rate dan open interest sebagai indikator tambahan.
5. Setelah rekam jejak stabil, pertimbangkan mode "paper trading otomatis" yang mengikuti semua sinyal untuk mengukur ekuitas strategi.
