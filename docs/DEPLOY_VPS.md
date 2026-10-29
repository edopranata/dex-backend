# Deploy ke VPS (Docker + GitHub Actions)

## 1. Gambaran

```
Laptop ──git push──► GitHub (dex-backend, dex-frontend)
                        └─ Actions: tes/build ──SSH (key khusus deploy)──► VPS
                                                                          └─ /opt/dex/backend/deploy/deploy.sh
VPS
/opt/dex/backend   clone dex-backend  (+ .env production, tidak di-commit)
/opt/dex/frontend  clone dex-frontend
/opt/edge          Cloudflare Tunnel bersama untuk semua aplikasi (nanti)

Container (docker compose, proyek "dex"):
  web (Nginx, port 8080 & alias "dexsim" di jaringan edge) ─► app (PHP-FPM + frontend hasil build:laravel)
  horizon (queue default + market-data) · scheduler · migrate (sekali jalan) · mysql (volume) · redis (volume)
```

- **Sekarang:** akses lewat `http://IP_VPS:8080`.
- **Nanti:** `dexsim.domain.com` lewat Cloudflare Tunnel. Port 80/443 tetap bebas untuk web utama `domain.com` dan subdomain lain.

## 2. SSH key dari laptop ke VPS (sekali)

Buat key khusus untuk VPS ini (isi passphrase agar aman):

```bash
ssh-keygen -t ed25519 -f ~/.ssh/dex_vps -C "edo@laptop dex-vps"
ssh-add --apple-use-keychain ~/.ssh/dex_vps        # macOS: simpan passphrase di Keychain
ssh-copy-id -i ~/.ssh/dex_vps.pub root@IP_VPS      # masukkan password root dari provider (sekali)
```

Di VPS NAT (seperti vpsindonesia), port SSH bukan 22: lihat detail VPS di panel dan tambahkan `-p PORT` pada `ssh-copy-id`.

Tambahkan ke `~/.ssh/config` supaya cukup mengetik `ssh dex-vps`:

```
Host dex-vps
    HostName IP_VPS
    Port PORT_SSH
    User root
    IdentityFile ~/.ssh/dex_vps
    AddKeysToAgent yes
    UseKeychain yes
```

Uji: `ssh dex-vps 'hostname && uptime'`.

Setelah login dengan key berhasil, matikan login root dengan password (opsional tapi disarankan):

```bash
ssh dex-vps "sed -i 's/^#\?PasswordAuthentication .*/PasswordAuthentication no/' /etc/ssh/sshd_config && systemctl reload ssh"
```

## 3. Push kode ke GitHub (pertama kali)

```bash
cd backend  && git push -u origin main
cd ../frontend && git push -u origin main
```

Kedua repo **publik**: siapa pun bisa melihat kodenya. Rahasia tidak ikut ter-push (`.env` di-ignore; production `.env` hanya ada di VPS). Jika ingin privat, ubah visibilitas repo dan pasang deploy key read-only di VPS (lihat bagian 9).

## 4. Setup VPS (sekali)

Dari laptop, di folder `backend/`:

```bash
ssh dex-vps 'bash -s' < deploy/server-setup.sh
```

Skrip ini:

1. Menampilkan info server, update sistem, memasang Docker Engine + Compose (repo resmi), swap 2 GB, firewall (SSH + 8080), fail2ban, dan update keamanan otomatis.
2. Membuat user `deploy` (grup docker) dan meng-clone kedua repo ke `/opt/dex`.
3. Mengecek akses DEX Screener, GeckoTerminal, GoPlus, CoinGecko, dan Fear & Greed dari IP VPS.
4. Membuat `/opt/dex/backend/.env` (password acak, buffer MySQL sesuai RAM, pendaftaran publik ditutup, URL `http://IP:8080`).
5. Membuat jaringan Docker `edge`, build image, menjalankan semua container, mengisi token awal, dan memasang backup harian.

Lalu buat akun dan isi data awal:

```bash
ssh dex-vps
cd /opt/dex/backend
docker compose exec app php artisan app:create-user
docker compose exec app php artisan market:bootstrap      # ±5 menit
```

Buka `http://IP_VPS:8080`.

> Selama memakai IP, koneksi belum terenkripsi (HTTP). Pakai password yang tidak dipakai di tempat lain, dan pindah ke Cloudflare Tunnel (bagian 7) begitu domain siap.

## 5. Deploy otomatis dari GitHub Actions

Buat key khusus untuk GitHub Actions (tanpa passphrase, hanya untuk deploy):

```bash
ssh-keygen -t ed25519 -N "" -f ~/.ssh/dex_github_actions -C "github-actions dex deploy"
```

Pasang public key-nya di VPS. Key ini dibatasi (*forced command*) sehingga hanya bisa menjalankan `deploy/ci-deploy.sh`: tidak bisa membuka shell, tidak bisa port forwarding.

```bash
ssh dex-vps "ACTIONS_PUBKEY='$(cat ~/.ssh/dex_github_actions.pub)' bash -s" < deploy/server-setup.sh
```

Simpan secrets di **kedua** repo (perintah `gh` dari laptop):

```bash
for repo in edopranata/dex-backend edopranata/dex-frontend; do
  gh secret set VPS_HOST        -R $repo --body "IP_VPS"
  gh secret set VPS_PORT        -R $repo --body "PORT_SSH"      # VPS NAT: port dari panel (mis. 20201)
  gh secret set VPS_SSH_KEY     -R $repo < ~/.ssh/dex_github_actions
  gh secret set VPS_KNOWN_HOSTS -R $repo --body "$(ssh-keyscan -p PORT_SSH -t ed25519 IP_VPS 2>/dev/null)"
done
```

Hapus private key dari laptop jika tidak dibutuhkan lagi (`rm ~/.ssh/dex_github_actions`); key baru bisa dibuat kapan saja.

Alur sehari-hari:

| Repo | Saat push ke `main` |
|---|---|
| `dex-backend` | `php artisan test`, jika lolos → deploy |
| `dex-frontend` | `npm run build` (type-check + build), jika lolos → deploy |

Deploy menarik **kedua** repo, membangun ulang image, menjalankan migrasi, mengganti container, lalu mengecek `/up`. Push ke dua repo bersamaan aman: deploy di server diantrikan (`flock`). Deploy manual: tab **Actions → Run workflow**, atau `ssh dex-vps 'sudo -u deploy bash /opt/dex/backend/deploy/deploy.sh'`.

## 6. Operasional

| Kebutuhan | Perintah (di VPS, folder `/opt/dex/backend`) |
|---|---|
| Status container | `docker compose ps` |
| Log | `docker compose logs -f app horizon scheduler` · dashboard queue: `/horizon` (admin) |
| Artisan | `docker compose exec app php artisan <perintah>` |
| Ubah konfigurasi | Edit `.env`, lalu `docker compose up -d` |
| Backup manual | `bash deploy/backup.sh` (otomatis tiap 02:30 ke `/var/backups/dex`) |
| Restore | `gunzip -c /var/backups/dex/FILE.sql.gz \| docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'` |
| Pemakaian disk | `df -h` dan `docker system df` |

Aktifkan AI: isi `AI_PROVIDER=gemini` dan `GEMINI_API_KEY=...` di `.env`, lalu `docker compose up -d`.

## 6b. Pemulihan otomatis (server mati / reboot)

Tiga lapis, dipasang oleh `deploy/server-setup.sh`:

| Lapis | Apa yang dilakukan |
|---|---|
| Docker `restart: unless-stopped` | Container yang crash langsung dijalankan ulang oleh Docker. |
| `dex.service` (systemd) | Saat boot menjalankan `docker compose up -d` (urutan MySQL/Redis → migrate → app/queue/scheduler → web) plus Cloudflare Tunnel jika ada; saat shutdown menghentikan container dengan rapi. |
| Watchdog (cron tiap 2 menit) | Cek `http://127.0.0.1:8080/up`; gagal 2× berturut-turut → `docker compose up -d`, lalu restart web & app bila perlu. Tidak berjalan saat deploy. Log: `/var/log/dex-watchdog.log`. |

