# Status DEX Simulator

Terakhir diperbarui: 18 November 2026. Perbarui file ini di commit yang sama setiap kali sebuah tugas selesai.

## Sekarang

- Berjalan di https://dexsim.tune-in.trade. Uang sungguhan belum ada: semua transaksi virtual.
- Server: VPSMurah kanaka-vps (3 vCPU, 6 GB) dengan IP dedicated sejak 13 Nov, Docker, Cloudflare Tunnel. Masa sewa sampai 29 Nov 2026; pemilik memperpanjang 1-2 bulan sekaligus. Detail di `docs/INFRASTRUKTUR.md` repo bersama.
- Produksi per 18 Nov 11.59 WIB: backend 11e872a, frontend 26239ac (rincian per sinyal di catatan dan sinyal bayangan dengan BTC dan Fear & Greed saat entry; BTC sebelum 15 Nov dihitung ulang dari candle; bayangan jeda setelah stop 12/24/48 jam). Diperiksa 12.00 WIB: container sehat, laporan v6 1,7 detik, tanpa error log. Sebelumnya 15 Nov: bayangan Buy dengan Fear & Greed dan acuan BTC.

### Strategi: v6 (`v6-2b1128`, sejak 12 Nov 10.27 WIB)

- Hanya Strong Buy yang membuat sinyal. Buy tetap dianalisa dan tampil sebagai pantauan (v5).
- Buy dan Strong Buy menjadi Hold bila 10 holder teratas memegang 20% supply atau lebih (v6).
- Auto-follow: maksimal 4 posisi, risiko 0,5% per posisi, batas rugi harian 3%.
- Semua akun menjual posisinya otomatis saat analisa token menjadi Sell, Strong Sell, atau Hindari. Sinyal v6 tetap diukur dengan aturan aslinya, jadi selisih hasil akun dan hasil sinyal menunjukkan dampak aturan ini.
- Aturan lengkapnya di [DOMAIN_RULES.md](DOMAIN_RULES.md); alasan v5 dan v6 di [DECISIONS.md](DECISIONS.md).
- Bukti yang mendasarinya belum memenuhi kriteria Fase 2. Strong Buy historis +0,07R per sinyal (PF 1,14); dengan top 10 di bawah 20%, sinyal live +0,50R (n=25) dan putar ulang +0,09R (n=44). Ambang 20% dipilih dari data yang sama.
- Seluruh data sejak 31 Okt berasal dari pasar turun: BTC dari sekitar US$81 ribu ke US$64 ribu (29 Okt - 15 Nov). Label tren SOL dan ETH di konteks pasar tidak bisa dipakai untuk periode setelah 1 Nov (SOL) dan 3 Nov (ETH): candle acuan berhenti diperbarui karena keduanya tidak pernah di Market. Diperbaiki 15 Nov; sejak itu SOL, ETH, dan BTC ikut setiap putaran candle.

### Sedang diukur (hanya pencatatan, tidak mengubah trading)

| Apa | Sejak | Dinilai | Di mana |
|---|---|---|---|
| Sinyal v6 sungguhan | 12 Nov 10.27 WIB | Setelah sekitar 30 sinyal, perkiraan 2-3 minggu | Laporan, versi `v6-…` |
| Strong Buy yang ditahan aturan holder, dibanding sinyal asli (`shadow_signals`) | 12 Nov sekitar 14.00 WIB | Bersama review v6 | Laporan → Uji aturan holder v6 |
| Exit first_check, dua kali tidak Buy, entry terkonfirmasi | 7 Nov | Bersama review v6 | Laporan → Catatan bayangan |
| Konfirmasi candle 1 jam (body solid, close di atas high sebelumnya), dengan dan tanpa volume ≥ 1,5x rata-rata 20 jam | 16 Nov (dihitung sejak 7 Nov dari candle tersimpan) | Bersama review v6, lalu diuji ulang saat pasar naik | Laporan → Catatan bayangan |
| Exit trailing ATR 3x candle 4 jam sejak entry, tanpa target, paling lama 30 hari (batas sejak 17 Nov) | 16 Nov (dihitung sejak 7 Nov dari candle tersimpan) | Bersama review v6 | Laporan → Catatan bayangan |
| Candle token bersinyal di luar Market tiap 2 jam (sebelumnya ±9 jam), supaya bayangan setelah sinyal tidak tertinggal | 16 Nov | Pantau beban di Admin → Token | Admin → Token |
| Jadwal antrean diratakan (token keluar :09/:24/:39/:54, discover :12/:42) dan deploy menunggu celah sepi | 16 Nov | Diukur 16 Nov 12.09-13.02 WIB: puncak 43 job (hanya siklus utama, sebelumnya 63), tunggu candle median 76 dtk maks 4,2 mnt (sebelumnya 4,2 / 12,4 mnt), discover menunggu 2 dtk (sebelumnya 7,6 mnt); menit 27-29 dan 57-59 kosong | Horizon, `app:deploy-window` |
| Jeda setelah stop: lewati sinyal baru pada token yang kena stop dalam 12, 24, atau 48 jam terakhir (pemilik 18 Nov, setelah AIA dan ARIA entry lagi 1,5-2 jam setelah stop) | 18 Nov (dihitung sejak 7 Nov dari sinyal tersimpan) | Bersama review v6 | Laporan → Catatan bayangan |
| Lewati pair berumur di bawah 14 hari (dari studi dump Sue, `dex/review/hasil-13nov-dump.md`) | 13 Nov 23.00 WIB | Bersama review v6 | Laporan → Catatan bayangan |
| Hubungan dengan BTC per analisa (korelasi, beta, kekuatan relatif 24 jam) dan BTC di konteks pasar. A: lewati token yang ikut BTC (korelasi ≥ 0,3 dan ≥ 0,1) saat tren 4 jam BTC tidak naik. B: hanya token yang ikut BTC saat tren 4 jam BTC naik (studi `dex/review/hasil-15nov-btc.md`) | 15 Nov 19.00 WIB | A bersama review v6; B butuh fase BTC naik | Laporan → Catatan bayangan; token → Gerak terhadap BTC; Dashboard → Acuan pasar |
| Setiap Buy sebagai sinyal bayangan (`buy_watch`), dengan Fear & Greed; di Laporan dipisah menurut tren 4 jam BTC. Untuk usulan pemilik: Buy + Strong Buy saat BTC naik, hanya Strong Buy saat turun | 15 Nov sekitar 16.30 WIB | Saat pemicu di `docs/UJI_ULANG_PASAR_NAIK.md` repo bersama terpenuhi, atau sekitar 30 trade saat BTC naik | Laporan → Sinyal bayangan |
| Sumber discovery per putaran, termasuk Jupiter (`discovery_sightings`) | 12 Nov 11.25 WIB | Minggu depan: apakah token yang disepakati banyak sumber lebih baik | Belum ada halaman |
| Daftar trader paling untung Birdeye, Solana dan BSC (`trader_rankings`) | 12 Nov 13.37 WIB | Sekitar 26 Nov: apakah wallet bertahan di daftar | Belum ada halaman |

