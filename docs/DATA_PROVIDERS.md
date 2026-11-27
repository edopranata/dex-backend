# Penyedia data DEX Simulator

Kuota, lisensi, dan hasil uji, masing-masing dengan tanggal pengecekannya. Angka paket bisa berubah, jadi periksa ulang sebelum mengandalkannya. Key milik pemilik diisi lewat `.env` atau Admin → Pengaturan, tidak pernah lewat chat atau git. Halaman Admin → General Information menampilkan status tiap layanan.

## Data pasar (dipakai)

| Provider | Peran | Kuota dan catatan |
|---|---|---|
| DEX Screener | Harga real-time, pair, likuiditas, volume, transaksi beli/jual, boost | Gratis tanpa akun, 300 permintaan per menit. Harga hanya 4 digit bermakna. Data tiap pair diperbarui sekitar tiap 30 detik (diukur dari VPS 19 Nov 2026, 3 pair Base/Solana/BSC, 2 menit: jeda 27-32 detik), jadi menarik lebih sering dari 15 detik tidak memberi harga baru, dan dua pengecekan berurutan sering membaca snapshot yang sama. WebSocket resminya (`wss://api.dexscreener.com`) hanya memuat profil, boost, takeover, dan iklan, tanpa harga pair (dicek 19 Nov 2026). Feed bisa menyimpang dari candle: FLOAT 5-14% di atas candle (audit 9-10 Nov), STANK isi 12% di bawah candle (9 Nov), sebabnya harga dicek silang ke GeckoTerminal sejak 10 Nov. Tidak punya candle atau daftar transaksi per wallet. Token Baru (26 Nov) menambah 1 permintaan per 30 pool muda per jaringan tiap menit (150 pool paling banyak, sekitar 5-8 per menit) |
| GeckoTerminal | Candle 15m/1h/4h/1d, transaksi whale, trending pool | Gratis, dibatasi per IP. Resminya 30 per menit, tetapi uji 29 Okt diblokir (HTTP 429) setelah sekitar 11 permintaan, jadi aplikasi memakai 10 per menit (`GECKOTERMINAL_RPM`). IP keluar VPS sejak 13 Nov adalah IP dedicated. Ini hambatan utama: 69% terpakai pada 11 Nov 13.11-14.10 WIB, dan job menunggu 2-5,6 menit. Token Baru (26 Nov) menambah `new_pools` 3 permintaan per 30 menit |
| GoPlus Security | Cek keamanan: honeypot, pajak, mint/freeze, top holder, LP terkunci, jumlah holder | Gratis tanpa akun. Satu cek per 10 detik. Pernah memberi porsi holder di atas 100% (TROLL, 10 Nov; diabaikan sejak 11 Nov) dan tidak menjawab untuk Solana (11 Nov 10.36-10.47 WIB). Di Solana, top 10 holder bisa ikut menghitung vault pool dan bursa. Uji 26 Nov 2026 (36 token Solana, `dex/review/holder-solana/hasil-26nov.md`): saldo 10 holder GoPlus sama dengan node Solana (beda <= 1 poin pada 30 token, paling jauh 3,0) dan supply sama; di Solana GoPlus tidak memberi `is_contract` dan tidak menandai vesting Magna sebagai terkunci |
| CoinGecko | Trending pencarian untuk discovery | Gratis; Demo API key opsional |
| Jupiter Tokens API v2 | Daftar top trending dan top organic score (Solana) untuk discovery, hanya dicatat sejak 12 Nov | `api.jup.ag`, tanpa key sekitar 30 permintaan per menit (dicek 11 Nov); dengan key Free 60 per menit. `lite-api.jup.ag` sedang dihentikan. Dipakai 2 permintaan per putaran discovery. Rencana Fase 4: penawaran swap, 1 permintaan per detik dengan key Free. Quote `api.jup.ag/swap/v1/quote` menjawab tanpa key (26 Nov 2026) |
| Birdeye Data Services | Daftar trader paling untung (Solana, BSC), sekali sehari sejak 12 Nov | Paket Standard gratis tanpa syarat saldo: 30.000 unit per bulan, 1 permintaan per detik; halaman key akun pemilik menyebut 60 per menit. `GET /trader/gainers-losers` 30 unit; 6 permintaan per hari ≈ 5.400 unit per bulan. Syarat layanan klausul 10.2 melarang membagikan, menjual, atau menampilkan data ke pihak lain (dan secara harfiah juga menyimpan, sementara FAQ resminya menyarankan menyimpan snapshot): datanya hanya untuk pemilik (dicek 12 Nov) |
| Fear & Greed Index (alternative.me) | Sentimen pasar umum | Gratis tanpa akun |
| RSS CoinDesk, Cointelegraph, Decrypt, The Block | Berita untuk sentimen | Gratis tanpa akun |

