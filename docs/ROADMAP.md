# Roadmap DEX Simulator

Dokumen kerja untuk 2 bulan ke depan (**30 Oktober – 24 Desember 2026**). Server tetap berjalan selama pengembangan; setiap fase punya **kriteria lolos** yang harus terpenuhi sebelum fase berikutnya yang menyangkut uang sungguhan dimulai.

**Cara memakai dokumen ini**

- Centang `[x]` item yang selesai dan isi tanggalnya; commit perubahan dokumen ini bersama kodenya.
- Setiap **Senin** lakukan review mingguan (bagian 4) dan catat hasilnya di bagian 6.
- Jika kriteria lolos tidak terpenuhi, **fase diperpanjang**, bukan dilewati. Tanggal adalah target, bukan kewajiban.

---

## 1. Status saat ini (31 Oktober 2026)

| Area | Status |
|---|---|
| Aplikasi | Simulator, analisis & sinyal (entry/SL/TP), discovery + Market otomatis, whale tracker, berita, panel Admin |
| Server | VPS NAT vpsindonesia (3 vCPU / 6 GB), Docker, akses **`https://dexsim.tune-in.trade`** (Cloudflare Tunnel) — **aktif s/d 29 Nov 2026** |
| Deploy | Push ke `main` (dex-backend / dex-frontend) → GitHub Actions (tes/build) → deploy otomatis |
| Keandalan | Auto-start saat boot (`dex.service`), watchdog `/up` tiap 2 menit, restart container otomatis |
| AI | Gemini utama, Groq cadangan; xAI dan OpenAI opsional |
| Backup | Cron harian 02:30 WIB (14 hari), download & backup manual dari Admin (jeda 60 menit) |
| Pemantauan | UptimeRobot, Healthchecks.io, dan status container di Admin → Container |
| Backup ke luar VPS | Salinan harian ke Cloudflare R2, uji restore lolos |
| Uang sungguhan | **Belum ada.** Semua transaksi masih virtual. |

---

## 2. Timeline

```
Minggu      1        2        3        4        5        6        7        8
          30 Okt   6 Nov   13 Nov   20 Nov   27 Nov   4 Des   11 Des   18 Des → 24 Des
Fase 1    ████████
Fase 2    ██████████████████████████████████████  (pengumpulan data sinyal berjalan terus)
Fase 3                      ████████████████
Fase 4                                          ████████████████
Go/No-Go                                                       ◆ paling cepat 10 Des
Fase 5                                                          ████████████████
Fase 6                                                                          → Jan 2027
```

| Fase | Periode | Fokus | Uang sungguhan |
|---|---|---|---|
| 0 | selesai 29 Okt | Fondasi aplikasi & server | Tidak |
| 1 | 30 Okt – 5 Nov | Stabil & terpantau | Tidak |
| 2 | 30 Okt – 30 Nov | Validasi strategi (backtest + rekam jejak) | Tidak |
| 3 | 13 – 26 Nov | Keamanan produksi | Tidak |
| 4 | Setelah ada strategi kandidat | Eksekusi bayangan: biaya swap nyata tanpa transaksi | Tidak |
| — | **10 Des** | **Go/No-Go** | Keputusan |
| 5 | Paling cepat setelah Go/No-Go | Semi-otomatis lewat wallet Solana, tombol Beli di Telegram | **Ya, modal kecil** |
| 6 | Setelah Fase 5 stabil | Otomatis penuh dengan circuit breaker, lalu EVM | Ya |
| R | Setelah review v5 (12-14 Nov), berjalan paralel | Radar Whale: token baru yang dibeli whale, Market dan akun simulasi sendiri | Tidak |

Keputusan penting di tengah jalan: **perpanjang VPS sebelum 29 Nov 2026** (lihat Fase 3).

---

## 3. Detail fase

### Fase 0 — Fondasi ✅ (selesai 29 Okt 2026)

- [x] Simulator trading (fee, slippage AMM, limit/SL/TP, ikuti sinyal)
- [x] Analisis 5 komponen + rekomendasi + entry/SL/TP + rekam jejak sinyal
- [x] Discovery (trending + whale) dan Market yang mengelola diri sendiri
- [x] Panel Admin: pengaturan, pengguna, backup, log, tugas terjadwal, sistem
- [x] Docker + CI/CD GitHub Actions + VPS + backup harian
- [x] Auto-start saat boot + watchdog

### Fase 1 — Stabil & terpantau (30 Okt – 5 Nov)

Tujuan: server berjalan tanpa diawasi, dan Anda **tahu** saat ada masalah.

- [ ] Rotate Gemini API key lama, isi key baru di Admin → Pengaturan → AI, klik *Tes koneksi AI*
- [x] Uji pemulihan: reboot VPS sekali, pastikan semua layanan kembali sendiri — **29 Okt 2026: online lagi ±60 detik setelah reboot, semua container, scheduler & queue pulih; watchdog memulihkan app yang dihentikan dalam ±22 detik**
- [x] **Notifikasi Telegram per pengguna**: sinyal Buy baru, TP/SL tercapai, peringatan rug pull, token masuk/keluar Market, ringkasan harian, jam tenang — **29 Okt 2026: admin memasang bot (webhook otomatis); tiap pengguna menautkan chat dengan kode unik di Profil → Telegram dan memilih notifikasinya sendiri**
- [x] Notifikasi Telegram untuk operasional: watchdog memulihkan layanan, backup gagal, job/jadwal gagal, AI dijeda lama
- [x] Keamanan akun: 2FA (TOTP + kode pemulihan) yang diaktifkan sendiri oleh pengguna; admin bisa me-reset 2FA pengguna yang terkunci
- [x] **Email ke pengguna baru** lewat Resend (`mail.tune-in.trade`, SMTP gratis 3.000/bulan) — **30 Okt 2026: tanpa pendaftaran publik; admin membuat akun dan password sementara (berlaku 72 jam, wajib diganti saat login pertama) dikirim ke email. Lupa password, reset 2FA lewat email (setelah password benar), dan notifikasi keamanan (password diganti, 2FA dimatikan) memakai template email bertema aplikasi.; email tes diterima di inbox, DKIM/SPF/DMARC aktif**
- [x] Monitor uptime eksternal: UptimeRobot ke `/health` tiap 5 menit (database, Redis, scheduler) + Healthchecks.io `dex-heartbeat` (5 menit, grace 10 menit; ikut melapor Horizon) & `dex-backup` (harian, grace 2 jam), semua ke Telegram — **30 Okt 2026: semua aktif; status tampil di Admin → General Information**
- [x] Salinan backup ke luar VPS (Cloudflare R2), uji restore sekali — **30 Okt 2026: upload harian ke bucket R2 `dex-bucket` (`deploy/backup.sh`): dump 2,9 MB ±1,9 dtk + upload ±1,1 dtk; uji restore dari R2 ke database sementara (`deploy/restore-check.sh`) LOLOS dalam 6,5 dtk (26/26 tabel, 29/29 migrasi). CPU puncak ±65% selama <2 dtk, RAM +80 MB; status di Admin → Backup**
- [x] **Ukur hasil dengan benar** — **31 Okt 2026: hasil sinyal memakai harga keluar sebenarnya, R bersih setelah fee & slippage, versi strategi, dan kondisi pasar (Fear & Greed, tren SOL/ETH, breadth) tersimpan di tiap analisis. Sinyal lama menjadi pembanding `v0`**

**Kriteria lolos:** 7 hari berturut-turut tanpa downtime yang tidak terdeteksi; backup harian ada dan pernah diuji restore.

