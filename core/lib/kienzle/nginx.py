"""nginx-Konfiguration der Suite aus festen Vorlagen erzeugen.

Eingaben sind nur geprüfte Werte: Servernamen (wie im Zertifikat) und das Modulverzeichnis aus manifest.check_all.
Grundsätze:
- Ein Zugang über Port 443; kein Port 80.
- Jede Anfrage an ein Modul wird vorher per auth_request von der Basis geprüft (/_auth/<id>).
- Benutzer und Rechte gehen nur aus der Antwort der Basis an das Modul. Kopfzeilen gleichen Namens vom Browser werden
  bei HTTP durch proxy_set_header ersetzt; bei FastCGI heißen die Werte KIENZLE_* (ohne HTTP_-Vorsilbe), die ein
  Browser nicht erzeugen kann.
- /_auth/ ist nur intern, /_health nur von localhost erreichbar.
"""
from __future__ import annotations

import ipaddress
import re

CORE_ROOT = '/opt/kienzle/core/current'
MODULE_ROOT = '/opt/kienzle/{id}/current'
CORE_SOCKET = '/run/kienzle/core/web.sock'
CA_DIR = '/etc/kienzle/ca'
HOST = re.compile(r'^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$')
FORWARD = (('benutzer', 'Benutzer'), ('name', 'Name'), ('rolle', 'Rolle'), ('rechte', 'Rechte'), ('csrf', 'Csrf'), ('bestaetigt', 'Bestaetigt'),
           ('praxis', 'Praxis'), ('drucker', 'Drucker'), ('menue', 'Menue'))
SAFE = re.compile(r'^[A-Za-z0-9_./:-]+$')


class Fehler(Exception):
    pass


def _safe(value: str) -> str:
    """Zusätzliche Absicherung: nur einfache Zeichen in die Konfiguration (die Werte sind ohnehin geprüft)."""
    if not isinstance(value, str) or not SAFE.match(value) or '..' in value:
        raise Fehler(f'Unzulässiger Wert für nginx: {str(value)[:60]}')
    return value


def server_names(hostnames: list[str]) -> list[str]:
    out = []
    for h in hostnames:
        try:
            out.append(str(ipaddress.ip_address(h)))
        except ValueError:
            if not HOST.match(h):
                raise Fehler(f'Ungültiger Servername: {h[:60]}') from None
            out.append(h)
    if not out:
        raise Fehler('Mindestens ein Servername nötig.')
    return out


def _fastcgi_common() -> list[str]:
    return ['        include fastcgi_params;', '        fastcgi_param HTTPS on;', '        fastcgi_param HTTP_PROXY "";',
            '        fastcgi_read_timeout 120s;']


WARTUNG = ("'<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\"><meta http-equiv=\"refresh\" content=\"3\">"
           "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Einen Moment bitte</title></head>"
           "<body style=\"font-family:sans-serif;max-width:32em;margin:4em auto;padding:0 1em\"><h1>Einen Moment bitte</h1>"
           "<p>Die Kienzle-Suite wird gerade aktualisiert oder neu gestartet. Diese Seite lädt sich automatisch neu.</p></body></html>'")


def _module(m: dict) -> list[str]:
    mid = _safe(m['module']['id'])
    web = m['web']
    path = _safe(web['path'])
    socket = _safe(web['upstream'].removeprefix('unix:'))
    if socket != f'/run/kienzle/{mid}/web.sock':
        raise Fehler(f'{mid}: Socket {socket} unzulässig.')
    lines = [f'    # Modul {mid}', f'    location = {path} {{ return 301 {path}/; }}', f'    location ^~ {path}/ {{',
             f'        auth_request /_auth/{mid};']
    for var, _ in FORWARD:
        lines.append(f'        auth_request_set $kienzle_{var} $upstream_http_x_kienzle_{var};')
    lines += ['        error_page 401 = @anmelden;', '        error_page 500 502 503 504 @wartung;', '        client_max_body_size 25m;']
    if web['protocol'] == 'fastcgi':
        entry = _safe(web['entry'])
        lines += _fastcgi_common()
        lines.append(f'        fastcgi_param SCRIPT_FILENAME {MODULE_ROOT.format(id=mid)}/{entry};')
        lines.append(f'        fastcgi_param SCRIPT_NAME {path};')
        for var, _ in FORWARD:
            lines.append(f'        fastcgi_param KIENZLE_{var.upper()} $kienzle_{var};')
        lines.append(f'        fastcgi_param KIENZLE_PFAD {path};')
        lines.append(f'        fastcgi_pass unix:{socket};')
    else:
        lines += ['        proxy_http_version 1.1;', '        proxy_set_header Host $host;', '        proxy_set_header Connection "";',
                  '        proxy_set_header X-Forwarded-Proto https;', '        proxy_set_header X-Forwarded-For $remote_addr;',
                  '        proxy_set_header X-Real-IP $remote_addr;', f'        proxy_set_header X-Kienzle-Pfad {path};',
                  '        proxy_read_timeout 120s;', '        proxy_redirect off;']
        for var, header in FORWARD:
            lines.append(f'        proxy_set_header X-Kienzle-{header} $kienzle_{var};')
        lines.append(f'        proxy_pass http://unix:{socket}:;')
    lines.append('    }')
    return lines


