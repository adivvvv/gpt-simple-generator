#!/usr/bin/env bash
# Quick LEMP + auto-gpt-blog installer for Ubuntu 24.04
# Usage: sudo bash install_sattelite_site.sh <linux_user> <domain> <lang>
# Example: sudo bash install_sattelite_site.sh camelmilk1 camel-milk.co.uk en
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Please run as root (use sudo)."; exit 1
fi

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 <linux_user> <domain> <lang>"; exit 1
fi

SYSUSER="$1"
DOMAIN_RAW="$2"
BLOG_LANG="$3"

# Canonical host logic: if passed "www.domain" -> canonical is www; otherwise canonical is bare
if [[ "$DOMAIN_RAW" =~ ^www\. ]]; then
  CANON="$DOMAIN_RAW"
  ALT="${DOMAIN_RAW#www.}"
else
  CANON="$DOMAIN_RAW"
  ALT="www.${DOMAIN_RAW}"
fi

# Constants
REPO_URL="https://github.com/adivvvv/auto-gpt-blog.git"
WEBROOT="/home/${SYSUSER}/auto-gpt-blog/public"
SITE_DIR="/home/${SYSUSER}"
ENV_FILE="${SITE_DIR}/auto-gpt-blog/.env"
ENV_EXAMPLE="${SITE_DIR}/auto-gpt-blog/.env.example"
NGX_AVAIL="/etc/nginx/sites-available/${CANON}"
NGX_ENABLED="/etc/nginx/sites-enabled/${CANON}"
API_KEY="XXXX-REPLACE-WITH-YOUR-KEY-XXXXX"  # Replace with your actual key
EMAIL="admin@${CANON#www.}"  # admin@rootdomain

export DEBIAN_FRONTEND=noninteractive

echo "==> apt update/upgrade…"
apt-get update -y
apt-get -o Dpkg::Options::="--force-confnew" dist-upgrade -y
apt-get autoremove -y

echo "==> install packages (nginx, php-fpm, extensions, git, certbot)…"
apt-get install -y nginx php-fpm php-cli php-curl php-xml php-mbstring php-zip php-intl git curl unzip \
                   certbot python3-certbot-nginx

# Detect PHP-FPM socket (Ubuntu 24 ships PHP 8.3; keep this dynamic)
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_SOCK="/run/php/php${PHPV}-fpm.sock"
if [[ ! -S "$PHP_SOCK" ]]; then
  # fallback to first matching socket
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
if [[ ! -d "${SITE_DIR}/auto-gpt-blog" ]]; then
  sudo -u "${SYSUSER}" -H bash -lc "cd ~ && git clone ${REPO_URL}"
else
  sudo -u "${SYSUSER}" -H bash -lc "cd ~/auto-gpt-blog && git pull --ff-only || true"
fi

# Ensure storage dirs are writable by php-fpm (group)
mkdir -p "${SITE_DIR}/auto-gpt-blog/storage/cache" "${SITE_DIR}/auto-gpt-blog/storage/logs"
chown -R "${SYSUSER}:www-data" "${SITE_DIR}/auto-gpt-blog"
find "${SITE_DIR}/auto-gpt-blog/storage" -type d -exec chmod 2775 {} \;
find "${SITE_DIR}/auto-gpt-blog/storage" -type f -exec chmod 664 {} \;

echo "==> configure .env…"
if [[ ! -f "${ENV_FILE}" && -f "${ENV_EXAMPLE}" ]]; then
  cp "${ENV_EXAMPLE}" "${ENV_FILE}"
fi

# Set required envs
sed -i "s|^FEED_BASE_URL=.*|FEED_BASE_URL=https://feed.camelway.eu|g" "${ENV_FILE}" || true
if grep -q "^FEED_API_KEY=" "${ENV_FILE}"; then
  sed -i "s|^FEED_API_KEY=.*|FEED_API_KEY=${API_KEY}|g" "${ENV_FILE}"
else
  echo "FEED_API_KEY=${API_KEY}" >> "${ENV_FILE}"
fi

# Set blog lang if placeholder exists; otherwise append
if grep -q "^BLOG_LANG=" "${ENV_FILE}"; then
  sed -i "s|^BLOG_LANG=.*|BLOG_LANG=${BLOG_LANG}|g" "${ENV_FILE}"
else
  echo "BLOG_LANG=${BLOG_LANG}" >> "${ENV_FILE}"
fi

# Base URL often needed by generators (safe default to https canonical)
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

    # ACME challenge
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

    # ACME challenge for ALT too
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
# Disable the default nginx site if present
if [[ -e /etc/nginx/sites-enabled/default ]]; then rm -f /etc/nginx/sites-enabled/default; fi

echo "==> test + reload nginx (HTTP only)…"
nginx -t
systemctl reload nginx

echo "==> obtain Let’s Encrypt certificate (for ${CANON}, ${ALT})…"
# Use webroot (no config mangling); fully non-interactive
certbot certonly --webroot -w "${WEBROOT}" \
  -d "${CANON}" -d "${ALT}" \
  --agree-tos -m "${EMAIL}" --no-eff-email --non-interactive --quiet

# Compose canonical redirect targets
if [[ "$DOMAIN_RAW" =~ ^www\. ]]; then
  REDIR_TARGET="https://${CANON}\$request_uri"
else
  REDIR_TARGET="https://${CANON}\$request_uri"
fi

echo "==> replace HTTP vhosts with HTTPS + canonical redirects…"
cat > "${NGX_AVAIL}" <<'NGINX'
# File re-written by installer; variables substituted below.
NGINX

cat >> "${NGX_AVAIL}" <<NGINX
# 1) HTTP -> HTTPS (both hosts), keep ACME reachable without redirect loop
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

    ssl_certificate     /etc/letsencrypt/live/${CANON#www.}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${CANON#www.}/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    return 301 https://${CANON}\$request_uri;
}

# 3) Canonical HTTPS app server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${CANON};
    root ${WEBROOT};
    index index.php index.html;

    ssl_certificate     /etc/letsencrypt/live/${CANON#www.}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${CANON#www.}/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

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

echo "==> final perms (readable by nginx)…"
chown -R "${SYSUSER}:www-data" "${SITE_DIR}/auto-gpt-blog"
find "${SITE_DIR}/auto-gpt-blog" -type d -exec chmod 0755 {} \;
find "${SITE_DIR}/auto-gpt-blog" -type f -exec chmod 0644 {} \;
find "${SITE_DIR}/auto-gpt-blog/storage" -type d -exec chmod 2775 {} \; 2>/dev/null || true
find "${SITE_DIR}/auto-gpt-blog/storage" -type f -exec chmod 0664 {} \; 2>/dev/null || true

echo "==> done. Visit: https://${CANON}"
