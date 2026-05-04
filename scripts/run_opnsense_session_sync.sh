#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
php "${SCRIPT_DIR}/sync_opnsense_sessions.php" --interface=lan
