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
- [ ] Evaluasi pengaruh AI (sentimen Gemini) vs tanpa AI

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

- [ ] **Perpanjang VPS** (sebelum 29 Nov) — **dijadwalkan pemilik pada 25 Nov 2026**. Paket pendek saja, karena akan pindah VPS sebelum Fase 4/5
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

- [ ] Setiap order paper meminta penawaran swap sungguhan dari agregator (Jupiter untuk Solana) tanpa mengirim transaksi, lalu menyimpan harga, price impact, biaya, dan rute
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
- [ ] Skor smart money & pengelompokan wallet (Helius gratis untuk Solana), entry 5 menit untuk kandidat teratas — **31 Okt 2026: menu Wallet menampilkan alamat dari transaksi whale (tanpa skor atau label pemilik)**
- [ ] **Token trending di X** lewat X Search xAI (±$40–60/bulan): hanya jika sentimen terbukti membantu di Fase 2

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
| Bug eksekusi order | Order salah | Konfirmasi manual, batas modal server-side, rekonsiliasi harian |
