"""Netzwerkspeicher: Dateien per SMB oder SFTP auf ein NAS legen, ohne Mount und ohne root.

Übernommen aus kienzlezeit (kienzlezeit-backup-und-restore.sh: Remote, quote, safe_remote_path, Hochladen mit „.part“,
Rücklesen und SHA-256-Vergleich) und kienzle-suite-beta (src/InvoiceExport.php: smbclient mit Zugangsdatei, Ordner
schrittweise anlegen, Auswertung von NT_STATUS). Läuft als Dienstbenutzer ks-ablage; die Zugangsdaten bekommt nur er
(/etc/kienzle/verbindungen/ablage.json, root:ks-ablage 0640).

Module legen Aufträge in ihren Ausgangsordner /var/spool/kienzle/ablage/<modul>/ (Gruppe ks-ablage):
  <auftrag>.json  {"ziel": "steuerbuero", "ordner": "Rechnungen/{jahr}/{monat}", "name": "2026-03-15_RE-42.pdf", "jahr": "2026", "monat": "03"}
                  „ordner“ liegt unterhalb des festen Basisordners des Ziels.
  <auftrag>.daten die Datei selbst
"""
from __future__ import annotations

import hashlib
import ipaddress
import json
import os
import re
import secrets
import shutil
import subprocess
import tempfile
import time
from pathlib import Path

E = os.environ.get
TARGETS = Path(E('KIENZLE_ABLAGE_ZIELE', '/etc/kienzle/verbindungen/ablage.json'))
SPOOL = Path(E('KIENZLE_ABLAGE_SPOOL', '/var/spool/kienzle/ablage'))
STATE = Path(E('KIENZLE_ABLAGE_STATE', '/var/lib/kienzle/ablage'))
SMBCLIENT = E('KIENZLE_SMBCLIENT', '/usr/bin/smbclient')
SFTP = E('KIENZLE_SFTP', '/usr/bin/sftp')
KEYSCAN = E('KIENZLE_SSH_KEYSCAN', '/usr/bin/ssh-keyscan')
IDENTITY = Path(E('KIENZLE_ABLAGE_SCHLUESSEL', '/var/lib/kienzle/ablage/.ssh/id_ed25519'))
MAX_FILE = 200 * 1024 * 1024
MAX_TRIES = 48                      # stündlich: zwei Tage lang erneut versuchen

TARGET_ID = re.compile(r'^[a-z][a-z0-9-]{1,30}$')
HOST = re.compile(r'^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$')
SHARE = re.compile(r'^[A-Za-z0-9_.$ -]{1,80}$')
USER = re.compile(r'^[A-Za-z0-9_.@\\-]{1,64}$')
DIR = re.compile(r'^[A-Za-z0-9_./ {}-]{0,200}$')
NAME = re.compile(r'^[A-Za-z0-9_][A-Za-z0-9_., -]{0,150}\.[A-Za-z0-9]{1,8}$')
JOB = re.compile(r'^[A-Za-z0-9_-]{8,64}$')
HOSTKEY = re.compile(r'^\S+ (ssh-ed25519|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521|rsa-sha2-256|rsa-sha2-512|ssh-rsa) [A-Za-z0-9+/=]{40,1000}$')


class Fehler(Exception):
    pass


# ---- Prüfung der Angaben -----------------------------------------------------------------------------------------
def remote_dir(value: str) -> str:
    """Zielordner relativ zur Freigabe bzw. zum Startordner: nur einfache Zeichen, kein „..“."""
    value = (value or '').strip().strip('/')
    if not DIR.match(value) or any(p in ('..', '.') for p in value.split('/')) or '//' in value:
        raise Fehler('Ordner: nur Buchstaben, Zahlen, Leerzeichen, /, _, -, Punkt und {jahr}/{monat}; kein „..“.')
    return value


def quote(value: str) -> str:
    if any(c in value for c in '\n\r\x00"\\;*?[]'):
        raise Fehler('Nicht unterstütztes Sonderzeichen im Pfad.')
    return '"' + value + '"'


