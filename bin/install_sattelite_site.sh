#!/usr/bin/env bash
# Quick LEMP + auto-gpt-blog installer for Ubuntu 24.04
# Usage: sudo bash install_settelite_site.sh <linux_user> <domain> <lang>
# Example: sudo bash install_settelite_site.sh myresearchblog myresearchblog.co.uk en

set -euo pipefail

if [[ $EUID -ne 0 ]]; then echo "Please run as root (use sudo)."; exit 1; fi
if [[ $# -ne 3 ]]; then echo "Usage: $0 <linux_user> <domain> <lang>"; exit 1; fi

SYSUSER="$1"
DOMAIN_RAW="$2"
BLOG_LANG="$3"

# Canonical host logic: if input starts with "www." => canonical is www; else canonical is bare
if [[ "$DOMAIN_RAW" =~ ^www\. ]]; then
  CANON="$DOMAIN_RAW"           # www.domain
  ALT="${DOMAIN_RAW#www.}"      # domain
else
  CANON="$DOMAIN_RAW"           # domain
  ALT="www.${DOMAIN_RAW}"       # www.domain
fi

# Paths + consts
REPO_URL="https://github.com/adivvvv/auto-gpt-blog.git"
SITE_DIR="/home/${SYSUSER}"
APP_DIR="${SITE_DIR}/auto-gpt-blog"
WEBROOT="${APP_DIR}/public"
ENV_FILE="${APP_DIR}/.env"
ENV_EXAMPLE="${APP_DIR}/.env.example"
NGX_AVAIL="/etc/nginx/sites-available/${CANON}"
NGX_ENABLED="/etc/nginx/sites-enabled/${CANON}"
SSL_SNIPPET="/etc/nginx/snippets/ssl-params.conf"
API_KEY="XXXX-REPLACE-WITH-YOUR-OWN-KEY-XXXX"  # get your free key by setting up your feed service https://github.com/adivvvv/gpt-simple-generator
EMAIL="admin@${CANON#www.}"  # admin@rootdomain

export DEBIAN_FRONTEND=noninteractive

echo "==> apt update/upgrade…"
apt-get update -y
apt-get -o Dpkg::Options::="--force-confnew" dist-upgrade -y
apt-get autoremove -y

echo "==> install packages (nginx, php-fpm, extensions, git, certbot)…"
apt-get install -y nginx php-fpm php-cli php-curl php-xml php-mbstring php-zip php-intl git curl unzip certbot

# Detect PHP-FPM socket (Ubuntu 24 ships PHP 8.3; keep dynamic)
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_SOCK="/run/php/php${PHPV}-fpm.sock"
if [[ ! -S "$PHP_SOCK" ]]; then
  FOUND="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)"
  PHP_SOCK="${FOUND:-/run/php/php-fpm.sock}"
fi

echo "==> create system user ${SYSUSER} (if missing) and add to www-data…"
if ! id -u "${SYSUSER}" >/dev/null 2>&1; then
  adduser --disabled-password --gecos "" "${SYSUSER}"
fi
usermod -aG www-data "${SYSUSER}"

echo "==> prepare directories…"
mkdir -p "${SITE_DIR}"
chown -R "${SYSUSER}:www-data" "${SITE_DIR}"

echo "==> clone repo as ${SYSUSER}…"
if [[ ! -d "${APP_DIR}" ]]; then
  sudo -u "${SYSUSER}" -H bash -lc "cd ~ && git clone ${REPO_URL}"
else
  sudo -u "${SYSUSER}" -H bash -lc "cd ~/auto-gpt-blog && git pull --ff-only || true"
fi

# Ensure storage + data dirs are writable by php-fpm (group)
mkdir -p "${APP_DIR}/storage/cache" "${APP_DIR}/storage/logs" "${APP_DIR}/data/posts"
chown -R "${SYSUSER}:www-data" "${APP_DIR}"
find "${APP_DIR}/storage" -type d -exec chmod 2775 {} \; || true
find "${APP_DIR}/storage" -type f -exec chmod 664 {} \; || true
find "${APP_DIR}/data" -type d -exec chmod 2775 {} \; || true
if [[ ! -f "${APP_DIR}/data/posts.json" ]]; then
  echo '{"posts":[]}' > "${APP_DIR}/data/posts.json"
fi
chmod 664 "${APP_DIR}/data/posts.json" || true

echo "==> configure .env…"
if [[ ! -f "${ENV_FILE}" && -f "${ENV_EXAMPLE}" ]]; then cp "${ENV_EXAMPLE}" "${ENV_FILE}"; fi

sed -i "s|^FEED_BASE_URL=.*|FEED_BASE_URL=https://feed.mydomain.com|g" "${ENV_FILE}" || true
if grep -q "^FEED_API_KEY=" "${ENV_FILE}"; then
  sed -i "s|^FEED_API_KEY=.*|FEED_API_KEY=${API_KEY}|g" "${ENV_FILE}"
else
  echo "FEED_API_KEY=${API_KEY}" >> "${ENV_FILE}"
fi
if grep -q "^BLOG_LANG=" "${ENV_FILE}"; then
  sed -i "s|^BLOG_LANG=.*|BLOG_LANG=${BLOG_LANG}|g" "${ENV_FILE}"
else
  echo "BLOG_LANG=${BLOG_LANG}" >> "${ENV_FILE}"
fi
if grep -q "^BASE_URL=" "${ENV_FILE}"; then
  sed -i "s|^BASE_URL=.*|BASE_URL=https://${CANON}|g" "${ENV_FILE}"
else
  echo "BASE_URL=https://${CANON}" >> "${ENV_FILE}"
fi

echo "==> write temporary HTTP vhosts for ACME (no redirects yet)…"
cat > "${NGX_AVAIL}" <<NGINX
server {
  listen 80;
  listen [::]:80;
  server_name ${CANON};
  root ${WEBROOT};
  index index.php index.html;
  client_max_body_size 10M;

  location ^~ /.well-known/acme-challenge/ {
    root ${WEBROOT};
    default_type "text/plain";
    try_files \$uri =404;
  }

  location / {
    try_files \$uri \$uri/ /index.php?\$query_string;
  }

  location ~ \.php\$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:${PHP_SOCK};
  }

  location ~ /\.(?!well-known) { deny all; }
  location ~* \.(env|ini|log|sh|bak)\$ { deny all; }

  access_log /var/log/nginx/${CANON}_access.log;
  error_log  /var/log/nginx/${CANON}_error.log;
}

server {
  listen 80;
  listen [::]:80;
  server_name ${ALT};
  root ${WEBROOT};
  index index.php index.html;

  location ^~ /.well-known/acme-challenge/ {
    root ${WEBROOT};
    default_type "text/plain";
    try_files \$uri =404;
  }

  location / {
    try_files \$uri \$uri/ /index.php?\$query_string;
  }

  location ~ \.php\$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:${PHP_SOCK};
  }

  location ~ /\.(?!well-known) { deny all; }
  location ~* \.(env|ini|log|sh|bak)\$ { deny all; }

  access_log /var/log/nginx/${ALT}_access.log;
  error_log  /var/log/nginx/${ALT}_error.log;
}
NGINX

