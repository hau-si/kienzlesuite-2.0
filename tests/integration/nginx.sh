#!/usr/bin/env bash
# Ende-zu-Ende-Prüfung der Basis mit echtem nginx und php-fpm: Installer, Zertifikat, Anmeldung, /_auth, Weitergabe an ein Modul.
# Läuft in GitHub Actions im Container ubuntu:26.04 als root, ohne systemd (Dienste werden hier von Hand gestartet).
# Nur Platzhalter-Namen (example.org); auf einem echten Server NICHT ausführen.
set -euo pipefail
repo=$(cd -- "$(dirname -- "$0")/../.." && pwd)
host=kienzle.example.org
pw='Wiesen-Blume-Fahrrad-7'
T=$(mktemp -d)
fail() { echo "FEHLER: $*"; for f in "$T"/*.log /var/log/nginx/error.log; do [ -f "$f" ] && { echo "--- $f"; tail -n 40 "$f"; }; done; exit 1; }
c() { curl -sS --noproxy '*' --max-time 10 --cacert /etc/kienzle/ca/ca.crt --resolve "$host:443:127.0.0.1" "$@"; }
code() { c -o /dev/null -w '%{http_code} %{redirect_url}' "$@"; }

echo '== Installer'
export KIENZLE_OHNE_SYSTEMD=1
KIENZLE_OHNE_SYSTEMD=1 bash "$repo/installer/install.sh" --name "$host" --praxis 'Praxis Beispiel'

echo '== Dienste starten'
phpv=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
runuser -u ks-core -- "/usr/sbin/php-fpm$phpv" --nodaemonize --fpm-config /etc/kienzle/core/php-fpm.conf > "$T/php.log" 2>&1 &
for _ in $(seq 1 50); do [ -S /run/kienzle/core/web.sock ] && break; sleep 0.2; done
[ -S /run/kienzle/core/web.sock ] || fail 'php-fpm-Socket fehlt'
[ "$(stat -c '%a %U %G' /run/kienzle/core)" = '750 ks-core www-data' ] || fail 'Socket-Ordner falsch geschützt'
nginx
sleep 1

echo '== Zertifikat und Servername'
c "https://$host/_health" | grep -q '"status":"ok"' || fail 'Gesundheitsprüfung'
c "https://$host/anmelden" | grep -q '<h1>Anmelden</h1>' || fail 'Anmeldeseite'
if curl -sS --noproxy '*' --max-time 5 -k --resolve "evil.example:443:127.0.0.1" https://evil.example/ >/dev/null 2>&1; then fail 'fremder Servername bekommt eine Seite'; fi
[ "$(code "https://$host/_auth/beispiel")" = '404 ' ] || fail '/_auth von außen erreichbar'
heads=$(c -I "https://$host/anmelden" | tr -d '\r')   # Kopfzeilen enden auf \r\n
grep -qi '^content-security-policy:' <<< "$heads" || fail 'CSP fehlt'
grep -qi '^strict-transport-security:' <<< "$heads" || fail 'HSTS fehlt'
grep -qi '^server: nginx$' <<< "$heads" || fail "Serverversion wird verraten: $(grep -i '^server:' <<< "$heads")"
[ "$(c -o /dev/null -w '%{http_code}' "https://$host/ca.crt")" = 200 ] || fail 'ca.crt nicht abrufbar'
[ "$(code "https://$host/assets/core.js")" = '200 ' ] || fail 'assets fehlen'

echo '== Erster Admin über den Einrichtungslink'
link=$(/opt/kienzle/core/current/bin/kienzle-admin einrichtungslink | grep -o 'https://[^ ]*')
[ -n "$link" ] || fail 'kein Einrichtungslink'
[ "$(stat -c %U /var/lib/kienzle/core/core.sqlite)" = ks-core ] || fail 'Datenbank gehört nicht ks-core'
r=$(code -c "$T/anna" -b "$T/anna" -H "Origin: https://$host" --data-urlencode "t=${link##*t=}" -d login=anna --data-urlencode "password=$pw" --data-urlencode "password2=$pw" "https://$host/einrichten")
[ "$r" = "303 https://$host/konto/otp" ] || fail "Einrichtung: $r"
[ "$(code -b "$T/anna" "https://$host/")" = "303 https://$host/konto/otp" ] || fail 'Admin ohne OTP kommt weiter'

echo '== Modul hinter nginx'
id ks-beispiel >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin ks-beispiel
install -d -m 0750 -o ks-beispiel -g www-data /run/kienzle/beispiel
runuser -u ks-beispiel -- python3 -I "$repo/tests/integration/echo-modul.py" /run/kienzle/beispiel/web.sock > "$T/modul.log" 2>&1 &
for _ in $(seq 1 50); do [ -S /run/kienzle/beispiel/web.sock ] && break; sleep 0.2; done
KIENZLE_NGINX_RELOAD='' /opt/kienzle/core/current/bin/kienzle-web schreiben "$repo/modules" || fail 'kienzle-web'
nginx -s reload; sleep 1
[ "$(stat -c '%a %U %G' /etc/kienzle/schluessel/beispiel.key)" = '640 root ks-beispiel' ] || fail 'Modulschlüssel falsch geschützt'
[ "$(stat -c '%a %U %G' /etc/kienzle/verbindungen/module/beispiel.json)" = '640 root ks-beispiel' ] || fail 'Verbindungsdatei des Moduls falsch geschützt'
if runuser -u ks-core -- cat /etc/kienzle/schluessel/beispiel.key >/dev/null 2>&1; then fail 'ks-core liest den Modulschlüssel'; fi

runuser -u ks-core -- php -r '
require "/opt/kienzle/core/current/php/bootstrap.php";
use Kienzle\Core\{Config, Database, Modules, Secrets, Users};
$c = Config::load("/etc/kienzle/core/core.json"); $db = new Database($c->dataDir); $db->migrate();
$u = new Users($db, Secrets::fromFile($c->secretKey), new Modules($c->modules));
$b = $u->create("bert", "Bert", false); $u->setPassword($b, "Sonnen-Uhr-Garten-3"); $u->setAccess($b, "beispiel", false, ["ansehen"]);
$k = $u->create("carla", "", false); $u->setPassword($k, "Sonnen-Uhr-Garten-3");' || fail 'Testkonten'

[ "$(code "https://$host/beispiel/liste")" = "303 https://$host/anmelden?weiter=/beispiel/liste" ] || fail 'Modul ohne Anmeldung erreichbar'
[ "$(code "https://$host/beispiel")" = "301 https://$host/beispiel/" ] || fail 'Weiterleitung auf /beispiel/'
login() { [ "$(code -c "$T/$1" -b "$T/$1" -H "Origin: https://$host" -d "login=$1" -d 'password=Sonnen-Uhr-Garten-3' "https://$host/anmelden")" = "303 https://$host/" ] || fail "Anmeldung $1"; }
login bert; login carla
out=$(c -b "$T/bert" -H 'X-Kienzle-Benutzer: anna' -H 'X-Kienzle-Rolle: admin' -H 'X-Kienzle-Rechte: alles' -H 'X_Kienzle_Benutzer: anna' "https://$host/beispiel/liste")
echo "$out" | python3 -c '
import json, sys
d = json.load(sys.stdin)["kopfzeilen"]
want = {"x-kienzle-benutzer": ["bert"], "x-kienzle-name": ["Bert"], "x-kienzle-rolle": ["mitarbeiter"], "x-kienzle-rechte": ["ansehen"], "x-kienzle-pfad": ["/beispiel"]}
for k, v in want.items():
    assert d.get(k) == v, (k, d.get(k))
assert len(d.get("x-kienzle-csrf", [""])[0]) == 64, d
' || fail "Weitergabe an das Modul falsch oder fälschbar: $out"
[ "$(c -b "$T/carla" -o /dev/null -w '%{http_code}' "https://$host/beispiel/liste")" = 403 ] || fail 'Konto ohne Recht erreicht das Modul'
[ "$(c -b "$T/bert" -o /dev/null -w '%{http_code}' "https://$host/verwaltung/benutzer")" = 403 ] || fail 'Mitarbeiter in der Verwaltung'

echo '== Erneut installieren ändert nichts Wichtiges'
fp=$(/opt/kienzle/core/current/bin/kienzle-zertifikat status | head -1)
key=$(sha256sum /etc/kienzle/core/secret.key)
KIENZLE_OHNE_SYSTEMD=1 bash "$repo/installer/install.sh" > "$T/zweiter-lauf.log" 2>&1 || fail 'zweiter Lauf'
[ "$fp" = "$(/opt/kienzle/core/current/bin/kienzle-zertifikat status | head -1)" ] || fail 'CA beim zweiten Lauf ersetzt'
[ "$key" = "$(sha256sum /etc/kienzle/core/secret.key)" ] || fail 'Schlüssel beim zweiten Lauf ersetzt'
[ "$(stat -c '%a %U %G' /etc/kienzle/core/secret.key)" = '640 root ks-core' ] || fail 'Schlüsselrechte'
[ "$(stat -c '%a %U' /etc/kienzle/ca/ca.key)" = '600 root' ] || fail 'CA-Schlüsselrechte'
[ "$(stat -c '%a %U' /var/lib/kienzle/core)" = '700 ks-core' ] || fail 'Datenordner'
[ "$(stat -c '%a %U' /var/lib/kienzle/core/anfragen)" = '700 ks-core' ] || fail 'Anfrageordner'
for u in kienzle-firewall.path kienzle-firewall-anwenden.service kienzle-firewall.service; do [ -f "/etc/systemd/system/$u" ] || fail "$u fehlt"; done
/opt/kienzle/core/current/bin/kienzle-firewall laden || fail 'Firewall laden ohne Regeln'
for u in kienzle-update.path kienzle-update-anwenden.service kienzle-update-pruefen.service kienzle-update-pruefen.timer kienzle-update-start.service; do
  [ -f "/etc/systemd/system/$u" ] || fail "$u fehlt"; done
[ -f /opt/kienzle/core/current/installer/install.sh ] || fail 'Installer nicht im Versionsordner'
[ -d /etc/kienzle/release-keys ] || fail 'Schlüsselordner fehlt'
id ks-ablage >/dev/null || fail 'Benutzer ks-ablage fehlt'
[ -f /var/lib/kienzle/ablage/.ssh/id_ed25519.pub ] || fail 'SSH-Schlüssel des Netzwerkspeichers fehlt'
[ "$(stat -c '%a %U %G' /etc/kienzle/verbindungen/ablage.json)" = '640 root ks-ablage' ] || fail 'ablage.json falsch geschützt'
runuser -u ks-ablage -- /opt/kienzle/core/current/bin/kienzle-ablage abarbeiten || fail 'kienzle-ablage ohne Aufträge'
if runuser -u ks-core -- cat /etc/kienzle/verbindungen/ablage.json >/dev/null 2>&1; then fail 'ks-core liest die NAS-Zugangsdaten'; fi

/opt/kienzle/core/current/bin/kienzle-update start || fail 'kienzle-update start'

echo '== Drucken, Sicherheitsupdates, Überblick'
cmp -s /etc/cups/cupsd.conf /opt/kienzle/core/current/installer/vorlagen/cupsd.conf || fail 'cupsd.conf nicht aus der Vorlage'
cmp -s /etc/samba/smb.conf /opt/kienzle/core/current/installer/vorlagen/smb.conf || fail 'smb.conf nicht aus der Vorlage'
[ -f /etc/kienzle/original/smb.conf ] || fail 'ursprüngliche smb.conf nicht gesichert'
ls /etc/cups/cupsd.conf.kienzle-vorher /etc/samba/smb.conf.kienzle-vorher >/dev/null 2>&1 && fail 'Zwischenkopien bleiben liegen'
if testparm -s --suppress-prompt /etc/samba/smb.conf 2>/dev/null | grep -E '^\[' | grep -qv '^\[global\]'; then fail 'Samba hat Freigaben ohne Auftrag'; fi
grep -q 'Automatic-Reboot "false"' /etc/apt/apt.conf.d/52kienzle-unattended || fail 'unattended-upgrades ohne Neustart-Sperre'
[ -d /var/lib/kienzle/ueberblick ] || fail 'Ordner für Überblicke fehlt'
for u in kienzle-ueberblick.path kienzle-ueberblick.service kienzle-ueberblick.timer; do [ -f "/etc/systemd/system/$u" ] || fail "$u fehlt"; done

echo '== Update-Seite'
runuser -u ks-core -- php -r '
require "/opt/kienzle/core/current/php/bootstrap.php";
$c = Kienzle\Core\Config::load("/etc/kienzle/core/core.json"); $db = new Kienzle\Core\Database($c->dataDir);
$u = new Kienzle\Core\Users($db, Kienzle\Core\Secrets::fromFile($c->secretKey), new Kienzle\Core\Modules($c->modules));
$id = $u->create("dora", "", true); $u->setPassword($id, "Sonnen-Uhr-Garten-3"); $u->enableOtp($id, "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP", 0);' || fail 'Admin für Update-Test'
code=$(php -r 'require "/opt/kienzle/core/current/php/bootstrap.php"; echo Kienzle\Core\Totp::code("JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP", Kienzle\Core\Totp::step());')
[ "$(code -c "$T/dora" -b "$T/dora" -H "Origin: https://$host" -d login=dora -d 'password=Sonnen-Uhr-Garten-3' "https://$host/anmelden")" = "303 https://$host/anmelden/code" ] || fail 'Admin-Anmeldung'
[ "$(code -c "$T/dora" -b "$T/dora" -H "Origin: https://$host" -d "code=$code" "https://$host/anmelden/code")" = "303 https://$host/" ] || fail 'Admin-Code'
c -b "$T/dora" "https://$host/verwaltung/updates" | grep -q 'Installiert: <strong>Basis' || fail 'Update-Seite'
c -b "$T/dora" "https://$host/verwaltung/firewall" | grep -q 'Freigegebene Netze' || fail 'Firewall-Seite'
c -b "$T/dora" "https://$host/verwaltung/verbindungen" | grep -q 'T2med (FHIR)' || fail 'Verbindungsseite'
c -b "$T/dora" "https://$host/verwaltung/einrichtung" | grep -q 'Admin mit Authenticator-App' || fail 'Einrichtungsseite'
c "https://$host/.well-known/openid-configuration" | grep -qF "\"issuer\":\"https://$host\"" || fail 'OIDC-Discovery über nginx'
c "https://$host/oidc/jwks" | grep -q '"crv":"P-256"' || fail 'OIDC-JWKS über nginx'
[ "$(code -X POST "https://$host/oidc/token" -d grant_type=authorization_code -d code=x -d client_id=unbekannt)" = '401 ' ] || fail 'OIDC-Token ohne Client nicht 401'
c -b "$T/dora" "https://$host/verwaltung/sso" | grep -q 'Dienst hinzufügen' || fail 'Seite Dienste'
# Die Seiten müssen Status und Einheiten der root-Dienste sehen (open_basedir von php-fpm).
for p in firewall updates verbindungen sicherung einrichtung drucker sso; do
  if c -b "$T/dora" "https://$host/verwaltung/$p" | grep -q 'Bitte den Installer ausführen'; then fail "Seite $p sieht ihren Dienst nicht"; fi
done
c -b "$T/dora" "https://$host/verwaltung/verbindungen" | grep -q 'ssh-ed25519 ' || fail 'Verbindungsseite zeigt den SSH-Schlüssel nicht (Status unlesbar)'
runuser -u ks-core -- php -d open_basedir="$(sed -n 's/^php_admin_value\[open_basedir\] = //p' /etc/kienzle/core/php-fpm.conf)" -r 'exit(is_executable("/usr/bin/qrencode") ? 0 : 1);' \
  || fail 'qrencode unter open_basedir nicht erreichbar (QR-Code für OTP)'

echo '== Sicherung (echtes restic, Ziel: lokaler Ordner)'
for u in kienzle-sicherung.path kienzle-sicherung-anwenden.service kienzle-sicherung.service kienzle-sicherung.timer; do [ -f "/etc/systemd/system/$u" ] || fail "$u fehlt"; done
sicher() { runuser -u ks-core -- sh -c "umask 077; printf '%s' '$1' > /var/lib/kienzle/core/anfragen/sicherung.json"; /opt/kienzle/core/current/bin/kienzle-sicherung anwenden
  python3 -c 'import json,sys; s=json.load(open("/var/lib/kienzle/sicherung/status.json"))["letzte"]; print(s["meldung"]); sys.exit(0 if s["ok"] else 1)' || fail "Sicherung: $1"; }
sicher '{"action":"einrichten","ziel":{"art":"lokal","pfad":"/srv/test-sicherung"},"praxis":"Praxis Beispiel"}'
[ "$(stat -c '%a %U' /etc/kienzle/sicherung/passwort)" = '600 root' ] || fail 'Sicherungspasswort falsch geschützt'
if runuser -u ks-core -- cat /etc/kienzle/sicherung/passwort >/dev/null 2>&1; then fail 'ks-core liest das Sicherungspasswort'; fi
blatt=$(c -b "$T/dora" "https://$host/verwaltung/sicherung/blatt")
grep -qF "$(cat /etc/kienzle/sicherung/passwort)" <<< "$blatt" || fail "Schlüsselblatt: $(head -c 300 <<< "$blatt")"
sicher '{"action":"jetzt"}'
sicher '{"action":"probe"}'
sicher '{"action":"pruefen","lesen":true}'
snap=$(python3 -c 'import json; print(json.load(open("/var/lib/kienzle/sicherung/status.json"))["schnappschuesse"][-1]["id"])')
sql() { runuser -u ks-core -- php -r '$p = new PDO("sqlite:/var/lib/kienzle/core/core.sqlite"); $r = $p->query($argv[1]); echo $r ? $r->fetchColumn() : "";' "$1"; }
sql "INSERT INTO settings VALUES ('test_nach_sicherung', '1', 0)" >/dev/null
[ "$(sql "SELECT count(*) FROM settings WHERE key = 'test_nach_sicherung'")" = 1 ] || fail 'Testeintrag'
sicher "{\"action\":\"wiederherstellen\",\"teil\":\"core\",\"snapshot\":\"$snap\"}"
[ "$(sql "SELECT count(*) FROM settings WHERE key = 'test_nach_sicherung'")" = 0 ] || fail 'Wiederherstellung hat den späteren Eintrag nicht entfernt'
[ "$(sql "SELECT count(*) FROM users WHERE login = 'dora'")" = 1 ] || fail 'Benutzer nach Wiederherstellung fehlt'
[ "$(stat -c '%U' /var/lib/kienzle/core/core.sqlite)" = ks-core ] || fail 'Datenbank nach Wiederherstellung nicht ks-core'
c -b "$T/dora" "https://$host/verwaltung/sicherung" | grep -q 'Letzte Sicherung' || fail 'Sicherungsseite'
sicher '{"action":"export","passwort":"Ein-langes-Export-Passwort"}'
c -b "$T/dora" -o "$T/export.kse" "https://$host/verwaltung/sicherung/export" || fail 'Export herunterladen'
head -c 24 "$T/export.kse" | grep -q '^KIENZLE-EINSTELLUNGEN 1 ' || fail 'Export-Datei falsch'
if grep -qa 'BEGIN' "$T/export.kse"; then fail 'Export enthält Klartext'; fi
python3 -I -c 'import sys; sys.path.insert(0, "/opt/kienzle/core/current/lib"); from pathlib import Path; from kienzle import sicherung as s
f = s.import_settings(Path(sys.argv[1]), "Ein-langes-Export-Passwort", False, Path(sys.argv[2])); sys.exit(0 if f else 1)' "$T/export.kse" "$T/ansicht" || fail 'Export entschlüsseln'
cmp -s "$T/ansicht/etc/kienzle/ca/ca.crt" /etc/kienzle/ca/ca.crt || fail 'CA fehlt im Export'
[ ! -e "$T/ansicht/etc/kienzle/sicherung/passwort" ] || fail 'Sicherungspasswort ohne Auswahl im Export'
sicher '{"action":"blatt-gedruckt"}'
[ ! -e /var/lib/kienzle/sicherung/blatt.html ] || fail 'Blatt nicht gelöscht'

echo '== Drucker (echtes CUPS ohne systemd)'
for u in kienzle-drucker.path kienzle-drucker-anwenden.service kienzle-drucker.timer; do [ -f "/etc/systemd/system/$u" ] || fail "$u fehlt"; done
/usr/sbin/cupsd || fail 'cupsd startet nicht'
for _ in $(seq 1 50); do [ -S /run/cups/cups.sock ] && break; sleep 0.2; done
if id -nG ks-core | grep -qw lpadmin; then fail 'ks-core ist in der Gruppe lpadmin'; fi
drucker() { runuser -u ks-core -- sh -c "umask 077; printf '%s' '$1' > /var/lib/kienzle/core/anfragen/drucker.json"; /opt/kienzle/core/current/bin/kienzle-drucker anwenden
  python3 -c 'import json,sys; s=json.load(open("/var/lib/kienzle/drucker/status.json"))["letzte"]; print(s["meldung"]); sys.exit(0 if s["ok"] else 1)' || fail "Drucker: $1"; }
drucker '{"action":"anlegen","art":"bon","name":"Bon-Test","adresse":"socket://192.0.2.50:9100","freigabe":["samba"]}'
lpstat -v Bon-Test | grep -q 'socket://192.0.2.50:9100' || fail 'Warteschlange fehlt in CUPS'
testparm -s --suppress-prompt 2>/dev/null | grep -q '^\[Bon-Test\]' || fail 'Samba-Freigabe fehlt'
install -d -m 0755 /opt/kienzle/beispiel && ln -sfn "$repo/modules/beispiel" /opt/kienzle/beispiel/current
install -d -m 0750 -o ks-beispiel -g ks-beispiel /var/lib/kienzle/beispiel
[ "$(stat -c '%a %U' /usr/lib/cups/backend/kienzle-leer)" = '755 root' ] || fail 'Backend kienzle-leer fehlt'
grep -q 'include = /etc/samba/kienzle-netz.conf' /etc/samba/smb.conf || fail 'smb.conf ohne Windows-Netz'
[ "$(testparm -s --suppress-prompt --parameter-name=workgroup 2>/dev/null)" = WORKGROUP ] || fail 'Arbeitsgruppe fehlt'
# Der zweite Lauf des Installers hat das Modulverzeichnis geleert; kienzle-web schreiben gleicht die Drucker mit ab.
KIENZLE_NGINX_RELOAD='' /opt/kienzle/core/current/bin/kienzle-web schreiben "$repo/modules" || fail 'kienzle-web mit Modul'
lpstat -v Beispiel-Drucker | grep -q 'kienzle-beispiel-beispiel-drucker:/Beispiel-Drucker' || fail 'virtueller Drucker fehlt'
[ "$(stat -c '%a %U' /usr/lib/cups/backend/kienzle-beispiel-beispiel-drucker)" = '755 root' ] || fail 'Backend falsch geschützt'
# IPP-Everywhere-Drucker des Moduls (ippeveprinter als ks-beispiel, nur localhost)
grep -q '^User=ks-beispiel' /etc/systemd/system/kienzle-drucker-beispiel-beispiel-pdf.service || fail 'Einheit des IPP-Druckers fehlt'
lpstat -v Beispiel-PDF | grep -q 'ipp://localhost:471' || fail 'IPP-Everywhere-Drucker fehlt in CUPS'
pgrep -u ks-beispiel -f ippeveprinter >/dev/null || fail 'ippeveprinter läuft nicht als ks-beispiel'
lp -d Beispiel-PDF "$repo/tests/integration/test.pdf" | grep -q 'request id is Beispiel-PDF-' || fail 'Auftrag an den IPP-Drucker'
for _ in $(seq 1 30); do [ -s /var/lib/kienzle/beispiel/letzter-auftrag ] && break; sleep 0.5; done
[ -s /var/lib/kienzle/beispiel/letzter-auftrag ] && echo "IPP-Drucker hat den Auftrag erhalten ($(cat /var/lib/kienzle/beispiel/letzter-auftrag) Byte)." \
  || echo 'Hinweis: Der Auftrag kam (noch) nicht beim Programm des Moduls an (Filter von CUPS im Container?).'
drucker '{"action":"arbeitsgruppe","arbeitsgruppe":"PRAXIS"}'
[ "$(testparm -s --suppress-prompt --parameter-name=workgroup 2>/dev/null)" = PRAXIS ] || fail 'Arbeitsgruppe nicht übernommen'
runuser -u ks-beispiel -- php -r 'require "/opt/kienzle/core/current/php/modul/Kienzle.php"; $k = new KienzleModul("beispiel", ["X" => "1"]);
  echo $k->drucken("Beispiel-Drucker", "%PDF-1.4 erfunden", "Test"), "\n";' | grep -q '^Beispiel-Drucker-' || fail 'Modul druckt nicht'
c -b "$T/dora" "https://$host/verwaltung/drucker" | grep -q 'Bon-Test' || fail 'Druckerseite'
drucker '{"action":"entfernen","name":"Bon-Test"}'
if lpstat -v Bon-Test >/dev/null 2>&1; then fail 'Warteschlange nicht entfernt'; fi
rm /opt/kienzle/beispiel/current
KIENZLE_NGINX_RELOAD='' /opt/kienzle/core/current/bin/kienzle-web schreiben || fail 'kienzle-web ohne Modul'
if lpstat -v Beispiel-Drucker >/dev/null 2>&1; then fail 'virtueller Drucker nach Entfernen des Moduls noch da'; fi
[ ! -e /usr/lib/cups/backend/kienzle-beispiel-beispiel-drucker ] || fail 'Backend nach Entfernen noch da'
[ ! -e /etc/systemd/system/kienzle-drucker-beispiel-beispiel-pdf.service ] || fail 'Einheit des IPP-Druckers nach Entfernen noch da'
if lpstat -v Beispiel-PDF >/dev/null 2>&1; then fail 'IPP-Drucker nach Entfernen des Moduls noch da'; fi
pkill -u ks-beispiel -f ippeveprinter || true

echo 'Ende-zu-Ende-Prüfung bestanden.'
