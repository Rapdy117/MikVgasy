#!/usr/bin/env bash
set -Eeuo pipefail

# Installation complete Radius Manager sur une machine Debian/Ubuntu neuve :
# recupere le code depuis GitHub, installe les pre-requis systeme, puis oriente
# vers la page web d'installation (creation de la base et du compte superadmin).
#
# Usage :
#   curl -fsSL https://raw.githubusercontent.com/Rapdy117/MikVgasy/main/install.sh | sudo bash
# ou, code deja clone localement :
#   sudo bash install.sh

REPO_URL="${REPO_URL:-https://github.com/Rapdy117/MikVgasy.git}"
BRANCH="${BRANCH:-main}"
APP_ROOT="${APP_ROOT:-/var/www/html}"
PHP_VERSION="${PHP_VERSION:-8.2}"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Lancez ce script avec sudo/root." >&2
    exit 1
fi
if ! command -v apt-get >/dev/null 2>&1; then
    echo "Distribution Debian/Ubuntu requise (apt-get absent)." >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

echo "==> Verification de git"
if ! command -v git >/dev/null 2>&1; then
    apt-get update
    apt-get install -y git
fi

echo "==> Recuperation du code (${BRANCH})"
if [[ -d "${APP_ROOT}/.git" ]]; then
    echo "    Depot deja present dans ${APP_ROOT}, mise a jour (git pull)."
    git -C "${APP_ROOT}" fetch origin "${BRANCH}"
    git -C "${APP_ROOT}" checkout "${BRANCH}"
    git -C "${APP_ROOT}" pull origin "${BRANCH}"
elif [[ -d "${APP_ROOT}" && -n "$(ls -A "${APP_ROOT}" 2>/dev/null)" ]]; then
    echo "${APP_ROOT} existe deja et n'est pas un depot git vide - installation interrompue." >&2
    echo "Supprimez ou videz ce dossier, ou definissez APP_ROOT vers un autre chemin, puis relancez." >&2
    exit 1
else
    mkdir -p "${APP_ROOT}"
    git clone --branch "${BRANCH}" --depth 1 "${REPO_URL}" "${APP_ROOT}"
fi

echo "==> Installation des pre-requis systeme (Apache, PHP ${PHP_VERSION}, MariaDB, FreeRADIUS)"
PHP_VERSION="${PHP_VERSION}" APP_ROOT="${APP_ROOT}" bash "${APP_ROOT}/tools/install_vps.sh"

echo ""
echo "============================================================"
echo " Pre-requis systeme installes et code recupere."
echo ""
echo " Etapes restantes, a faire manuellement avant d'ouvrir la page web :"
echo "   1. Renseigner ${APP_ROOT}/config/db.json et config/radius.json"
echo "      (identifiants reels de votre base MySQL/MariaDB et de FreeRADIUS)."
echo "   2. Ouvrir http://<ip-ou-nom-de-ce-serveur>/tools/install.php"
echo "      dans un navigateur pour creer la base et le compte superadmin."
echo "============================================================"
