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
c -I "https://$host/anmelden" | grep -qi '^content-security-policy:' || fail 'CSP fehlt'
c -I "https://$host/anmelden" | grep -qi '^strict-transport-security:' || fail 'HSTS fehlt'
c -I "https://$host/anmelden" | grep -qi '^server: nginx$' || fail 'Serverversion wird verraten'
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

echo 'Ende-zu-Ende-Prüfung bestanden.'
