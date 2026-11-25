#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")"
case "$(uname -m)" in x86_64) arch=amd64;; aarch64|arm64) arch=arm64;; armv7l|armv6l) arch=arm;; *) echo 'Nicht unterstützte Architektur.'; exit 1;; esac
target="${XDG_DATA_HOME:-$HOME/.local/share}/kienzle-starter"
applications="${XDG_DATA_HOME:-$HOME/.local/share}/applications"
test -f client.json && test -f server.crt && test -f schemas.txt || { echo 'client.json, schemas.txt und server.crt aus dem Paket fehlen.'; exit 1; }
command -v xdg-mime >/dev/null || { echo 'Bitte xdg-utils installieren.'; exit 1; }
asset="kienzle-starter-linux-$arch"
test -f "$asset" && test -f SHA256SUMS || { echo "$asset oder SHA256SUMS fehlt im Paket. Bitte das Paket auf dem Server neu laden."; exit 1; }
expected=$(awk -v name="$asset" '$2==name {print $1}' SHA256SUMS)
actual=$(sha256sum "$asset"); actual=${actual%% *}
test -n "$expected" && test "$expected" = "$actual" || { echo 'Prüfsumme stimmt nicht.'; exit 1; }
umask 077
mkdir -p "$target" "$applications"
install -m 700 "$asset" "$target/kienzle-starter"
install -m 600 client.json "$target/client.json"
install -m 644 server.crt "$target/server.crt"
escaped=${target//\\/\\\\}; escaped=${escaped//\"/\\\"}; escaped=${escaped//\$/\\\$}; escaped=${escaped//\`/\\\`}; escaped=${escaped//%/%%}
schemes=$(grep -E '^kienzle-[a-z0-9-]+$' schemas.txt || true)
test -n "$schemes" || { echo 'schemas.txt enthält keine Einträge.'; exit 1; }
mime=''
for s in $schemes; do mime="${mime}x-scheme-handler/$s;"; done
cat > "$applications/kienzle-starter.desktop" <<EOT
[Desktop Entry]
Type=Application
Name=Kienzle-Starter
Exec="$escaped/kienzle-starter" --url %u
Terminal=false
NoDisplay=true
MimeType=$mime
EOT
chmod 644 "$applications/kienzle-starter.desktop"
if command -v update-desktop-database >/dev/null; then update-desktop-database "$applications"; fi
for s in $schemes; do xdg-mime default kienzle-starter.desktop "x-scheme-handler/$s"; echo "Eingerichtet: $s"; done
echo 'Kienzle-Starter eingerichtet.'
echo "Für den Browser bitte das Zertifikat $target/server.crt als vertrauenswürdig importieren."
echo 'Der Starter prüft das exakte Serverzertifikat bereits über seinen Fingerabdruck.'