### Fase 2 — Validasi strategi (30 Okt – 30 Nov)

Tujuan: membuktikan dengan data apakah sinyal layak dipakai dengan uang sungguhan.

Aturan kerja: satu perubahan strategi per versi, dipertahankan hanya jika hasil bersihnya membaik. Sumber data tetap gratis (GeckoTerminal, DEX Screener, GoPlus, Jupiter).

**2A. Pembanding**
- [x] **Backtest**: jalankan mesin analisis pada candle historis (1h/4h, 90 hari), hasilkan win rate, rata-rata R bersih, profit factor, drawdown. Jika SL dan TP kena di candle yang sama, hitung SL. Periode pengembangan dan pengujian dipisah — **31 Okt 2026: `market:backfill-candles` + `market:backtest`. Strategi awal: pengembangan -0,04R (PF 0,93), pengujian -0,21R (PF 0,69), drawdown 107R. Hanya aturan teknikal yang teruji; token mati belum tercakup**

**2B. Penyaring entry** (dampak terbesar menurut data awal)
- [x] Gerbang tren 4 jam — **31 Okt 2026: diuji, tidak dipakai. Temuan dari 20 sinyal live tidak terbukti pada ratusan trade (pengujian -0,27R)**
- [x] Tolak entry saat harga sudah terlalu jauh naik (`EXTENDED_MOVE`). Batas RSI 55/65 sudah diuji dan tidak berpengaruh — **31 Okt 2026: diuji, belum dipakai. Maks. 1 ATR di atas EMA20: pengembangan +0,13R, pengujian -0,08R. Maks. 2 ATR lebih buruk (pengujian -0,18R)**
- [x] Jarak SL minimum 4% (strategi `v2`) — **31 Okt 2026: pengembangan +0,10R (PF 1,21), pengujian -0,12R (PF 0,81), drawdown 41R. TP 1R/2R/3R diuji dan lebih buruk**
- [x] Setup entry breakout/pullback dengan hasil `NO_TRADE` jika setup tidak terpenuhi — **31 Okt 2026: diuji, belum dipakai. Breakout 24 jam: -0,01R / -0,11R. Pullback 0,25 ATR: +0,11R / -0,05R. Volume 1,5x: +0,15R / -0,14R. Semua pengujian masih negatif dan selisihnya masih dalam batas noise**

**2C. Kondisi pasar**
- [ ] Deteksi regime (bullish, bearish, sideways, volatil) dari data yang sudah direkam — **31 Okt 2026: aturan sederhana tren 4 jam SOL/ETH diuji, belum konsisten (tolak saat bearish: pengujian -0,24R; hanya saat bullish: pengembangan +0,01R, pengujian -0,03R dari 44 trade). Belum dipakai; perlu data di lebih banyak kondisi pasar**

**2D. Risiko & kelayakan keluar**
- [ ] Status `PASS / REJECTED / REVIEW / INSUFFICIENT_DATA`; data kosong tidak lagi bernilai 50
- [ ] Cek kemampuan keluar posisi (Jupiter untuk Solana, rumus AMM untuk EVM)

**2E. Kualitas volume & bobot**
- [ ] Tekanan beli per periode, pembeli unik, rasio volume terhadap likuiditas
- [x] Halaman laporan performa mingguan (sinyal, R per minggu, per token, per rekomendasi) — **31 Okt 2026: menu Laporan, dengan kriteria Fase 2 yang dihitung otomatis per versi strategi**
- [x] Aturan risiko di simulator: ukuran posisi % ekuitas, maksimum posisi terbuka, batas rugi harian — **31 Okt 2026: backtest batas posisi: maks. 3 drawdown 15R, maks. 5 27R, tanpa batas 41R. Akun Auto-follow memakai maks. 3 posisi, risiko 1% per trade, dan batas rugi harian 3% ekuitas (rugi terealisasi per hari WIB; batas rugi harian belum diuji di backtest)**
- [x] Mode "paper auto-follow": ikuti semua sinyal otomatis dengan aturan risiko di atas, ukur kurva ekuitasnya — **31 Okt 2026: ekuitas dicatat tiap 15 menit; riwayat sebelum tanggal ini tidak bisa dibuat ulang**
- [ ] Tuning bobot/ambang di Admin berdasarkan hasil backtest (catat setiap perubahan di bagian 6)
- [ ] **Backtest putar ulang** dari analisis yang tersimpan: menguji bobot dan ambang skor lengkap (on-chain, whale, sentimen) dengan skor yang benar-benar ada saat itu. Mulai setelah 2-3 minggu data, sejak analisis disimpan 90 hari (4 Nov 2026)
- [ ] Evaluasi pengaruh AI (sentimen Gemini) vs tanpa AI

**Aturan keputusan (4 Nov 2026):** sebuah versi strategi baru boleh dinilai bila punya **minimal 100 trade di backtest yang hanya memakai masa token di Market** dan **minimal 30 sinyal live selesai**, dan keduanya memenuhi kriteria di bawah. Backtest sebelum 4 Nov terlalu optimistis dan tidak dipakai untuk keputusan.

**Kriteria lolos (semua):**

| Metrik | Ambang minimum |
|---|---|
| Sinyal selesai (live, bukan backtest) | ≥ 30 |
| Rata-rata hasil bersih per sinyal | > +0,2R |
| Total R bersih live | Positif |
| Profit factor bersih | > 1,3 |
| Backtest 90 hari | Rata-rata > +0,2R, konsisten dengan hasil live |
| Drawdown maksimum paper auto-follow | < 15% ekuitas |

Jika belum terpenuhi pada 30 Nov: lanjutkan Fase 2 (perbaiki strategi), Fase 3 & 4 boleh tetap berjalan, **Fase 5 ditunda**.

### Fase 3 — Keamanan produksi (13 – 26 Nov)

Tujuan: server siap memegang private key wallet trading.

- [ ] **Perpanjang VPS** (sebelum 29 Nov) — **dijadwalkan pemilik pada 25 Nov 2026**. Paket pendek saja, karena akan pindah VPS sebelum Fase 4/5. **5 Nov 2026: pindah ke VPSMurah dibatalkan; VPS ini diperpanjang 1 bulan untuk sementara** (lihat `docs/RENCANA_VPS_DAN_AKUN.md` di workspace)
- [ ] **IP dedicated** — IP NAT bersama melemahkan IP whitelist API exchange. **Diputuskan saat masuk Fase 4/5, bersama pindah VPS. 2 Nov 2026: tujuan pindah VPSMurah (bukan Biznet Gio), dengan IP dedicated opsional**
- [x] Domain + **Cloudflare Tunnel** (HTTPS), lalu tutup port 30040 (`WEB_BIND=127.0.0.1`) — **29 Okt 2026: `https://dexsim.tune-in.trade`, port 8080 hanya localhost, firewall hanya SSH, cookie `secure`**
- [x] Cloudflare: aktifkan *Always Use HTTPS*; hapus port forwarding 30040 dan domain di panel proxy vpsindonesia — **31 Okt 2026: HTTP dialihkan 301 ke HTTPS; port 30040 dan 8080 tidak terjangkau dari luar**
- [ ] **2FA (TOTP)** untuk login, wajib untuk admin — 2FA sudah tersedia sejak Fase 1, tinggal mewajibkannya untuk admin
- [ ] **Log audit**: login, perubahan pengaturan, setiap order (siapa, kapan, apa), serta keputusan sistem (kenapa sinyal ditolak, kenapa token diganti). Mencakup semua pengguna dan akun sistem auto-follow, tidak bisa diubah atau dihapus, dan **hanya admin yang bisa melihat**. Bukti harga tiap order terisi sudah berjalan sejak 1 Nov 2026 (`order_fill_evidence`)
- [ ] Batasi sesi: logout otomatis, satu perangkat admin
- [ ] Review keamanan kode (endpoint admin, validasi, rahasia di log)

