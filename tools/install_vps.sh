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

# Collecteurs MikroTik (trafic, WAN, qualité réseau, logs hotspot) : décalés pour éviter les
# chevauchements. Ecrit ici (root, via install_vps.sh) car la page web d'installation tourne en
# www-data et ne peut pas écrire dans /etc/cron.d/.
cat > /etc/cron.d/radius-manager-network-quality <<EOF
# Radius Manager - collectes MikroTik décalées pour éviter les chevauchements
# :00 LAN/Hotspot/traffic monitor lourd
# :20 compteurs WAN dédiés
# :30 qualité réseau
* * * * * root /usr/bin/php ${APP_ROOT}/scripts/monitor_mikrotik_traffic.php >/dev/null 2>&1
* * * * * root /bin/sleep 20 && /usr/bin/php ${APP_ROOT}/scripts/monitor_mikrotik_wan_usage.php >/dev/null 2>&1
* * * * * root /bin/sleep 30 && /usr/bin/php ${APP_ROOT}/scripts/monitor_network_quality.php >/dev/null 2>&1
# Archive des logs Hotspot MikroTik (buffer /log volatile) - toutes les 2h, retention 48h
0 */2 * * * root /usr/bin/php ${APP_ROOT}/scripts/monitor_mikrotik_hotspot_logs.php >/dev/null 2>&1
EOF
chmod 0644 /etc/cron.d/radius-manager-network-quality

echo "Installation système terminée."
php -v | head -1
apache2 -v | head -1
freeradius -v | head -1 || true
systemctl --no-pager --full is-active apache2 freeradius
