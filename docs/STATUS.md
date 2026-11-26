# Status DEX Simulator

Terakhir diperbarui: 26 November 2026. Perbarui file ini di commit yang sama setiap kali sebuah tugas selesai.

## Sekarang

- Berjalan di https://dexsim.tune-in.trade. Uang sungguhan belum ada: semua transaksi virtual.
- Server: VPSMurah kanaka-vps (4 core, 8 GB, 80 GB NVMe) dengan IP dedicated sejak 13 Nov, Docker, Cloudflare Tunnel. Masa sewa sampai 1 Mar 2027 (diperpanjang 3 bulan 24 Nov 2026). Detail di `docs/INFRASTRUKTUR.md` repo bersama.
- Produksi per 26 Nov 2026 23.50 WIB: backend 1ebf88f (Symfony 7.4.24, perbaikan keamanan routing, http-kernel, mailer, http-foundation; composer audit bersih, 495 tes lolos, diperiksa 23.50 WIB: container sehat, tanpa job gagal). Sebelumnya 23.01 WIB: backend 1f968bb, frontend aee13bd (strategi v8 `v8-fecdac`; Token Baru sejak 21.59 WIB). Diperiksa 23.14 WIB: container sehat, tanpa job gagal, analisa 23.11 sudah v8, sinyal v7 #246 dan #247 masih terbuka dengan exit v7, Token Baru 31 pool (9 dari discovery, 22 dari GeckoTerminal new_pools). Sebelumnya Produksi per 21 Nov 10.43 WIB: backend 4094ba1, frontend 8156671 (strategi v7 `v7-a7bb4e` dan ukuran posisi dengan biaya). Diperiksa 10.58 WIB: container sehat, tanpa error log, 20 analisa pertama sudah v7, laporan v6 terbaca (3,8 detik) dengan baris dan grup bayangan baru. Sebelumnya 18 Nov 14.28 WIB: backend 71ce2ec, frontend e956a73 (bayangan konfirmasi candle 15 menit: filter dan entry tertunda; diperiksa 14.30 WIB, container sehat, tanpa error log). Sebelumnya 18 Nov 11.59 WIB: backend 11e872a, frontend 26239ac (rincian per sinyal di catatan dan sinyal bayangan dengan BTC dan Fear & Greed saat entry; BTC sebelum 15 Nov dihitung ulang dari candle; bayangan jeda setelah stop 12/24/48 jam). Diperiksa 12.00 WIB: container sehat, laporan v6 1,7 detik, tanpa error log. Sebelumnya 15 Nov: bayangan Buy dengan Fear & Greed dan acuan BTC.
- Backup: Sejak 22 Nov 2026 backup luar VPS bisa ke Google Drive (`simulator-backup/<nama>`) dan R2 sekaligus; Drive terpasang 22 Nov 2026 18.57 WIB untuk semua aplikasi; backup manual 19.00 masuk Drive dan R2, uji restore dari Drive lolos (34/34 tabel). Langkah dan hasil di `docs/INFRASTRUKTUR.md` repo bersama.

