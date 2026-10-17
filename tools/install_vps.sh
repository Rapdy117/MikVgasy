#!/usr/bin/env bash
set -Eeuo pipefail

# Installation Radius Manager sur Debian/Ubuntu.
# Usage: PHP_VERSION=8.2 sudo bash tools/install_vps.sh
PHP_VERSION="${PHP_VERSION:-8.2}"
APP_ROOT="${APP_ROOT:-/var/www/html}"
export DEBIAN_FRONTEND=noninteractive

if [[ "${EUID}" -ne 0 ]]; then echo "Lancez ce script avec sudo/root." >&2; exit 1; fi
if ! command -v apt-get >/dev/null 2>&1; then echo "Distribution Debian/Ubuntu requise (apt-get absent)." >&2; exit 1; fi

apt-get update
apt-get install -y apache2 freeradius freeradius-mysql mariadb-server mariadb-client unzip zip curl ca-certificates   "php${PHP_VERSION}" "libapache2-mod-php${PHP_VERSION}" "php${PHP_VERSION}-cli"   "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-mbstring"   "php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip"

a2enmod rewrite ssl headers php${PHP_VERSION}
if [[ -d "${APP_ROOT}" ]]; then
  chown -R www-data:www-data "${APP_ROOT}"
  find "${APP_ROOT}" -type d -exec chmod 755 {} +
  find "${APP_ROOT}" -type f -exec chmod 644 {} +
  [[ -d "${APP_ROOT}/uploads" ]] && chmod 775 "${APP_ROOT}/uploads"
fi
systemctl enable mariadb apache2 freeradius
systemctl restart mariadb
systemctl restart apache2
systemctl restart freeradius

# Sauvegarde quotidienne des données sensibles (SQL + configuration RADIUS), idempotente.
install -m 0750 -o root -g root "${APP_ROOT}/tools/backup_sensitive.php" /usr/local/sbin/radius-manager-backup-sensitive.php
cat > /etc/cron.d/radius-manager-sensitive-backup <<EOF
# Sauvegarde quotidienne des données sensibles Radius Manager
17 2 * * * root /usr/bin/php /usr/local/sbin/radius-manager-backup-sensitive.php >/var/log/radius-manager-backup.log 2>&1
EOF
chmod 0644 /etc/cron.d/radius-manager-sensitive-backup

echo "Installation système terminée."
php -v | head -1
apache2 -v | head -1
freeradius -v | head -1 || true
systemctl --no-pager --full is-active apache2 freeradius