def _starter(m: dict) -> list[str]:
    """Arbeitsplatz-Starter (z. B. Kienzle-SumUp 1.x): ruft https://<server>/api.php ohne Anmeldung der Suite auf und weist sich
    mit dem Starter-Schlüssel aus. Nur diese eine Adresse; das Modul erfährt über KIENZLE_OEFFENTLICH, dass niemand angemeldet ist."""
    mid = _safe(m['module']['id'])
    web = m['web']
    if web['protocol'] != 'fastcgi':
        raise Fehler(f'{mid}: Starter nur für FastCGI-Module.')
    socket = _safe(web['upstream'].removeprefix('unix:'))
    return [f'    # Arbeitsplatz-Starter des Moduls {mid} (ohne Anmeldung; das Modul prüft den Starter-Schlüssel)', '    location = /api.php {',
            '        limit_except POST { deny all; }', '        client_max_body_size 64k;'] + _fastcgi_common() + [
            f'        fastcgi_param SCRIPT_FILENAME {MODULE_ROOT.format(id=mid)}/{_safe(web["entry"])};',
            f'        fastcgi_param SCRIPT_NAME {_safe(web["path"])};', '        fastcgi_param KIENZLE_OEFFENTLICH starter;',
            f'        fastcgi_param KIENZLE_PFAD {_safe(web["path"])};', f'        fastcgi_pass unix:{socket};', '    }']


def _starter_kontext(m: dict) -> list[str]:
    """Startkontexte des Moduls (module.toml [[starters]]): POST https://<server>/starter/<modul-id>. Anders als /api.php prüft hier die Basis
    den Starter-Schlüssel (auth_request), das Modul bekommt nur geprüfte Aufrufe (KIENZLE_STARTER_GEPRUEFT) und weiß nicht, wer es war."""
    mid = _safe(m['module']['id'])
    web = m['web']
    if web['protocol'] != 'fastcgi':
        raise Fehler(f'{mid}: Startkontexte nur für FastCGI-Module.')
    socket = _safe(web['upstream'].removeprefix('unix:'))
    return [f'    # Startkontexte des Moduls {mid} (Starter-Schlüssel prüft die Basis)', f'    location = /starter/{mid} {{',
            '        limit_except POST { deny all; }', '        client_max_body_size 64k;', '        auth_request /_starter-pruefen;'] + _fastcgi_common() + [
            f'        fastcgi_param SCRIPT_FILENAME {MODULE_ROOT.format(id=mid)}/{_safe(web["entry"])};',
            f'        fastcgi_param SCRIPT_NAME {_safe(web["path"])};', '        fastcgi_param KIENZLE_OEFFENTLICH starter;',
            '        fastcgi_param KIENZLE_STARTER_GEPRUEFT 1;', f'        fastcgi_param KIENZLE_PFAD {_safe(web["path"])};', f'        fastcgi_pass unix:{socket};', '    }']