**Kriteria lolos:** semua item tercentang; akses hanya lewat HTTPS; restore backup berhasil diuji.

### Fase 4 — Eksekusi bayangan (setelah ada strategi kandidat)

Tujuan: mengukur biaya swap sungguhan **tanpa mengirim transaksi**. Diubah 3 Nov 2026 dari rencana exchange CEX, karena token yang dianalisis hampir semuanya hanya ada di DEX.

- [ ] Setiap order paper meminta penawaran swap sungguhan dari agregator (Jupiter untuk Solana) tanpa mengirim transaksi, lalu menyimpan harga, price impact, biaya, dan rute. **10 Nov 2026:** Jupiter dipakai di fase ini (keputusan pemilik). Paket Free cukup: penggunaan tak terbatas, 1 permintaan per detik (halaman harga dicek pemilik 10 Nov; Developer $25 per bulan untuk 10 per detik tidak dibutuhkan). Kebutuhan kita sekitar ribuan quote per hari: quote beli saat order paper terisi dan quote jual berkala per posisi Solana. Tanpa key hanya 0,5 per detik, dan `lite-api` sedang dihentikan. Key didaftarkan pemilik saat Fase 4 dimulai, lalu diisi lewat `.env` atau panel admin. Saat membangun, dokumentasinya bisa dibaca lewat MCP dokumentasi Jupiter (`https://developers.jup.ag/docs/mcp`, tanpa key). BSC, Base, dan Ethereum butuh agregator lain
- [ ] Uji jual kembali: penawaran swap balik untuk setiap posisi, supaya token pajak dan honeypot ketahuan sebelum ada uang sungguhan
- [ ] Laporan selisih harga paper dengan harga swap nyata, per token dan per ukuran order
- [ ] Hasil strategi dihitung ulang memakai biaya nyata
- [ ] Pilih penyedia RPC Solana dan agregator, dan periksa dokumentasi terbarunya: order limit on-chain untuk TP, batas permintaan, dan biaya

**Kriteria lolos:** selama 2 minggu, strategi tetap di atas kriteria Fase 2 setelah memakai biaya swap nyata, dan tidak ada token yang lolos analisis tetapi tidak bisa dijual.

### Go/No-Go

Lanjut ke Fase 5 **hanya jika** Fase 2, 3, dan 4 lolos, dan aplikasi sudah berjalan di VPS baru. Catat keputusan dan alasannya di bagian 6.

**3 Nov 2026: Fase 5 ditunda.** v3 jauh dari kriteria Fase 2 (16 sinyal selesai, -0,46R, PF 0,44). Go/No-Go baru diadakan setelah satu versi strategi lolos kriteria Fase 2, jadi 10 Desember adalah tanggal paling cepat, bukan jadwal tetap.

### Fase 5 — Semi-otomatis lewat wallet, Solana saja

Tujuan: eksekusi sungguhan dengan modal kecil, dan setiap pembelian disetujui pemilik.

- [ ] **Alur Telegram:** sinyal masuk zona entry, pesan ke Telegram, pemilik menekan **Beli**, aplikasi swap lewat wallet, lalu memasang SL dan TP1-TP3. Setelah TP1, SL pindah ke harga entry, dan seterusnya, sama seperti paper trading
- [ ] **Wallet khusus trading:** terpisah dari wallet utama, saldo kecil, dan aplikasi hanya boleh swap ke SOL atau USDC, tidak pernah transfer ke alamat lain
- [ ] **Private key** terenkripsi di server, hanya dipakai oleh bagian yang menandatangani swap, dan tidak pernah tampil di log, panel, atau Telegram
- [ ] **Batas modal yang ditegakkan server** (lihat bagian 5), tidak bisa dilewati dari UI atau Telegram
- [ ] **Kill switch:** satu perintah Telegram atau tombol Admin menjual semua posisi dan mematikan eksekusi
- [ ] **TP sebagai order on-chain** bila agregator menyediakannya, supaya tetap jalan walau server mati. SL tetap dijaga server
- [ ] **Pengaman pemantau:** bila harga tidak terpantau beberapa menit, eksekusi berhenti sendiri dan pemilik mendapat peringatan
- [ ] Batas slippage per swap, dan swap ditolak bila price impact melewati batas
- [ ] Rekonsiliasi harian saldo wallet dengan catatan aplikasi; notifikasi Telegram untuk setiap swap

**Kriteria lolos:** 2-4 minggu tanpa kesalahan operasional (swap nyasar, SL tidak terpasang, selisih saldo), dan hasil nyata sejalan dengan hasil paper.

### Fase 6 — Lanjutan

- [ ] **Otomatis penuh:** alur Fase 5 tanpa tombol Beli, dengan *circuit breaker* yang berhenti sendiri bila rugi harian atau drawdown melewati batas
- [ ] Wallet EVM (Base/ETH/BSC) — paling akhir, karena gas, MEV, dan stop loss sepenuhnya bergantung pada server
- [ ] Skor smart money & pengelompokan wallet (Helius gratis untuk Solana), entry 5 menit untuk kandidat teratas — **31 Okt 2026: menu Wallet menampilkan alamat dari transaksi whale (tanpa skor atau label pemilik)**. **10 Nov 2026, ide (dari artikel X tentang smart wallet GMGN):** nilai riwayat tiap wallet dari data whale 60 hari, misalnya seberapa sering ia membeli sebelum harga naik, lalu tampilkan dan uji "jumlah smart wallet yang sedang membeli" sebagai ganti total volume whale. Bisa menjawab temuan audit 9 Nov bahwa skor whale hampir tidak bergerak. Wajib lolos backtest; direview lebih dalam setelah review v5, bukan dibangun sekarang
- [ ] **Token trending di X** lewat X Search xAI (±$40–60/bulan): hanya jika sentimen terbukti membantu di Fase 2

### Fase R — Radar Whale (paralel, mulai setelah review v5)

Tujuan: memantau token baru yang sedang dibeli whale atau smart wallet, dan mengukur apakah mengikuti mereka lebih baik daripada token pilihan discovery. **Jalur tambahan, bukan pengganti** (disetujui pemilik 11 Nov 2026). Market 20 token, discovery, analisa, akun auto-follow yang ada, dan penilaian v5 tidak berubah. Dibangun di dalam DEX, bukan subdomain baru: batas GeckoTerminal dihitung per IP, jadi aplikasi terpisah di VPS yang sama tidak menambah kapasitas; tabel token, cek keamanan, data whale, harga, dan akun simulasi bisa dipakai ulang; dan akun pusat 1 Jan 2027 tidak bertambah satu aplikasi lagi.