def check_target(t: dict) -> dict:
    """Ziel prüfen und vereinheitlichen. Geheimnisse (passwort) bleiben unverändert drin."""
    if not isinstance(t, dict):
        raise Fehler('Ziel ungültig.')
    out = {'id': str(t.get('id', '')), 'name': str(t.get('name', '')).strip()[:60], 'art': t.get('art')}
    if not TARGET_ID.match(out['id']):
        raise Fehler('Kennung des Ziels: 2–31 Zeichen, Kleinbuchstaben, Ziffern, Bindestrich.')
    if not out['name'] or re.search(r'[\x00-\x1f]', out['name']):
        raise Fehler('Bitte eine Bezeichnung angeben.')
    host = str(t.get('host', '')).strip()
    try:
        ipaddress.ip_address(host)
    except ValueError:
        if not HOST.match(host):
            raise Fehler('Servername oder IP-Adresse ungültig.') from None
    out['host'] = host
    out['ordner'] = remote_dir(str(t.get('ordner', '')))
    if '{' in out['ordner'] or '}' in out['ordner']:
        raise Fehler('Basisordner des Ziels ohne Platzhalter; {jahr}/{monat} bestimmt das Modul für seine Dateien.')
    if out['art'] == 'smb':
        if not SHARE.match(str(t.get('freigabe', ''))):
            raise Fehler('Name der Freigabe ungültig.')
        out['freigabe'] = str(t['freigabe'])
        out['gast'] = t.get('gast') is True
        if not out['gast']:
            for k, rx in (('benutzer', USER),):
                if not rx.match(str(t.get(k, ''))):
                    raise Fehler('Benutzername ungültig.')
            out['benutzer'] = str(t['benutzer'])
            out['domaene'] = str(t.get('domaene', ''))[:64]
            if out['domaene'] and not USER.match(out['domaene']):
                raise Fehler('Domäne ungültig.')
            pw = t.get('passwort', '')
            if not isinstance(pw, str) or any(c in pw for c in '\r\n\x00') or len(pw) > 200:
                raise Fehler('Passwort ungültig.')
            out['passwort'] = pw
    elif out['art'] == 'sftp':
        port = t.get('port', 22)
        if not isinstance(port, int) or not 1 <= port <= 65535:
            raise Fehler('Port ungültig.')
        out['port'] = port
        if not USER.match(str(t.get('benutzer', ''))):
            raise Fehler('Benutzername ungültig.')
        out['benutzer'] = str(t['benutzer'])
        hk = str(t.get('hostkey', '')).strip()
        if hk and not HOSTKEY.match(hk):
            raise Fehler('Schlüssel des Servers ungültig.')
        out['hostkey'] = hk
    else:
        raise Fehler('Art: SMB oder SFTP.')
    return out


def folder_for(template: str, year: str, month: str) -> str:
    if not re.fullmatch(r'\d{4}', year) or not re.fullmatch(r'\d{2}', month):
        raise Fehler('Jahr/Monat ungültig.')
    return remote_dir(template.replace('{jahr}', year).replace('{monat}', month))


