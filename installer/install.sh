#!/usr/bin/env bash
# Kienzle-Suite: Installer der Basis für Ubuntu Server 26.04 LTS.
#
#   sudo bash installer/install.sh                         (Erstinstallation; Name und Praxis danach im Webinterface)
#   sudo bash installer/install.sh --name kienzle.praxis.internal [--name 192.0.2.10] [--praxis "Praxis Dr. Muster"]
#   sudo bash installer/install.sh --wiederherstellen     (neue VM nach Totalausfall: alles aus der Sicherung holen)
#   sudo bash installer/install.sh --admin-reset NAME     (Admin-Zugang an der Konsole zurücksetzen, wie kienzle-admin)
#   sudo bash installer/install.sh --kanal beta           (Update-Kanal festlegen: stabil oder beta)
#
# Mehrfaches Ausführen ist erlaubt: Vorhandenes (Schlüssel, Zertifizierungsstelle, Daten) bleibt erhalten.
# Richtet ein: Anmeldung, Zertifizierungsstelle, nginx/php-fpm, Firewall, Updater, Verbindungen, Sicherung, CUPS und Samba
# als Druckserver, automatische Sicherheitsupdates, AppArmor-Ergänzungen der Module, Einrichtungs-Überblick.
# Anleitung: docs/INSTALLATION.md. Für eine unbeaufsichtigte Installation: installer/autoinstall.yaml.
set -euo pipefail

here=$(cd -- "$(dirname -- "$0")/.." && pwd)
# Aus dem Repository: Programm in core/. Aus einem installierten Versionsordner (Rücksprung): Programm direkt in $here.
if [ -f "$here/core/VERSION" ]; then src=$here/core; else src=$here; fi
version=$(tr -dc '0-9A-Za-z.+-' < "$src/VERSION")
nosystemd=${KIENZLE_OHNE_SYSTEMD:-}      # nur für Tests im Container ohne systemd
names=(); auto_names=''; practice=''; update=''; restore=''; restore_json=''; admin_reset=''; kanal=''

usage() { echo 'Aufruf: sudo bash installer/install.sh --name SERVERNAME [--name IP] [--praxis "Name"]'; exit 2; }
while [ $# -gt 0 ]; do
  case "$1" in
    --name) [ $# -ge 2 ] || usage; names+=("$(printf '%s' "$2" | tr 'A-Z' 'a-z')"); shift 2 ;;
    --praxis) [ $# -ge 2 ] || usage; practice=$2; shift 2 ;;
    --aktualisieren) update=1; shift ;;   # vom Updater: ohne Rückfragen, ohne Einrichtungslink
    --wiederherstellen) restore=1; shift ;;
    --admin-reset) [ $# -ge 2 ] || usage; admin_reset=$2; shift 2 ;;
    --kanal) [ $# -ge 2 ] || usage; kanal=$2; shift 2 ;;
    --wiederherstellen-json) [ $# -ge 2 ] || usage; restore=1; restore_json=$2; shift 2 ;;   # für Tests: Angaben aus Datei
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
[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?$ ]] || { echo 'core/VERSION ungültig.'; exit 1; }
[ -z "$kanal" ] || [ "$kanal" = stabil ] || [ "$kanal" = beta ] || { echo 'Kanal: stabil oder beta.'; exit 2; }
[ -z "$admin_reset" ] || [[ $admin_reset =~ ^[a-z0-9._-]{1,64}$ ]] || { echo "Ungültiger Benutzername: $admin_reset"; exit 2; }

# Vorabprüfungen (nur Hinweise; die Installation läuft trotzdem weiter).
if [ -z "$update" ]; then
  cores=$(nproc 2>/dev/null || echo 1)
  mem_kb=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)
  disk_kb=$(df -Pk / 2>/dev/null | awk 'NR==2 {print $2}')
  [ "$cores" -ge 2 ] || echo "Hinweis: $cores CPU-Kern(e); empfohlen sind mindestens 2."
  [ "${mem_kb:-0}" -ge 3800000 ] || echo "Hinweis: $(( ${mem_kb:-0} / 1024 )) MB Arbeitsspeicher; empfohlen sind mindestens 4 GB."
  [ "${disk_kb:-0}" -ge 95000000 ] || echo "Hinweis: $(( ${disk_kb:-0} / 1048576 )) GB Speicher auf /; empfohlen sind mindestens 100 GB."
