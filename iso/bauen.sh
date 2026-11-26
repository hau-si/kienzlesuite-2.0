#!/usr/bin/env bash
# Kienzle-Suite: kleine Start-ISO bauen (läuft auf Linux, z. B. im GitHub-Workflow .github/workflows/iso.yml).
#
#   bash iso/bauen.sh [AUSGABE.iso]       (Standard: kienzle-suite-start.iso)
#
# Vorgehen: Die offizielle Ubuntu-Server-ISO wird geladen und gegen SHA256SUMS samt GPG-Signatur geprüft. Dann entfernt xorriso das große
# Dateisystem (Pakete, Squashfs) und behält Kernel, Initrd und die BIOS/UEFI-Startdateien von Ubuntu (Secure Boot bleibt gültig). Dazu kommen
# Startmenü und die Autoinstall-Dateien (Bezeichnung CIDATA). Beim Start lädt die ISO Ubuntu aus dem Netz. Ergebnis: rund 100 bis 150 MB.
# Braucht: xorriso, gnupg, curl, python3.
set -euo pipefail
here=$(cd -- "$(dirname -- "$0")" && pwd)
ziel=${1:-kienzle-suite-start.iso}
punkt=${UBUNTU_PUNKT:-26.04.1}
reihe=${punkt%.*}
basis=${UBUNTU_BASIS:-https://releases.ubuntu.com/$reihe}
isoname=ubuntu-$punkt-live-server-amd64.iso
for c in xorriso gpgv curl python3 sha256sum; do command -v "$c" >/dev/null || { echo "$c fehlt."; exit 1; }; done
[ ! -e "$ziel" ] || { echo "Ausgabedatei existiert schon: $ziel"; exit 1; }
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
cd "$tmp"

echo '== Ubuntu-ISO laden und prüfen'
curl -fsSL -o SHA256SUMS "$basis/SHA256SUMS"
curl -fsSL -o SHA256SUMS.gpg "$basis/SHA256SUMS.gpg"
# Signaturschlüssel der Ubuntu-CD-Images (Fingerabdruck fest, nicht „irgendein“ Schlüssel vom Server).
fpr=843938DF228D22F7B3742BC0D94AA3F0EFE21092
gpg --batch --no-default-keyring --keyring ./ubuntu.kbx --keyserver hkps://keyserver.ubuntu.com --recv-keys "$fpr"
gpgv --keyring ./ubuntu.kbx SHA256SUMS.gpg SHA256SUMS
curl -fL -o "$isoname" "$basis/$isoname"
grep " \*\?$isoname\$" SHA256SUMS | sed 's/ \*/  /' | sha256sum -c -

echo '== Seed und Startmenü'
python3 "$here/erzeugen_seed.py" seed
url="$basis/$isoname"
sed "s|@@ISO_URL@@|$url|g" "$here/seed/grub.cfg" > grub.cfg

echo '== Verkleinern und ergänzen'
xorriso -indev "$isoname" -outdev "$tmp/aus.iso" \
  -boot_image any replay \
  -volid CIDATA \
  -find /casper -type f -not -name vmlinuz -not -name initrd -exec rm -- \
  -rm_r /pool /dists /install /preseed -- \
  -map seed/user-data /user-data \
  -map seed/meta-data /meta-data \
  -map grub.cfg /boot/grub/grub.cfg \
  -commit
cd - >/dev/null
mv "$tmp/aus.iso" "$ziel"
ls -l "$ziel"
echo "Fertig: $ziel"