- **26 Nov 2026: v8** (keputusan pemilik setelah review v7), lihat Strategi di atas dan [DECISIONS.md](DECISIONS.md). 495 tes lolos.
- **26 Nov 2026: menu Token Baru (permintaan pemilik, hanya pencatatan).** Pool di bawah 24 jam dicatat terpisah dari Market dengan candle 5 menit sendiri dan trade kertas strategi New Token Hunter (pembanding, A, B, C); tidak ada sinyal, order, atau auto-follow. Cara kerja di [ARCHITECTURE.md](ARCHITECTURE.md), alasan di [DECISIONS.md](DECISIONS.md). 491 tes lolos; migrasi dicoba di MySQL lokal; dijalankan lokal dengan API asli (12 pool tercatat dari 60, sampel dan cek GoPlus jalan).
- 26 Nov 2026: uji strategi New Token Hunter dari pemilik (`dex/new_token_hunter/`; hanya baca, `dex/review/new-token-hunter/hasil-26nov.md`). Data: 270 pair yang ditemukan discovery saat umurnya 5 menit-24 jam (sekarang ditolak `min_age_hours` 24), candle 5 menit GeckoTerminal, exit sesuai dokumen (TP +20/+40/+70%, stop -10%/-15%, maks 24 jam), biaya 2%. Early Liquidity +0,04R (n=90), Breakout -0,04R (n=112), Pullback +0,12R (n=61); semuanya negatif tanpa 3 trade terbaik, dan tidak lebih baik dari membeli setiap token saat pertama terlihat (+0,04R, n=230). Median tiap strategi -12% (stop), dan ditahan 24 jam mediannya -57% sampai -76%. Ikut beli whale pada pair < 24 jam juga negatif (n=34, PF 0,60); data whale untuk pair semuda itu hanya 7 token dan baru diambil sehari kemudian. Tidak diterapkan.
- 26 Nov 2026: uji holder Solana (hanya baca, `dex/review/holder-solana/hasil-26nov.md`): angka GoPlus cocok dengan node Solana, tetapi top 10 aplikasi ikut menghitung pool token itu sendiri (32 dari 36 token) dan vesting Magna. Tanpa pool dan vesting, keputusan batas 20% berubah dari ditahan ke lolos pada 7 dari 36 token. Ambang 20% dipilih dari data yang ikut menghitung pool. Uji ulang hari yang sama (`dex/review/holder-pool/hasil-26nov.md`, putar ulang 123 Strong Buy 8-25 Nov): 11 trade Solana yang akan lolos bila pool dibuang rata-rata -0,39R (menang 2 dari 11), lebih buruk dari yang lolos sekarang (-0,13R), jadi cara hitung tidak diubah. Aturan 20% tetap memisahkan di luar sampel pemilihannya (sejak 12 Nov, n=100: -0,28R lawan -0,60R). Penyedia di [DATA_PROVIDERS.md](DATA_PROVIDERS.md).
- 26 Nov 2026: toleransi cek silang harga ke GeckoTerminal turun dari 5% ke 3% (TAC 25 Nov terjual 4,9% di bawah candle; alasan di [DECISIONS.md](DECISIONS.md)). 480 tes lolos.
- 20 Nov 2026: konfirmasi harga kini butuh dua snapshot DEX Screener yang berbeda (data DEX Screener diperbarui sekitar tiap 30 detik per pair, diukur 19 Nov). Pengecekan yang membaca snapshot sama tidak menggeser harga sebelumnya. 452 tes lolos.

### Strategi: v8 (sejak 26 Nov 2026; versi lengkap di Laporan)

