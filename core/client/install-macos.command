#!/bin/bash
set -euo pipefail
cd -- "$(dirname -- "$0")"
case "$(uname -m)" in arm64) arch=arm64;; x86_64) arch=amd64;; *) echo 'Nicht unterstützte Architektur.'; exit 1;; esac
test -f client.json && test -f server.crt && test -f schemas.txt || { echo 'client.json, schemas.txt und server.crt aus dem Paket fehlen.'; exit 1; }
asset="kienzle-starter-darwin-$arch"
test -f "$asset" && test -f SHA256SUMS || { echo "$asset oder SHA256SUMS fehlt im Paket. Bitte das Paket auf dem Server neu laden."; exit 1; }
expected=$(awk -v name="$asset" '$2==name {print $1}' SHA256SUMS)
actual=$(shasum -a 256 "$asset"); actual=${actual%% *}
test -n "$expected" && test "$expected" = "$actual" || { echo 'Prüfsumme stimmt nicht.'; exit 1; }
umask 077
target="$HOME/Library/Application Support/Kienzle-Starter"
app="$HOME/Applications/Kienzle-Starter.app"
mkdir -p "$target" "$HOME/Applications"
install -m 700 "$asset" "$target/kienzle-starter"
install -m 600 client.json "$target/client.json"
install -m 644 server.crt "$target/server.crt"
source_file=$(mktemp -t kienzle-starter)
trap 'rm -f "$source_file"' EXIT
cat > "$source_file" <<'APPLESCRIPT'
on run
    display dialog "Der Kienzle-Starter wird aus T2med gestartet." buttons {"OK"} default button "OK"
end run
on open location theURL
    set starterPath to (POSIX path of (path to home folder)) & "Library/Application Support/Kienzle-Starter/kienzle-starter"
    do shell script (quoted form of starterPath) & " --url " & (quoted form of theURL) & " >/dev/null 2>&1 &"
end open location
APPLESCRIPT
osacompile -o "$app" "$source_file"
plist="$app/Contents/Info.plist"
/usr/libexec/PlistBuddy -c 'Delete :CFBundleURLTypes' "$plist" 2>/dev/null || true
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes array' "$plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0 dict' "$plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes array' "$plist"
i=0
while IFS= read -r s; do
  case "$s" in kienzle-[a-z0-9-]*) /usr/libexec/PlistBuddy -c "Add :CFBundleURLTypes:0:CFBundleURLSchemes:$i string $s" "$plist"; i=$((i+1)); echo "Eingerichtet: $s";; esac
done < schemas.txt
test "$i" -gt 0 || { echo 'schemas.txt enthält keine Einträge.'; exit 1; }
/usr/libexec/PlistBuddy -c 'Set :CFBundleIdentifier de.kienzle.starter' "$plist" 2>/dev/null || /usr/libexec/PlistBuddy -c 'Add :CFBundleIdentifier string de.kienzle.starter' "$plist"
/usr/libexec/PlistBuddy -c 'Add :LSUIElement bool true' "$plist" 2>/dev/null || true
codesign --force --deep --sign - "$app"
'/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister' -f "$app"
security add-trusted-cert -r trustRoot -k "$HOME/Library/Keychains/login.keychain-db" "$target/server.crt"
echo 'Kienzle-Starter eingerichtet.'
