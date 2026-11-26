#!/usr/bin/env bash
# Bildschirmfotos der Basis und aller Module mit erfundenen Daten erzeugen (benötigt php, Chrome/Chromium und Playwright für Python): bash scripts/bildschirmfotos.sh [Zielordner, Standard docs/screenshots]
set -euo pipefail
cd "$(dirname "$0")/.."
ziel="${1:-docs/screenshots}"; tmp="$(mktemp -d)"; mkdir -p "$ziel"
php scripts/bildschirmfotos.php "$tmp" >/dev/null
# Module: lokale PHP-Server mit erfundenen Werten (Kasse Port 8099, Terminzettel und Signatur Port 8098)
export VORSCHAU_DATEN="$tmp/kasse"; php scripts/kasse-vorschau/init.php >/dev/null
VORSCHAU_DATEN="$tmp/module" php scripts/modul-vorschau/init.php >/dev/null
php -S 127.0.0.1:8099 scripts/kasse-vorschau/router.php >/dev/null 2>&1 & server=$!
VORSCHAU_DATEN="$tmp/module" php -S 127.0.0.1:8098 scripts/modul-vorschau/router.php >/dev/null 2>&1 & server2=$!
trap 'kill $server $server2 2>/dev/null; rm -rf "$tmp"' EXIT
sleep 1
python3 scripts/bildschirmfotos-seiten.py "$ziel" "$tmp"
echo "Bilder in $ziel"