- Hanya Strong Buy yang membuat sinyal. Buy tetap dianalisa dan tampil sebagai pantauan (v5).
- Buy dan Strong Buy menjadi Hold bila 10 holder teratas memegang 20% supply atau lebih (v6).
- **v7:** sinyal yang sudah entry ditutup di analisa pertama yang tidak lagi Buy atau Strong Buy, dan Strong Buy dengan skor 77 atau lebih tidak membuat sinyal (diikuti sebagai sinyal bayangan `high_score`). Sinyal v6 yang masih terbuka (EVAA #208, UP #211, VVV #213) selesai dengan exit v6.
- **v8 (26 Nov 2026, setelah review v7):** Strong Buy hanya menjadi sinyal bila analisa sebelumnya (paling lama 20 menit) juga Buy atau Strong Buy; Strong Buy pertama diikuti sebagai sinyal bayangan `first_buy`. Sinyal v7 yang masih terbuka tetap keluar saat analisa turun.
- Auto-follow: maksimal 4 posisi, risiko 0,5% per posisi termasuk fee, gas, dan price impact beli dan jual (sejak 21 Nov), batas rugi harian 3%.
- Semua akun menjual posisinya otomatis saat analisa token menjadi Sell, Strong Sell, atau Hindari.
- Aturan lengkapnya di [DOMAIN_RULES.md](DOMAIN_RULES.md); alasan v5, v6, dan v7 di [DECISIONS.md](DECISIONS.md).
- Review v6 (21 Nov, 32 sinyal, `dex/review/hasil-21nov-v6-final.md`): -16,44R, PF 0,34, gagal kriteria rata-rata, total, dan PF. Dengan kedua aturan v7 -4,31R: masih negatif. Semua sinyal v6 masuk saat BTC 4 jam turun dan Fear & Greed 10-28.
- Bukti yang mendasarinya belum memenuhi kriteria Fase 2. Strong Buy historis +0,07R per sinyal (PF 1,14); dengan top 10 di bawah 20%, sinyal live +0,50R (n=25) dan putar ulang +0,09R (n=44). Ambang 20% dipilih dari data yang sama.
- Seluruh data sejak 31 Okt berasal dari pasar turun: BTC dari sekitar US$81 ribu ke US$64 ribu (29 Okt - 15 Nov). Label tren SOL dan ETH di konteks pasar tidak bisa dipakai untuk periode setelah 1 Nov (SOL) dan 3 Nov (ETH): candle acuan berhenti diperbarui karena keduanya tidak pernah di Market. Diperbaiki 15 Nov; sejak itu SOL, ETH, dan BTC ikut setiap putaran candle.

- **Trailing ATR di v7 (dicek 23 Nov 2026 sekitar 20.00 WIB):** +14,41R dari 9 sinyal, dibanding +6,07R sungguhan. Tapi baru 2 yang selesai (+0,90R); 7 masih terbuka (+13,51R, dinilai di close terakhir). ARIA menyumbang +11,6R lewat 4 sinyal yang tumpang-tindih, dan lima sinyal tumpang-tindih bernilai +5,90R. Sejak 7 Nov (100 sinyal): trailing ATR −32,89R dibanding −33,97R sungguhan, median per sinyal sama (−1,02R dan −1,03R). Belum ada bukti trailing ATR lebih baik. Laporan sejak 23 Nov memisahkan posisi yang masih terbuka dan menghitung satu posisi per token (lihat DOMAIN_RULES.md).

### Sedang diukur (hanya pencatatan, tidak mengubah trading)

| Apa | Sejak | Dinilai | Di mana |
|---|---|---|---|
| Sinyal v7 sungguhan, dibanding exit v6 pada salinan bayangannya | 21 Nov 2026 | Setelah sekitar 30 sinyal, dan saat pasar berbalik (`docs/UJI_ULANG_PASAR_NAIK.md`) | Laporan, versi `v7-…`; Catatan bayangan → Tahan sampai target, stop, atau 72 jam seperti v6 |
| Strong Buy skor 77+ yang tidak jadi sinyal (`high_score`) | 21 Nov 2026 | Bersama review v7 | Laporan → Sinyal bayangan |
| Strong Buy yang ditahan aturan holder, dibanding sinyal asli (`shadow_signals`) | 12 Nov sekitar 14.00 WIB | Bersama review v6 | Laporan → Uji aturan holder v6 |
| Exit first_check, dua kali tidak Buy, entry terkonfirmasi | 7 Nov | Bersama review v6 | Laporan → Catatan bayangan |
| Konfirmasi candle 1 jam (body solid, close di atas high sebelumnya), dengan dan tanpa volume ≥ 1,5x rata-rata 20 jam | 16 Nov (dihitung sejak 7 Nov dari candle tersimpan) | Bersama review v6, lalu diuji ulang saat pasar naik | Laporan → Catatan bayangan |
| Konfirmasi candle 15 menit (aturan sama dengan 1 jam). A: hanya entry kalau candle 15 menit terakhir sebelum entry konfirmasi. B: tunggu candle 15 menit konfirmasi sampai batas entry 12 jam, entry di close-nya, lalu diikuti seperti sinyal bayangan; kena stop lebih dulu berarti tidak dibuka (pemilik 18 Nov, dari uji AIA/ARIA) | 18 Nov (dihitung sejak 7 Nov dari candle tersimpan) | Bersama review v6 | Laporan → Catatan bayangan |
| Exit trailing ATR 3x candle 4 jam sejak entry, tanpa target, paling lama 30 hari (batas sejak 17 Nov) | 16 Nov (dihitung sejak 7 Nov dari candle tersimpan) | Bersama review v6 | Laporan → Catatan bayangan |
| Candle token bersinyal di luar Market tiap 2 jam (sebelumnya ±9 jam), supaya bayangan setelah sinyal tidak tertinggal | 16 Nov | Pantau beban di Admin → Token | Admin → Token |
| Jadwal antrean diratakan (token keluar :09/:24/:39/:54, discover :12/:42) dan deploy menunggu celah sepi | 16 Nov | Diukur 16 Nov 12.09-13.02 WIB: puncak 43 job (hanya siklus utama, sebelumnya 63), tunggu candle median 76 dtk maks 4,2 mnt (sebelumnya 4,2 / 12,4 mnt), discover menunggu 2 dtk (sebelumnya 7,6 mnt); menit 27-29 dan 57-59 kosong | Horizon, `app:deploy-window` |
| Jeda setelah stop: lewati sinyal baru pada token yang kena stop dalam 12, 24, atau 48 jam terakhir (pemilik 18 Nov, setelah AIA dan ARIA entry lagi 1,5-2 jam setelah stop) | 18 Nov (dihitung sejak 7 Nov dari sinyal tersimpan) | Bersama review v6 | Laporan → Catatan bayangan |
| Lewati pair berumur di bawah 14 hari (dari studi dump Sue, `dex/review/hasil-13nov-dump.md`) | 13 Nov 23.00 WIB | Bersama review v6 | Laporan → Catatan bayangan |
| Hubungan dengan BTC per analisa (korelasi, beta, kekuatan relatif 24 jam) dan BTC di konteks pasar. A: lewati token yang ikut BTC (korelasi ≥ 0,3 dan ≥ 0,1) saat tren 4 jam BTC tidak naik. B: hanya token yang ikut BTC saat tren 4 jam BTC naik (studi `dex/review/hasil-15nov-btc.md`) | 15 Nov 19.00 WIB | A bersama review v6; B butuh fase BTC naik | Laporan → Catatan bayangan; token → Gerak terhadap BTC; Dashboard → Acuan pasar |
| Setiap Buy sebagai sinyal bayangan (`buy_watch`), dengan Fear & Greed; di Laporan dipisah menurut tren 4 jam BTC. Untuk usulan pemilik: Buy + Strong Buy saat BTC naik, hanya Strong Buy saat turun | 15 Nov sekitar 16.30 WIB | Saat pemicu di `docs/UJI_ULANG_PASAR_NAIK.md` repo bersama terpenuhi, atau sekitar 30 trade saat BTC naik | Laporan → Sinyal bayangan |
| Token Baru: pool di bawah 24 jam dan trade kertas New Token Hunter (pembanding, A Early Liquidity, B Breakout, C Pullback) | 26 Nov 2026 | Sekitar 200 trade per strategi, atau saat pasar berbalik (`docs/UJI_ULANG_PASAR_NAIK.md`) | Menu Token Baru |
| Sumber discovery per putaran, termasuk Jupiter (`discovery_sightings`) | 12 Nov 11.25 WIB | Minggu depan: apakah token yang disepakati banyak sumber lebih baik | Belum ada halaman |
| Daftar trader paling untung Birdeye, Solana dan BSC (`trader_rankings`) | 12 Nov 13.37 WIB | **Dinilai 26 Nov: tidak lolos** (lihat ROADMAP Fase R, `dex/review/birdeye/hasil-26nov.md`); pencatatan harian dihentikan 26 Nov (pemilik), data 12-26 Nov disimpan | Belum ada halaman |

### AI

- Urutan provider: Gemini → Gemini model kedua (Gemma 4) → Groq (gpt-oss-120b) → Groq model kedua (gpt-oss-20b), diatur pemilik di panel.
- Ringkasan AI untuk Buy, Strong Buy, dan token yang sedang dipegang atau punya order menunggu. 20% kuota harian tiap provider disisakan untuk sentimen berita.
- Pemakaian 24 jam sampai 12 Nov 15.15 WIB: sekitar 210 permintaan (186 ringkasan unik, sekitar 22 batch sentimen), jauh di bawah kapasitas rantai sekitar 1.700 per hari.

- **Koin kena TP (19 Nov 2026, permintaan pemilik, di `dev`):** halaman `/tp-coins` dan `GET /api/reports/tp-coins` (`TpCoins`). Token yang sinyalnya pernah mencapai TP1 (semua versi), per sinyal pola, entry, jarak, kondisi BTC, dan volume dari analisa saat sinyal dibuat, waktu ke TP1 dan turun terdalam dari candle 15 menit, plus median kena TP1 vs tidak kena. Hanya membaca; trading v6 tidak berubah. Cek 196 sinyal selesai: sinyal berikutnya setelah TP -0,23R (70), setelah meleset -0,47R (42), sinyal pertama -0,37R (84), jadi bukan filter.

**Tersambung ke Akun tune-in.trade sejak 24 Nov 2026 10.43 WIB, cara masuk `both`** (tombol "Masuk lewat tune-in.trade" plus login lama; `ACCOUNT_INTERNAL_URL=https://tune-in.trade`, alasan di `account/backend/docs/DECISIONS.md`). Akun teman (bukan akun sistem) tidak tersambung dan tetap bisa memakai login lama selama `both`; akun sistem strategi tidak pernah login. Login pemilik lewat Akun berhasil 24 Nov 10.48 WIB (user lama terikat lewat email, tetap admin). Kembali ke login lama: `php artisan app:auth-mode local`. Sebelumnya: login lewat Akun (tahap 2) di produksi sejak 23 Nov 2026, cara masuk `local` (dicek setelah deploy: migrasi jalan, `/auth/redirect` kembali ke `/login`, log bersih). Lihat [ARCHITECTURE.md](ARCHITECTURE.md). Cara masuk tetap `local` sampai pemilik menyambungkan aplikasi ini di tahap 4 (`account:connect-app dex`, isi `.env`, lalu pilih `both`). Diuji dengan 17 tes Pest baru, dan migrasi `account_id` dicoba di MySQL 8.4.

**Sidebar penuh/mini/sembunyi dan tinggi grafik bisa ditarik (24 Nov 2026, pemilik):** tombol di kiri toolbar desktop; dalam mode mini atau sembunyi, ekuitas tampil di toolbar. Tinggi kartu grafik ditarik dari garis di bawah grafik. Keduanya tersimpan di browser. Diuji di Chrome headless dengan layout asli.

**Grafik candle dengan indikator dan alat gambar (24 Nov 2026, pemilik):** halaman token memakai KLineChart: 27 indikator (menu Indikator, parameter bisa diubah), 15 alat gambar (garis tren, ray, horizontal, vertikal, channel, Fibonacci, kotak, lingkaran, kuas, teks, catatan), magnet, hapus terpilih atau semua; garis entry/SL/TP dan panah lot tetap. Indikator dan gambar tersimpan di browser (gambar per token). Diuji di Chrome headless dengan harga token kecil. Lihat [ARCHITECTURE.md](ARCHITECTURE.md).

**Pemicu pasar berbalik (24 Nov 2026, permintaan pemilik, di `dev`):** `app:market-turn-check` tiap hari 01.05 UTC menghitung keempat pemicu `docs/UJI_ULANG_PASAR_NAIK.md` repo bersama dan mengirim Telegram ke admin saat ada yang baru terpenuhi. Hanya membaca; trading tidak berubah. Lihat [ARCHITECTURE.md](ARCHITECTURE.md). Diuji dengan 3 tes Pest (`MarketTurnTest`).

**Perbaikan 24 Nov 2026:** setiap `throttle` di route punya prefix sendiri (sebelumnya semua pembatas seorang user atau IP tamu berbagi satu counter, sehingga misalnya beberapa permintaan di satu fitur bisa membuat ganti password atau 2FA ditolak 429; tes regresi `ThrottleTest`), dan bawaan `ACCOUNT_INTERNAL_URL` menjadi `https://tune-in.trade`.

## Langkah berikutnya

1. Pantau sinyal v7 pertama: penutupan saat analisa turun, pesan Telegram, dan lot auto-follow yang ikut ditutup. Cek rugi saat stop pada lot dengan ukuran baru (target 1,0x risiko, kecuali gap). **25 Nov 2026 16.30 WIB:** belum ada lot v7 yang kena stop (16 lot ditutup, semuanya karena analisa turun). Sebagai gantinya dihitung rugi seandainya tiap lot dijual utuh di stop-nya, dengan harga fill, fee, gas, dan dampak harga seperti `TradingService` (likuiditas saat ini): 53 lot di lima akun auto-follow (akun `buy` tidak punya lot karena v7 hanya Strong Buy), rasio rugi/target 0,89-1,00, median 0,99, paling tinggi 1,001 (dulu median 1,10). Rasio di bawah 1 muncul saat fill lebih murah dari `entry_high` yang dipakai untuk menghitung ukuran (IDOL #216 0,89, SI #226 0,95). Gap di bawah stop tetap tidak tertangkap; cek ulang saat lot pertama benar-benar kena stop (bagian 6 `dex/review/v7-review.sh`). Skrip `dex/review/v7-stop-check.php` (tidak di git). **25 Nov 2026 22.36 WIB (`dex/review/hasil-v7-review-25nov.md`):** lot v7 pertama kena stop (ARIA #232, -1,07R sinyal); di akun `all`, `strong_buy`, dan `bsc` rugi/target tepat 1,00, jadi ukuran dengan biaya bekerja seperti rencana. Sinyal v7 selesai 20 dari 30 (40% menang, +0,06R per sinyal, total +1,19R, PF 1,23); tanpa IDOL -0,11R per sinyal, dan 12 sinyal terakhir sebagian besar rugi kecil.
2. S8 (beli setelah turun > 15% dalam 1 jam): uji ulang 21 Nov negatif pada data baru (ROADMAP Fase 2B); ditunda pemilik 21 Nov sampai sekitar 200 kejadian sejak token ditemukan (21 Nov: 37).

## Menunggu

| Kapan | Yang dicek |
|---|---|
| 13 Nov pagi | Sentimen berita tidak lagi jatuh ke kamus kata (24 berita pada 11-12 Nov, sebelum perbaikan penghitung Gemini); hasil Birdeye hari kedua |
| Sekitar 30 sinyal v8 | Review v8: sinyal v8 dibanding bayangan `first_buy`, hasil per jaringan (calon filter: tanpa Solana). Dasar keputusan v8 dari review v7 (bahan 26 Nov 2026 22.00 WIB, `dex/review/hasil-v7-review-26nov.md`): 31 sinyal selesai, +0,09R per sinyal (target > +0,2R, tidak lolos), total +2,85R, PF 1,47, drawdown 7,37%; tanpa IDOL -0,01R per sinyal, tanpa 3 sinyal terbaik -1,58R; 10 token, semuanya saat BTC 4 jam turun dan Fear & Greed 11-13. Rugi saat stop dengan ukuran baru tepat 1,00x target. Dua bayangan membaik di v6 dan lagi di v7: entry hanya bila analisa sebelumnya juga Buy (+1,12R, v6 +11,36R) dan lewati token ikut BTC (korelasi ≥ 0,1) saat BTC tidak naik (+0,90R, v6 +8,45R). Pemilik memilih v8 = aturan pertama saja, tanpa filter jaringan (26 Nov). |
| Sekitar Feb 2027, atau saat riwayat candle 4 jam >= 6 bulan untuk >= 100 token | Uji ulang strategi swing Fibonacci 4 jam (aturan akun FutureSim `swing_fib_4h`, hanya long) di token DEX. Uji 20 Nov: 30 trade dari 140 token (riwayat 4 jam median 55 hari), tanpa biaya +0,03R, dengan biaya DEX -0,10R; terlalu sedikit untuk disimpulkan (pemilik: simpan untuk nanti). Bisa dipercepat dengan mengunduh riwayat GeckoTerminal dari laptop (sekitar 280 permintaan). Bahan: `dex/review/hasil-20nov-swing-fib.md`, `swing-fib-dex.py` |
| 28 Des | Data whale sendiri mencapai 60 hari; uji wallet diulang tanpa wallet yang ditandai bot (`dex/review/whale-btc.py`), termasuk perbandingan saat tren BTC naik dan turun |

## Menunggu pemilik

- Key Mistral (opsional; rantai AI sekarang sudah cukup).

## Belum terbukti

- Strategi mana pun di pasar yang tidak bearish.
- Ambang holder 20% di luar sampel.
- Halaman Laporan untuk tabel uji aturan holder belum dilihat dengan data asli.
- Jual otomatis dengan menunggu cek silang belum pernah terjadi di produksi. Penjualan pertama (12 Nov 20.56 WIB, AMDc dan FLOAT) memakai versi sebelumnya.
