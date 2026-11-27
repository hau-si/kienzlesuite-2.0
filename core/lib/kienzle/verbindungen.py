"""Zentrale Verbindungen (T2med/FHIR, E-Mail, Netzwerkspeicher) – root-Dienst, Aufruf über core/bin/kienzle-verbindungen.

Regel (docs/ARCHITEKTUR.md, „Zugänge zu anderen Diensten“): Was mehrere Module brauchen, verwaltet die Basis; ein Modul
bekommt nur die Verbindungen, die es in [[connections]] anmeldet, lesbar nur für seinen Benutzer.

- Gespeichert wird root-eigen in /etc/kienzle/verbindungen/konfiguration.json (0600), samt Geheimnissen.
- Das Webinterface (ks-core, ohne Netz) sieht nur eine Fassung ohne Geheimnisse (status.json) und schickt Änderungen als
  Anfragedatei; ein leeres Passwort heißt „unverändert“.
- Verteilt wird an /etc/kienzle/verbindungen/module/<id>.json (root:ks-<id>, 0640) und an den Netzwerkspeicher-Dienst
  (/etc/kienzle/verbindungen/ablage.json, root:ks-ablage, 0640). Dazu je Modul ein eigener Schlüssel für seine
  modul-eigenen Geheimnisse (/etc/kienzle/schluessel/<id>.key, root:ks-<id>, 0640), den die Basis nicht verwendet.
"""
from __future__ import annotations

import grp
import hashlib
import json
import os
import re
import secrets
import shutil
import smtplib
import socket
import ssl
import stat
import subprocess
import time
import urllib.parse
from pathlib import Path

from . import ablage

E = os.environ.get
ETC = Path(E('KIENZLE_VERB_ETC', '/etc/kienzle/verbindungen'))
KEYS = Path(E('KIENZLE_VERB_SCHLUESSEL', '/etc/kienzle/schluessel'))
STATE = Path(E('KIENZLE_VERB_STATE', '/var/lib/kienzle/verbindungen'))
REQUEST = Path(E('KIENZLE_VERB_REQUEST', '/var/lib/kienzle/core/anfragen/verbindungen.json'))
MODULES = Path(E('KIENZLE_VERB_MODULES', '/var/lib/kienzle/core/modules.json'))
SPOOL = Path(E('KIENZLE_ABLAGE_SPOOL', '/var/spool/kienzle/ablage'))
NACHRICHTEN_SPOOL = Path(E('KIENZLE_NACHRICHTEN_SPOOL', '/var/spool/kienzle/nachrichten'))
ABLAGE_PUB = Path(E('KIENZLE_ABLAGE_SCHLUESSEL', '/var/lib/kienzle/ablage/.ssh/id_ed25519') + '.pub')
OPENSSL = E('KIENZLE_OPENSSL', '/usr/bin/openssl')
CHOWN = E('KIENZLE_VERB_CHOWN', '1') == '1'          # Tests ohne root: 0
URL = re.compile(r'^https://[A-Za-z0-9.-]{1,253}(?::\d{1,5})?(?:/[A-Za-z0-9._~%/-]*)?$')
EMAIL = re.compile(r'^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,63}$')
CONTROL = re.compile(r'[\x00-\x1f\x7f]')


class Fehler(Exception):
    pass


def app_uid() -> int:
    if 'KIENZLE_VERB_UID' in os.environ:
        return int(os.environ['KIENZLE_VERB_UID'])
    import pwd
    return pwd.getpwnam('ks-core').pw_uid


# ---- Speichern ---------------------------------------------------------------------------------------------------
def _write(path: Path, data: dict | str, mode: int, group: str | None = None) -> None:
    path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    tmp = path.with_name('.' + path.name + '.neu')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        f.write(data if isinstance(data, str) else json.dumps(data, ensure_ascii=False, indent=1))
    os.chmod(tmp, mode)
    if group and CHOWN:
        shutil.chown(tmp, 'root', group)
    os.replace(tmp, path)


def load() -> dict:
    try:
        data = json.loads((ETC / 'konfiguration.json').read_text(encoding='utf-8'))
    except FileNotFoundError:
        data = {}
    return {'t2med': data.get('t2med'), 'mail': data.get('mail'), 'sms': data.get('sms'), 'letterxpress': data.get('letterxpress'), 'ablage': data.get('ablage', [])}


def save(config: dict) -> None:
    _write(ETC / 'konfiguration.json', config, 0o600)


def _text(value, name: str, limit: int, required: bool = True) -> str:
    if not isinstance(value, str):
        raise Fehler(f'{name}: Text erwartet.')
    value = value.strip()
    if required and not value:
        raise Fehler(f'{name} fehlt.')
    if len(value) > limit or CONTROL.search(value):
        raise Fehler(f'{name} ungültig.')
    return value


def _url(value, name: str) -> str:
    value = _text(value, name, 300).rstrip('/')
    if not URL.match(value) or '..' in value:
        raise Fehler(f'{name}: nur https-Adressen ohne Benutzer, Parameter oder „..“, z. B. https://t2med-server:16567/aps/fhir/api/r4')
    port = urllib.parse.urlparse(value).port
    if port is not None and not 1 <= port <= 65535:
        raise Fehler(f'{name}: Port ungültig.')
    return value


