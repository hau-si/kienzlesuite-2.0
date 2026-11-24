#!/usr/bin/env bash
# Bildschirmfotos der Basis und der Kasse mit erfundenen Daten erzeugen: bash scripts/bildschirmfotos.sh [Zielordner, Standard docs/screenshots]
set -euo pipefail
cd "$(dirname "$0")/.."
ziel="${1:-docs/screenshots}"; tmp="$(mktemp -d)"; mkdir -p "$ziel"
chrome="$(command -v google-chrome || command -v chromium || command -v chromium-browser)"
php scripts/bildschirmfotos.php "$tmp" >/dev/null
aufnahme() { # Name Breite Höhe [URL]
  "$chrome" --headless=new --no-sandbox --disable-gpu --hide-scrollbars --virtual-time-budget=8000 --window-size="$2,$3" --screenshot="$ziel/$1.png" "${4:-file://$tmp/$1.html}" >/dev/null 2>&1
}
aufnahme anmelden 1200 640;      aufnahme start 1200 760;       aufnahme benutzer 1200 760
aufnahme gruppen 1200 1250;      aufnahme konto 1200 1200;      aufnahme einrichtung 1200 1150
aufnahme verbindungen 1200 1250; aufnahme updates 1200 640
# Kasse: lokaler PHP-Server mit erfundenen Werten
export VORSCHAU_DATEN="$tmp/kasse"; php scripts/kasse-vorschau/init.php >/dev/null
php -S 127.0.0.1:8099 scripts/kasse-vorschau/router.php >/dev/null 2>&1 & server=$!
trap 'kill $server 2>/dev/null; rm -rf "$tmp"' EXIT
sleep 1
aufnahme kasse-start 1280 820 http://127.0.0.1:8099/kasse/
aufnahme kasse-einstellungen 1280 1100 http://127.0.0.1:8099/kasse/einstellungen
echo "Bilder in $ziel"