Update keamanan yang membutuhkan reboot dijalankan otomatis pukul 04:00 WIB (matikan dengan `AUTO_REBOOT=0` saat menjalankan setup).

Perintah berguna:

```bash
systemctl status dex                  # status service
tail -f /var/log/dex-watchdog.log     # riwayat pemulihan
sudo reboot                           # uji: setelah ±1–2 menit semua layanan harus kembali
```

## 7. Pindah ke domain dengan Cloudflare Tunnel

Satu tunnel dipakai bersama oleh semua aplikasi di VPS ini.

1. Tambahkan `domain.com` ke Cloudflare (paket Free) dan ganti nameserver di registrar.
2. **Zero Trust → Networks → Tunnels → Create tunnel** (Cloudflared, nama mis. `vps`), salin **token**.
3. Jalankan tunnel bersama di VPS:
   ```bash
   ssh dex-vps "TUNNEL_TOKEN='eyJ...' bash -s" < deploy/server-setup.sh
   ```
   (atau manual: salin `deploy/edge/compose.yaml` ke `/opt/edge`, isi `/opt/edge/.env`, `docker compose -f /opt/edge/compose.yaml up -d`).
4. Tab **Public Hostname** tunnel: `dexsim.domain.com` → service **HTTP** → `dexsim:80`.
5. Ubah `/opt/dex/backend/.env`:
   ```dotenv
   APP_URL=https://dexsim.domain.com
   SANCTUM_STATEFUL_DOMAINS=dexsim.domain.com
   SESSION_SECURE_COOKIE=true
   WEB_BIND=127.0.0.1
   ```
   lalu `docker compose up -d` dan tutup port: `ufw delete allow 8080/tcp`.

Aplikasi lain (mis. web utama `domain.com`) cukup bergabung ke jaringan `edge` dengan alias sendiri, lalu tambahkan public hostname baru di tunnel yang sama:

```yaml
services:
  web:
    networks:
      default:
      edge:
        aliases: [mainweb]      # Cloudflare: domain.com -> http://mainweb:80
networks:
  edge:
    external: true
```

## 8. Troubleshooting

| Gejala | Penyebab & solusi |
|---|---|
| Login "CSRF token mismatch" (419) | `SANCTUM_STATEFUL_DOMAINS` harus sama persis dengan host di address bar, termasuk port (`IP:8080`). Saat via IP, `SESSION_SECURE_COOKIE=false`. |
| Actions gagal "Permission denied (publickey)" | Public key Actions belum terpasang di `/home/deploy/.ssh/authorized_keys`, atau secret `VPS_SSH_KEY` tidak lengkap (harus termasuk baris BEGIN/END). |
| Actions gagal "Host key verification failed" | Isi ulang secret `VPS_KNOWN_HOSTS` dengan `ssh-keyscan -p PORT_SSH -t ed25519 IP_VPS` (formatnya `[IP]:PORT ssh-ed25519 ...`). |
| Aplikasi tidak bisa dibuka di `http://IP:8080` | VPS NAT hanya meneruskan port tertentu. Pakai port yang diteruskan (lihat panel) sebagai `WEB_PORT` di `.env` dan `ufw allow PORT/tcp`, atau langsung pakai Cloudflare Tunnel (tidak butuh port masuk). |
| Deploy gagal di `git pull` | Ada perubahan manual di `/opt/dex/*` di server. Cek `git -C /opt/dex/backend status`; jangan edit kode langsung di server. |
| Cloudflare error 502/1033 | Cek `docker compose -f /opt/edge/compose.yaml logs`; public hostname harus `http://dexsim:80`; container web harus jalan. |
| Data candle/whale tidak bertambah | `docker compose logs queue`; banyak HTTP 429 berarti IP dibatasi GeckoTerminal: turunkan `SIM_MAX_TOKENS` atau `GECKOTERMINAL_RPM`. |
| Container `migrate` gagal | `docker compose logs migrate`; password MySQL hanya berlaku saat volume pertama kali dibuat, jangan ubah `DB_PASSWORD` setelahnya. |