def _cert(pem) -> str:
    """Genau ein Zertifikat im PEM-Format, von OpenSSL lesbar."""
    if not pem:
        return ''
    if not isinstance(pem, str) or len(pem) > 20000:
        raise Fehler('Zertifikat zu groß.')
    pem = pem.replace('\r', '').strip()
    if re.search(r'[\x00-\x09\x0b-\x1f\x7f]', pem) or pem.count('-----BEGIN CERTIFICATE-----') != 1 or not pem.endswith('-----END CERTIFICATE-----'):
        raise Fehler('Zertifikat: genau ein Zertifikat im PEM-Format (-----BEGIN CERTIFICATE----- … -----END CERTIFICATE-----).')
    r = subprocess.run([OPENSSL, 'x509', '-noout'], input=pem.encode(), capture_output=True, timeout=10)
    if r.returncode != 0:
        raise Fehler('Zertifikat lässt sich nicht lesen.')
    return pem + '\n'


def set_t2med(config: dict, d: dict) -> None:
    old = config.get('t2med') or {}
    launch = d.get('launch_urls', [])
    if not isinstance(launch, list) or len(launch) > 10:
        raise Fehler('Höchstens 10 Start-Adressen.')
    base = _url(d.get('base_url'), 'Adresse des FHIR-Servers')
    key = d.get('api_key', '')
    clear = d.get('api_key_entfernen') is True
    key = key.strip() if isinstance(key, str) else key   # beim Kopieren mitgenommene Leerzeichen am Rand
    if not isinstance(key, str) or len(key) > 300 or CONTROL.search(key):
        raise Fehler('API-Schlüssel ungültig.')
    config['t2med'] = {'base_url': base, 'launch_urls': sorted({_url(u, 'Start-Adresse') for u in launch} | {base}),
                       'api_key': '' if clear else (key or old.get('api_key', '')), 'ca_pem': _cert(d.get('ca_pem', '')) if 'ca_pem' in d else old.get('ca_pem', ''),
                       'pin': d.get('pin') is True}
    if config['t2med']['pin'] and not config['t2med']['ca_pem']:
        raise Fehler('Zum festen Prüfen (Pinning) bitte das Zertifikat des T2med-Servers eintragen.')


def set_mail(config: dict, d: dict) -> None:
    old = config.get('mail') or {}
    host = _text(d.get('host'), 'SMTP-Server', 253)
    if not ablage.HOST.match(host):
        raise Fehler('SMTP-Server ungültig.')
    port = d.get('port')
    if not isinstance(port, int) or not 1 <= port <= 65535:
        raise Fehler('Port ungültig.')
    sec = d.get('sicherheit')
    if sec not in ('starttls', 'smtps'):
        raise Fehler('Verschlüsselung: starttls oder smtps.')
    sender = _text(d.get('absender'), 'Absender', 254)
    if not EMAIL.match(sender):
        raise Fehler('Absender ist keine E-Mail-Adresse.')
    pw = d.get('passwort', '')
    if not isinstance(pw, str) or len(pw) > 300 or CONTROL.search(pw):
        raise Fehler('Passwort ungültig.')
    config['mail'] = {'host': host, 'port': port, 'sicherheit': sec, 'benutzer': _text(d.get('benutzer', ''), 'Benutzer', 254, required=False),
                      'passwort': pw if pw else old.get('passwort', ''), 'absender': sender}


SMS_NUMBER = re.compile(r'^\+[1-9][0-9]{7,14}$')
SMS_SENDER = re.compile(r'^(?:[A-Za-z0-9]{1,11}|\+?[0-9]{1,15})$')
SMS_URL = 'https://gateway.seven.io/api/'


def set_sms(config: dict, d: dict) -> None:
    """SMS-Verbindung (seven.io): API-Schlüssel und optionaler Absendername; ein leerer Schlüssel behält den gespeicherten."""
    old = config.get('sms') or {}
    key = d.get('api_key', '')
    if not isinstance(key, str) or len(key) > 200 or CONTROL.search(key) or ' ' in key:
        raise Fehler('API-Schlüssel ungültig.')
    key = key or old.get('api_key', '')
    if not key:
        raise Fehler('Bitte den API-Schlüssel von seven.io eingeben.')
    sender = _text(d.get('absender', ''), 'Absender', 16, required=False)
    if sender and not SMS_SENDER.match(sender):
        raise Fehler('Absender: höchstens 11 Buchstaben/Ziffern (ohne Leerzeichen) oder eine Nummer.')
    config['sms'] = {'anbieter': 'seven', 'api_key': key, 'absender': sender}


LXP_URL = 'https://api.letterxpress.de/v3'


def set_letterxpress(config: dict, d: dict) -> None:
    """Letterxpress (Briefversand): Benutzername, API-Key und Betrieb Test/Live; ein leerer Schlüssel behält den gespeicherten."""
    old = config.get('letterxpress') or {}
    key = d.get('api_key', '')
    if not isinstance(key, str) or len(key) > 200 or CONTROL.search(key):
        raise Fehler('API-Key ungültig.')
    key = key.strip() or old.get('api_key', '')
    if not key:
        raise Fehler('Bitte den Letterxpress API-Key eingeben.')
    mode = d.get('modus', 'test')
    if mode not in ('test', 'live'):
        raise Fehler('Betrieb: bitte Test oder Live wählen.')
    config['letterxpress'] = {'username': _text(d.get('username', ''), 'Benutzername', 200), 'api_key': key, 'modus': mode}


