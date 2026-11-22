#!/usr/bin/env bash
# Kienzle-Suite: Installer der Basis für Ubuntu Server 26.04 LTS.
#
#   sudo bash installer/install.sh --name kienzle.praxis.internal [--name 192.168.1.10] [--praxis "Praxis Dr. Muster"]
#
# Mehrfaches Ausführen ist erlaubt: Vorhandenes (Schlüssel, Zertifizierungsstelle, Daten) bleibt erhalten.
# Stand Meilenstein 2: Basis mit Anmeldung, lokaler Zertifizierungsstelle, php-fpm und nginx. Firewall, Updates,
# Netzwerkspeicher, Sicherung, CUPS/Samba und autoinstall folgen (docs/PLAN.md, M3–M8).
set -euo pipefail

here=$(cd -- "$(dirname -- "$0")/.." && pwd)
# Aus dem Repository: Programm in core/. Aus einem installierten Versionsordner (Rücksprung): Programm direkt in $here.
if [ -f "$here/core/VERSION" ]; then src=$here/core; else src=$here; fi
version=$(tr -dc '0-9A-Za-z.+-' < "$src/VERSION")
nosystemd=${KIENZLE_OHNE_SYSTEMD:-}      # nur für Tests im Container ohne systemd
names=(); practice=''; update=''

usage() { echo 'Aufruf: sudo bash installer/install.sh --name SERVERNAME [--name IP] [--praxis "Name"]'; exit 2; }
while [ $# -gt 0 ]; do
  case "$1" in
    --name) [ $# -ge 2 ] || usage; names+=("$(printf '%s' "$2" | tr 'A-Z' 'a-z')"); shift 2 ;;
    --praxis) [ $# -ge 2 ] || usage; practice=$2; shift 2 ;;
    --aktualisieren) update=1; shift ;;   # vom Updater: ohne Rückfragen, ohne Einrichtungslink
    -h|--hilfe) usage ;;
    *) echo "Unbekannte Option: $1"; usage ;;
  esac
done

[ "$EUID" -eq 0 ] || { echo 'Bitte mit sudo ausführen.'; exit 1; }
. /etc/os-release
if [ "${ID:-}" != ubuntu ] || [ "${VERSION_ID:-}" != 26.04 ]; then
  echo "Die Kienzle-Suite unterstützt nur Ubuntu Server 26.04 LTS (gefunden: ${PRETTY_NAME:-unbekannt})."; exit 1
fi
[ -n "$nosystemd" ] || command -v systemctl >/dev/null || { echo 'systemd wird benötigt.'; exit 1; }
[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'core/VERSION ungültig.'; exit 1; }

