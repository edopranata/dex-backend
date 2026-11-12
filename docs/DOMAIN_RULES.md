# Aturan domain DEX Simulator

Aturan pasar dan strategi yang harus dipatuhi kode. Setiap bagian menyebut statusnya: **berlaku** berarti sudah ada di kode, **rencana** berarti wajib diikuti saat fiturnya dibangun. Angka diambil dari `config/market.php`; kalau berbeda, kode yang benar. Sebagian angka bisa diubah pemilik di panel Admin, dan perubahan aturan strategi otomatis membuat versi strategi baru.

## Market (berlaku)

- Maksimal 20 token dipantau (`SIM_MAX_TOKENS`). Token masuk dari discovery atau ditambahkan manual; yang manual otomatis dikunci.
- Token yang tidak dikunci dan tidak dipakai (posisi, order, sinyal terbuka) dikeluarkan bila skornya turun selama 7 hari. Saat Market penuh, kandidat discovery yang kuat menggantikan token terlemah. Token yang baru dikeluarkan tidak boleh masuk lagi selama 3 hari.
- Satu token hanya punya satu sinyal terbuka. Token tidak boleh ada di Market dua kali lewat pool berbeda.

## Discovery (berlaku)

- Sumber kandidat: trending pool GeckoTerminal (6 jam, per jaringan), trending CoinGecko, dan boost DEX Screener. Boost adalah iklan berbayar; kandidat yang hanya muncul dari boost dikurangi 15 poin dan tidak pernah masuk Market otomatis.
- Saringan: likuiditas minimal $50.000, volume 24 jam minimal $100.000, umur pair minimal 24 jam, stablecoin dan koin besar dikeluarkan. Pair yang dihargai dalam token lain diganti dengan pair SOL, wrapped native, atau stablecoin bila ada.
- Skor: whale 35%, tren 30%, keamanan 20%, perhatian 15%. Masuk Market bila skor minimal 65, whale di atas 50, keamanan minimal 60, maksimal 3 token baru per putaran.
- Daftar Jupiter (Solana) dan setiap sumber per putaran hanya dicatat, sejak 12 Nov 2026. Belum memengaruhi skor atau pemilihan.

## Analisa dan rekomendasi (berlaku, versi v6)

- Dihitung tiap 15 menit dari candle 1 jam dan tren 4 jam. Skor akhir = teknikal 35% + on-chain 20% + whale 15% + sentimen 15% + fundamental 15%.
- Ambang: Strong Buy 72, Buy 60, Hold 42, Sell 30.
- Rekomendasi beli dibatalkan bila:
  - token honeypot, atau likuiditas turun lebih dari 40% dalam 24 jam;
  - skor on-chain di bawah 40 (menjadi Avoid);
  - data candle belum cukup, atau skor teknikal di bawah 50 (menjadi Hold);
  - **10 holder teratas memegang 20% supply atau lebih** menurut cek keamanan terakhir (menjadi Hold; v6, 12 Nov 2026). Tanpa data holder aturan ini dilewati;
  - untuk Buy saja: harga sudah naik lebih dari 15% dalam 24 jam, atau entry kurang dari 5% di bawah puncak 24 jam (v4);
  - jarak stop di bawah 4% dari harga (biaya akan memakan hasilnya).
- **Hanya Strong Buy yang membuat sinyal** (v5, 12 Nov 2026). Buy tetap dianalisa dan tampil sebagai pantauan.
- Setiap analisa dan sinyal menyimpan versi strategi dan kondisi pasar saat itu.

## Sinyal (berlaku)

- Level: zona entry, stop di bawah swing low dengan jarak 1-3 ATR, target 1,5R, 2,5R, dan 4R.
- Menunggu entry paling lama 12 jam. Kalau harga menyentuh stop sebelum masuk zona, sinyal batal.
- Setelah entry: sepertiga posisi dijual di tiap target, stop pindah ke harga entry setelah TP1, dan sinyal ditutup paling lama 72 jam setelah entry.
- Level hanya dianggap tercapai bila dua pengecekan harga berurutan setuju. Bila harga DEX Screener berbeda lebih dari batas toleransi dengan pool yang sama di GeckoTerminal, harga GeckoTerminal yang dipakai (sejak 10 Nov 2026).
- Hasil dicatat dalam R bersih: harga keluar sebenarnya, fee 0,3% per swap, dan price impact.

## Simulator dan auto-follow (berlaku)

- Saldo awal $10.000, fee swap 0,3%, price impact dari likuiditas pool. Harga lebih tua dari 30 detik diambil ulang sebelum order dieksekusi.
- Akun auto-follow mengikuti setiap sinyal baru: maksimal 4 posisi terbuka, risiko 0,5% ekuitas per posisi (sejak 12 Nov 2026), posisi maksimal 25% ekuitas dan 0,25% likuiditas pool, berhenti membeli untuk hari itu (WIB) setelah rugi terealisasi 3%.
- Gas per swap: Solana, BSC, dan Base $0,01; Ethereum $0,25.

## Catatan bayangan (berlaku)

Tidak mengubah trading, hanya mengukur calon aturan:

- Exit bila analisa pertama setelah entry tidak lagi Buy, exit setelah dua analisa berturut-turut tidak Buy, dan entry yang dikonfirmasi analisa sebelumnya (sejak 7 Nov 2026).
- Strong Buy yang ditahan aturan holder, dan setiap sinyal asli sebagai pembanding, diikuti di candle 15 menit dengan aturan sinyal di atas (sejak 12 Nov 2026). Disimpan terpisah dari sinyal asli, jadi tidak memblokir sinyal, membuat order, atau mengirim notifikasi.

## Aturan keputusan strategi (berlaku sejak 4 Nov 2026)

- Satu perubahan strategi per versi.
- Versi baru dinilai bila punya minimal 100 trade di backtest yang hanya memakai masa token di Market, dan minimal 30 sinyal live selesai.
- Hanya bukti di luar sampel yang dipakai untuk memutuskan.
- Kriteria lolos Fase 2 ada di [ROADMAP.md](ROADMAP.md).

## Uang sungguhan (rencana, Fase 5)

Wallet trading terpisah dan bersaldo kecil, setiap pembelian disetujui pemilik lewat Telegram, aplikasi hanya boleh swap dan tidak pernah transfer, dan batas modal ditegakkan server. Rinciannya di [ROADMAP.md](ROADMAP.md) bagian 5.
