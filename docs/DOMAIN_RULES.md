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

## Analisa dan rekomendasi (berlaku, versi v8)

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
- **Strong Buy dengan skor 77 atau lebih tidak membuat sinyal** (v7, 21 Nov 2026). Rekomendasinya tetap Strong Buy di halaman token, dengan alasan di daftar alasan, dan diikuti sebagai sinyal bayangan `high_score` dengan skornya. Batas diatur `market.analysis.strong_buy_max_score` (0 = nonaktif).
- **Strong Buy pertama tidak membuat sinyal** (v8, 26 Nov 2026): Strong Buy hanya menjadi sinyal bila analisa token sebelumnya, paling lama 20 menit sebelumnya, juga Buy atau Strong Buy. Analisa yang ditahan menjadi Hold oleh aturan holder atau aturan mengejar harga dihitung bukan Buy. Rekomendasinya tetap Strong Buy di halaman token, dengan alasan di daftar alasan, dan diikuti sebagai sinyal bayangan `first_buy` dengan skornya. Batas diatur `market.analysis.previous_buy_max_minutes` (0 = nonaktif).
- Setiap analisa dan sinyal menyimpan versi strategi dan kondisi pasar saat itu.

## Sinyal (berlaku)

- Level: zona entry, stop di bawah swing low dengan jarak 1-3 ATR, target 1,5R, 2,5R, dan 4R.
- Menunggu entry paling lama 12 jam. Kalau harga menyentuh stop sebelum masuk zona, sinyal batal.
- Setelah entry: sepertiga posisi dijual di tiap target, stop pindah ke harga entry setelah TP1, dan sinyal ditutup paling lama 72 jam setelah entry.
- **Keluar saat analisa turun** (v7, 21 Nov 2026): sinyal yang sudah entry ditutup di harga analisa pertama yang tidak lagi Buy atau Strong Buy (status `downgraded`), dengan target yang sudah tercapai tetap dihitung. Berlaku untuk sinyal v7 dan sesudahnya (sejak v8 26 Nov 2026; sebelumnya hanya versi yang sedang berlaku); sinyal v6 yang masih terbuka saat pergantian ke v7 selesai dengan exit v6. Akun auto-follow ikut menutup lot-nya seperti saat sinyal selesai. Exit lama (target, stop, 72 jam) tetap diukur pada salinan bayangan sinyal (`accepted`) dan ditampilkan sebagai catatan bayangan "Tahan sampai target, stop, atau 72 jam seperti v6". Bisa dimatikan dengan `market.analysis.exit_on_downgrade`.
- Level hanya dianggap tercapai bila dua pengecekan harga berurutan setuju. Sejak 20 Nov 2026 pengecekan pertama harus berasal dari snapshot DEX Screener yang berbeda (sidik harga, harga native, volume, transaksi, dan perubahan 5 menit, serta likuiditas); snapshot yang sama dibaca ulang tidak mengonfirmasi apa pun. Bila harga DEX Screener berbeda lebih dari batas toleransi dengan pool yang sama di GeckoTerminal, harga GeckoTerminal yang dipakai (sejak 10 Nov 2026). Batasnya 3% sejak 26 Nov 2026 (sebelumnya 5%). Pool yang quote-nya bukan token utama (selain SOL, WETH/ETH, WBNB/BNB, stablecoin, dan BTC terbungkus; misalnya TAC/SPX) selalu memakai harga GeckoTerminal, dan bila GeckoTerminal tidak bisa ditanya, isian, jual penutup lot auto-follow, dan pergerakan sinyal menunggu paling lama 14 menit sebelum memakai harga DEX Screener; order market manual di pool itu ditolak selama menunggu (sejak 27 Nov 2026).
- Hasil dicatat dalam R bersih: harga keluar sebenarnya, fee 0,3% per swap, dan price impact.

## Simulator dan auto-follow (berlaku)

- Saldo awal $10.000, fee swap 0,3%, price impact dari likuiditas pool. Harga lebih tua dari 30 detik diambil ulang sebelum order dieksekusi.
- Akun auto-follow mengikuti setiap sinyal baru: maksimal 4 posisi terbuka, risiko 0,5% ekuitas per posisi (sejak 12 Nov 2026; sejak 21 Nov 2026 rugi saat stop sudah termasuk fee, gas, dan price impact beli dan jual, kecuali gap di bawah stop), posisi maksimal 25% ekuitas dan 0,25% likuiditas pool, berhenti membeli untuk hari itu (WIB) setelah rugi terealisasi 3%.
- Gas per swap: Solana, BSC, dan Base $0,01; Ethereum $0,25.
- **Jual otomatis saat Sell** (sejak 12 Nov 2026): begitu analisa sebuah token menjadi Sell, Strong Sell, atau Hindari, posisi token itu di semua akun (termasuk auto-follow) dijual di harga pasar dan order yang menunggu dibatalkan. Pemilik posisi mendapat pesan Telegram. Kalau harga tidak segar, penjualan menunggu analisa berikutnya. Harga jualnya harus sudah dicek silang ke GeckoTerminal; kalau cek silang tidak tersedia, penjualan menunggu satu siklus analisa (15 menit), lalu tetap dijalankan dengan harga DEX Screener (sejak 12 Nov 2026). Hasil sinyal tidak berubah oleh aturan ini (sinyal v7 sendiri keluar saat analisa tidak lagi Buy, yang mencakup Sell). Bisa dimatikan di Admin → Pengaturan → Simulator.
- Token yang sedang dipegang atau punya order menunggu selalu mendapat ringkasan AI, apa pun rekomendasinya (sejak 12 Nov 2026). Token lain hanya untuk Buy dan Strong Buy.