conf=/etc/kienzle/core/core.json
if [ ${#names[@]} -eq 0 ]; then
  if [ -f "$conf" ]; then
    mapfile -t names < <(python3 -c 'import json,sys; print("\n".join(json.load(open(sys.argv[1]))["hostnames"]))' "$conf")
  else
    echo 'Bitte mindestens einen Servernamen angeben, unter dem die Arbeitsplätze die Suite erreichen, z. B.:'
    echo "  sudo bash installer/install.sh --name kienzle.praxis.internal --name $(hostname -I 2>/dev/null | awk '{print $1}')"
    echo 'Passkeys funktionieren nur über einen Namen, nicht über eine IP-Adresse.'
    exit 2
  fi
fi
for n in "${names[@]}"; do
  [[ $n =~ ^[a-z0-9.:-]{1,253}$ ]] || { echo "Ungültiger Servername: $n"; exit 2; }
done
if [ -z "$practice" ] && [ -f "$conf" ]; then practice=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("practice",""))' "$conf"); fi

# Paketsperre: Läuft gerade ein anderer Paketvorgang (z. B. automatische Updates), bis zu 10 Minuten warten.
# (übernommen aus kienzle-suite-beta, scripts/install-server.sh)
wait_apt() {
  local i
  for i in $(seq 1 120); do
    if ! fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock >/dev/null 2>&1; then return 0; fi
    [ "$i" -eq 1 ] && echo 'Warte auf einen laufenden Paketvorgang (höchstens 10 Minuten) …'
    sleep 5
  done
  echo 'Paketsperre nach 10 Minuten noch belegt.'; return 1
}
echo "== Kienzle-Suite Basis $version"
wait_apt
DEBIAN_FRONTEND=noninteractive apt-get update -q
wait_apt
DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends \
  nginx php-fpm php-cli php-sqlite3 php-mbstring openssl qrencode python3 ca-certificates psmisc curl nftables iproute2 smbclient openssh-client

phpv=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
phpfpm=/usr/sbin/php-fpm$phpv
[ -x "$phpfpm" ] || { echo "php-fpm für PHP $phpv fehlt."; exit 1; }
php -r 'exit(function_exists("sodium_crypto_secretbox") && defined("PASSWORD_ARGON2ID") ? 0 : 1);' || { echo 'PHP ohne libsodium/Argon2.'; exit 1; }

echo '== Benutzer und Ordner'
id ks-core >/dev/null 2>&1 || useradd --system --home-dir /var/lib/kienzle/core --no-create-home --shell /usr/sbin/nologin ks-core
id ks-ablage >/dev/null 2>&1 || useradd --system --home-dir /var/lib/kienzle/ablage --no-create-home --shell /usr/sbin/nologin ks-ablage
install -d -m 0755 -o root -g root /etc/kienzle /var/lib/kienzle /opt/kienzle /opt/kienzle/core /opt/kienzle/core/releases
install -d -m 0750 -o root -g ks-core /etc/kienzle/core
install -d -m 0700 -o ks-core -g ks-core /var/lib/kienzle/core /var/lib/kienzle/core/anfragen
install -d -m 0755 -o root -g root /var/lib/kienzle/firewall /var/lib/kienzle/update /var/lib/kienzle/verbindungen
install -d -m 0755 -o root -g root /etc/kienzle/verbindungen /etc/kienzle/verbindungen/module /etc/kienzle/schluessel /var/spool/kienzle /var/spool/kienzle/ablage
install -d -m 0755 -o ks-ablage -g ks-ablage /var/lib/kienzle/ablage
install -d -m 0700 -o ks-ablage -g ks-ablage /var/lib/kienzle/ablage/.ssh
# Eigener SSH-Schlüssel des Netzwerkspeicher-Dienstes (für SFTP-Ziele; der öffentliche Teil steht unter Verwaltung → Verbindungen).
if [ ! -f /var/lib/kienzle/ablage/.ssh/id_ed25519 ] && command -v ssh-keygen >/dev/null; then
  runuser -u ks-ablage -- ssh-keygen -q -t ed25519 -N '' -C "ks-ablage@$(hostname)" -f /var/lib/kienzle/ablage/.ssh/id_ed25519
fi

# Programm in den Versionsordner kopieren (nur root beschreibbar) und „current“ atomar umsetzen.
release=/opt/kienzle/core/releases/$version
tmp=$(mktemp -d /opt/kienzle/core/releases/.neu.XXXXXX)
cp -r -- "$src/." "$tmp/"
# Installer mit ablegen: Damit kann der Updater später auf genau diese Version zurückspringen.
rm -rf -- "$tmp/installer"; mkdir "$tmp/installer"; cp -r -- "$here/installer/." "$tmp/installer/"
chown -R root:root "$tmp"; chmod -R u=rwX,go=rX "$tmp"
rm -rf -- "$release.alt"; [ -e "$release" ] && mv -- "$release" "$release.alt"
mv -- "$tmp" "$release"; rm -rf -- "$release.alt"
ln -sfn "releases/$version" /opt/kienzle/core/.current.neu && mv -Tf /opt/kienzle/core/.current.neu /opt/kienzle/core/current
for tool in kienzle-admin kienzle-zertifikat kienzle-web kienzle-modul-pruefen kienzle-update kienzle-verbindungen; do ln -sfn "/opt/kienzle/core/current/bin/$tool" "/usr/local/sbin/$tool"; done

echo '== Einstellungen und Schlüssel'
python3 - "$conf" "$practice" "${names[@]}" <<'PY'
import json, os, sys
path, practice, names = sys.argv[1], sys.argv[2], sys.argv[3:]
data = {}
if os.path.exists(path):
    with open(path, encoding='utf-8') as f:
        data = json.load(f)
data.update({'hostnames': names, 'practice': practice[:60], 'data_dir': '/var/lib/kienzle/core',
             'secret_key': '/etc/kienzle/core/secret.key', 'modules': '/var/lib/kienzle/core/modules.json'})
tmp = path + '.neu'
with open(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640), 'w', encoding='utf-8') as f:
    json.dump(data, f, ensure_ascii=False, indent=1)
os.replace(tmp, path)
PY
chown root:ks-core "$conf"; chmod 0640 "$conf"
if [ ! -s /etc/kienzle/core/secret.key ]; then
  (umask 077; openssl rand -hex 32 > /etc/kienzle/core/secret.key)
fi
chown root:ks-core /etc/kienzle/core/secret.key; chmod 0640 /etc/kienzle/core/secret.key

