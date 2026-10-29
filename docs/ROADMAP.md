# Roadmap DEX Simulator

Dokumen kerja untuk 2 bulan ke depan (**30 Oktober – 24 Desember 2026**). Server tetap berjalan selama pengembangan; setiap fase punya **kriteria lolos** yang harus terpenuhi sebelum fase berikutnya yang menyangkut uang sungguhan dimulai.

**Cara memakai dokumen ini**

- Centang `[x]` item yang selesai dan isi tanggalnya; commit perubahan dokumen ini bersama kodenya.
- Setiap **Senin** lakukan review mingguan (bagian 4) dan catat hasilnya di bagian 6.
- Jika kriteria lolos tidak terpenuhi, **fase diperpanjang**, bukan dilewati. Tanggal adalah target, bukan kewajiban.

---

## 1. Status saat ini (29 Oktober 2026)

| Area | Status |
|---|---|
| Aplikasi | Simulator, analisis & sinyal (entry/SL/TP), discovery + Market otomatis, whale tracker, berita, panel Admin |
| Server | VPS NAT vpsindonesia (3 vCPU / 6 GB), Docker, akses **`https://dexsim.tune-in.trade`** (Cloudflare Tunnel) — **aktif s/d 29 Nov 2026** |
| Deploy | Push ke `main` (dex-backend / dex-frontend) → GitHub Actions (tes/build) → deploy otomatis |
| Keandalan | Auto-start saat boot (`dex.service`), watchdog `/up` tiap 2 menit, restart container otomatis |
| Backup | Cron harian 02:30 WIB (14 hari), download & backup manual dari Admin (jeda 60 menit) |
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
- [ ] **Notifikasi Telegram**: sinyal Buy baru, TP/SL tercapai, peringatan rug pull, token masuk/keluar Market
- [ ] Notifikasi Telegram untuk operasional: watchdog memulihkan layanan, backup gagal, job gagal
- [ ] Monitor uptime eksternal gratis (mis. UptimeRobot / Healthchecks.io) ke `/up`, kirim ke Telegram
- [ ] Salinan backup ke luar VPS (mis. Google Drive / Cloudflare R2), uji restore sekali

**Kriteria lolos:** 7 hari berturut-turut tanpa downtime yang tidak terdeteksi; backup harian ada dan pernah diuji restore.

### Fase 2 — Validasi strategi (30 Okt – 30 Nov)

Tujuan: membuktikan dengan data apakah sinyal layak dipakai dengan uang sungguhan.

- [ ] **Backtest**: jalankan mesin analisis pada candle historis (1h/4h, 90 hari), hasilkan win rate, rata-rata R, drawdown
- [ ] Halaman laporan performa mingguan (sinyal, R per minggu, per token, per rekomendasi)
- [ ] Aturan risiko di simulator: ukuran posisi % ekuitas, maksimum posisi terbuka, batas rugi harian
- [ ] Mode "paper auto-follow": ikuti semua sinyal otomatis dengan aturan risiko di atas, ukur kurva ekuitasnya
- [ ] Tuning bobot/ambang di Admin berdasarkan hasil backtest (catat setiap perubahan di bagian 6)
- [ ] Evaluasi pengaruh AI (sentimen Gemini) vs tanpa AI

**Kriteria lolos (semua):**

| Metrik | Ambang minimum |
|---|---|
| Sinyal selesai (live, bukan backtest) | ≥ 30 |
| Rata-rata hasil per sinyal | > +0,2R |
| Total R live | Positif |
| Backtest 90 hari | Rata-rata > +0,2R, konsisten dengan hasil live |
| Drawdown maksimum paper auto-follow | < 15% ekuitas |

Jika belum terpenuhi pada 30 Nov: lanjutkan Fase 2 (perbaiki strategi), Fase 3 & 4 boleh tetap berjalan, **Fase 5 ditunda**.

### Fase 3 — Keamanan produksi (13 – 26 Nov)

Tujuan: server siap memegang API key exchange.

- [ ] **Perpanjang VPS** (sebelum 29 Nov). Pertimbangkan paket 12 bulan (hemat 15%) jika Fase 1 stabil
- [ ] **IP dedicated** (opsi provider) — IP NAT bersama melemahkan IP whitelist API exchange
- [x] Domain + **Cloudflare Tunnel** (HTTPS), lalu tutup port 30040 (`WEB_BIND=127.0.0.1`) — **29 Okt 2026: `https://dexsim.tune-in.trade`, port 8080 hanya localhost, firewall hanya SSH, cookie `secure`**
- [ ] Cloudflare: aktifkan *Always Use HTTPS*; hapus port forwarding 30040 dan domain di panel proxy vpsindonesia
- [ ] **2FA (TOTP)** untuk login, wajib untuk admin
- [ ] **Log audit**: login, perubahan pengaturan, setiap order (siapa, kapan, apa)
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