# ---- Übertragung -------------------------------------------------------------------------------------------------
class Remote:
    """Nur Dateioperationen über SFTP bzw. smbclient; keine Shell auf dem NAS, kein Mount."""

    def __init__(self, target: dict, work: Path):
        self.t = target
        self.work = work

    def command(self, commands: list[str]) -> str:
        t = self.t
        script = commands
        if t['art'] == 'sftp':
            if not t.get('hostkey'):
                raise Fehler('Schlüssel des Servers noch nicht bestätigt.')
            known = self.work / 'known_hosts'
            known.write_text(t['hostkey'].replace(t['hostkey'].split(' ', 1)[0], f'[{t["host"]}]:{t["port"]}' if t['port'] != 22 else t['host'], 1) + '\n')
            args = [SFTP, '-F', '/dev/null', '-q', '-b', '-', '-P', str(t['port']), '-i', str(IDENTITY), '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
                    '-o', 'StrictHostKeyChecking=yes', '-o', 'GlobalKnownHostsFile=/dev/null', '-o', f'UserKnownHostsFile={known}',
                    '-o', 'ConnectTimeout=15', f'{t["benutzer"]}@{t["host"]}']
            r = subprocess.run(args, input='\n'.join(script) + '\n', capture_output=True, text=True, timeout=600)
            failed = r.returncode != 0
        else:
            args = [SMBCLIENT, f'//{t["host"]}/{t["freigabe"]}', '--option=client min protocol=SMB2', '-m', 'SMB3', '-t', '60', '-c', '; '.join(script)]
            if t.get('gast'):
                args += ['-U', 'guest%', '-N', '--use-kerberos=off']
            else:
                auth = self.work / 'auth'
                fd = os.open(auth, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
                with os.fdopen(fd, 'w') as f:
                    f.write(f'username = {t["benutzer"]}\npassword = {t["passwort"]}\n' + (f'domain = {t["domaene"]}\n' if t.get('domaene') else ''))
                args += ['-A', str(auth)]
            r = subprocess.run(args, capture_output=True, text=True, timeout=600)
            codes = sorted(set(re.findall(r'\bNT_STATUS_[A-Z0-9_]{1,64}\b', r.stdout + r.stderr)) - {'NT_STATUS_OBJECT_NAME_COLLISION'})
            failed = r.returncode != 0 or bool(codes)
            if failed and codes:
                raise Fehler('Netzwerkspeicher: ' + ', '.join(codes[:3]))
        if failed:
            raise Fehler('Übertragung fehlgeschlagen. Server, Freigabe, Zugangsdaten und Rechte prüfen.')
        return r.stdout

    @staticmethod
    def _cds(parts: list[str]) -> list[str]:
        return [f'cd {quote(p)}' for p in parts]

    def mkdirs(self, folder: str) -> list[str]:
        """Ordner Ebene für Ebene anlegen; „existiert bereits“ ist kein Fehler. Liefert die Ebenen."""
        parts = [p for p in self.t['ordner'].split('/') + folder.split('/') if p]
        for i, part in enumerate(parts):
            if self.t['art'] == 'sftp':
                self.command(self._cds(parts[:i]) + [f'-mkdir {quote(part)}'])
            else:
                try:
                    self.command(self._cds(parts[:i]) + [f'mkdir {quote(part)}'])
                except Fehler:
                    pass   # meist „existiert bereits“; echte Fehler zeigt das folgende Hochladen
        return parts

    def put_verified(self, local: Path, folder: str, name: str) -> str:
        """Hochladen als „.part“, zurücklesen, Prüfsumme vergleichen, dann umbenennen."""
        if not NAME.match(name):
            raise Fehler('Dateiname ungültig.')
        cds = self._cds(self.mkdirs(folder))
        part = name + '.part'
        back = self.work / 'zurueck'
        self.command(cds + [f'put {quote(str(local))} {quote(part)}'])
        self.command(cds + [f'get {quote(part)} {quote(str(back))}'])
        if not back.is_file() or sha256(back) != sha256(local):
            raise Fehler('Prüfung nach dem Hochladen fehlgeschlagen.')
        self.command(cds + [f'rename {quote(part)} {quote(name)}'])
        return '/'.join([p for p in [self.t['ordner'], folder] if p] + [name])

    def probe(self, folder: str) -> None:
        name = f'kienzle-verbindungstest-{secrets.token_hex(6)}.txt'
        src = self.work / 'probe'
        src.write_text(secrets.token_hex(16))
        cds = self._cds(self.mkdirs(folder))
        self.command(cds + [f'put {quote(str(src))} {quote(name)}'])
        try:
            back = self.work / 'zurueck'
            self.command(cds + [f'get {quote(name)} {quote(str(back))}'])
            if sha256(back) != sha256(src):
                raise Fehler('Verbindungstest: Inhalt stimmt nach dem Zurücklesen nicht.')
        finally:
            self.command(cds + [('rm ' if self.t['art'] == 'sftp' else 'del ') + quote(name)])


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for block in iter(lambda: f.read(1 << 20), b''):
            h.update(block)
    return h.hexdigest()


def test_target(target: dict) -> str:
    """Schreiben, Zurücklesen und Löschen einer Testdatei."""
    t = check_target(target)
    with tempfile.TemporaryDirectory(prefix='kienzle-ablage-') as tmp:
        Remote(t, Path(tmp)).probe('')
    return f'Schreiben, Zurücklesen und Löschen auf {t["name"]} erfolgreich.'


def scan_hostkey(host: str, port: int) -> list[str]:
    """Schlüssel des SFTP-Servers abrufen (zum Bestätigen durch den Admin anhand des Fingerabdrucks)."""
    if not (HOST.match(host) or _is_ip(host)) or not 1 <= port <= 65535:
        raise Fehler('Server ungültig.')
    r = subprocess.run([KEYSCAN, '-T', '10', '-p', str(port), '-t', 'ed25519,ecdsa,rsa', host], capture_output=True, text=True, timeout=30)
    keys = [line.strip() for line in r.stdout.splitlines() if HOSTKEY.match(line.strip())]
    if not keys:
        raise Fehler('Der Server liefert keinen SSH-Schlüssel. Adresse und Port prüfen.')
    return keys


def fingerprint(hostkey: str) -> str:
    import base64
    blob = base64.b64decode(hostkey.split()[2])
    return 'SHA256:' + base64.b64encode(hashlib.sha256(blob).digest()).decode().rstrip('=')


def _is_ip(v: str) -> bool:
    try:
        ipaddress.ip_address(v)
        return True
    except ValueError:
        return False


# ---- Aufträge der Module -----------------------------------------------------------------------------------------
def load_targets() -> dict[str, dict]:
    try:
        data = json.loads(TARGETS.read_text(encoding='utf-8'))
    except FileNotFoundError:
        return {}
    return {t['id']: check_target(t) for t in data.get('ziele', [])}


def _status(jobs: dict) -> None:
    STATE.mkdir(mode=0o755, parents=True, exist_ok=True)
    tmp = STATE / '.status.neu'
    tmp.write_text(json.dumps({'zeit': int(time.time()), 'auftraege': jobs}, ensure_ascii=False, indent=1))
    os.chmod(tmp, 0o644)
    os.replace(tmp, STATE / 'status.json')


def process() -> dict:
    """Alle Aufträge aller Module abarbeiten. Fehlgeschlagene bleiben liegen und werden später erneut versucht."""
    targets = load_targets()
    try:
        old = json.loads((STATE / 'status.json').read_text(encoding='utf-8')).get('auftraege', {})
    except (OSError, ValueError):
        old = {}
    jobs = {}
    for module_dir in sorted(p for p in SPOOL.iterdir() if p.is_dir() and not p.is_symlink()) if SPOOL.is_dir() else []:
        module = module_dir.name
        if not re.fullmatch(r'[a-z][a-z0-9-]{1,28}', module):
            continue
        for meta in sorted(module_dir.glob('*.json')):
            job = meta.stem
            key = f'{module}/{job}'
            data_file = meta.with_suffix('.daten')
            info = {'modul': module, 'versuche': old.get(key, {}).get('versuche', 0) + 1, 'zeit': int(time.time())}
            try:
                if not JOB.match(job) or meta.is_symlink() or data_file.is_symlink() or meta.stat().st_size > 4096:
                    raise Fehler('Auftrag ungültig.')
                req = json.loads(meta.read_text(encoding='utf-8'))
                if not isinstance(req, dict):
                    raise Fehler('Auftrag ungültig.')
                target = targets.get(str(req.get('ziel')))
                if target is None:
                    raise Fehler('Unbekanntes Ziel.')
                if not data_file.is_file() or data_file.stat().st_size > MAX_FILE:
                    raise Fehler('Datei fehlt oder ist zu groß.')
                name = str(req.get('name', ''))
                if not NAME.match(name):
                    raise Fehler('Dateiname ungültig.')
                folder = folder_for(remote_dir(str(req.get('ordner', ''))), str(req.get('jahr', '0000')), str(req.get('monat', '00')))
                with tempfile.TemporaryDirectory(prefix='kienzle-ablage-') as tmp:
                    path = Remote(target, Path(tmp)).put_verified(data_file, folder, name)
                meta.unlink()
                data_file.unlink()
                info.update(zustand='abgelegt', ziel=target['id'], pfad=path)
            except (Fehler, OSError, ValueError, subprocess.SubprocessError) as e:
                info.update(zustand='fehler', meldung=str(e)[:200])
                if info['versuche'] >= MAX_TRIES:
                    failed = module_dir / 'fehlgeschlagen'
                    failed.mkdir(mode=0o770, exist_ok=True)
                    for f in (meta, data_file):
                        if f.exists() and not f.is_symlink():
                            shutil.move(str(f), failed / f.name)
                    info['zustand'] = 'aufgegeben'
            jobs[key] = info
    # Erledigte Einträge eine Woche lang anzeigen.
    for key, info in old.items():
        if key not in jobs and info.get('zustand') in ('abgelegt', 'aufgegeben') and info.get('zeit', 0) > time.time() - 7 * 86400:
            jobs[key] = info
    _status(jobs)
    return jobs
