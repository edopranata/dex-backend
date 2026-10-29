#!/usr/bin/env bash
# Setup VPS Ubuntu 24.04 baru untuk DEX Simulator. Jalankan sekali sebagai root, misalnya dari laptop:
#
#   ssh dex-vps 'bash -s' < deploy/server-setup.sh
#
# Opsi (environment variable, taruh sebelum `bash -s`, contoh: ssh dex-vps 'WEB_PORT=8080 bash -s' < ...):
#   ACTIONS_PUBKEY  public key GitHub Actions; hanya boleh menjalankan deploy (forced command)
#   TUNNEL_TOKEN    token Cloudflare Tunnel bersama (/opt/edge); bisa ditambahkan belakangan
#   WEB_PORT        port aplikasi di dalam VPS (default 8080)
#   PUBLIC_PORT     port publik yang dipakai browser; di VPS NAT diberikan panel port forwarding (default = WEB_PORT)
#   BASE_ONLY=1     hanya konfigurasi dasar server (Docker, firewall, swap, user), tanpa aplikasi
#   HARDEN_SSH=0    jangan matikan login SSH dengan password (default: dimatikan jika root sudah punya key)
#   TIMEZONE        zona waktu server (default Asia/Jakarta)
#   AUTO_REBOOT=0   jangan reboot otomatis untuk update keamanan (default: 04:00 WIB bila diperlukan)
#
# Aman dijalankan ulang: langkah yang sudah selesai dilewati, .env yang sudah ada tidak ditimpa.
# Catatan: skrip ini dibaca dari stdin, jadi setiap perintah yang bisa membaca stdin diberi </dev/null.
set -euo pipefail