- [ ] **Uji baca saja, 1-2 minggu, Solana dulu.** Catat sinyal pembelian smart money dan KOL, lalu hitung hasil hipotetisnya tanpa akun dan tanpa halaman. Bandingkan dengan wallet whale dari data kita sendiri (60 hari transaksi besar dari GeckoTerminal). Kandidat sumber: GMGN OpenAPI (`track smartmoney`, `track kol`, `market signal`, `market trenches`; repo `GMGNAI/gmgn-skills`). Belum dicek: harga, kuota, batas permintaan (pihak ketiga menyebut sekitar 2 per detik), dan izin menyimpan serta menampilkan datanya. Key dibuat pemilik di gmgn.ai dan diisi lewat `.env` atau panel admin. Key demo hanya untuk tes. Label "smart money" dibuat GMGN dan tidak bisa kita periksa
- [ ] **Market sendiri.** Halaman Radar Whale dengan slot sendiri (usulan maksimal 10), terpisah dari 20 slot Market. Token yang juga ada di Market diberi penanda, tetapi hasilnya dihitung per sumber
- [ ] **Akun auto-follow sendiri**, supaya hasil Radar dan Market bisa dibandingkan dan data v5 tetap bersih
- [ ] **Data tanpa GeckoTerminal.** Untuk Solana, Jupiter Tokens API: harga, volume beli/jual, volume dan pembeli organik, net buyer, jumlah holder, top holder, mint/freeze authority, dan saldo developer (dicek 11 Nov: satu panggilan bisa sampai 100 token; tanpa key 30 per menit, dengan key Free 60 per menit, sejatah dengan quote Fase 4). Jupiter tidak punya daftar transaksi per wallet, jadi tidak bisa menemukan pembelian whale. Jupiter juga menjadi cadangan cek keamanan Solana saat GoPlus tidak menjawab. BSC/Base memakai GoPlus dan DEX Screener seperti sekarang
- [ ] **Aturan sendiri, bukan analisa teknikal Market**, karena token baru belum punya riwayat candle. Masuk saat whale membeli dan lolos filter. Keluar lewat TP/SL, batas waktu, atau saat whale menjual. Grafik dari harga yang kita catat sendiri
- [ ] **Filter wajib:** keamanan token, umur dan riwayat wallet pembeli, wallet developer atau *bundle* milik pembuat token, dan ambang whale terhadap likuiditas (di pool $20 ribu, beli $1.000 sudah terhitung whale)
- [ ] **Antrean dan worker sendiri** (misalnya `radar`, 1 proses, sekitar 60-130 MB RAM), terpisah dari `market-data`, supaya tidak menambah antrean GeckoTerminal
- [ ] Perluas ke BSC/Base setelah Solana terbukti

**Kriteria lanjut:** uji baca saja menunjukkan expectancy positif setelah perkiraan biaya swap, sebelum halaman dan akun dibangun. Klaim di postingan X ($88 menjadi $2.535 semalam) tidak dijadikan acuan.

---

## 4. Review mingguan (setiap Senin)

| Metrik | Sumber | Catatan |
|---|---|---|
| Uptime & pemulihan watchdog | `/var/log/dex-watchdog.log`, monitor uptime | Ada downtime tidak terdeteksi? |
| Tugas terjadwal gagal | Admin → Tugas terjadwal | Kolom "24 jam" |
| Job gagal | Admin → Sistem | Retry atau perbaiki |
| Error 429 GeckoTerminal | Admin → Log (cari `429`) | Turunkan `SIM_MAX_TOKENS` jika sering |
| Sinyal selesai, win rate, total R | Halaman Sinyal | Bandingkan dengan minggu lalu |
| Token masuk/keluar Market | Market → Aktivitas | Apakah discovery menemukan token bagus? |
| Disk & ukuran database | Admin → Sistem | Disk kosong > 10 GB |
| Backup terbaru | Admin → Backup | Ada backup < 24 jam |

---

## 5. Batas modal (Fase 5 ke atas)

Besaran modal **ditentukan sendiri oleh pemilik**. Aplikasi wajib menegakkan batas yang diisi di Admin sebagai pengaman terhadap bug atau salah klik:

| Batas | Nilai (diisi pemilik) | Saran awal |
|---|---|---|
| Total modal maksimum di wallet trading | ________ | Sejumlah yang siap hilang seluruhnya |
| Maksimum per transaksi | ________ | 5–10% dari total modal aplikasi |
| Slippage atau price impact maksimum per swap | ________ | 2–3% |
| Risiko per transaksi (jarak ke SL × ukuran) | ________ | ≤ 1–2% dari total modal aplikasi |
| Maksimum posisi terbuka | ________ | 3 |
| Batas rugi harian (aplikasi berhenti otomatis) | ________ | 3–5% dari total modal aplikasi |
| Pasangan yang diizinkan | ________ | Koin besar dengan likuiditas tinggi |

Aturan tetap: wallet trading terpisah dari wallet utama, aplikasi hanya boleh swap dan tidak pernah transfer ke alamat lain, dan dana di luar batas di atas tidak disimpan di wallet itu.

---

## 6. Log keputusan & review