ln -sf "${NGX_AVAIL}" "${NGX_ENABLED}"
[[ -e /etc/nginx/sites-enabled/default ]] && rm -f /etc/nginx/sites-enabled/default

echo "==> test + reload nginx (HTTP only)…"
nginx -t
systemctl reload nginx

echo "==> obtain Let’s Encrypt certificate (for ${CANON}, ${ALT})…"
mkdir -p "${WEBROOT}/.well-known/acme-challenge"
certbot certonly --webroot -w "${WEBROOT}" \
  --cert-name "${CANON}" --expand \
  -d "${CANON}" -d "${ALT}" \
  --agree-tos -m "${EMAIL}" --no-eff-email --non-interactive

echo "==> write nginx TLS params snippet…"
cat > "${SSL_SNIPPET}" <<'SNIP'
ssl_protocols TLSv1.2 TLSv1.3;
ssl_prefer_server_ciphers off;
ssl_session_timeout 1d;
ssl_session_cache shared:SSL:50m;
ssl_session_tickets off;
ssl_ciphers HIGH:!aNULL:!MD5;
add_header X-Content-Type-Options nosniff;
add_header Referrer-Policy strict-origin-when-cross-origin;
add_header X-Frame-Options SAMEORIGIN;
SNIP

# Use the same lineage name as the primary domain we requested first.
CERT_ROOT="${CANON}"
REDIR_TARGET="https://${CANON}\$request_uri"

echo "==> replace HTTP vhosts with HTTPS + canonical redirects…"
cat > "${NGX_AVAIL}" <<NGINX
# 1) HTTP -> HTTPS redirect (both hosts), keep ACME reachable
server {
  listen 80;
  listen [::]:80;
  server_name ${CANON} ${ALT};
  root ${WEBROOT};

  location ^~ /.well-known/acme-challenge/ {
    root ${WEBROOT};
    default_type "text/plain";
    try_files \$uri =404;
  }

  location / {
    return 301 ${REDIR_TARGET};
  }
}

# 2) Non-canonical HTTPS -> canonical HTTPS
server {
  listen 443 ssl http2;
  listen [::]:443 ssl http2;
  server_name ${ALT};

  ssl_certificate     /etc/letsencrypt/live/${CERT_ROOT}/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/${CERT_ROOT}/privkey.pem;
  include ${SSL_SNIPPET};

  return 301 https://${CANON}\$request_uri;
}