fi

conf=/etc/kienzle/core/core.json
if [ -n "$restore" ] && [ -f /var/lib/kienzle/core/core.sqlite ]; then
  echo 'Auf diesem Server ist die Suite schon eingerichtet. Wiederherstellen nur auf einer frischen VM.'; exit 2
fi
if [ ${#names[@]} -eq 0 ] && [ -z "$restore" ]; then
  if [ -f "$conf" ]; then
    mapfile -t names < <(python3 -c 'import json,sys; print("\n".join(json.load(open(sys.argv[1]))["hostnames"]))' "$conf")
  else
    # Erstinstallation ohne Angaben: Rechnername und alle IPv4-Adressen der VM. Den endgültigen Namen legt ein Admin
    # danach unter Verwaltung → Server fest (dort auch Praxisname); das Zertifikat wird dann neu ausgestellt.
    # Zuerst die IP-Adressen: Unter ihnen erreichen die Arbeitsplätze den Server sicher schon jetzt (Einrichtungslink).
    for ip in $(hostname -I 2>/dev/null); do
      if [[ $ip =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] && [[ ! $ip =~ ^127\. ]]; then names+=("$ip"); fi
    done
    host=$(hostname -f 2>/dev/null | tr 'A-Z' 'a-z')
    if [[ $host =~ ^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$ ]] && [ "$host" != localhost ]; then names+=("$host"); fi
    [ ${#names[@]} -gt 0 ] || { echo 'Weder Rechnername noch IP-Adresse gefunden. Bitte mit --name angeben.'; exit 2; }
    auto_names=1
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
  nginx php-fpm php-cli php-sqlite3 php-mbstring openssl qrencode python3 ca-certificates psmisc curl nftables iproute2 smbclient openssh-client restic cifs-utils \
  cups cups-bsd cups-ipp-utils avahi-daemon avahi-utils samba unattended-upgrades

phpv=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
phpfpm=/usr/sbin/php-fpm$phpv
[ -x "$phpfpm" ] || { echo "php-fpm für PHP $phpv fehlt."; exit 1; }
php -r 'exit(function_exists("sodium_crypto_secretbox") && defined("PASSWORD_ARGON2ID") ? 0 : 1);' || { echo 'PHP ohne libsodium/Argon2.'; exit 1; }

echo '== Uhrzeit'
# Authenticator-Codes brauchen die richtige Uhrzeit. Zeitsynchronisation an, Zeitzone der Praxis (falls noch UTC).
if [ -z "$nosystemd" ] && command -v timedatectl >/dev/null; then
  case "$(timedatectl show -p Timezone --value 2>/dev/null)" in Etc/UTC|UTC|'') timedatectl set-timezone Europe/Berlin || true ;; esac
  timedatectl set-ntp true >/dev/null 2>&1 || true
  for _ in $(seq 1 20); do [ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ] && break; sleep 1; done
  if [ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ]; then echo "Uhrzeit synchronisiert: $(date '+%d.%m.%Y %H:%M:%S')"
  else echo "Hinweis: Uhrzeit nicht synchronisiert (Server: $(date '+%d.%m.%Y %H:%M:%S')). Bei der Einrichtung der Authenticator-App bietet die Suite an, die Uhr zu stellen."; fi
fi
echo '== Benutzer und Ordner'
id ks-core >/dev/null 2>&1 || useradd --system --home-dir /var/lib/kienzle/core --no-create-home --shell /usr/sbin/nologin ks-core
id ks-ablage >/dev/null 2>&1 || useradd --system --home-dir /var/lib/kienzle/ablage --no-create-home --shell /usr/sbin/nologin ks-ablage
install -d -m 0755 -o root -g root /etc/kienzle /var/lib/kienzle /opt/kienzle /opt/kienzle/core /opt/kienzle/core/releases
install -d -m 0750 -o root -g ks-core /etc/kienzle/core
install -d -m 0700 -o ks-core -g ks-core /var/lib/kienzle/core /var/lib/kienzle/core/anfragen
install -d -m 0755 -o root -g root /var/lib/kienzle/firewall /var/lib/kienzle/update /var/lib/kienzle/verbindungen
install -d -m 0755 -o root -g root /etc/kienzle/verbindungen /etc/kienzle/verbindungen/module /etc/kienzle/schluessel /var/spool/kienzle /var/spool/kienzle/ablage
install -d -m 0755 -o ks-ablage -g ks-ablage /var/lib/kienzle/ablage
install -d -m 0700 -o root -g root /etc/kienzle/sicherung /var/cache/kienzle-sicherung
install -d -m 0755 -o root -g root /var/lib/kienzle/sicherung /var/lib/kienzle/ueberblick /var/lib/kienzle/servername /var/lib/kienzle/module /etc/kienzle/module
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
# Mitgelieferte Module (ohne Beispielmodul): installierbar über Verwaltung → Module.
if [ "$src" != "$here" ] && [ -d "$here/modules" ]; then
  rm -rf -- "$tmp/modules"; mkdir "$tmp/modules"
  for m in "$here"/modules/*/; do
    m=${m%/}; [ "$(basename -- "$m")" = beispiel ] && continue
    [ -f "$m/module.toml" ] && cp -r -- "$m" "$tmp/modules/"
  done
fi
chown -R root:root "$tmp"; chmod -R u=rwX,go=rX "$tmp"
rm -rf -- "$release.alt"; [ -e "$release" ] && mv -- "$release" "$release.alt"
mv -- "$tmp" "$release"; rm -rf -- "$release.alt"
ln -sfn "releases/$version" /opt/kienzle/core/.current.neu && mv -Tf /opt/kienzle/core/.current.neu /opt/kienzle/core/current
for tool in kienzle-admin kienzle-zertifikat kienzle-web kienzle-modul-pruefen kienzle-update kienzle-verbindungen kienzle-sicherung kienzle-ueberblick kienzle-drucker kienzle-servername kienzle-module; do ln -sfn "/opt/kienzle/core/current/bin/$tool" "/usr/local/sbin/$tool"; done

if [ -n "$restore" ]; then
  echo '== Wiederherstellung aus der Sicherung'
  if [ -n "$restore_json" ]; then
    /opt/kienzle/core/current/bin/kienzle-sicherung komplett --json "$restore_json"
  else
    /opt/kienzle/core/current/bin/kienzle-sicherung komplett < /dev/tty
  fi
  # Namen und Praxis kommen aus der gesicherten Einstellung, sofern nicht neu angegeben.
  if [ ${#names[@]} -eq 0 ]; then
    mapfile -t names < <(python3 -c 'import json,sys; print("\n".join(json.load(open(sys.argv[1]))["hostnames"]))' "$conf")
  fi
  if [ -z "$practice" ]; then practice=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("practice",""))' "$conf"); fi
  chown -R ks-core:ks-core /var/lib/kienzle/core
fi

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

vorlagen=/opt/kienzle/core/current/installer/vorlagen
echo '== Drucken: CUPS und Samba'
# Feste Vorlagen der Basis. Erst prüfen (cupsd -t, testparm), dann die bisherige Datei einmalig sichern und ersetzen;
# startet der Dienst danach nicht, kommt die vorige Datei zurück. (Vorgehen aus terminzettel/install.py.)
install -d -m 0700 -o root -g root /etc/kienzle/original
conf_changed=()
put_conf() {   # Vorlage Ziel Dienst Prüfbefehl…
  local src=$1 dest=$2 unit=$3; shift 3
  local check; check=$(mktemp -d)
  cp -- "$src" "$check/$(basename -- "$dest")"
  if ! "$@" "$check/$(basename -- "$dest")" >"$check/log" 2>&1; then
    echo "Vorlage $(basename -- "$dest") wird abgelehnt, bisherige bleibt:"; tail -n 5 "$check/log"; rm -rf -- "$check"; return 1
  fi
  rm -rf -- "$check"
  if [ -f "$dest" ] && cmp -s "$src" "$dest"; then return 0; fi
  [ -f "$dest" ] && [ ! -f "/etc/kienzle/original/$(basename -- "$dest")" ] && cp -p -- "$dest" "/etc/kienzle/original/$(basename -- "$dest")"
  [ -f "$dest" ] && cp -p -- "$dest" "$dest.kienzle-vorher"
  install -m 0644 -o root -g root "$src" "$dest"
  conf_changed+=("$unit:$dest")
}
cups_check() { cupsd -t -c "$1" -s /etc/cups/cups-files.conf; }
install -d -m 0755 /var/lib/kienzle/drucker /usr/share/ppd/kienzle
[ -f /etc/samba/kienzle-drucker.conf ] || install -m 0644 /dev/null /etc/samba/kienzle-drucker.conf
[ -f /etc/samba/kienzle-netz.conf ] || printf '   workgroup = WORKGROUP\n   server role = standalone server\n' > /etc/samba/kienzle-netz.conf
install -m 0755 -o root -g root /opt/kienzle/core/current/backend/kienzle-leer /usr/lib/cups/backend/kienzle-leer
put_conf "$vorlagen/cupsd.conf" /etc/cups/cupsd.conf cups.service cups_check || true
put_conf "$vorlagen/smb.conf" /etc/samba/smb.conf smbd.service testparm -s --suppress-prompt || true
install -d -m 1777 /var/spool/samba
if [ -n "$nosystemd" ]; then for e in "${conf_changed[@]}"; do rm -f -- "${e#*:}.kienzle-vorher"; done; fi

echo '== Automatische Sicherheitsupdates'
# Nur die Sicherheitsquelle der Distribution, kein automatischer Neustart (übernommen aus kienzle-suite-beta).
if ! grep -rqs 'APT::Periodic::Unattended-Upgrade' /etc/apt/apt.conf.d/; then
  printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades
fi
printf '// Kienzle-Suite: Neustarts plant die Praxis selbst.\nUnattended-Upgrade::Automatic-Reboot "false";\n' > /etc/apt/apt.conf.d/52kienzle-unattended

if [ -n "$kanal" ]; then
  python3 - "$kanal" <<'PY'
import json, os, sys
path = '/var/lib/kienzle/update/einstellungen.json'
data = {}
if os.path.exists(path):
    with open(path, encoding='utf-8') as f:
        data = json.load(f)
data['kanal'] = sys.argv[1]
if data.get('signatur') == 'beta' and sys.argv[1] != 'beta':
    data['signatur'] = 'aus'
with open(path + '.neu', 'w', encoding='utf-8') as f:
    json.dump(data, f)
os.replace(path + '.neu', path)
PY
  echo "Update-Kanal: $kanal"
fi

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
  "$vorlagen/kienzle-sicherung.path" "$vorlagen/kienzle-sicherung-anwenden.service" "$vorlagen/kienzle-sicherung.service" "$vorlagen/kienzle-sicherung.timer" \
  "$vorlagen/kienzle-ueberblick.path" "$vorlagen/kienzle-ueberblick.service" "$vorlagen/kienzle-ueberblick.timer" \
  "$vorlagen/kienzle-drucker.path" "$vorlagen/kienzle-drucker-anwenden.service" "$vorlagen/kienzle-drucker.timer" \
  "$vorlagen/kienzle-servername.path" "$vorlagen/kienzle-servername-anwenden.service" \
  "$vorlagen/kienzle-module.path" "$vorlagen/kienzle-module-anwenden.service" "$vorlagen/kienzle-module-pruefen.service" "$vorlagen/kienzle-module-pruefen.timer" \
  /etc/systemd/system/
chmod 0644 /etc/systemd/system/kienzle-core-php.service

# nginx: nur die Suite. Die Beispielseite der Distribution wird abgeschaltet (nur der mitgelieferte Verweis).
if [ -L /etc/nginx/sites-enabled/default ] && [ "$(readlink /etc/nginx/sites-enabled/default)" = /etc/nginx/sites-available/default ]; then
  rm -f /etc/nginx/sites-enabled/default
fi
# Modulverzeichnis aus den installierten Modulen (ohne Module: nur die Basis).
KIENZLE_NGINX_RELOAD="" /opt/kienzle/core/current/bin/kienzle-web module || KIENZLE_NGINX_RELOAD="" /opt/kienzle/core/current/bin/kienzle-web neu
/opt/kienzle/core/current/bin/kienzle-verbindungen verteilen
if [ -z "$nosystemd" ]; then
  systemctl daemon-reload
  # Der php-fpm-Dienst der Distribution (Pool www-data) wird nicht gebraucht.
  systemctl disable --now "php$phpv-fpm.service" >/dev/null 2>&1 || true
  systemctl enable --now kienzle-core-php.service kienzle-zertifikat.timer kienzle-firewall.path
  systemctl enable kienzle-firewall.service kienzle-update-start.service
  systemctl enable --now kienzle-update.path kienzle-update-pruefen.timer kienzle-verbindungen.path kienzle-ablage.timer kienzle-sicherung.path kienzle-sicherung.timer kienzle-ueberblick.path kienzle-ueberblick.timer kienzle-drucker.path kienzle-drucker.timer kienzle-servername.path kienzle-module.path kienzle-module-pruefen.timer
  # Druckserver: CUPS und smbd; nmbd (NetBIOS) und der AD-Dienst werden nicht gebraucht.
  systemctl disable --now nmbd.service samba-ad-dc.service >/dev/null 2>&1 || true
  systemctl enable cups.service smbd.service >/dev/null 2>&1 || true
  for entry in "${conf_changed[@]}"; do
    unit=${entry%%:*}; dest=${entry#*:}
    if ! systemctl restart "$unit"; then
      echo "$unit startet mit der neuen Konfiguration nicht – vorige Datei wieder eingesetzt."
      [ -f "$dest.kienzle-vorher" ] && cp -p -- "$dest.kienzle-vorher" "$dest" && systemctl restart "$unit" || true
    fi
    rm -f -- "$dest.kienzle-vorher"
  done
  systemctl start cups.service smbd.service >/dev/null 2>&1 || true
  if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q 'Status: active'; then
    echo 'Hinweis: ufw ist aktiv. Die Firewall der Suite (Verwaltung → Firewall) arbeitet unabhängig davon; bitte nur eine von beiden verwenden.'
  fi
  systemctl restart kienzle-core-php.service
  systemctl enable nginx.service >/dev/null 2>&1 || true
  systemctl restart nginx.service
  # Erreichbarkeit prüfen: über den ersten Namen mit Prüfung gegen die eigene CA.
  ok=''
  for _ in $(seq 1 20); do
    if curl -fsS --noproxy '*' --max-time 5 --cacert /etc/kienzle/ca/ca.crt --connect-to "${names[0]}:443:127.0.0.1:443" "https://${names[0]}/_health" >/dev/null 2>&1; then ok=1; break; fi
    sleep 1
  done
  [ -n "$ok" ] || { echo 'Die Basis antwortet nicht. Details: journalctl -u kienzle-core-php -u nginx'; exit 1; }
fi

echo
echo "Kienzle-Suite Basis $version ist installiert: https://${names[0]}/"
echo 'Zertifizierungsstelle für die Arbeitsplätze: https://'"${names[0]}"'/ca.crt'
echo "Einrichtungs-Überblick: https://${names[0]}/verwaltung/einrichtung"
echo "Fingerabdruck zum Vergleich: $(/opt/kienzle/core/current/bin/kienzle-zertifikat status | sed -n 's/^CA (SHA-256): //p')"
if [ -n "$auto_names" ]; then
  echo "Erreichbar unter: ${names[*]}. Den endgültigen Namen (für Passkeys nötig) und den Praxisnamen bitte unter"
  echo "Verwaltung → Server festlegen."
fi
/opt/kienzle/core/current/bin/kienzle-ueberblick sammeln >/dev/null || echo 'Hinweis: Einrichtungs-Überblicke der Module nicht lesbar.'
/opt/kienzle/core/current/bin/kienzle-module pruefen --sofort >/dev/null 2>&1 || true   # Angebot für Verwaltung → Module (GitHub darf fehlen)
if [ -n "$admin_reset" ]; then /opt/kienzle/core/current/bin/kienzle-admin zuruecksetzen "$admin_reset"; fi
if [ -n "$nosystemd" ] || [ -n "$update" ]; then exit 0; fi
if ! /opt/kienzle/core/current/bin/kienzle-admin benutzer 2>/dev/null | grep -q 'Basis-Admin'; then
  echo; /opt/kienzle/core/current/bin/kienzle-admin einrichtungslink
else
  echo 'Admin-Zugang verloren? sudo kienzle-admin zuruecksetzen NAME'
fi