# Öffentliche Release-Schlüssel (nur *.pem). Ohne Schlüssel bleiben Updates gesperrt.
install -d -m 0755 -o root -g root /etc/kienzle/release-keys
find /etc/kienzle/release-keys -maxdepth 1 -name '*.pem' -delete
for key in "$here"/installer/release-keys/*.pem; do [ -f "$key" ] && install -m 0644 -o root -g root "$key" /etc/kienzle/release-keys/; done

echo '== Zertifikat'
/opt/kienzle/core/current/bin/kienzle-zertifikat einrichten

echo '== Dienste'
vorlagen=/opt/kienzle/core/current/installer/vorlagen
install -m 0644 "$vorlagen/tmpfiles-kienzle.conf" /etc/tmpfiles.d/kienzle.conf
if [ -n "$nosystemd" ]; then
  install -d -m 0755 -o root -g root /run/kienzle; install -d -m 0750 -o ks-core -g www-data /run/kienzle/core
else
  systemd-tmpfiles --create /etc/tmpfiles.d/kienzle.conf
fi
install -m 0640 -o root -g ks-core "$vorlagen/php-fpm-core.conf" /etc/kienzle/core/php-fpm.conf
sed "s#@PHPFPM@#$phpfpm#" "$vorlagen/kienzle-core-php.service" > /etc/systemd/system/kienzle-core-php.service
install -m 0644 "$vorlagen/kienzle-zertifikat.service" "$vorlagen/kienzle-zertifikat.timer" \
  "$vorlagen/kienzle-firewall.path" "$vorlagen/kienzle-firewall-anwenden.service" "$vorlagen/kienzle-firewall.service" \
  "$vorlagen/kienzle-update.path" "$vorlagen/kienzle-update-anwenden.service" "$vorlagen/kienzle-update-pruefen.service" \
  "$vorlagen/kienzle-update-pruefen.timer" "$vorlagen/kienzle-update-start.service" \
  "$vorlagen/kienzle-verbindungen.path" "$vorlagen/kienzle-verbindungen-anwenden.service" "$vorlagen/kienzle-ablage.service" "$vorlagen/kienzle-ablage.timer" \
  /etc/systemd/system/
chmod 0644 /etc/systemd/system/kienzle-core-php.service

# nginx: nur die Suite. Die Beispielseite der Distribution wird abgeschaltet (nur der mitgelieferte Verweis).
if [ -L /etc/nginx/sites-enabled/default ] && [ "$(readlink /etc/nginx/sites-enabled/default)" = /etc/nginx/sites-available/default ]; then
  rm -f /etc/nginx/sites-enabled/default
fi
KIENZLE_NGINX_RELOAD='' /opt/kienzle/core/current/bin/kienzle-web schreiben
/opt/kienzle/core/current/bin/kienzle-verbindungen verteilen
if [ -z "$nosystemd" ]; then
  systemctl daemon-reload
  # Der php-fpm-Dienst der Distribution (Pool www-data) wird nicht gebraucht.
  systemctl disable --now "php$phpv-fpm.service" >/dev/null 2>&1 || true
  systemctl enable --now kienzle-core-php.service kienzle-zertifikat.timer kienzle-firewall.path
  systemctl enable kienzle-firewall.service kienzle-update-start.service
  systemctl enable --now kienzle-update.path kienzle-update-pruefen.timer kienzle-verbindungen.path kienzle-ablage.timer
  if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q 'Status: active'; then
    echo 'Hinweis: ufw ist aktiv. Die Firewall der Suite (Verwaltung → Firewall) arbeitet unabhängig davon; bitte nur eine von beiden verwenden.'
  fi
  systemctl restart kienzle-core-php.service
  systemctl enable nginx.service >/dev/null 2>&1 || true
  systemctl restart nginx.service
  # Erreichbarkeit prüfen: über den ersten Namen mit Prüfung gegen die eigene CA.
  ok=''
  for _ in $(seq 1 20); do
    if curl -fsS --noproxy '*' --max-time 5 --cacert /etc/kienzle/ca/ca.crt --resolve "${names[0]}:443:127.0.0.1" "https://${names[0]}/_health" >/dev/null 2>&1; then ok=1; break; fi
    sleep 1
  done
  [ -n "$ok" ] || { echo 'Die Basis antwortet nicht. Details: journalctl -u kienzle-core-php -u nginx'; exit 1; }
fi

echo
echo "Kienzle-Suite Basis $version ist installiert: https://${names[0]}/"
echo 'Zertifizierungsstelle für die Arbeitsplätze: https://'"${names[0]}"'/ca.crt'
echo "Fingerabdruck zum Vergleich: $(/opt/kienzle/core/current/bin/kienzle-zertifikat status | sed -n 's/^CA (SHA-256): //p')"
if [ -n "$nosystemd" ] || [ -n "$update" ]; then exit 0; fi
if ! /opt/kienzle/core/current/bin/kienzle-admin benutzer 2>/dev/null | grep -q 'Basis-Admin'; then
  echo; /opt/kienzle/core/current/bin/kienzle-admin einrichtungslink
else
  echo 'Admin-Zugang verloren? sudo kienzle-admin zuruecksetzen NAME'
fi