# 3) Canonical HTTPS app server
server {
  listen 443 ssl http2;
  listen [::]:443 ssl http2;
  server_name ${CANON};

  ssl_certificate     /etc/letsencrypt/live/${CERT_ROOT}/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/${CERT_ROOT}/privkey.pem;
  include ${SSL_SNIPPET};

  root ${WEBROOT};
  index index.php index.html;
  client_max_body_size 10M;

  location / {
    try_files \$uri \$uri/ /index.php?\$query_string;
  }

  location ~ \.php\$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:${PHP_SOCK};
  }

  location ~ /\.(?!well-known) { deny all; }
  location ~* \.(env|ini|log|sh|bak)\$ { deny all; }

  access_log /var/log/nginx/${CANON}_ssl_access.log;
  error_log  /var/log/nginx/${CANON}_ssl_error.log;
}
NGINX

echo "==> test + reload nginx (HTTPS)…"
nginx -t
systemctl reload nginx

echo "==> restart php-fpm…"
systemctl restart "php${PHPV}-fpm" || systemctl restart php-fpm || true

echo "==> final perms…"
chown -R "${SYSUSER}:www-data" "${APP_DIR}"
find "${APP_DIR}" -type d -exec chmod 0755 {} \;
find "${APP_DIR}" -type f -exec chmod 0644 {} \;
find "${APP_DIR}/storage" -type d -exec chmod 2775 {} \; 2>/dev/null || true
find "${APP_DIR}/storage" -type f -exec chmod 0664 {} \; 2>/dev/null || true
chmod -R +x ${APP_DIR}/bin

# -------------------------
# Certbot auto-renew (nightly) + safe Nginx reload on success
# -------------------------
echo "==> configure nightly certbot renew (single server-level timer)…"
# Disable vendor timer to avoid double scheduling; our timer runs nightly.
systemctl disable --now certbot.timer 2>/dev/null || true

# Install deploy hook: reload Nginx only AFTER a successful renewal; coalesce via flock.
HOOK_DIR="/etc/letsencrypt/renewal-hooks/deploy"
HOOK="${HOOK_DIR}/000-reload-nginx.sh"
mkdir -p "${HOOK_DIR}"
cat > "${HOOK}" <<'HOOK'
#!/usr/bin/env bash
set -euo pipefail
# This runs once per successfully renewed certificate.
# Use flock so multiple cert renewals trigger at most one reload.
exec flock -n /run/certbot-nginx-reload.lock -c "systemctl reload nginx"
HOOK
chmod 755 "${HOOK}"

# Nightly certbot renew (03:00 + up to 2h randomized delay to stagger across servers)
cat > /etc/systemd/system/certbot-nightly.service <<'UNIT'
[Unit]
Description=Nightly certbot renew (all certificates)

[Service]
Type=oneshot
ExecStart=/usr/bin/certbot renew -q
UNIT

cat > /etc/systemd/system/certbot-nightly.timer <<'UNIT'
[Unit]
Description=Nightly certbot renew (randomized)

[Timer]
OnCalendar=03:00
RandomizedDelaySec=7200
Persistent=true

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now certbot-nightly.timer

# -------------------------
# systemd service + timers (content generation)
# -------------------------
echo "==> install systemd service + two randomized timers (AM/PM)…"

CANON_SAFE="$(echo "${CANON,,}" | sed -E 's/[^a-z0-9]+/-/g; s/^-+|-+$//g')"
UNIT_BASE="auto-gpt-blog-article-${SYSUSER}-${CANON_SAFE}"

SERVICE_PATH="/etc/systemd/system/${UNIT_BASE}.service"
TIMER_AM_PATH="/etc/systemd/system/${UNIT_BASE}-am.timer"
TIMER_PM_PATH="/etc/systemd/system/${UNIT_BASE}-pm.timer"

cat > "${SERVICE_PATH}" <<UNIT
[Unit]
Description=Generate one article for ${CANON} (auto-gpt-blog) and rebuild/ping
Wants=network-online.target
After=network-online.target

[Service]
Type=oneshot
User=${SYSUSER}
WorkingDirectory=${APP_DIR}
Environment=WEB_GROUP=www-data
ExecStart=/usr/bin/php ${APP_DIR}/bin/article-generate
UNIT

cat > "${TIMER_AM_PATH}" <<UNIT
[Unit]
Description=Random morning article generation for ${CANON} (00:00–12:00)

[Timer]
OnCalendar=*-*-* 00:00
RandomizedDelaySec=12h
Persistent=true
Unit=${UNIT_BASE}.service

[Install]
WantedBy=timers.target
UNIT

cat > "${TIMER_PM_PATH}" <<UNIT
[Unit]
Description=Random afternoon article generation for ${CANON} (12:00–24:00)

[Timer]
OnCalendar=*-*-* 12:00
RandomizedDelaySec=12h
Persistent=true
Unit=${UNIT_BASE}.service

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now "${UNIT_BASE}-am.timer" "${UNIT_BASE}-pm.timer"

echo "==> timers active (next runs shown):"
systemctl list-timers | grep -E "certbot-nightly|${UNIT_BASE}" || true

echo "==> done. Visit: https://${CANON}"
