#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")"
case "$(uname -m)" in x86_64) arch=amd64;; aarch64|arm64) arch=arm64;; armv7l|armv6l) arch=arm;; *) echo 'Nicht unterstützte Architektur.'; exit 1;; esac
target="${XDG_DATA_HOME:-$HOME/.local/share}/praxisherz-starter"
applications="${XDG_DATA_HOME:-$HOME/.local/share}/applications"
test -f client.json && test -f server.crt && test -f schemas.txt || { echo 'client.json, schemas.txt und server.crt aus dem Paket fehlen.'; exit 1; }
command -v xdg-mime >/dev/null || { echo 'Bitte xdg-utils installieren.'; exit 1; }
asset="praxisherz-starter-linux-$arch"
test -f "$asset" && test -f SHA256SUMS || { echo "$asset oder SHA256SUMS fehlt im Paket. Bitte das Paket auf dem Server neu laden."; exit 1; }
expected=$(awk -v name="$asset" '$2==name {print $1}' SHA256SUMS)
actual=$(sha256sum "$asset"); actual=${actual%% *}
test -n "$expected" && test "$expected" = "$actual" || { echo 'Prüfsumme stimmt nicht.'; exit 1; }
umask 077
mkdir -p "$target" "$applications"
# Idempotent: jede Zeile sagt „neu“, „aktualisiert“ oder „unverändert“.
place() { # Quelle Ziel Modus Beschriftung
  if [ -f "$2" ] && cmp -s "$1" "$2"; then printf '%-13s %s\n' unverändert "$4"; return; fi
  had=no; [ -f "$2" ] && had=yes
  install -m "$3" "$1" "$2"
  printf '%-13s %s\n' "$([ $had = yes ] && echo aktualisiert || echo neu)" "$4"
}
place "$asset" "$target/praxisherz-starter" 700 Starterprogramm
place client.json "$target/client.json" 600 'Einstellungen und Schlüssel'
place server.crt "$target/server.crt" 644 'Zertifikat (Datei)'
escaped=${target//\\/\\\\}; escaped=${escaped//\"/\\\"}; escaped=${escaped//\$/\\\$}; escaped=${escaped//\`/\\\`}; escaped=${escaped//%/%%}
schemes=$(grep -E '^(praxisherz|kienzle)-[a-z0-9-]+$' schemas.txt || true)
test -n "$schemes" || { echo 'schemas.txt enthält keine Einträge.'; exit 1; }
mime=''
for s in $schemes; do mime="${mime}x-scheme-handler/$s;"; done
# Vor der Umbenennung: Ordner und Eintrag „kienzle-starter“ entfernen.
for alt in "${XDG_DATA_HOME:-$HOME/.local/share}/kienzle-starter" "$applications/kienzle-starter.desktop"; do
  if [ -e "$alt" ]; then rm -r -- "$alt" && printf '%-13s %s\n' entfernt "$alt"; fi
done
cat > "$applications/praxisherz-starter.desktop" <<EOT
[Desktop Entry]
Type=Application
Name=Praxisherz-Starter
Exec="$escaped/praxisherz-starter" --url %u
Terminal=false
NoDisplay=true
MimeType=$mime
EOT
chmod 644 "$applications/praxisherz-starter.desktop"
if command -v update-desktop-database >/dev/null; then update-desktop-database "$applications"; fi
for s in $schemes; do xdg-mime default praxisherz-starter.desktop "x-scheme-handler/$s"; echo "Eingerichtet: $s"; done
# Virtuelle Drucker (drucker.txt: Name|Adresse|Papier je Zeile) über CUPS einrichten
if [ -f drucker.txt ] && grep -q '|' drucker.txt && command -v lpadmin >/dev/null; then
  while IFS='|' read -r name url _; do
    [ -n "$name" ] || continue
    cups="ipp://${url#http://}"
    if lpstat -v "$name" 2>/dev/null | grep -qF "$cups"; then printf '%-13s %s\n' unverändert "Drucker $name"; continue; fi
    if sudo lpadmin -p "$name" -E -v "$cups" -m everywhere; then printf '%-13s %s\n' neu "Drucker $name"
    else echo "Drucker $name nicht eingerichtet (Anleitung: Seite „Arbeitsplätze“ von Praxisherz)."; fi
  done < drucker.txt
fi
echo 'Arbeitsplatz eingerichtet. Das Paket kann gelöscht werden.'
echo "Für den Browser bitte das Zertifikat $target/server.crt als vertrauenswürdig importieren."
echo 'Der Starter prüft das exakte Serverzertifikat bereits über seinen Fingerabdruck.'