def render(hostnames: list[str], registry: dict) -> str:
    names = server_names(hostnames)
    out = ['# Kienzle-Suite – erzeugt von kienzle-web. Nicht von Hand ändern: Änderungen gehen beim nächsten Erzeugen verloren.',
           '', 'server {', '    listen 443 ssl default_server;', '    listen [::]:443 ssl default_server;', '    server_tokens off;',
           f'    ssl_certificate {CA_DIR}/server.crt;', f'    ssl_certificate_key {CA_DIR}/server.key;',
           '    # Unbekannte Namen: keine Seite. Den Handshake nicht abweisen – ohne SNI (Aufruf über die IP) bräche sonst jede',
           '    # Verbindung ab; nginx wählt den Server danach über die Kopfzeile Host.', '    return 421;', '}', '',
           'server {', '    listen 443 ssl;', '    listen [::]:443 ssl;', '    http2 on;', '    server_name ' + ' '.join(names) + ';',
           f'    ssl_certificate {CA_DIR}/server.crt;', f'    ssl_certificate_key {CA_DIR}/server.key;',
           '    ssl_protocols TLSv1.2 TLSv1.3;', '    ssl_prefer_server_ciphers off;', '    ssl_session_tickets off;',
           '    ssl_session_cache shared:kienzle:10m;', '    server_tokens off;', '    ignore_invalid_headers on;', '    underscores_in_headers off;',
           '    client_max_body_size 1m;', '    access_log off;  # keine Adressen mit Vorgangs- oder Patientenbezug protokollieren',
           '    add_header Strict-Transport-Security "max-age=31536000" always;', '',
           '    location @anmelden { return 303 /anmelden?weiter=$uri; }', '',
           '    # Kurze Pause beim Update oder Neustart: statt „502 Bad Gateway“ eine Seite, die sich selbst neu lädt', '    error_page 502 503 504 @wartung;',
           '    location @wartung {', '        default_type text/html;', f'        return 503 {WARTUNG};', '    }', '',
           '    # Prüfung für Module (nur intern über auth_request)', '    location ^~ /_auth/ {', '        internal;',
           '        # Der Körper der Anfrage geht nicht an die Prüfung, nginx prüft aber auch hier die Größe: ohne diese Zeile gälte das Limit',
           '        # des Servers (1m) und Uploads in Modulen (bis 25m) scheiterten schon an der Anmeldeprüfung mit 413.',
           '        client_max_body_size 25m;', '        fastcgi_pass_request_body off;', '        fastcgi_param CONTENT_LENGTH "";'] + _fastcgi_common() + [
           '        fastcgi_param REQUEST_URI $uri;  # sonst stünde hier die ursprüngliche Adresse',
           f'        fastcgi_param SCRIPT_FILENAME {CORE_ROOT}/public/index.php;', f'        fastcgi_pass unix:{CORE_SOCKET};', '    }', '',
           '    # Prüfung des Starter-Schlüssels für /starter/<modul> (nur intern über auth_request; der Schlüssel steht im Kopf Authorization)',
           '    location = /_starter-pruefen {', '        internal;', '        fastcgi_pass_request_body off;', '        fastcgi_param CONTENT_LENGTH "";'] + _fastcgi_common() + [
           '        fastcgi_param REQUEST_URI /_starter-pruefen;', f'        fastcgi_param SCRIPT_FILENAME {CORE_ROOT}/public/index.php;',
           f'        fastcgi_pass unix:{CORE_SOCKET};', '    }', '',
           '    location = /_health {', '        allow 127.0.0.1;', '        allow ::1;', '        deny all;'] + _fastcgi_common() + [
           f'        fastcgi_param SCRIPT_FILENAME {CORE_ROOT}/public/index.php;', f'        fastcgi_pass unix:{CORE_SOCKET};', '    }', '',
           '    location = /ca.crt {', f'        alias {CA_DIR}/ca.crt;', '        default_type application/x-x509-ca-cert;',
           '        add_header Content-Disposition "attachment; filename=kienzle-suite-ca.crt" always;', '    }', '',
           '    location ^~ /assets/ {', f'        alias {CORE_ROOT}/public/assets/;', '        add_header X-Content-Type-Options nosniff always;', '    }', '']
    for m in registry.get('modules', []):
        if m.get('web'):
            out += _module(m) + ['']
            if m['web'].get('starter'):
                out += _starter(m) + ['']
            if m.get('starters'):
                out += _starter_kontext(m) + ['']
    out += ['    # Basis: alle übrigen Pfade', '    location / {'] + _fastcgi_common() + [
            f'        fastcgi_param SCRIPT_FILENAME {CORE_ROOT}/public/index.php;', f'        fastcgi_pass unix:{CORE_SOCKET};', '    }', '}', '']
    return '\n'.join(out)