def letterxpress_balance(c: dict | None) -> float | None:
    """Guthaben bei Letterxpress abfragen (nichts wird gedruckt oder verschickt); None, wenn die Antwort keinen Betrag enthält."""
    if not c:
        raise Fehler('Letterxpress ist nicht eingerichtet.')
    import urllib.request
    body = json.dumps({'auth': {'username': c['username'], 'apikey': c['api_key'], 'mode': c.get('modus', 'test')}}).encode()
    req = urllib.request.Request(LXP_URL + '/balance', data=body, method='GET', headers={'Accept': 'application/json', 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=15) as r:  # noqa: S310 (feste https-Adresse)
            out = json.loads(r.read(20000).decode('utf-8', 'replace'))
    except OSError as e:
        raise Fehler(f'Letterxpress nicht erreichbar oder Zugangsdaten abgelehnt: {str(e)[:100]}') from None
    except ValueError:
        raise Fehler('Letterxpress hat unlesbar geantwortet.') from None
    data = out.get('data') if isinstance(out, dict) and isinstance(out.get('data'), dict) else {}
    bal = data.get('balance')
    return float(bal) if isinstance(bal, (int, float)) and not isinstance(bal, bool) else None


def _balance_record(key: str, value: float | None) -> None:
    """Letzten abgerufenen Wert samt Zeit merken (Anzeige auf der Verbindungsseite)."""
    try:
        old = json.loads((STATE / 'status.json').read_text(encoding='utf-8'))
    except (OSError, ValueError):
        old = {}
    g = old.get('guthaben', {})
    g[key] = {'wert': value, 'zeit': int(time.time())}
    old['guthaben'] = g
    _write(STATE / 'status.json', old, 0o644)


def test_letterxpress(c: dict | None) -> str:
    """Zugangsdaten prüfen (Guthaben abfragen); es wird nichts gedruckt oder verschickt."""
    bal = letterxpress_balance(c)
    _balance_record('letterxpress', bal)
    betrieb = 'Live (echter Postversand)' if c.get('modus') == 'live' else 'Test (nichts wird verschickt)'
    return 'Verbunden' + (f'. Guthaben: {bal:.2f} €' if bal is not None else '') + f'. Betrieb: {betrieb}.'


def balance_letterxpress(c: dict | None) -> str:
    bal = letterxpress_balance(c)
    _balance_record('letterxpress', bal)
    return f'Guthaben bei Letterxpress: {bal:.2f} €.' if bal is not None else 'Letterxpress hat kein Guthaben gemeldet.'


def _sms_call(c: dict, path: str, payload: dict | None) -> dict:
    import urllib.request
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(SMS_URL + path, data=data, method='POST' if payload is not None else 'GET',
                                 headers={'X-Api-Key': c['api_key'], 'Accept': 'application/json', 'Content-Type': 'application/json', 'SentWith': 'Praxisherz'})
    try:
        with urllib.request.urlopen(req, timeout=15) as r:  # noqa: S310 (feste https-Adresse)
            body = r.read(20000).decode('utf-8', 'replace')
    except OSError as e:
        raise Fehler(f'seven.io nicht erreichbar oder Schlüssel abgelehnt: {str(e)[:100]}') from None
    try:
        out = json.loads(body)
    except ValueError:
        out = body.strip()
    return out if isinstance(out, dict) else {'wert': out}


def sms_balance(c: dict | None) -> float | None:
    """Guthaben bei seven.io abfragen (es wird nichts verschickt) und samt Zeit merken; None, wenn kein Betrag gemeldet wird."""
    if not c:
        raise Fehler('SMS ist nicht eingerichtet.')
    bal = _sms_betrag(_sms_call(c, 'balance', None))
    _balance_record('sms', bal)
    return bal


def _sms_betrag(antwort: dict) -> float | None:
    """Betrag aus der Antwort von seven.io lesen: Zahl, Text („20.50“, „20,50“) oder Objekt mit amount/balance/value."""
    wert = antwort.get('wert') if 'wert' in antwort else next((antwort[k] for k in ('amount', 'balance', 'value', 'credit') if k in antwort), None)
    if isinstance(wert, dict):
        return _sms_betrag(wert)
    if isinstance(wert, bool):
        return None
    if isinstance(wert, (int, float)):
        return float(wert)
    if isinstance(wert, str):
        try:
            return float(wert.strip().replace('€', '').replace(',', '.'))
        except ValueError:
            return None
    return None


def test_sms(c: dict | None) -> str:
    bal = sms_balance(c)
    return 'Schlüssel von seven.io akzeptiert (keine SMS gesendet).' + (f' Guthaben: {bal:.2f} €.' if bal is not None else '')


def balance_sms(c: dict | None) -> str:
    bal = sms_balance(c)
    return f'Guthaben bei seven.io: {bal:.2f} €.' if bal is not None else 'seven.io hat kein Guthaben gemeldet.'


def send_sms(c: dict | None, to: str, text: str) -> None:
    """Eine SMS über die zentrale Verbindung (nur kurzer Text, keine Patientendaten)."""
    if not c:
        raise Fehler('SMS ist nicht eingerichtet.')
    if not isinstance(to, str) or not SMS_NUMBER.match(to):
        raise Fehler('Handynummer bitte im Format +491701234567 angeben.')
    if not isinstance(text, str) or not text.strip() or len(text) > 320 or CONTROL.search(text.replace('\n', ' ')):
        raise Fehler('SMS-Text ungültig.')
    payload = {'to': to, 'text': text}
    if c.get('absender'):
        payload['from'] = c['absender']
    r = _sms_call(c, 'sms', payload)
    if str(r.get('success', '')) != '100':
        raise Fehler(f'seven.io hat die SMS nicht angenommen (Antwort {str(r.get("success", r))[:40]}).')


def test_sms_send(c: dict | None, to: str) -> str:
    send_sms(c, to, 'Praxisherz: Testnachricht. Es ist nichts weiter zu tun.')
    return f'Test-SMS an {to} verschickt.'


def set_target(config: dict, d: dict) -> None:
    if not isinstance(d, dict):
        raise Fehler('Ziel ungültig.')
    old = next((t for t in config['ablage'] if t['id'] == d.get('id')), {})
    merged = {**d}
    if d.get('art') == 'smb' and not d.get('passwort') and not d.get('gast'):
        merged['passwort'] = old.get('passwort', '')
    if d.get('art') == 'sftp':
        # Der Serverschlüssel kommt nur über „abrufen und übernehmen“ (Fingerabdruck geprüft), nie mit dem Formular.
        merged['hostkey'] = old.get('hostkey', '') if old.get('host') == d.get('host') and old.get('port', 22) == d.get('port', 22) else ''
    target = ablage.check_target(merged)
    if not old and len(config['ablage']) >= 20:
        raise Fehler('Höchstens 20 Ziele.')
    config['ablage'] = [t for t in config['ablage'] if t['id'] != target['id']] + [target]
    config['ablage'].sort(key=lambda t: t['id'])


# ---- Verteilen ---------------------------------------------------------------------------------------------------
def registry() -> list[dict]:
    try:
        info = os.stat(MODULES, follow_symlinks=False)
    except FileNotFoundError:
        return []
    if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o022 or (CHOWN and info.st_uid != 0):
        raise Fehler('Modulverzeichnis ist nicht vertrauenswürdig.')
    return json.loads(MODULES.read_text(encoding='utf-8')).get('modules', [])


def _group_exists(name: str) -> bool:
    if not CHOWN:
        return True
    try:
        grp.getgrnam(name)
        return True
    except KeyError:
        return False


def distribute(config: dict) -> list[str]:
    """Dateien je Modul und für den Netzwerkspeicher schreiben. Liefert Hinweise (z. B. fehlende Benutzer)."""
    notes = []
    _write(ETC / 'ablage.json', {'ziele': config['ablage']}, 0o640, 'ks-ablage' if _group_exists('ks-ablage') else None)
    wanted = set()
    senden = []                                         # Module mit Nachrichten-Ausgang (Verbindung „nachrichten“)
    for m in registry():
        mid = m['module']['id']
        group = f'ks-{mid}'
        if not _group_exists(group):
            notes.append(f'{mid}: Benutzer {group} fehlt, nichts verteilt.')
            continue
        key = KEYS / f'{mid}.key'
        if not key.exists():
            _write(key, secrets.token_hex(32) + '\n', 0o640, group)
        ids = [c['id'] for c in m.get('connections', [])]
        if not ids:
            continue
        out = {}
        if 't2med' in ids and config['t2med']:
            out['t2med'] = config['t2med']
        if 'mail' in ids and config['mail']:
            out['mail'] = config['mail']
        if 'letterxpress' in ids and config.get('letterxpress'):
            out['letterxpress'] = config['letterxpress']
        if 'ablage' in ids:
            spool = SPOOL / mid
            spool.mkdir(mode=0o2770, parents=True, exist_ok=True)
            os.chmod(spool, 0o2770)
            if CHOWN and _group_exists('ks-ablage'):
                shutil.chown(spool, group, 'ks-ablage')
            inbox = spool / 'eingang'
            inbox.mkdir(mode=0o2770, exist_ok=True)
            os.chmod(inbox, 0o2770)
            if CHOWN and _group_exists('ks-ablage'):
                shutil.chown(inbox, group, 'ks-ablage')
            out['ablage'] = {'ausgang': str(spool), 'eingang': str(inbox), 'ziele': [{'id': t['id'], 'name': t['name']} for t in config['ablage']]}
        if 'nachrichten' in ids:
            # Ausgang für SMS und E-Mail: das Modul legt Aufträge ab, ks-nachrichten verschickt sie mit den Zugängen der Basis.
            ausgang = NACHRICHTEN_SPOOL / mid
            ausgang.mkdir(mode=0o2770, parents=True, exist_ok=True)
            os.chmod(ausgang, 0o2770)
            if CHOWN and _group_exists('ks-nachrichten'):
                shutil.chown(ausgang, group, 'ks-nachrichten')
            out['nachrichten'] = {'ausgang': str(ausgang), 'sms': bool(config.get('sms')), 'mail': bool(config.get('mail'))}
            senden.append(mid)
        _write(ETC / 'module' / f'{mid}.json', out, 0o640, group)
        wanted.add(f'{mid}.json')
    for old in (ETC / 'module').glob('*.json') if (ETC / 'module').is_dir() else []:
        if old.name not in wanted:
            old.unlink()
    # Zugänge für den Nachrichten-Dienst (nur ks-nachrichten liest sie) und die Liste der Module, deren Ausgang er abarbeitet.
    _write(ETC / 'nachrichten.json', {'sms': config.get('sms') or None, 'mail': config.get('mail') or None, 'module': sorted(senden)}, 0o640,
           'ks-nachrichten' if _group_exists('ks-nachrichten') else None)
    return notes


def fetch_certificate(base_url: str) -> str:
    """Zertifikat des T2med-Servers abrufen (ungeprüft; der Admin bestätigt es anhand des Fingerabdrucks)."""
    url = urllib.parse.urlparse(base_url)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    try:
        with socket.create_connection((url.hostname, url.port or 443), timeout=10) as raw, ctx.wrap_socket(raw, server_hostname=url.hostname) as s:
            der = s.getpeercert(binary_form=True)
    except (OSError, ssl.SSLError) as e:
        raise Fehler(f'T2med nicht erreichbar: {str(e)[:120]}') from None
    if not der:
        raise Fehler('Der Server liefert kein Zertifikat.')
    return ssl.DER_cert_to_PEM_cert(der)


# ---- Prüfen ------------------------------------------------------------------------------------------------------
def test_t2med(c: dict) -> str:
    if not c:
        raise Fehler('T2med ist nicht eingerichtet.')
    ctx = ssl.create_default_context()
    if c.get('ca_pem'):
        ctx = ssl.create_default_context(cadata=c['ca_pem'])
    if c.get('pin') and c.get('ca_pem'):
        # Fest hinterlegtes Zertifikat: nur der Fingerabdruck zählt (auch selbstsigniert, auch bei IP-Adresse statt Namen)
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    url = urllib.parse.urlparse(c['base_url'] + '/metadata')
    try:
        with socket.create_connection((url.hostname, url.port or 443), timeout=10) as raw, ctx.wrap_socket(raw, server_hostname=url.hostname) as s:
            if c.get('pin'):
                der = ssl.PEM_cert_to_DER_cert(c['ca_pem'])
                if hashlib.sha256(s.getpeercert(binary_form=True)).digest() != hashlib.sha256(der).digest():
                    raise Fehler('Der T2med-Server zeigt ein anderes Zertifikat als eingetragen.')
            path = url.path + ('?' + url.query if url.query else '')
            s.sendall(f'GET {path} HTTP/1.1\r\nHost: {url.hostname}\r\nAccept: application/fhir+json\r\nX-API-Key: {c.get("api_key", "")}\r\nConnection: close\r\n\r\n'.encode())
            data = b''
            while len(data) < 2_000_000:
                chunk = s.recv(65536)
                if not chunk:
                    break
                data += chunk
    except (OSError, ssl.SSLError) as e:
        hint = ' Das Zertifikat lässt sich unter Verbindungen → T2med abrufen und nach Prüfung des Fingerabdrucks übernehmen.' if 'CERTIFICATE_VERIFY_FAILED' in str(e) else ''
        raise Fehler(f'T2med nicht erreichbar: {str(e)[:120]}.{hint}') from None
    head, _, body = data.partition(b'\r\n\r\n')
    status = head.split(b' ', 2)[1:2]
    code = status[0].decode('ascii', 'replace')[:3] if status else '?'
    if code in ('401', '403'):
        # T2med verlangt für die FHIR-Abfragen neben dem Client Secret (X-API-Key) das OAuth-Token, das es nur beim Aufruf aus der Akte mitgibt.
        # Die Prüfung hat kein Token und kann das Secret deshalb nicht allein bestätigen; Server und Zertifikat sind aber in Ordnung.
        return (f'T2med ist erreichbar und das Zertifikat stimmt (HTTP {code}). Das Client Secret lässt sich hier nicht allein prüfen, '
                'weil T2med zusätzlich das Token verlangt, das es nur beim Öffnen der Kasse aus T2med mitgibt. Bitte die Kasse einmal aus T2med öffnen.')
    if code == '404':
        raise Fehler('T2med kennt diese Adresse nicht (HTTP 404). Der Pfad der FHIR-Adresse stimmt nicht, meist …/aps/fhir/api/r4.')
    if status != [b'200'] or b'CapabilityStatement' not in body:
        kind = ''
        for line in head.split(b'\r\n')[1:]:
            if line.lower().startswith(b'content-type:'):
                kind = line.split(b':', 1)[1].strip().decode('ascii', 'replace')[:60]
        raise Fehler(f'T2med antwortet (HTTP {code}{", " + kind if kind else ""}), aber nicht wie ein FHIR-Server unter {url.path}. '
                     'Adresse (Port und Pfad) prüfen: sie muss auf die FHIR-Schnittstelle von T2med zeigen, nicht auf die Weboberfläche.')
    return 'FHIR-Server antwortet (CapabilityStatement).'


def _smtp(c: dict, action) -> None:
    ctx = ssl.create_default_context()
    if E('KIENZLE_SMTP_CAFILE'):                       # nur Tests: eigener Test-Server
        ctx.load_verify_locations(E('KIENZLE_SMTP_CAFILE'))
    smtp = smtplib.SMTP_SSL(c['host'], c['port'], timeout=15, context=ctx) if c['sicherheit'] == 'smtps' else smtplib.SMTP(c['host'], c['port'], timeout=15)
    with smtp:
        if c['sicherheit'] == 'starttls':
            smtp.starttls(context=ctx)
        if c.get('benutzer'):
            smtp.login(c['benutzer'], c.get('passwort', ''))
        action(smtp)


GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token'
GMAIL_SEND = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'
GOOGLE_REDIRECT = 'http://localhost'
CLIENT_ID = re.compile(r'^[A-Za-z0-9._-]{10,200}$')


def _google_post(url: str, data: bytes, headers: dict) -> dict:
    """POST an Google; Fehler ohne Schlüssel und mit kurzer Begründung."""
    import urllib.error
    import urllib.request
    req = urllib.request.Request(url, data=data, method='POST', headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:  # noqa: S310 (feste https-Adressen)
            out = json.loads(r.read(200000).decode('utf-8', 'replace') or '{}')
    except urllib.error.HTTPError as e:
        try:
            err = json.loads(e.read(20000).decode('utf-8', 'replace'))
        except ValueError:
            err = {}
        fehler = err.get('error')
        why = err.get('error_description') or (fehler if isinstance(fehler, str) else '') or (fehler.get('message') if isinstance(fehler, dict) else '') or ''
        raise Fehler(f'Google lehnt ab (HTTP {e.code})' + (f': {CONTROL.sub(" ", str(why))[:160]}' if why else '') + '.') from None
    except (OSError, ValueError) as e:
        raise Fehler(f'Google nicht erreichbar: {str(e)[:100]}') from None
    return out if isinstance(out, dict) else {}


def set_gmail(config: dict, d: dict) -> None:
    """Gmail per OAuth: eigener Google-Client der Praxis; der Code aus der Anmeldung wird gegen ein Refresh-Token getauscht.
    Ohne neuen Code bleibt die bestehende Anmeldung (nur Absender, Client ändern ist dann nicht möglich)."""
    old = config.get('mail') if (config.get('mail') or {}).get('art') == 'gmail' else {}
    cid = _text(d.get('client_id', old.get('client_id', '')), 'Client-ID', 200)
    if not CLIENT_ID.match(cid):
        raise Fehler('Client-ID ungültig (sie endet meist auf .apps.googleusercontent.com).')
    secret = d.get('client_secret', '')
    if not isinstance(secret, str) or len(secret) > 200 or CONTROL.search(secret) or ' ' in secret:
        raise Fehler('Client-Secret ungültig.')
    secret = secret or old.get('client_secret', '')
    if not secret:
        raise Fehler('Bitte das Client-Secret eintragen.')
    sender = _text(d.get('absender'), 'Absender', 254)
    if not EMAIL.match(sender):
        raise Fehler('Absender ist keine E-Mail-Adresse (die Gmail-Adresse, mit der du dich anmeldest).')
    code = d.get('code', '')
    if not isinstance(code, str) or len(code) > 500 or CONTROL.search(code) or ' ' in code:
        raise Fehler('Code ungültig.')
    refresh = old.get('refresh_token', '')
    if code:
        data = urllib.parse.urlencode({'code': code, 'client_id': cid, 'client_secret': secret, 'redirect_uri': GOOGLE_REDIRECT, 'grant_type': 'authorization_code'}).encode()
        out = _google_post(GOOGLE_TOKEN, data, {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'})
        refresh = out.get('refresh_token', '')
        if not refresh:
            raise Fehler('Google hat kein Dauer-Token geliefert. Bitte die Anmeldung wiederholen und unter „Zugriff“ alles bestätigen '
                         '(ggf. bei myaccount.google.com → Sicherheit den alten Zugriff entfernen).')
    if not refresh:
        raise Fehler('Noch nicht mit Google angemeldet: bitte auf „Mit Google verbinden“ klicken und den Code übernehmen.')
    config['mail'] = {'art': 'gmail', 'client_id': cid, 'client_secret': secret, 'refresh_token': refresh, 'absender': sender}


def _gmail_access(c: dict) -> str:
    data = urllib.parse.urlencode({'client_id': c['client_id'], 'client_secret': c['client_secret'], 'refresh_token': c['refresh_token'], 'grant_type': 'refresh_token'}).encode()
    out = _google_post(GOOGLE_TOKEN, data, {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'})
    token = out.get('access_token')
    if not isinstance(token, str) or not token:
        raise Fehler('Google hat kein Zugriffs-Token geliefert.')
    return token


def test_mail(c: dict) -> str:
    if not c:
        raise Fehler('E-Mail ist nicht eingerichtet.')
    if c.get('art') == 'gmail':
        _gmail_access(c)
        return 'Anmeldung bei Google erfolgreich (keine Nachricht gesendet).'
    try:
        _smtp(c, lambda smtp: None)
    except (OSError, smtplib.SMTPException, ssl.SSLError) as e:
        raise Fehler(f'Anmeldung am SMTP-Server fehlgeschlagen: {str(e)[:120]}') from None
    return 'Anmeldung am SMTP-Server erfolgreich (keine Nachricht gesendet).'


def send_mail(c: dict | None, to: list[str], subject: str, body: str) -> None:  # noqa: C901
    """Nachricht von Praxisherz über die zentrale E-Mail-Verbindung (nur Text, keine Patientendaten)."""
    from email.message import EmailMessage
    if not c:
        raise Fehler('E-Mail ist nicht eingerichtet.')
    to = [a for a in to if isinstance(a, str) and EMAIL.match(a)][:10]
    if not to:
        raise Fehler('Keine gültige Empfängeradresse.')
    msg = EmailMessage()
    msg['From'], msg['To'], msg['Subject'] = c['absender'], ', '.join(to), CONTROL.sub('', subject)[:150]
    msg['Auto-Submitted'] = 'auto-generated'
    msg.set_content(body[:20000])
    if c.get('art') == 'gmail':
        import base64
        raw = base64.urlsafe_b64encode(msg.as_bytes()).decode()
        kopf = {'Authorization': 'Bearer ' + _gmail_access(c), 'Content-Type': 'application/json', 'Accept': 'application/json'}
        _google_post(GMAIL_SEND, json.dumps({'raw': raw}).encode(), kopf)
        return
    try:
        _smtp(c, lambda smtp: smtp.send_message(msg))
    except (OSError, smtplib.SMTPException, ssl.SSLError) as e:
        raise Fehler(f'Versand fehlgeschlagen: {str(e)[:120]}') from None


def test_mail_send(c: dict | None, to: str) -> str:
    if not isinstance(to, str) or not EMAIL.match(to):
        raise Fehler('Bitte eine gültige Empfängeradresse eingeben.')
    send_mail(c, [to], 'Praxisherz: Testnachricht', 'Diese Nachricht wurde von Praxisherz verschickt, um die E-Mail-Verbindung zu prüfen.\n'
              'Es ist nichts weiter zu tun.\n')
    return f'Testnachricht an {to} verschickt.'


# ---- Status ohne Geheimnisse -------------------------------------------------------------------------------------
def _mail_status(mail: dict) -> dict:
    """Mail-Verbindung für die Statusanzeige (ohne Passwort, Secret und Token)."""
    if mail.get('art') == 'gmail':
        return {'art': 'gmail', 'client_id': mail['client_id'], 'absender': mail['absender'],
                'secret_gesetzt': bool(mail.get('client_secret')), 'verbunden': bool(mail.get('refresh_token'))}
    return {'art': 'smtp', **{k: mail[k] for k in ('host', 'port', 'sicherheit', 'benutzer', 'absender')}, 'passwort_gesetzt': bool(mail.get('passwort'))}


def view(config: dict, extra: dict | None = None) -> dict:
    try:
        old = json.loads((STATE / 'status.json').read_text(encoding='utf-8'))
    except (OSError, ValueError):
        old = {}
    t2 = config['t2med']
    mail = config['mail']
    sms = config.get('sms')
    lxp = config.get('letterxpress')
    ca_fp = ''
    if t2 and t2.get('ca_pem'):
        ca_fp = hashlib.sha256(ssl.PEM_cert_to_DER_cert(t2['ca_pem'])).hexdigest()
    usage = []
    try:
        for m in registry():
            for c in m.get('connections', []):
                usage.append({'modul': m['module']['id'], 'name': m['module']['name'], 'verbindung': c['id'], 'zweck': c['purpose'], 'pflicht': c['required']})
    except (Fehler, OSError, ValueError):
        pass
    out = {'zeit': int(time.time()), 'tests': old.get('tests', {}), 'hostkeys': old.get('hostkeys', {}), 'zertifikate': old.get('zertifikate', {}),
           'hinweise': old.get('hinweise', []), 'guthaben': old.get('guthaben', {}),
           't2med': {**{k: t2[k] for k in ('base_url', 'launch_urls', 'pin')}, 'api_key_gesetzt': bool(t2.get('api_key')), 'zertifikat': ca_fp} if t2 else None,
           'mail': _mail_status(mail) if mail else None,
           'sms': {'anbieter': sms['anbieter'], 'absender': sms.get('absender', ''), 'schluessel_gesetzt': bool(sms.get('api_key'))} if sms else None,
           'letterxpress': {'username': lxp['username'], 'modus': lxp.get('modus', 'test'), 'schluessel_gesetzt': bool(lxp.get('api_key'))} if lxp else None,
           'ablage': [{**{k: v for k, v in t.items() if k != 'passwort'}, 'passwort_gesetzt': bool(t.get('passwort')),
                       'fingerprint': ablage.fingerprint(t['hostkey']) if t.get('hostkey') else ''} for t in config['ablage']],
           'nutzung': usage, 'sftp_schluessel': ABLAGE_PUB.read_text(encoding='utf-8').strip() if ABLAGE_PUB.is_file() else ''}
    out.update(extra or {})
    _write(STATE / 'status.json', out, 0o644)
    return out


# ---- Anfragen ----------------------------------------------------------------------------------------------------
def handle() -> None:
    uid = app_uid()
    try:
        fd = os.open(REQUEST, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        return
    try:
        info = os.fstat(fd)
        raw = os.read(fd, 65537) if stat.S_ISREG(info.st_mode) and info.st_size <= 65536 and (uid < 0 or info.st_uid == uid) else None
    finally:
        os.close(fd)
        try:
            os.unlink(REQUEST)
        except OSError:
            pass
    config = load()
    try:
        req = json.loads(raw) if raw else None
        if not isinstance(req, dict):
            raise Fehler('Anfrage ungültig.')
        result = dispatch(config, req)
        view(config, {'letzte': {'ok': True, 'meldung': result, 'zeit': int(time.time())}})
    except (Fehler, ablage.Fehler, ValueError, TypeError, KeyError) as e:
        msg = str(e) if isinstance(e, (Fehler, ablage.Fehler)) else 'Anfrage ungültig.'
        view(config, {'letzte': {'ok': False, 'meldung': msg[:300], 'zeit': int(time.time())}})


def _test_record(key: str, ok: bool, message: str) -> None:
    try:
        old = json.loads((STATE / 'status.json').read_text(encoding='utf-8'))
    except (OSError, ValueError):
        old = {}
    tests = old.get('tests', {})
    tests[key] = {'ok': ok, 'meldung': message[:300], 'zeit': int(time.time())}
    old['tests'] = tests
    _write(STATE / 'status.json', old, 0o644)


def dispatch(config: dict, req: dict) -> str:
    action = req.get('action')
    d = req.get('daten') if isinstance(req.get('daten'), dict) else {}
    if action == 't2med':
        if req.get('loeschen') is True:
            config['t2med'] = None
        else:
            set_t2med(config, d)
        save(config)
        notes = distribute(config)
        view(config, {'hinweise': notes})
        return 'T2med-Verbindung gespeichert und an die Module verteilt.' if config['t2med'] else 'T2med-Verbindung entfernt.'
    if action == 'mail':
        if req.get('loeschen') is True:
            config['mail'] = None
        else:
            set_mail(config, d)
        save(config)
        view(config, {'hinweise': distribute(config)})
        return 'E-Mail-Verbindung gespeichert und an die Module verteilt.' if config['mail'] else 'E-Mail-Verbindung entfernt.'
    if action == 'gmail':
        set_gmail(config, d)
        save(config)
        view(config, {'hinweise': distribute(config)})
        return 'Gmail verbunden und an die Module verteilt.'
    if action == 'sms':
        if req.get('loeschen') is True:
            config['sms'] = None
        else:
            set_sms(config, d)
        save(config)
        view(config, {'hinweise': distribute(config)})
        return 'SMS-Verbindung gespeichert.' if config.get('sms') else 'SMS-Verbindung entfernt.'
    if action == 'letterxpress':
        if req.get('loeschen') is True:
            config['letterxpress'] = None
        else:
            set_letterxpress(config, d)
        save(config)
        view(config, {'hinweise': distribute(config)})
        return 'Letterxpress gespeichert und an die Module verteilt.' if config.get('letterxpress') else 'Letterxpress entfernt.'
    if action == 'sms-senden':
        send_sms(config.get('sms'), req.get('an'), req.get('text'))
        return 'SMS verschickt.'
    if action == 'mail-senden':
        send_mail(config.get('mail'), [req.get('an')], str(req.get('betreff') or ''), str(req.get('text') or ''))
        return 'E-Mail verschickt.'
    if action == 'ziel':
        set_target(config, d)
        save(config)
        view(config, {'hinweise': distribute(config)})
        return f'Ziel „{d.get("name", "")}“ gespeichert.'
    if action == 'ziel-loeschen':
        before = len(config['ablage'])
        config['ablage'] = [t for t in config['ablage'] if t['id'] != req.get('id')]
        if len(config['ablage']) == before:
            raise Fehler('Ziel nicht gefunden.')
        save(config)
        view(config, {'hinweise': distribute(config)})
        return 'Ziel entfernt.'
    if action == 'verteilen':
        notes = distribute(config)
        view(config, {'hinweise': notes})
        return 'Verbindungen neu verteilt.'
    if action == 'pruefen':
        art, tid = req.get('art'), req.get('id')
        key = 'ziel:' + str(tid) if art == 'ziel' else str(art)
        try:
            if art == 't2med':
                msg = test_t2med(config['t2med'])
            elif art == 'mail':
                msg = test_mail(config['mail'])
            elif art == 'sms':
                msg = test_sms(config.get('sms'))
            elif art == 'letterxpress':
                msg = test_letterxpress(config.get('letterxpress'))
            elif art == 'guthaben' and tid == 'sms':
                key = 'guthaben'
                msg = balance_sms(config.get('sms'))
            elif art == 'guthaben':
                key = 'guthaben'
                msg = balance_letterxpress(config.get('letterxpress'))
            elif art == 'testsms':
                key = 'sms'
                msg = test_sms_send(config.get('sms'), req.get('an'))
            elif art == 'testmail':
                key = 'mail'
                msg = test_mail_send(config['mail'], req.get('an'))
            elif art == 'ziel':
                target = next((t for t in config['ablage'] if t['id'] == tid), None)
                if not target:
                    raise Fehler('Ziel nicht gefunden.')
                if target['art'] == 'sftp' and not target.get('hostkey'):
                    # Noch kein Serverschlüssel bestätigt: gleich abrufen, damit unten die Schaltfläche zum Übernehmen erscheint.
                    keys = ablage.scan_hostkey(target['host'], target['port'])
                    view(config, {'hostkeys': {target['id']: [{'zeile': k, 'fingerprint': ablage.fingerprint(k), 'art': k.split()[1]} for k in keys]}})
                    raise Fehler('Der Schlüssel des Servers ist noch nicht bestätigt. Er wurde abgerufen: Bitte unten den Fingerabdruck '
                                 'mit der Anzeige des NAS vergleichen und auf „Übernehmen“ klicken, dann erneut prüfen.')
                msg = ablage.test_target(target)
            else:
                raise Fehler('Unbekannte Prüfung.')
        except (Fehler, ablage.Fehler, OSError, subprocess.SubprocessError) as e:
            _test_record(key, False, str(e))
            raise Fehler(str(e)) from None
        _test_record(key, True, msg)
        return msg
    if action == 'zertifikat-holen':
        t2 = config.get('t2med')
        if not t2:
            raise Fehler('Bitte zuerst die Adresse des FHIR-Servers speichern.')
        pem = fetch_certificate(t2['base_url'])
        der = ssl.PEM_cert_to_DER_cert(pem)
        view(config, {'zertifikate': {'t2med': {'pem': pem, 'fingerprint': hashlib.sha256(der).hexdigest()}}})
        return 'Zertifikat des T2med-Servers abgerufen. Bitte den Fingerabdruck mit der Anzeige in T2med oder im Browser vergleichen und übernehmen.'
    if action == 'zertifikat-uebernehmen':
        status = json.loads((STATE / 'status.json').read_text(encoding='utf-8'))
        cand = (status.get('zertifikate') or {}).get('t2med')
        if not cand or not config.get('t2med') or cand.get('fingerprint') != req.get('fingerprint'):
            raise Fehler('Kein abgerufenes Zertifikat mit diesem Fingerabdruck.')
        config['t2med']['ca_pem'] = _cert(cand['pem'])
        config['t2med']['pin'] = True
        save(config)
        status['zertifikate'].pop('t2med', None)
        _write(STATE / 'status.json', status, 0o644)
        view(config, {'hinweise': distribute(config)})
        return 'Zertifikat des T2med-Servers übernommen; es wird ab jetzt fest geprüft.'
    if action == 'hostkey-holen':
        target = next((t for t in config['ablage'] if t['id'] == req.get('id') and t['art'] == 'sftp'), None)
        if not target:
            raise Fehler('SFTP-Ziel nicht gefunden.')
        keys = ablage.scan_hostkey(target['host'], target['port'])
        found = [{'zeile': k, 'fingerprint': ablage.fingerprint(k), 'art': k.split()[1]} for k in keys]
        view(config, {'hostkeys': {target['id']: found}})
        return 'Schlüssel des Servers abgerufen. Bitte den Fingerabdruck mit der Anzeige des NAS vergleichen und übernehmen.'
    if action == 'hostkey-uebernehmen':
        status = json.loads((STATE / 'status.json').read_text(encoding='utf-8'))
        cand = next((k for k in status.get('hostkeys', {}).get(str(req.get('id')), []) if k['fingerprint'] == req.get('fingerprint')), None)
        target = next((t for t in config['ablage'] if t['id'] == req.get('id')), None)
        if not cand or not target:
            raise Fehler('Kein abgerufener Schlüssel mit diesem Fingerabdruck.')
        target['hostkey'] = cand['zeile']
        save(config)
        status['hostkeys'].pop(target['id'], None)
        _write(STATE / 'status.json', status, 0o644)
        view(config, {'hinweise': distribute(config)})
        return 'Schlüssel des Servers übernommen; er wird ab jetzt fest geprüft.'
    raise Fehler('Anfrage ungültig.')