## AI (dipakai)

Batas dan urutannya diatur di Admin → Pengaturan → AI. Tiap provider punya batas per menit dan per hari di aplikasi; 20% batas harian disisakan untuk sentimen berita.

| Provider | Catatan |
|---|---|
| Google Gemini (`gemini-flash-lite-latest`) | AI Studio 11 Nov: Flash Lite 15 per menit, 500 per hari, dihitung ulang tengah malam Pasifik (15.00 WIB). Aplikasi: 12 per menit, 450 per hari |
| Gemini model kedua (`gemma-4-26b-a4b-it`) | Key Gemini yang sama, kuota per model. AI Studio 11 Nov: 30 per menit, 16K token per menit, 14.400 per hari. Uji 11 Nov: 31B menjawab 503; 26B 1,5 detik dengan thinking minimal |
| Groq (`openai/gpt-oss-120b`) dan Groq model kedua (`openai/gpt-oss-20b`) | Kuota per model (11 Nov): 30 per menit, 1.000 per hari, 8K token per menit, 200K token per hari; token yang lebih dulu habis. Uji 11 Nov dengan prompt produksi: sentimen 20 berita ±1.600 token, ringkasan ±700. Aplikasi: 4 per menit, 120 per hari per slot |
| OpenRouter (`openrouter/free`) | Tanpa kredit: 20 per menit, 50 per hari, dihitung ulang 07.00 WIB. Tidak di rantai sejak 12 Nov |
| Mistral (`mistral-small-latest`) | Paket gratis Experiment, sekitar 1 permintaan per detik. Key belum diisi |
| xAI, OpenAI | Berbayar per token, opsional, tidak dipakai |
| VPSMurah AI Service (`my.vpsmurah.co.id/api/v1`, kompatibel OpenAI) | Kredit prabayar, 1 kredit = Rp 10; menurut panel Rp 50.000 ≈ 15 juta token dengan model hemat (13 Nov). Belum di rantai; pemilik hanya menyiapkan anggaran (13 Nov) |

Pemakaian token tidak dicatat aplikasi. Perkiraan dari data produksi 24 jam sampai 13 Nov 10.10 WIB: 143 ringkasan unik (prompt ±2.400 karakter, jawaban ±520 karakter) dan 86 berita, sekitar 210-260 ribu token per hari atau 7-8 juta per bulan. Dengan harga VPSMurah di atas sekitar Rp 25.000-30.000 per bulan.

## Lainnya (dipakai)

| Layanan | Catatan |
|---|---|
| Telegram Bot API | Notifikasi per pengguna dan operasional |
| Resend (`mail.tune-in.trade`) | SMTP gratis 3.000 email per bulan (30 Okt) |
| Cloudflare R2 (`dex-bucket`) | Salinan backup harian, uji restore lolos 30 Okt |
| UptimeRobot, Healthchecks.io | Pemantauan dari luar VPS |

## Dicek, tidak dipakai