## Catatan bayangan (berlaku)

Tidak mengubah trading, hanya mengukur calon aturan:

- Exit bila analisa pertama setelah entry tidak lagi Buy, exit setelah dua analisa berturut-turut tidak Buy, dan entry yang dikonfirmasi analisa sebelumnya (sejak 7 Nov 2026).
- Konfirmasi candle (sejak 16 Nov 2026, dihitung dari candle 1 jam tersimpan untuk sinyal sejak 7 Nov): hanya entry yang candle 1 jam terakhir sebelum entry hijau solid (body ≥ separuh range, ekor atas ≤ seperempat) dan close di atas high jam sebelumnya; varian kedua menambah volume ≥ 1,5x rata-rata 20 jam. Bayangan saja, v6 tidak berubah.
- Exit chandelier (sejak 16 Nov 2026, dihitung dari candle tersimpan untuk sinyal sejak 7 Nov): tanpa target; stop awal = stop sinyal, lalu di tiap close candle 4 jam naik ke puncak sejak entry dikurangi 3x ATR14 4 jam, tidak pernah turun; dicek di candle 1 jam; sinyal yang belum kena stop dinilai di harga terakhir. Bayangan saja, v6 tidak berubah.
- Cara membaca baris bayangan (sejak 23 Nov 2026): posisi yang belum keluar dihitung terpisah ("masih terbuka", dinilai di close candle terakhir), dan "satu posisi per token" menghitung seperti akun sungguhan: sinyal yang entry saat aturan masih memegang sinyal sebelumnya di token yang sama dihitung 0R. Selisih terhadap R aktual memakai angka satu posisi per token.
- Lewati sinyal pada pair berumur di bawah 14 hari: sinyal itu dihitung 0R (sejak 13 Nov 2026 16.00 UTC).
- Hubungan dengan BTC (sejak 15 Nov 2026 12.00 UTC): setiap analisa menyimpan korelasi dan beta return 1 jam token terhadap BTC selama 72 jam (minimal 40 jam data), serta perubahan 24 jam token, BTC, dan selisihnya. Tren 4 jam BTC diambil dari konteks pasar (close dan EMA20 terhadap EMA50, sama seperti tren token). A: sinyal dari token yang ikut BTC (korelasi ≥ 0,3, varian ≥ 0,1) dihitung 0R bila tren 4 jam BTC tidak naik. B: hanya sinyal dari token dengan korelasi ≥ 0,3 saat tren 4 jam BTC naik yang dihitung; sisanya 0R. Sinyal tanpa data BTC tidak disentuh A dan berada di luar B.
- Setiap Buy (yang tidak menjadi sinyal sejak v5) dengan level entry, stop, dan target diikuti sebagai sinyal bayangan dengan cara yang sama seperti aturan holder, dengan nilai Fear & Greed saat analisa (sejak 15 Nov 2026). Di Laporan dipisah menurut tren 4 jam BTC saat analisa (naik atau tidak naik).
- Strong Buy yang ditahan aturan holder, dan setiap sinyal asli sebagai pembanding, diikuti di candle 15 menit dengan aturan sinyal di atas (sejak 12 Nov 2026). Disimpan terpisah dari sinyal asli, jadi tidak memblokir sinyal, membuat order, atau mengirim notifikasi.

## Aturan keputusan strategi (berlaku sejak 4 Nov 2026)

- Satu perubahan strategi per versi.
- Versi baru dinilai bila punya minimal 100 trade di backtest yang hanya memakai masa token di Market, dan minimal 30 sinyal live selesai.
- Hanya bukti di luar sampel yang dipakai untuk memutuskan.
- Kriteria lolos Fase 2 ada di [ROADMAP.md](ROADMAP.md).

## Uang sungguhan (rencana, Fase 5)

Wallet trading terpisah dan bersaldo kecil, setiap pembelian disetujui pemilik lewat Telegram, aplikasi hanya boleh swap dan tidak pernah transfer, dan batas modal ditegakkan server. Rinciannya di [ROADMAP.md](ROADMAP.md) bagian 5.
