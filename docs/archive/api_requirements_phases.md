# Dokumentasi Persiapan API Pihak Ketiga (Third-Party APIs)
**Proyek:** Real-Time Crypto Trading Simulator & Market Analyzer

Berikut adalah daftar lengkap Web API pihak ketiga yang perlu disiapkan, dikelompokkan berdasarkan fungsinya, beserta tahapan integrasinya dalam proses pengembangan (*development phases*).

## 1. Daftar Web API yang Dibutuhkan

| Kategori | Nama Layanan API | Kegunaan Utama | Status Biaya |
| :--- | :--- | :--- | :--- |
| **Harga & Data DEX** | **DEX Screener API** | Menarik harga *real-time*, profil token, likuiditas, dan riwayat transaksi (Whale Tracker). | **Gratis** (Sangat direkomendasikan) |
| **Harga & Data DEX (Opsional)** | **Birdeye API** | Alternatif DEX Screener jika fokus utama Anda di jaringan Solana. | Gratis (Terbatas) / Berbayar |
| **Indikator Teknikal** | **TAAPI.io** | Mengambil nilai indikator langsung jadi (RSI, MACD, EMA) tanpa perlu hitung manual di server. | Gratis (Terbatas) / Berbayar |
| **Agregator Berita** | **CryptoPanic API** | Mengambil berita kripto terbaru secara *real-time* dari berbagai sumber media dan forum. | **Gratis** |
| **Analisis Sentimen (AI)** | **Gemini API / OpenAI API** | Membaca berita dari CryptoPanic dan menentukan sentimen (*Bullish/Bearish/Neutral*). | Gratis (Gemini) / Berbayar (OpenAI) |
| **Data On-Chain** | **Etherscan / Solscan API** | Melacak jumlah *holder* token, pergerakan dompet *developer*, dan verifikasi *smart contract*. | **Gratis** (Dengan batasan *rate limit*) |

---

## 2. Fase Integrasi API (Development Phases)

Untuk menjaga agar proses pengembangan tetap terstruktur, integrasikan API di atas secara bertahap sesuai fase berikut:

### Fase 1: Membangun Inti Simulator (Trading Dasar)
Fokus pada fungsi beli, jual, dan pemantauan harga secara *real-time* sebelum masuk ke analisis yang rumit.

*   **API yang digunakan:** DEX Screener API
*   **Target Implementasi:**
    *   Vue JS melakukan *fetching* (tarik data) ke API DEX Screener setiap 3-5 detik.
    *   Menampilkan grafik *candlestick* di antarmuka (menggunakan TradingView Lightweight Charts).
    *   Backend Laravel menyimpan transaksi virtual berdasarkan harga *real-time* yang ditarik tersebut.

### Fase 2: Analisis Teknikal & Deteksi Transaksi Besar (Whale)
Fokus pada pembuatan *Cronjob* dan *Queue* di Laravel untuk membaca kondisi teknikal pasar.

*   **API yang digunakan:** DEX Screener API & TAAPI.io
*   **Target Implementasi:**
    *   **Whale Tracker:** Laravel Job memanggil DEX Screener API untuk mendeteksi transaksi pembelian/penjualan bernilai di atas $10,000.
    *   **Technical Indicator:** Laravel Job memanggil TAAPI.io (atau menghitung manual dari data *candle* DEX Screener) untuk mendapatkan angka RSI dan MACD setiap 15 menit.

### Fase 3: Integrasi Berita & Analisis Sentimen AI
Fokus menambahkan indikator fundamental dan sentimen pasar ke dalam algoritma rekomendasi.

*   **API yang digunakan:** CryptoPanic API & Gemini/OpenAI API
*   **Target Implementasi:**
    *   Laravel Job memanggil CryptoPanic API setiap jam untuk mengambil 10 berita terbaru terkait token yang sedang dipantau.
    *   Laravel mengirimkan judul berita tersebut ke API AI (Gemini/OpenAI) dengan *prompt*: *"Berikan skor sentimen 1-100 untuk berita kripto ini"*.
    *   Hasil skor disimpan di *database* untuk digabungkan dengan skor analisis teknikal.

### Fase 4: Analisis On-Chain & Penilaian Akhir (Scoring System)
Fokus pada keamanan token dan pembuatan sistem sinyal perdagangan yang matang.

*   **API yang digunakan:** Etherscan (Ethereum/Base/Arbitrum) atau Solscan (Solana)
*   **Target Implementasi:**
    *   Laravel Job memeriksa API *blockchain explorer* untuk memastikan jumlah pemegang token (*holders*) terus bertambah dan likuiditas tidak ditarik tiba-tiba (*rug pull check*).
    *   Laravel menghitung semua data dari Fase 2, 3, dan 4 untuk menghasilkan sinyal final: **"Strong Buy", "Hold", atau "Sell"**, yang akan langsung muncul di *dashboard* Vue JS pengguna.