### AI

- Urutan provider: Gemini → Gemini model kedua (Gemma 4) → Groq (gpt-oss-120b) → Groq model kedua (gpt-oss-20b), diatur pemilik di panel.
- Ringkasan AI untuk Buy, Strong Buy, dan token yang sedang dipegang atau punya order menunggu. 20% kuota harian tiap provider disisakan untuk sentimen berita.
- Pemakaian 24 jam sampai 12 Nov 15.15 WIB: sekitar 210 permintaan (186 ringkasan unik, sekitar 22 batch sentimen), jauh di bawah kapasitas rantai sekitar 1.700 per hari.

## Langkah berikutnya

1. Review v6 setelah sekitar 30 sinyal, bersama hasil sinyal bayangan. Kolom "Data bercelah" di Laporan (sejak 17 Nov) menandai baris berbasis candle yang datanya kurang lengkap; nilai bersama atau tanpa baris itu. Sejak 18 Nov setiap baris catatan bayangan dan sinyal bayangan bisa diklik untuk melihat sinyalnya satu per satu (apa yang dilakukan aturan, R aktual dan R aturan), dengan harga BTC, tren 4 jam BTC, dan Fear & Greed saat entry dari snapshot analisa terakhir sebelum entry. Snapshot sebelum 15 Nov belum menyimpan BTC; untuk sinyal itu harga dan tren BTC dihitung ulang dari candle BTC yang sudah tutup sebelum entry (ditandai ≈ dan * di Laporan).
2. Setelah review v6 (disetujui pemilik 17 Nov): ukuran posisi auto-follow memasukkan biaya pulang-pergi. Audit 17 Nov: rugi nyata saat kena stop median 1,10x risiko rencana 0,5%, terburuk 2,95x.

## Menunggu

| Kapan | Yang dicek |
|---|---|
| 13 Nov pagi | Sentimen berita tidak lagi jatuh ke kamus kata (24 berita pada 11-12 Nov, sebelum perbaikan penghitung Gemini); hasil Birdeye hari kedua |
| 26 Nov | Pemilik menaikkan RAM VPS ke 8 GB dan memperpanjang 1-3 bulan (masa aktif sekarang sampai 29 Nov); cek layanan DEX setelah reboot |
| Sekitar 26 Nov | Uji ketahanan daftar trader Birdeye (14 hari data) |
| Sekitar 30 sinyal v6 | Review v6, termasuk tabel uji aturan holder |
| 28 Des | Data whale sendiri mencapai 60 hari; uji wallet diulang tanpa wallet yang ditandai bot (`dex/review/whale-btc.py`), termasuk perbandingan saat tren BTC naik dan turun |

## Menunggu pemilik

- Key Mistral (opsional; rantai AI sekarang sudah cukup).

## Belum terbukti

- Strategi mana pun di pasar yang tidak bearish.
- Ambang holder 20% di luar sampel.
- Halaman Laporan untuk tabel uji aturan holder belum dilihat dengan data asli.
- Jual otomatis dengan menunggu cek silang belum pernah terjadi di produksi. Penjualan pertama (12 Nov 20.56 WIB, AMDc dan FLOAT) memakai versi sebelumnya.