## 9. Jika repo dijadikan privat

Di VPS, sebagai user `deploy`, buat key per repo dan daftarkan sebagai **Deploy key (read-only)** di Settings repo masing-masing:

```bash
sudo -u deploy ssh-keygen -t ed25519 -N "" -f /home/deploy/.ssh/github_backend
sudo -u deploy ssh-keygen -t ed25519 -N "" -f /home/deploy/.ssh/github_frontend
```

Tambahkan host alias di `/home/deploy/.ssh/config` (`github-backend`, `github-frontend` dengan `IdentityFile` masing-masing), lalu ganti remote: `git -C /opt/dex/backend remote set-url origin git@github-backend:edopranata/dex-backend.git` (sama untuk frontend).

## 10. Alternatif tempat deploy

Stack ini hanya butuh **satu mesin Linux dengan Docker** (±1 GB RAM saat jalan, ±2 GB saat build). Karena dengan Cloudflare Tunnel server tidak perlu IP publik atau port terbuka, pilihannya luas. Harga di bawah adalah kisaran dari sumber pihak ketiga per Oktober 2026 dan sering berubah; cek halaman harga masing-masing sebelum memesan.

| Pilihan | Latensi dari Indonesia | Kisaran biaya | Catatan |
|---|---|---|---|
| **VPS lokal Indonesia** (vpsindonesia, IDCloudHost, Biznet Gio) | Terendah (±5–20 ms) | Rupiah, bervariasi | Bayar dalam Rupiah, support lokal. Cek dulu IP-nya tidak diblokir GeckoTerminal (skrip setup mengecek otomatis). |
| **Contabo Singapura** | ±15–30 ms | Termurah per GB RAM (sumber menyebut ±US$5 untuk 4 vCPU / 8 GB) | Nilai terbaik untuk aplikasi ini; performa disk lebih rendah dari DigitalOcean, tidak masalah untuk beban kita. |
| **Akamai/Linode** (Singapura, ada region Jakarta) | ±5–30 ms | Menengah (sumber menyebut US$12–20 untuk 4 GB) | Jaringan stabil; region Jakarta membuat latensi sangat rendah. |
| **Vultr / DigitalOcean Singapura** | ±15–30 ms | Lebih mahal (sekitar US$20–24 untuk 4 GB) | Andal, dashboard bagus, tetapi lebih mahal untuk spesifikasi sama. |
| **Oracle Cloud Always Free** (Singapura/Jakarta, ARM) | ±5–30 ms | Gratis | Image kita sudah teruji di ARM. Kuota gratis dilaporkan dipotong pada 2026 dan kapasitas ARM di Singapura sering habis; ada risiko instance gratis yang dianggap idle direklamasi. Cocok untuk eksperimen, bukan andalan. |
| **Server di rumah** (mini PC/laptop bekas) + Cloudflare Tunnel | Tergantung ISP | Hanya listrik | Tanpa IP publik dan tanpa port forwarding berkat tunnel. Bergantung listrik & internet rumah; IP residensial biasanya lebih longgar terhadap rate limit API. |
| **Hetzner Singapura** | ±15–30 ms | Naik tajam sejak Juni 2026 | Paket murah (CX/CAX) dilaporkan tidak tersedia; tidak lagi menarik. |
| **PaaS** (Fly.io, Railway, Render) | Region Singapura tersedia | Biasanya lebih mahal untuk stack ini | Butuh 5 proses (app, queue, scheduler, MySQL, Redis) yang ditagih terpisah, dan `compose.yaml` tidak bisa dipakai langsung. Tidak disarankan. |

Rekomendasi untuk simulator pribadi: **VPS 2 vCPU / 4 GB di Indonesia atau Singapura + Cloudflare Tunnel**. Paket 6 GB tetap aman, tetapi 4 GB sudah cukup.