| Tanggal | Keputusan / hasil review | Alasan |
|---|---|---|
| 29 Okt 2026 | Server production berjalan di vpsindonesia (1 bulan), akses via IP:port | Uji stabilitas & akses API sebelum perpanjang |
| 29 Okt 2026 | Exchange target: Tokocrypto & Indodax (berizin OJK); Binance tidak dipakai langsung | Binance masuk daftar platform ilegal Satgas PASTI (Jun 2026) |
| 29 Okt 2026 | Auto-start (`dex.service`), watchdog 2 menit, reboot keamanan otomatis 04:00 WIB | Server harus pulih sendiri selama 2 bulan berjalan tanpa diawasi; uji reboot lolos |
| 29 Okt 2026 | Modal live dibatasi sendiri oleh pemilik; aplikasi wajib menegakkan batas di bagian 5 | Pengaman berlapis terhadap bug dan salah klik |
| 29 Okt 2026 | Domain `tune-in.trade` (Cloudflare) + Tunnel bersama `/opt/edge`; aplikasi di `dexsim.tune-in.trade`, akses IP:port ditutup | VPS NAT tidak bisa sertifikat langsung; tunnel tanpa port masuk, siap untuk subdomain lain |
| 30 Okt 2026 | AI memakai rantai provider: Gemini (utama, gratis) → xAI Grok (cadangan, berbayar, batas 100 request/hari) | Kuota gratis Gemini sering habis; analisis tetap jalan tanpa biaya besar |
| 30 Okt 2026 | Backup ke luar VPS memakai Cloudflare R2, bukan Google Drive | Gratis 10 GB, key S3 dibatasi satu bucket, tanpa login OAuth yang bisa kedaluwarsa |
| 30 Okt 2026 | Monitoring: UptimeRobot (cek dari luar) + Healthchecks.io (heartbeat dari dalam & backup) | Gratis, keduanya kirim ke Telegram; heartbeat menangkap scheduler/Horizon mati yang tidak terlihat dari luar |
| 30 Okt 2026 | Email transaksional memakai Resend (SMTP), bukan Brevo | Gratis 3.000/bulan tanpa branding; domain diverifikasi lewat DNS Cloudflare |
| 31 Okt 2026 | Strategi dari blueprint masuk Fase 2-6 sesuai data; token trending X pindah ke Fase 6; target utama expectancy bersih, bukan win rate | 20 sinyal pertama: win 30%, -0,34R. Skor belum memprediksi harga, sentimen belum membedakan, dan semua sumber data tambahan bisa gratis |
| 31 Okt 2026 | Strategi `v2`: sinyal butuh jarak stop minimal 4%. Gerbang tren 4 jam, batas RSI, dan TP 1R/2R/3R tidak dipakai | Backtest 90 hari: stop di bawah 4% menyumbang -126R; aturan lain tidak membaik di periode pengujian. Belum ada skenario yang untung di periode pengujian |
| 31 Okt 2026 | Akun Auto-follow: maks. 3 posisi terbuka, risiko 1% ekuitas per trade, order dibatasi 25% ekuitas dan 0,25% likuiditas pool. Pencatatan sinyal tidak dibatasi | Batas posisi menurunkan drawdown backtest dari 41R ke 15R tanpa mengubah cara sinyal diukur |
| 31 Okt 2026 | Setup entry (breakout, pullback, volume, `EXTENDED_MOVE`) tidak masuk strategi; tetap `v2` | Tidak ada yang positif di periode pengujian, dan selisihnya masih dalam batas noise. Menambah aturan sekarang berisiko overfitting |
| 31 Okt 2026 | Pemantauan container di Admin → Container: script di host mengirim data ke MySQL, interval 10–60 detik (default 15), riwayat 7 hari, tanpa ikut backup | Tanpa MongoDB karena butuh container dan backup tambahan untuk satu tabel; tanpa `docker.sock` di aplikasi karena setara akses root |
| 31 Okt 2026 | VPS diperpanjang 25 Nov 2026; IP dedicated diputuskan saat Fase 4/5 bersama pindah ke Biznet Gio | Exchange butuh IP whitelist yang andal, dan perpindahan harus minim downtime |
| 31 Okt 2026 | Tiga akun Auto-follow: gabungan, hanya Strong Buy, dan hanya Buy, dengan modal dan batas risiko sama | Membandingkan rekomendasi sebagai akun: data awal Strong Buy 60% (5 sinyal), Buy 19% (16 sinyal), sampel masih kecil |
| 31 Okt 2026 | Groq ditambahkan sebagai pilihan provider AI (cadangan Gemini); xAI tetap tersedia | Akun xAI belum punya kredit, sehingga cadangan AI tidak berfungsi |
| 2 Nov 2026 | Token yang masih punya posisi, order tertunda, atau sinyal terbuka tidak bisa keluar dari Market lewat jalur apa pun; order dan sinyal baru ditolak untuk token di luar Market | Temuan produksi: `three`, `IDOL`, `worth`, `TRUE`, `AIN` keluar saat analisisnya masih di antrean, sehingga posisi, order, dan sinyalnya beku dan menahan slot auto-follow. Kelima token diputuskan terpisah |
| 2 Nov 2026 | Lima token beku dibersihkan atas persetujuan pemilik: 9 order dibatalkan, sinyal 33, 34, 35, 39, 40 ditutup `expired` tanpa R, posisi `three` dan `IDOL` akun `all` dijual di harga terakhir (order 202 dan 203, rugi total $0,39). Simulasi tidak dimulai ulang | Semua sinyalnya v0 dan v2, jadi v3 tidak terkena. Backup sebelum perubahan: `dex-2026-11-02-220928`. Kedua order jual juga tercatat di bukti fill dengan `price_at` lama, jadi jangan dibaca sebagai eksekusi pasar normal |
| 3 Nov 2026 | Review awal v3: syarat Buy dinaikkan dan diuji sebagai strategi `v4`; Strong Buy tidak diubah, sebagai pembanding; Fase 5 ditunda sampai ada versi yang lolos Fase 2 | 16 sinyal v3 selesai: -0,46R, PF 0,44, Buy -0,63R vs Strong Buy -0,19R. Sinyal setelah naik 15-40% dalam 24 jam 0 dari 4 menang, entry dalam 5% dari puncak 24 jam 1 dari 7. Dugaan kandidat terlalu lama dan masuk terlambat karena slot penuh tidak terbukti: rekomendasi beli datang dalam 1 jam, entry langsung, dan akun yang penuh melewati sinyal, bukan menunda. Filter baru wajib lolos backtest 90 hari dulu, karena filter ATR di atas EMA20, batas RSI, dan pullback sudah pernah ditolak |
| 3 Nov 2026 | Fase 4-6 diubah dari exchange CEX ke swap lewat wallet: Fase 4 eksekusi bayangan tanpa transaksi, Fase 5 semi-otomatis di Solana dengan tombol Beli di Telegram, Fase 6 otomatis penuh lalu EVM | Token yang dianalisis hampir semuanya hanya ada di DEX. Pemilik sudah pernah memakai DEX dan memahami risikonya: stop loss bergantung pada server, private key di server, slippage, MEV, dan tidak ada perlindungan regulator |
| 3 Nov 2026 | Strategi `v4` disiapkan di `dev`: Buy (bukan Strong Buy) ditahan bila entry kurang dari 5% di bawah puncak 24 jam. Batas kenaikan 24 jam diuji dan tidak dipakai. Naik ke produksi setelah review 7 Nov atas persetujuan pemilik | Backtest 30 hari di salinan produksi (data 1 jam hanya tersimpan sejak 3 Okt), 47 token, semua sinyal teknikal karena sejarah tidak bisa membedakan Buy dari Strong Buy: aturan v3 (stop ≥ 4%) pengembangan +0,20R PF 1,42, uji +0,07R PF 1,13, drawdown 28R; + 5% di bawah puncak +0,15R PF 1,27 dan +0,24R PF 1,51, drawdown 15R; + batas naik 15% +0,13R dan +0,01R; gabungan +0,02R dan +0,30R, tidak konsisten. Live v3 jauh di bawah backtest (-0,46R), selisih ini belum dijelaskan |
| 3 Nov 2026 | Selisih live dan backtest diselidiki. Penyebab utama: backtest ikut menghitung masa sebelum token ditemukan, padahal token ditemukan karena sedang naik. Backtest perlu diperbaiki agar hanya memakai candle sejak token masuk aplikasi, dan semua hasil backtest Fase 2 sebelumnya harus dibaca dengan catatan ini | Pada token hasil discovery, aturan v3: 168 trade sebelum token masuk +0,16R PF 1,31, 15 trade sesudahnya +0,02R PF 1,04. Eksekusi bukan penyebab: rugi saat stop live -1,09R vs backtest -1,08R, biaya 0,06R vs 0,08R, entry tidak melewati zona, jarak stop sama 14%. Pemilihan sinyal juga berbeda: dari 15 sinyal live hanya 1 yang juga muncul di backtest, karena live memakai skor on-chain, whale, dan sentimen yang tidak ada di backtest. Di periode yang sama, backtest +0,30R (7 trade) dan live -0,44R (12 sinyal) |
| 4 Nov 2026 | Backtest diperbaiki (5aa8095): token hanya diperdagangkan selama ada di Market, seperti live. Semua skenario diulang. Hasilnya kini sejalan dengan live, jadi hasil backtest sebelum tanggal ini terlalu optimistis | 31 hari, 47 token. Aturan v3 (stop ≥ 4%): -0,24R PF 0,62 (47 trade), sebelumnya +0,12R PF 1,22 (207 trade). v4 (5% di bawah puncak + batas naik 15%): +0,11R PF 1,26 (17 trade). 5% di bawah puncak saja: +0,05R PF 1,10 (18). Breakout: +0,16R PF 1,33 (21). Semua skenario lain negatif. Sampel kecil, karena riwayat Market baru ada sejak 29 Okt |
| 4 Nov 2026 | Pemilik setuju: v4 naik ke live setelah review 7 Nov dengan kedua aturan aktif (5% di bawah puncak 24 jam dan batas naik 15% dalam 24 jam); breakout disimpan untuk v5; analisis disimpan 90 hari dan riwayat Market permanen; aturan keputusan 100 trade backtest dan 30 sinyal live | Kedua aturan v4 terbaik di backtest yang sudah diperbaiki dan sejalan dengan data live. Satu perubahan per versi. Data skor dan riwayat Market dibutuhkan untuk backtest tanpa bias, dan penghapusan pertama akan terjadi 12 Nov |
| 6 Nov 2026 | Review v3 dimajukan ke malam 6 Nov; deploy tetap 7 Nov. Pemilik memilih v4 dengan **hanya batas naik 15% dalam 24 jam aktif**; aturan 5% di bawah puncak tetap ada di config (default kedua aturan) dan dimatikan lewat panel admin (nilai 0). Strong Buy tidak berubah. Fase 5 tetap ditunda. Buffer pool MySQL DEX diturunkan dari 1.185 MB ke 768 MB saat deploy | 45 sinyal v3 tertutup: -0,29R PF 0,56; Buy -0,33R, Strong Buy -0,19R. Pada 29 sinyal sesudah analisa 3 Nov (belum dipakai menyusun aturan): naik 15-40% dalam 24 jam -0,58R (n=6), aturan di bawah puncak tidak terbukti (disaring -0,17R n=11, lolos -0,22R n=18). Swap VPS penuh oleh MySQL; pemakaian nyata buffer pool DEX sekitar 530 MB |
| 6 Nov 2026 | Kedua aturan v4 bisa diatur dari Admin → Pengaturan → Bobot & ambang analisis; 0 = nonaktif, dan nilainya masuk hash versi strategi | Supaya pilihan pemilik (hanya batas naik) bisa dipasang tanpa mengubah default, dan hasilnya tercatat sebagai versi tersendiri |
| 7 Nov 2026 | **v4 live** pukul 10.54 WIB (`main` cdfbc39, dari 5541e3c). Aturan di bawah puncak 24 jam diisi 0 di pengaturan sebelum deploy, jadi analisa pertama (10.56, 20 token) langsung tercatat `v4-6428c1` dengan batas naik 15% saja. Sinyal v3 yang masih terbuka tetap diikuti seperti biasa. Buffer pool MySQL DEX diturunkan ke 768 MB pukul 12.04 (Horizon dijeda dan scheduler dihentikan selama restart 33 detik; 0 job gagal) | Cek ulang 08.32 WIB pada 50 sinyal v3: kesimpulan review tidak berubah (Buy sesudah 3 Nov yang ditahan batas naik -0,37R n=10, yang diambil -0,15R n=17; aturan di bawah puncak tetap tidak terbukti) |
| 7 Nov 2026 | Pemilik setuju: lot akun Auto-follow ikut ditutup saat sinyalnya selesai (kedaluwarsa, stop loss, take profit, atau batal). Sisa lot dijual di harga pasar pada putaran `market:prices` berikutnya, order keluar yang menunggu dibatalkan; lot menunggu bila harga token belum segar. Akun manusia tidak diubah. Lima lot lama dari sinyal yang sudah tutup ikut tertutup saat aturan ini live, di harga pasar saat itu | Sebelumnya lot hidup terus setelah sinyalnya tutup. Per 7 Nov 15.30 WIB ketiga slot Hanya Strong Buy terisi lot dari sinyal kedaluwarsa (GATO #57, Percolator #71, IDOL #84), sehingga 8 sinyal 7 Nov (#112-#119) hanya 1 yang dibeli, dan v4 tidak teruji di akun itu. Lot tersebut juga menahan tokennya di Market |
| 7 Nov 2026 | Laporan per akun Auto-follow (disetujui pemilik): kriteria Fase 2 dari lot akun sendiri (R lot = P&L dibagi risiko saat beli), corong sinyal dengan alasan dilewati, cara lot berakhir, per minggu, daftar lot, dan grafik token dengan penanda beli/jual (`?lot=`). Keputusan Auto-follow kini dicatat di `auto_follow_decisions`; riwayat diisi dari order dan log sejak 29 Okt (`market:backfill-decisions`). Catatan bayangan calon v5 (keluar saat rekomendasi tidak lagi Buy/Strong Buy) dicatat per sinyal tanpa mengubah trading, dihitung untuk sinyal yang entry sejak 7 Nov 20.00 WIB. Koneksi MySQL dipaksa UTC | Dari 56 sinyal v3/v4 selesai, 46 sempat turun ke Hold; perkiraan kasar keluar saat itu -1,2R dibanding -13,9R aktual, tetapi dihitung pada data yang sama, jadi perlu diukur ke depan. Riwayat log: Gabungan melewati 74 sinyal dan membeli 18, Hanya Buy 42 dan 15, Hanya Strong Buy 14 dan 8, semuanya karena slot penuh. MySQL lokal berzona WIB menggeser timestamp 7 jam; produksi sudah UTC |
| 7 Nov 2026 | Catatan bayangan calon v5 jadi empat varian, tanpa mengubah trading: keluar di Hold pertama, keluar bila analisa pertama setelah entry tidak lagi Buy, keluar setelah dua analisa berturut-turut tidak Buy (tercatat sejak 23.45 WIB), dan hanya entry yang analisa sebelumnya juga Buy. Pemilik memutuskan fokus ke DEX sampai tahap uji uang sungguhan | 65 sinyal selesai sejak 1 Nov: yang analisa pertamanya setelah entry sudah tidak Buy kalah di kedua periode (1-4 Nov 4 sinyal -2,21R; 4-7 Nov 5 sinyal -5,41R; 1 menang dari 9). Konfirmasi Buy sebelum entry tidak konsisten (1-4 Nov rata-rata -0,25R lawan -0,07R tanpa konfirmasi; 4-7 Nov -0,26R lawan -0,64R). Replay analisa live di salinan lokal: mesin cocok per sinyal (40 pasangan -8,46R lawan -8,91R live), tetapi total bergeser belasan R hanya karena sinyal mana yang terbuka, jadi selisih sekitar 3R antar aturan masih noise. Rekomendasi sering bolak-balik: 31 dari 68 sinyal yang sempat turun kembali ke Buy |
| 8 Nov 2026 | Candle: hanya 15m yang diambil rutin (tiap 15 menit, 96 candle = 24 jam); 1h, 4h, dan 1d dirakit dari 15m. Token baru atau yang kembali ke Market mengambil 15m paling jauh 7 hari (672 candle); bila celahnya lebih panjang, 1h, 4h, dan 1d diambil langsung sekali. Jadwal 4h dan 1d terpisah dihapus. 15m disimpan 90 hari. Whale tetap tiap 15 menit (pilihan pemilik, supaya transaksi whale tidak terlewat). Versi strategi tetap v4 | Beban GeckoTerminal per jam turun dari sekitar 275-285 (92-95% batas 5 per menit) ke sekitar 175 (58%). Uji di salinan produksi: candle rakitan sama dengan GeckoTerminal untuk close (5 dari 8.713 candle 1h berbeda lebih dari 0,5%); skor teknikal sama persis di 5.164 analisa 3-7 Nov dan tidak ada rekomendasi yang berubah. Selisih yang ada terutama di periode yang masih berjalan, di mana candle rakitan lebih baru |
| 8 Nov 2026 | Token yang sudah keluar Market tetap diambil candle 15m-nya (permintaan pemilik), bergiliran 10 token per jam di menit :20, yang candle-nya paling lama duluan, tanpa batas jumlah token untuk sekarang. Menu Admin → Token menampilkan semua token yang pernah masuk Market (kandidat yang tidak pernah masuk tidak ditampilkan): status, berapa kali masuk, kapan dan kenapa terakhir keluar, candle tersimpan per timeframe, 15m terakhir, dan cara pengambilannya, ditambah perkiraan permintaan GeckoTerminal per jam. Token yang 15m-nya lebih lama dari 7 hari ditandai "Data bolong", dan admin bisa menekan "Ambil 1.000 candle" (satu permintaan, ±10 hari) lewat antrean yang sama | Mengambil token keluar tiap 15 menit seperti token Market butuh sekitar 208 permintaan per jam untuk 52 token dan melampaui batas 300 per jam; bergiliran hanya 10 per jam. Satu permintaan 15m menutup celah sampai 7 hari, jadi data tetap utuh selama giliran tiap token di bawah 7 hari (sekitar 1.600 token keluar); menu Token memberi tanda bila batas itu terlewati. Per 7 Nov: 64 token pernah di Market, 44 sudah keluar, giliran tiap token sekitar 4,4 jam |
| 8 Nov 2026 | Akun auto-follow membeli saat sinyal masuk zona entry (status Pending ke Active), bukan saat sinyal dibuat, sehingga lot dan sinyal masuk di saat dan harga yang sama; sinyal yang tidak pernah masuk zona tidak dibeli. Laporan akun memisahkan sinyal yang masih menunggu entry dan yang tidak pernah entry. Sinyal yang kena stop di entry setelah TP1 atau TP2 ditampilkan sebagai "TP1, lalu stop di entry", bukan take profit biasa. Versi strategi tetap v4 (R sinyal tidak berubah) | Perbandingan 28 jam pertama v4: lot Strong Buy AIOT dan GATO terisi 5 dan 14 menit sebelum sinyalnya masuk, karena order limit langsung terisi pada satu harga sementara sinyal butuh dua harga berturut-turut di zona. Bila harga lari dan tidak kembali, sinyal kedaluwarsa tanpa entry dan lotnya dijual paksa tanpa hasil sinyal. Percolator #117 berstatus take profit padahal bersihnya -0,19R |
| 8 Nov 2026 | AI punya cadangan kedua: OpenRouter (model gratis, bawaan openrouter/free, hanya penyedia yang mendukung JSON). Urutan Gemini, lalu Groq (openai/gpt-oss-20b), lalu OpenRouter bila keduanya dijeda atau batas hariannya habis. Batas aplikasi 10 per menit dan 45 per hari; jeda kuota harian OpenRouter berakhir pukul 00.00 UTC. API key diisi pemilik di Admin → Pengaturan → AI | OpenRouter tanpa pembelian kredit: 20 request per menit dan 50 per hari untuk semua model gratis (dokumen OpenRouter, dicek 8 Nov). Pemakaian 7 Nov: Gemini 197 dari 250, Groq 16 dari 100; Gemini dijeda 4 kali karena HTTP 503 sejak v4. Uji produksi 8 Nov: google/gemma-4-31b-it:free HTTP 429 (dibatasi di Google AI Studio); openrouter/free dijawab Nvidia Nemotron 3.5 Super, ringkasan analisa LYN dalam 16 detik, biaya 0 |
| 8 Nov 2026 | Tiga akun auto-follow per jaringan: Solana, BNB Chain, dan Ethereum. Masing-masing membeli Buy dan Strong Buy hanya di jaringannya, dengan batas risiko yang sama, dan membayar gas per swap jaringan itu di atas fee 0,3% (bawaan Solana $0,01, BNB Chain $0,01, Ethereum $0,25; bisa diubah di panel). Akun lain tetap tanpa gas supaya sebanding dengan riwayatnya. Laporan punya tabel per jaringan dari data sinyal, dipisah Buy dan Strong Buy. Base belum dibuatkan akun (tidak ada token Base di Market). Tujuannya memilih wallet jaringan untuk uji uang sungguhan | Sinyal selesai sampai 8 Nov: BNB Chain -0,10R (n=30, Strong Buy +0,41R n=8), Ethereum -0,07R (29, Strong Buy +0,18R n=8), Solana -0,39R (36, Strong Buy -0,15R n=8), Base -0,82R (9). Gas swap 8 Nov: Ethereum sekitar $0,04 (Etherscan, 0,054 gwei), BNB Chain $0,007 (BscScan), Solana di bawah 1 sen; dengan order $300-1.800, gas hanya sekitar 0,01R per lot |
| 8 Nov 2026 | Data holder: setiap cek GoPlus menyimpan 10 holder teratas (alamat, persen, terkunci, kontrak, pool, burn, tag), porsi holder terbesar yang bisa menjual (bukan terkunci, kontrak, burn, atau pool), serta persen creator dan owner (EVM). Halaman token menampilkannya dengan tanda "Holder dominan" (60% ke atas) dan "Waspada" (30% ke atas). Analisa mencatat porsi itu dan menyebutnya di alasan bila 30% ke atas, tanpa mengubah skor; aturan skornya diputuskan di review v5. Token yang sudah keluar Market dicek sekali sehari pukul 10.35 WIB. Perbaikan bug: cek dikirim satu per 10 detik, penolakan GoPlus dicoba ulang lalu dicatat, dan kunci job unik berakhir setelah 1 jam | Sebelumnya tiap putaran 6 jam hanya 17 dari 20 token tersimpan; ZIG, BTW, dan LYN (diantrekan terakhir) tidak pernah diperbarui sejak masuk Market. Pemindaian 8 Nov atas 56 token yang pernah di Market: top 10 < 20% rata-rata +0,15R (35 sinyal), 20-40% -0,36R (31), 40-60% -0,59R (6); hanya 1 token dengan satu holder >= 60% (TRUMP 71,8%). Data holder kondisi 8 Nov, bukan saat sinyal; di Solana bursa dan vault pool belum bisa dibedakan |
| 10 Nov 2026 | Portofolio dan portofolio akun auto-follow menampilkan posisi terbuka sebagai kartu: garis harga dengan stop loss (dan stop awal bila sudah dipindah ke entry), harga beli, TP1-TP3 (yang terisi ditandai dengan jam dan harganya), dan harga sekarang (portofolio akun kini diperbarui tiap 15 detik, sebelumnya 60). Di bawahnya riwayat token itu: tiap trade dari beli sampai posisi kosong lagi, dengan harga masuk, setiap keluar (TP, SL, SL di entry, tutup otomatis) beserta jamnya, dan hasil. Notifikasi progres sinyal di Telegram dan notifikasi web kini menyertakan jam kejadian. Live 10 Nov 08.49 WIB (backend 6120c35, frontend a52eb6d) | Pemilik melihat notifikasi TP1 yang levelnya di grafik sudah tersentuh jauh sebelumnya. Cek 20 order TP 6-9 Nov: 16 terisi di candle 15m yang sama dengan sentuhan pertama; 4 terlambat 47 menit sampai 10 jam karena sentuhan sebelumnya hanya wick (GATO +0,7%, AIOT +6,65%, GANG +7% di atas TP) yang tidak terlihat oleh harga 15 detik. Order diisi dari harga 15 detik (dua cek berturut-turut), bukan dari candle; logika pengisian tidak diubah |
| 10 Nov 2026 | Cek silang harga sebelum order atau sinyal bergerak: saat harga DEX Screener (dua cek berturut-turut) mencapai sebuah level, harga pool yang sama di GeckoTerminal (sumber grafik) ikut diminta. Selisih ≤5%: tidak ada perubahan, isi di harga DEX Screener. Selisih >5%: level dinilai ulang dan diisi dengan harga GeckoTerminal. Slot GeckoTerminal penuh: order dan sinyal menunggu cek berikutnya, order market tetap terisi di harga DEX Screener (`dexscreener_unchecked`). GeckoTerminal gagal: harga DEX Screener dipakai dan dicatat di log. Jawaban GeckoTerminal dipakai ulang 45 detik. Bukti isi order menyimpan harga pembanding (`checked_price`) dan sumbernya. Discovery memilih pair token yang sama dengan quote SOL, wrapped native, atau stablecoin bila likuiditasnya cukup, dan tidak lagi memasukkan pool kedua dari token yang sudah ada di Market (penambahan manual juga ditolak). Dimatikan lewat `PRICE_CROSS_CHECK=false`. Live 10 Nov 11.47 WIB (backend 6b9002a). VVV #74 (pool aero) dikeluarkan setelah sinyal #143 selesai, atas persetujuan pemilik; tidak ada order atau posisi akun mana pun di token itu | Audit 10 Nov: 4 dari 149 isi sejak 2 Nov jauh di luar candle 15m, semuanya dengan harga yang sama dua kali: limit beli STANK (pair STANK/STONK) terisi 12% di bawah semua candle, beli market FLOAT 8-10% di atas, dan sebuah stop loss -4,4%. Feed hanya 4 digit, jadi 139 isi wajar juga memakai harga yang sama dua kali; menolak harga kembar akan menahan hampir semua order. Membandingkan dengan candle tersimpan (≤15 menit) pada 5% menahan 41 isi wajar demi 3 yang salah, karena TP dan SL memang terisi saat harga keluar dari kisaran sebelumnya. Cek 20 token aktif: 19 selisih ≤0,6%, FLOAT +14%. VVV (Base) masuk Market dua kali lewat pool aerodrome dan aero, masing-masing dengan sinyal terbuka |
| 10 Nov 2026 | Grafik, notifikasi, dan ringkasan AI mengikuti kondisi terbaru. (1) Candle periode berjalan di grafik token digerakkan harga live 15 detik pada periode yang benar (dihitung dari 00.00 UTC seperti backend): periode yang sama memperpanjang candle tersimpan terakhir, periode baru membuka candle dari close sebelumnya, dan candle tersimpan menggantikannya saat sinkron. Sebelumnya harga live hanya dipasang bila angkanya berubah, ditimpa lagi tiap muat ulang candle 60 detik, dan selalu ditempel ke candle tersimpan terakhir. (2) Notifikasi sinyal baru menyebut zona entry lengkap, bukan hanya batas atasnya. (3) Ringkasan AI dipakai ulang hanya bila ditulis kurang dari 3 jam lalu (dihitung sejak ditulis) dan skor akhir bergeser kurang dari 5 poin sejak itu. Candle tersimpan 1m dari harga 15 detik tidak dibuat (pemilik setuju 10 Nov) | GANG sinyal #152 (15.56 WIB entry 0,005018, wajar, GeckoTerminal 0,005184): grafik masih menampilkan candle 15.30 WIB berpenutupan 0,00482 karena candle disinkron per 15 menit lewat antrean (31 job antre pukul 16.01), sehingga zona entry 0,00507-0,005275 tampak di atas harga. Notifikasi menulis "Entry di $0.005275". Ringkasan AI dari 09.41 WIB (skor 74) masih dipakai pukul 15.56 (skor 84): batas 3 jam dihitung dari analisa sebelumnya, jadi tidak pernah habis selama rekomendasi sama. Live 10 Nov 16.32 WIB (backend 4a4430f, frontend 3701be7); siklus pertama menulis ulang 15 dari 20 ringkasan tanpa peringatan di log |
| 10 Nov 2026 | Halaman Market punya kolom Whale 24j: jumlah transaksi whale dan total beli whale 24 jam terakhir per token, dengan rincian beli/jual dan ambang whale di tooltip, serta urutan "whale beli 24j" dan "jumlah whale 24j". Hanya tampilan; analisa tidak berubah. Pemilik memilih 24 jam saja (bukan 24 dan 48 jam) | Ambang whale per token mengikuti likuiditas pool ($1.000-$10.000), jadi jumlahnya tidak sebanding antar token. Data whale diambil tiap 15 menit, jadi angkanya di-cache 1 menit |
| 11 Nov 2026 | Perbaikan bug cek keamanan: (1) porsi holder dari GoPlus di atas 100% supply diabaikan dan diberi catatan, sementara sisa hasil cek tetap disimpan dan dinilai; (2) GoPlus yang tidak menjawab (timeout) dicoba lagi nanti lalu hanya dicatat sebagai peringatan, seperti penolakan; (3) mengeluarkan token yang sudah keluar Market tidak lagi mencatat event baru. Disetujui pemilik 11 Nov | TROLL 10 Nov: GoPlus memberi pool 4.029.241% dan total top 10 7.037.630%, melampaui kolom (maks 999.999,99), jadi seluruh cek gagal disimpan sejak 10 Nov 01.16 WIB (0 baris >100% tersimpan). 11 Nov 10.36-10.47 WIB: GoPlus Solana tidak menjawab (juga dari laptop), 18 job gagal; BSC normal. VVV: kedua pool sudah digantikan discovery (#74 10 Nov 20.55, #13 11 Nov 04.57 WIB); pengeluaran manual #13 yang disetujui pemilik mencatat event kedua yang keliru, lalu dihapus |
| 11 Nov 2026 | Fase R (Radar Whale) dicatat sebagai jalur paralel di dalam DEX, dengan Market dan akun simulasi sendiri. 20 slot Market tidak dibagi. Mulai setelah review v5, diawali uji baca saja di Solana. Worker `market-data` tetap 1 proses | Pemilik ingin memantau token baru yang dibeli whale. Membagi 20 slot akan mengubah data v5, dan mencampur keduanya membuat hasil tidak bisa dinilai. Ukuran 11 Nov 13.11-14.10 WIB: CPU 3 core dengan load 1,0-1,3, RAM tersedia sekitar 1,2 GB, swap terpakai 2,2 GB. Job `default` menunggu sekitar 4 detik. Job GeckoTerminal menunggu 2 menit (candle, median) sampai 5,6 menit (whale, median), dan tiap job sekitar 12 detik, kebanyakan menunggu slot 10 permintaan per menit. Slot itu terpakai 69% dari jam tersebut, jadi worker tambahan hanya ikut mengantre |
| | | |

---

## 7. Risiko yang dipantau

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Strategi belum terbukti | Rugi saat live | Kriteria lolos Fase 2 & Go/No-Go |
| Rate limit API gratis (IP NAT bersama) | Data candle/whale tertunda | Pantau 429, kurangi token, pertimbangkan IP dedicated |
| VPS mati / jaringan putus | Sinyal & eksekusi berhenti, SL tidak dijaga | Auto-start, watchdog, TP sebagai order on-chain, eksekusi berhenti sendiri bila harga tidak terpantau, saldo wallet kecil |
| Kebocoran private key wallet | Seluruh isi wallet trading | Wallet terpisah dan bersaldo kecil, key terenkripsi dan tidak pernah tampil, aplikasi hanya boleh swap, 2FA, HTTPS |
| Slippage, MEV, token pajak atau honeypot | Harga eksekusi jauh lebih buruk, token tidak bisa dijual | Eksekusi bayangan di Fase 4, batas slippage per swap, uji jual kembali sebelum beli |
| Agregator atau RPC berubah atau mati | Swap gagal | Adapter terpisah, peringatan bila swap gagal, kill switch |
| Format respons penyedia data berubah (DEX Screener, GeckoTerminal) | Harga atau candle salah tanpa error | Cek silang harga (live 10 Nov). Ide 10 Nov, belum dibangun: peringatan bila respons kehilangan field yang dipakai atau angkanya tidak masuk akal |
| Bug eksekusi order | Order salah | Konfirmasi manual, batas modal server-side, rekonsiliasi harian |
