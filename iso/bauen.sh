#!/usr/bin/env bash
# Kienzle-Suite: Installations-ISO bauen (baut aus der offiziellen Ubuntu-Server-ISO 26.04.x).
#
#   bash iso/bauen.sh /pfad/ubuntu-26.04.1-live-server-amd64.iso [ausgabe.iso]
#
# Die Prüfsumme der Ubuntu-ISO muss vorher gegen SHA256SUMS von releases.ubuntu.com geprüft werden (siehe docs/INSTALLATION.md).
# Es wird nur ergänzt: Autoinstall (nur Netzwerk, Datenträger und Benutzer interaktiv), Startmenü, holen.py und die Release-Schlüssel;
# Kernel, Dateisystem und Boot-Einträge (BIOS/UEFI) der Ubuntu-ISO bleiben unverändert. Braucht: xorriso.
set -euo pipefail
here=$(cd -- "$(dirname -- "$0")" && pwd)
quelle=${1:?Aufruf: bauen.sh UBUNTU.iso [AUSGABE.iso]}
ziel=${2:-kienzle-suite-installer.iso}
command -v xorriso >/dev/null || { echo 'xorriso fehlt (sudo apt install xorriso).'; exit 1; }
[ -f "$quelle" ] || { echo "Datei nicht gefunden: $quelle"; exit 1; }
[ ! -e "$ziel" ] || { echo "Ausgabedatei existiert schon: $ziel"; exit 1; }
mkdir -p "$here/../build" && tmp=$(mktemp -d "$here/../build/iso.XXXXXX") && trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/kienzle"
cp "$here/holen.py" "$tmp/kienzle/holen.py"
cp -r "$here/../installer/release-keys" "$tmp/kienzle/release-keys"
rm -f "$tmp/kienzle/release-keys/README.md"
ls "$tmp"/kienzle/release-keys/*.pem >/dev/null
# Autoinstall im Wurzelverzeichnis der ISO, eigene Startmenüs, eigene Dateien unter /kienzle.
xorriso -indev "$quelle" -outdev "$ziel" \
  -boot_image any replay \
  -map "$here/seed/autoinstall.yaml" /autoinstall.yaml \
  -map "$here/seed/grub.cfg" /boot/grub/grub.cfg \
  -map "$tmp/kienzle" /kienzle \
  -commit
echo "Fertig: $ziel"