| Provider | Alasan dan tanggal |
|---|---|
| GMGN OpenAPI | Label smart money dan KOL. Paket Free mensyaratkan saldo $100 di akun GMGN (terlihat di halaman pembuatan key, 12 Nov; tidak tertulis di dokumentasinya). Free 5/5, Plus 20/20, Pro 50/50 (leaky bucket); feed smart money berbobot 1, sampai 200 transaksi per permintaan, tanpa rentang waktu. Syarat penyimpanan data tidak ditemukan |
| Helius, Moralis, Dune | Dicek 12 Nov sebagai sumber wallet tambahan. Helius gratis 1 juta kredit per bulan. Moralis dan Dune: sumber resminya saling bertentangan soal paket gratis. Belum dipakai. Helius dicek ulang 26 Nov: Free 1 juta kredit, 10 RPC per detik, 2 per detik untuk DAS dan Enhanced, tanpa kartu kredit; syarat layanan (Cloud Services Agreement 28 Sep 2026, pasal 3.2) melarang pemakaian "personal, household, or familial" |
| RPC publik Solana tanpa key | Uji 26 Nov 2026: `getTokenLargestAccounts` ditolak di api.mainnet-beta.solana.com (429 di setiap percobaan), PublicNode (minta token pribadi), dRPC (paket gratis tidak mencakup Solana), dan Ankr (minta key). `getTokenSupply` dan `getMultipleAccounts` masih jalan di api.mainnet-beta |
| RugCheck (Solana) | Dicek 26 Nov 2026: `GET api.rugcheck.xyz/v1/tokens/{mint}/report/summary` menjawab tanpa key (skor, risiko, LP terkunci). Batas permintaan tidak disebut di spesifikasi API; syarat layanan tidak terbaca karena halamannya dirender di browser. Belum dipakai |
| Claude API (Anthropic) | Uji lokal 27 Nov 2026 dengan prompt dan input produksi (`dex/review/claude-test/`, di luar git; key pemilik di `.env` folder itu): sentimen 380 berita berkorelasi 0,86 (Haiku 5.5) dan 0,85 (Opus 5.5) dengan nilai produksi, rata-rata bergeser -0,7 / +0,8, sehingga skor akhir hanya bergeser sekitar 0,05 poin; ringkasan Claude lebih lengkap dan angkanya cocok dengan data. Biaya uji Haiku US$0,015, Opus US$0,66; perkiraan untuk semua ringkasan Haiku sekitar US$10-20 dan Opus US$500-800 per bulan. Tidak dipasang: tidak mengubah hasil trading, dan Gemini/Groq/Gemma gratis dalam kuota |
| NVIDIA API Catalog | Endpoint gratis ditujukan untuk uji coba, tanpa batas harian yang jelas (12 Nov) |
| CryptoPanic | Endpoint gratis sudah dihapus (29 Okt) |
| TAAPI.io | Token DEX kecil umumnya tidak tersedia; indikator dihitung sendiri (29 Okt) |
| Etherscan, Solscan | Data holder berbayar; diganti GoPlus (29 Okt). Etherscan API V2 dicek 26 Nov 2026: paket Free 3 permintaan per detik, 100.000 per hari, hanya pemakaian pribadi dan riset; Ethereum termasuk, BNB Chain (56) dan Base (8453) hanya paket berbayar; daftar holder, jumlah holder, dan info token tetap PRO (Standard ke atas); menyimpan data melewati masa simpan yang tidak disebutkan butuh lisensi terpisah |

Penilaian awal penyedia (29 Okt) tersimpan di [archive/ANALISA_DOKUMEN.md](archive/ANALISA_DOKUMEN.md).

## Acuan pasar (15 Nov 2026)

BTC diambil dari pool cbBTC/USDC di Base (Aerodrome, `0x42609aa1…`, likuiditas sekitar US$5,8 juta, volume sekitar US$90 juta per hari, pool dibuat 22 Okt 2026) lewat GeckoTerminal, bersama SOL dan WETH. Ketiganya menambah satu permintaan per token per putaran 15 menit. Harga ini harga DEX, tidak dicampur dengan harga bursa terpusat. Alamatnya di `config/market.php` (`benchmarks`). Binance archive hanya dipakai untuk riset, karena terbit sehari kemudian.
