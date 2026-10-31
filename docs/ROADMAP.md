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
Go/No-Go                                                       ◆ 10 Des
Fase 5                                                          ████████████████
Fase 6                                                                          → Jan 2027
```

| Fase | Periode | Fokus | Uang sungguhan |
|---|---|---|---|
| 0 | selesai 29 Okt | Fondasi aplikasi & server | Tidak |
| 1 | 30 Okt – 5 Nov | Stabil & terpantau | Tidak |
| 2 | 30 Okt – 30 Nov | Validasi strategi (backtest + rekam jejak) | Tidak |
| 3 | 13 – 26 Nov | Keamanan produksi | Tidak |
| 4 | 27 Nov – 10 Des | Exchange **read-only** | Tidak (hanya membaca saldo) |
| — | **10 Des** | **Go/No-Go** | Keputusan |
| 5 | 11 – 24 Des | Live terbatas di CEX | **Ya, modal kecil** |
| 6 | Jan 2027 → | Wallet Solana (Jupiter), otomasi penuh | Ya |

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

Tujuan: server siap memegang API key exchange.

- [ ] **Perpanjang VPS** (sebelum 29 Nov) — **dijadwalkan pemilik pada 25 Nov 2026**. Paket pendek saja, karena akan pindah VPS sebelum Fase 4/5
- [ ] **IP dedicated** — IP NAT bersama melemahkan IP whitelist API exchange. **Diputuskan saat masuk Fase 4/5, bersama pindah VPS ke Biznet Gio dengan downtime minimal**
- [x] Domain + **Cloudflare Tunnel** (HTTPS), lalu tutup port 30040 (`WEB_BIND=127.0.0.1`) — **29 Okt 2026: `https://dexsim.tune-in.trade`, port 8080 hanya localhost, firewall hanya SSH, cookie `secure`**
- [x] Cloudflare: aktifkan *Always Use HTTPS*; hapus port forwarding 30040 dan domain di panel proxy vpsindonesia — **31 Okt 2026: HTTP dialihkan 301 ke HTTPS; port 30040 dan 8080 tidak terjangkau dari luar**
- [ ] **2FA (TOTP)** untuk login, wajib untuk admin — 2FA sudah tersedia sejak Fase 1, tinggal mewajibkannya untuk admin
- [ ] **Log audit**: login, perubahan pengaturan, setiap order (siapa, kapan, apa), serta keputusan sistem (kenapa sinyal ditolak, kenapa token diganti)
- [ ] Batasi sesi: logout otomatis, satu perangkat admin
- [ ] Review keamanan kode (endpoint admin, validasi, rahasia di log)

**Kriteria lolos:** semua item tercentang; akses hanya lewat HTTPS; restore backup berhasil diuji.

### Fase 4 — Exchange read-only (27 Nov – 10 Des)

Tujuan: aplikasi membaca akun exchange sungguhan **tanpa bisa bertransaksi**.

- [ ] Integrasi **CCXT** (PHP) untuk Tokocrypto dan Indodax (exchange berizin OJK)
- [ ] Simpan API key terenkripsi di Admin; **izin: baca saja**
- [ ] Tampilkan saldo, posisi, dan riwayat trade asli; rekonsiliasi dengan simulator
- [ ] Data pasar CEX (candle, orderbook) untuk koin yang terdaftar di exchange; pemetaan token DEX ↔ simbol CEX
- [ ] Simulasi sinyal memakai harga & fee exchange asli (paper trading di orderbook nyata)

**Kriteria lolos:** saldo & riwayat di aplikasi cocok 100% dengan aplikasi exchange selama 7 hari.

### Go/No-Go — 10 Desember 2026

Lanjut ke Fase 5 **hanya jika** Fase 1–4 lolos. Catat keputusan dan alasannya di bagian 6.

### Fase 5 — Live terbatas di CEX (11 – 24 Des)

Tujuan: eksekusi sungguhan dengan modal kecil dan pengaman berlapis.

- [ ] Tombol **"Eksekusi sinyal"** dengan layar konfirmasi (bukan otomatis penuh)
- [ ] Entry + **OCO TP/SL native di exchange** (tetap jalan walau server mati)
- [ ] **Batas modal yang ditegakkan server** (lihat bagian 5), tidak bisa dilewati dari UI
- [ ] **Kill switch**: batalkan semua order terbuka & matikan eksekusi dengan satu klik
- [ ] API key: izin trade **tanpa withdraw**, IP whitelist ke IP dedicated
- [ ] Rekonsiliasi harian order aplikasi vs exchange; notifikasi Telegram setiap eksekusi

**Kriteria lolos:** 2 minggu tanpa kesalahan operasional (order nyasar, SL tidak terpasang, selisih saldo).

### Fase 6 — Lanjutan (Januari 2027 →)

- [ ] Wallet Solana khusus trading (terpisah dari wallet utama) + **Jupiter** Swap API & Trigger API (OTOCO, trailing stop)
- [ ] Otomasi penuh opsional dengan *circuit breaker* (berhenti sendiri jika rugi harian/drawdown melewati batas)
- [ ] Wallet EVM (Base/ETH/BSC) — paling akhir, karena stop loss bergantung pada server
- [ ] Skor smart money & pengelompokan wallet (Helius gratis untuk Solana), entry 5 menit untuk kandidat teratas — **31 Okt 2026: menu Wallet menampilkan alamat dari transaksi whale (tanpa skor atau label pemilik); skor baru dibuat jika data ini terbukti membantu**
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
| Total modal maksimum di exchange untuk aplikasi | ________ | Sejumlah yang siap hilang seluruhnya |
| Maksimum per transaksi | ________ | 5–10% dari total modal aplikasi |
| Risiko per transaksi (jarak ke SL × ukuran) | ________ | ≤ 1–2% dari total modal aplikasi |
| Maksimum posisi terbuka | ________ | 3 |
| Batas rugi harian (aplikasi berhenti otomatis) | ________ | 3–5% dari total modal aplikasi |
| Pasangan yang diizinkan | ________ | Koin besar dengan likuiditas tinggi |

Aturan tetap: API key **tanpa izin withdraw**; dana di luar batas di atas tidak disimpan di akun yang terhubung ke aplikasi.

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
| | | |

---

## 7. Risiko yang dipantau

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Strategi belum terbukti | Rugi saat live | Kriteria lolos Fase 2 & Go/No-Go |
| Rate limit API gratis (IP NAT bersama) | Data candle/whale tertunda | Pantau 429, kurangi token, pertimbangkan IP dedicated |
| VPS mati / jaringan putus | Sinyal & eksekusi berhenti | Auto-start, watchdog, SL/TP native di exchange |
| Kebocoran API key | Dana di exchange | Enkripsi, tanpa izin withdraw, IP whitelist, 2FA, HTTPS |
| Perubahan regulasi / exchange | Integrasi terputus | Pakai exchange berizin OJK, CCXT agar mudah berpindah |
| Bug eksekusi order | Order salah | Konfirmasi manual, batas modal server-side, rekonsiliasi harian |