REPO_BACKEND="${REPO_BACKEND:-https://github.com/edopranata/dex-backend.git}"
REPO_FRONTEND="${REPO_FRONTEND:-https://github.com/edopranata/dex-frontend.git}"
BRANCH="${BRANCH:-main}"
APP_ROOT="${APP_ROOT:-/opt/dex}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
WEB_PORT="${WEB_PORT:-8080}"
PUBLIC_PORT="${PUBLIC_PORT:-$WEB_PORT}"
ACTIONS_PUBKEY="${ACTIONS_PUBKEY:-}"
TUNNEL_TOKEN="${TUNNEL_TOKEN:-}"
SWAP_SIZE="${SWAP_SIZE:-2G}"
BASE_ONLY="${BASE_ONLY:-0}"
HARDEN_SSH="${HARDEN_SSH:-1}"
TIMEZONE="${TIMEZONE:-Asia/Jakarta}"
AUTO_REBOOT="${AUTO_REBOOT:-1}"

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[!] %s\033[0m\n' "$*"; }
ok() { printf '\033[1;32m[ok] %s\033[0m\n' "$*"; }
as_deploy() { sudo -u "$DEPLOY_USER" -H "$@"; }

[ "$(id -u)" -eq 0 ] || { echo "Jalankan sebagai root."; exit 1; }
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || warn "Dibuat untuk Ubuntu 24.04, terdeteksi: ${PRETTY_NAME:-unknown}"
export DEBIAN_FRONTEND=noninteractive

step "Info server"
echo "OS     : ${PRETTY_NAME:-?}"
echo "CPU    : $(nproc) vCPU"
echo "RAM    : $(awk '/MemTotal/ {printf "%.1f GB", $2/1024/1024}' /proc/meminfo)"
echo "Disk / : $(df -h / | awk 'NR==2 {print $4 " kosong dari " $2}')"

step "Zona waktu ${TIMEZONE}"
timedatectl set-timezone "$TIMEZONE"
ok "$(date)"

step "Update sistem & paket dasar"
apt-get update -y
apt-get upgrade -y
apt-get install -y ca-certificates curl gnupg git ufw fail2ban unattended-upgrades openssl

step "Docker Engine + Compose (repo resmi Docker)"
if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -y
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
systemctl enable --now docker >/dev/null
ok "$(docker --version)"

step "Swap ${SWAP_SIZE}"
if swapon --show | grep -q .; then
    ok "Swap sudah ada"
else
    fallocate -l "$SWAP_SIZE" /swapfile
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    echo 'vm.swappiness=10' > /etc/sysctl.d/99-swappiness.conf
    sysctl -p /etc/sysctl.d/99-swappiness.conf >/dev/null
    ok "Swap aktif"
fi

step "Firewall (SSH + port ${WEB_PORT})"
ufw allow OpenSSH >/dev/null
ufw allow "${WEB_PORT}/tcp" >/dev/null
ufw --force enable >/dev/null
ok "$(ufw status | head -1)"

step "SSH: login hanya dengan key"
if [ "$HARDEN_SSH" = "1" ] && [ -s /root/.ssh/authorized_keys ]; then
    cat > /etc/ssh/sshd_config.d/00-hardening.conf <<'CONF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
CONF
    sshd -t
    systemctl reload ssh 2>/dev/null || systemctl restart ssh
    ok "Login password SSH dimatikan (konsol VNC di panel provider tetap bisa dipakai)"
else
    warn "Dilewati: root belum punya authorized_keys atau HARDEN_SSH=0"
fi

step "fail2ban & update keamanan otomatis"
systemctl enable --now fail2ban >/dev/null
printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades
ok "Aktif"

step "User '${DEPLOY_USER}' untuk deploy"
if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "$DEPLOY_USER" >/dev/null
fi
usermod -aG docker "$DEPLOY_USER"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
touch "/home/$DEPLOY_USER/.ssh/authorized_keys"
chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"
if [ -n "$ACTIONS_PUBKEY" ] && ! grep -qF "$ACTIONS_PUBKEY" "/home/$DEPLOY_USER/.ssh/authorized_keys"; then
    echo "command=\"$APP_ROOT/backend/deploy/ci-deploy.sh\",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty $ACTIONS_PUBKEY" \
        >> "/home/$DEPLOY_USER/.ssh/authorized_keys"
    ok "Key GitHub Actions dipasang (hanya bisa menjalankan deploy)"
fi
ok "User siap (grup docker)"

step "Jaringan Docker bersama 'edge'"
docker network inspect edge >/dev/null 2>&1 || docker network create edge >/dev/null
ok "edge"

if [ "$BASE_ONLY" = "1" ]; then
    printf '\n\033[1;32mKonfigurasi dasar selesai.\033[0m Jalankan lagi tanpa BASE_ONLY setelah kode ada di GitHub.\n'
    exit 0
fi

step "Clone repository ke ${APP_ROOT}"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$APP_ROOT"
[ -d "$APP_ROOT/backend/.git" ] || as_deploy git clone -b "$BRANCH" "$REPO_BACKEND" "$APP_ROOT/backend"
[ -d "$APP_ROOT/frontend/.git" ] || as_deploy git clone -b "$BRANCH" "$REPO_FRONTEND" "$APP_ROOT/frontend"
ok "backend: $(as_deploy git -C "$APP_ROOT/backend" log -1 --format='%h %s')"
ok "frontend: $(as_deploy git -C "$APP_ROOT/frontend" log -1 --format='%h %s')"

step "Cek akses API dari IP server"
check() {
    local code
    code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$2" || echo 000)
    if [ "$code" = "200" ]; then ok "$1"; else warn "$1 menjawab HTTP $code"; fi
}
check "DEX Screener" "https://api.dexscreener.com/latest/dex/search?q=SOL"
check "GeckoTerminal" "https://api.geckoterminal.com/api/v2/networks?page=1"
check "GoPlus" "https://api.gopluslabs.io/api/v1/supported_chains"
check "CoinGecko" "https://api.coingecko.com/api/v3/search/trending"
check "Fear & Greed" "https://api.alternative.me/fng/"

SERVER_IP=$(curl -fsS -m 10 https://api.ipify.org || hostname -I | awk '{print $1}')

step "Konfigurasi backend/.env (production)"
cd "$APP_ROOT/backend"
FIRST_RUN=false
set_env() {
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
ensure_env() {
    grep -q "^$1=" .env || { echo "$1=$2" >> .env; ok "Tambah $1 ke .env"; }
}
if [ -f .env ]; then
    ok ".env sudah ada, nilai lama tidak diubah"
    ensure_env LOG_STACK "daily,stderr"
    ensure_env LOG_DAILY_DAYS 14
    ensure_env BACKUP_HOST_PATH /var/backups/dex
    ensure_env BACKUP_COOLDOWN_MINUTES 60
else
    FIRST_RUN=true
    cp .env.docker.example .env
    set_env APP_KEY "base64:$(openssl rand -base64 32)"
    set_env DB_PASSWORD "$(openssl rand -hex 24)"
    set_env MYSQL_ROOT_PASSWORD "$(openssl rand -hex 24)"
    MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
    POOL=$(( MEM_MB / 5 )); [ "$POOL" -lt 256 ] && POOL=256; [ "$POOL" -gt 2048 ] && POOL=2048
    set_env MYSQL_BUFFER_POOL "${POOL}M"
    set_env WEB_PORT "$WEB_PORT"
    set_env APP_URL "http://$SERVER_IP:$PUBLIC_PORT"
    set_env SANCTUM_STATEFUL_DOMAINS "$SERVER_IP:$PUBLIC_PORT"
    set_env SESSION_SECURE_COOKIE false
    chown "$DEPLOY_USER:$DEPLOY_USER" .env
    chmod 600 .env
    ok ".env dibuat dengan password acak"
fi

step "Build & jalankan container (pertama kali ±5-10 menit)"
as_deploy bash "$APP_ROOT/backend/deploy/deploy.sh" --no-pull </dev/null

if [ "$FIRST_RUN" = true ]; then
    step "Token awal"
    as_deploy docker compose -f "$APP_ROOT/backend/compose.yaml" exec -T app php artisan db:seed --class=MarketTokenSeeder --force </dev/null
fi

if [ -n "$TUNNEL_TOKEN" ]; then
    step "Cloudflare Tunnel bersama (/opt/edge)"
    install -d /opt/edge
    cp "$APP_ROOT/backend/deploy/edge/compose.yaml" /opt/edge/compose.yaml
    echo "CLOUDFLARE_TUNNEL_TOKEN=$TUNNEL_TOKEN" > /opt/edge/.env
    chmod 600 /opt/edge/.env
    docker compose -f /opt/edge/compose.yaml up -d </dev/null
    ok "cloudflared berjalan; atur public hostname di dashboard Cloudflare -> http://dexsim:80"
fi

step "Auto-start saat boot & watchdog"
install -m 644 "$APP_ROOT/backend/deploy/systemd/dex.service" /etc/systemd/system/dex.service
systemctl daemon-reload
systemctl enable dex.service >/dev/null 2>&1
systemctl start dex.service
echo "*/2 * * * * root $APP_ROOT/backend/deploy/watchdog.sh >> /var/log/dex-watchdog.log 2>&1" > /etc/cron.d/dex-watchdog
ok "dex.service aktif saat boot; watchdog cek /up tiap 2 menit"

if [ "$AUTO_REBOOT" = "1" ]; then
    printf 'Unattended-Upgrade::Automatic-Reboot "true";\nUnattended-Upgrade::Automatic-Reboot-Time "04:00";\n' > /etc/apt/apt.conf.d/52dex-auto-reboot
    ok "Reboot otomatis 04:00 WIB hanya jika update keamanan membutuhkannya"
fi

step "Backup database harian (02:30 WIB, cron root)"
install -d -m 700 -o 82 -g 82 /var/backups/dex
chown -R 82:82 /var/backups/dex
echo "30 2 * * * root $APP_ROOT/backend/deploy/backup.sh >> /var/log/dex-backup.log 2>&1" > /etc/cron.d/dex-backup
ok "Backup ke /var/backups/dex (disimpan 14 hari), terlihat & bisa diunduh di Admin > Backup"

cat <<MSG

$(printf '\033[1;32m')Selesai!$(printf '\033[0m') Aplikasi: http://$SERVER_IP:$PUBLIC_PORT

  Buat akun:        cd $APP_ROOT/backend && docker compose exec app php artisan app:create-user
  Data pasar awal:  docker compose exec app php artisan market:bootstrap
  Log:              docker compose logs -f app queue scheduler
MSG
