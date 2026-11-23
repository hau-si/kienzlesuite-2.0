"""Datensicherung mit restic (root-Dienst, Aufruf über core/bin/kienzle-sicherung). Konzept: docs/ARCHITEKTUR.md, „Backup“.

- Verschlüsselt auf dem Server (AES-256, Schlüssel aus einem zufälligen Passwort, nur root lesbar). Das Passwort steht
  außerdem auf dem Schlüsselblatt; nie auf dem NAS, nach der Einrichtung nie mehr im Webinterface.
- Ziele: SFTP (empfohlen, eigener SSH-Schlüssel, fester Serverschlüssel), restic-REST-Server (Nur-Anfügen möglich),
  SMB nur über ein Kernel-Mount für die Dauer der Sicherung (SMB 3.1.1, verschlüsselt), lokaler Ordner (z. B. USB-Platte).
- Gesichert werden ein stimmiger Stand der Datenbanken (SQLite per „VACUUM INTO“, Module über backup.snapshot), die
  Datenordner und /etc/kienzle; keine Programme, nicht das Sicherungspasswort selbst.
- Täglich sichern; sonntags aufräumen (forget --prune) und prüfen (check); am ersten Sonntag im Monat zusätzlich einen
  Teil der Daten lesen und die Datenbanken probeweise wiederherstellen (PRAGMA integrity_check).
Übernommen aus kienzlezeit (kienzlezeit-backup-und-restore.sh): geprüfte Wiederherstellung, zeitgesteuerter Ablauf.
"""
from __future__ import annotations

import json
import os
import re
import secrets
import shutil
import sqlite3
import stat
import subprocess
import tempfile
import time
from pathlib import Path

from . import ablage

# runuser liegt in /usr/sbin; der Dienst hat nicht immer sbin im PATH: absoluten Pfad bestimmen.
RUNUSER_BIN = next((c for c in ('/usr/sbin/runuser', '/usr/bin/runuser', '/sbin/runuser') if os.path.exists(c)), 'runuser')

E = os.environ.get
RESTIC = E('KIENZLE_RESTIC', '/usr/bin/restic')
MOUNT = E('KIENZLE_MOUNT', '/usr/bin/mount')
UMOUNT = E('KIENZLE_UMOUNT', '/usr/bin/umount')
ETC = Path(E('KIENZLE_SICHERUNG_ETC', '/etc/kienzle/sicherung'))
STATE = Path(E('KIENZLE_SICHERUNG_STATE', '/var/lib/kienzle/sicherung'))
CACHE = Path(E('KIENZLE_SICHERUNG_CACHE', '/var/cache/kienzle-sicherung'))
REQUEST = Path(E('KIENZLE_SICHERUNG_REQUEST', '/var/lib/kienzle/core/anfragen/sicherung.json'))
KIENZLE_ETC = Path(E('KIENZLE_SICHERUNG_KIENZLE_ETC', '/etc/kienzle'))
DATA = Path(E('KIENZLE_SICHERUNG_DATA', '/var/lib/kienzle'))
MODULES = Path(E('KIENZLE_SICHERUNG_MODULES', '/var/lib/kienzle/core/modules.json'))
TARGETS = Path(E('KIENZLE_SICHERUNG_ZIELE', '/etc/kienzle/verbindungen/konfiguration.json'))
MNT = Path(E('KIENZLE_SICHERUNG_MNT', '/run/kienzle/sicherung'))
TIMER_DROPIN = Path(E('KIENZLE_SICHERUNG_TIMER', '/etc/systemd/system/kienzle-sicherung.timer.d/zeitplan.conf'))
SYSTEMCTL = E('KIENZLE_SYSTEMCTL', '/usr/bin/systemctl')
CA_CERT = Path(E('KIENZLE_SICHERUNG_CA', '/etc/kienzle/ca/ca.crt'))
QRENCODE = E('KIENZLE_QRENCODE', '/usr/bin/qrencode')
FREQ = {'taeglich': 36, 'woechentlich': 8 * 24 + 12, 'monatlich': 32 * 24}      # Stunden bis zur Warnung „zu alt“
DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
TRESOR = E('KIENZLE_TRESOR', str(Path(__file__).resolve().parents[2] / 'bin' / 'kienzle-tresor'))
EXPORT_HOURS = 24
IDENTITY = Path(E('KIENZLE_ABLAGE_SCHLUESSEL', '/var/lib/kienzle/ablage/.ssh/id_ed25519'))
HOST = 'kienzle-suite'
KEEP_DEFAULT = {'daily': 7, 'weekly': 4, 'monthly': 12, 'yearly': 3}
STALE_HOURS = 36
REST = re.compile(r'^https://[A-Za-z0-9.-]{1,253}(?::\d{1,5})?(?:/[A-Za-z0-9._~-]+)*/?$')


class Fehler(Exception):
    pass


def app_uid() -> int:
    if 'KIENZLE_SICHERUNG_UID' in os.environ:
        return int(os.environ['KIENZLE_SICHERUNG_UID'])
    import pwd
    return pwd.getpwnam('ks-core').pw_uid


def _write(path: Path, data, mode: int, group: str | None = None) -> None:
    path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    tmp = path.with_name('.' + path.name + '.neu')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        f.write(data if isinstance(data, str) else json.dumps(data, ensure_ascii=False, indent=1))
    os.chmod(tmp, mode)
    if group and E('KIENZLE_SICHERUNG_CHOWN', '1') == '1':
        shutil.chown(tmp, 'root', group)
    os.replace(tmp, path)


def settings() -> dict:
    try:
        s = json.loads((ETC / 'einstellungen.json').read_text(encoding='utf-8'))
    except FileNotFoundError:
        return {}
    return s if isinstance(s, dict) else {}


def read_status() -> dict:
    try:
        return json.loads((STATE / 'status.json').read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return {}


def set_status(**changes) -> dict:
    s = read_status()
    s.update(changes)
    s['zeit'] = int(time.time())
    s['warnungen'] = warnings(s)
    s['zeitplan'], s['mail_an'] = schedule(), settings().get('mail_an') or []   # für die Anzeige (Einstellungen sind nur root lesbar)
    _write(STATE / 'status.json', s, 0o644)
    return s


def warnings(s: dict) -> list[str]:
    out = []
    if not settings().get('ziel'):
        return ['Die Datensicherung ist noch nicht eingerichtet.']
    last = s.get('letzte_sicherung') or {}
    hours = FREQ[schedule()['haeufigkeit']]
    if not last.get('ok') or last.get('zeit', 0) < time.time() - hours * 3600:
        out.append(f'Die letzte erfolgreiche Sicherung ist älter als {hours} Stunden.' if last.get('ok') else 'Die letzte Sicherung ist fehlgeschlagen oder fehlt.')
    for key, label in (('letzte_pruefung', 'Prüfung'), ('letzte_probe', 'Probe-Wiederherstellung')):
        if s.get(key) and not s[key].get('ok'):
            out.append(f'Die letzte {label} der Sicherung ist fehlgeschlagen.')
    if not settings().get('nas_schnappschuesse'):
        out.append('Schreibgeschützte Schnappschüsse am NAS sind nicht als eingerichtet bestätigt (Schutz vor Erpressungstrojanern).')
    if (STATE / 'blatt.html').exists():
        out.append('Das Schlüsselblatt wurde noch nicht als gedruckt bestätigt.')
    return out


# ---- Ziel und restic ---------------------------------------------------------------------------------------------
def _ablage_target(tid: str, direct: dict | None = None) -> dict:
    if direct is not None:                       # nur bei der Wiederherstellung nach Totalausfall (Installer)
        return ablage.check_target(direct)
    try:
        cfg = json.loads(TARGETS.read_text(encoding='utf-8'))
    except FileNotFoundError:
        cfg = {}
    t = next((x for x in cfg.get('ablage', []) if x.get('id') == tid), None)
    if not t:
        raise Fehler('Das gewählte Ziel gibt es unter Verbindungen nicht mehr.')
    return ablage.check_target(t)


def check_target(ziel: dict) -> dict:
    """Ziel der Sicherung: {"art": "ziel", "id": …, "ordner": …} | {"art": "rest", "url": …, "benutzer", "passwort"} | {"art": "lokal", "pfad": …}."""
    if not isinstance(ziel, dict):
        raise Fehler('Ziel ungültig.')
    art = ziel.get('art')
    if art == 'ziel':
        _ablage_target(str(ziel.get('id')))
        return {'art': 'ziel', 'id': str(ziel['id']), 'ordner': ablage.remote_dir(str(ziel.get('ordner') or 'kienzle-sicherung'))}
    if art == 'rest':
        url = str(ziel.get('url', ''))
        if not REST.match(url):
            raise Fehler('REST-Server: nur https-Adressen, z. B. https://nas.example.org:8000/kienzle')
        user, pw = str(ziel.get('benutzer', '')), str(ziel.get('passwort', ''))
        if (user and not ablage.USER.match(user)) or re.search(r'[\x00-\x1f]', pw) or len(pw) > 200:
            raise Fehler('Zugangsdaten des REST-Servers ungültig.')
        return {'art': 'rest', 'url': url.rstrip('/'), 'benutzer': user, 'passwort': pw}
    if art == 'lokal':
        p = str(ziel.get('pfad', ''))
        if not re.fullmatch(r'/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+', p) or '..' in p.split('/') or p.startswith(('/etc', '/var/lib/kienzle', '/opt', '/proc', '/sys')):
            raise Fehler('Ordner ungültig (absoluter Pfad, z. B. /media/sicherung).')
        return {'art': 'lokal', 'pfad': p}
    raise Fehler('Art des Ziels: Netzwerkspeicher, REST-Server oder lokaler Ordner.')


class Repo:
    """restic-Aufrufe für ein Ziel; bei SMB wird die Freigabe nur für die Dauer des Vorgangs eingehängt."""

    def __init__(self, ziel: dict):
        self.ziel = ziel
        self.work = Path(tempfile.mkdtemp(prefix='kienzle-sicherung-'))
        os.chmod(self.work, 0o700)
        self.mounted = False

    def __enter__(self):
        z = self.ziel
        env = {'PATH': '/usr/bin:/bin:/usr/sbin:/sbin', 'HOME': str(self.work), 'RESTIC_PASSWORD_FILE': str(ETC / 'passwort'), 'RESTIC_CACHE_DIR': str(CACHE)}
        self.extra = []
        if z['art'] == 'lokal':
            env['RESTIC_REPOSITORY'] = z['pfad']
        elif z['art'] == 'rest':
            env['RESTIC_REPOSITORY'] = 'rest:' + z['url']
            if z['benutzer']:
                env['RESTIC_REST_USERNAME'], env['RESTIC_REST_PASSWORD'] = z['benutzer'], z['passwort']
        else:
            t = _ablage_target(z.get('id', ''), z.get('direkt'))
            base = '/'.join(p for p in (t['ordner'], z['ordner']) if p)
            if t['art'] == 'sftp':
                if not t.get('hostkey'):
                    raise Fehler('Schlüssel des SFTP-Servers noch nicht bestätigt (Verwaltung → Verbindungen).')
                known = self.work / 'known_hosts'
                known.write_text(t['hostkey'].replace(t['hostkey'].split(' ', 1)[0], t['host'] if t['port'] == 22 else f'[{t["host"]}]:{t["port"]}', 1) + '\n')
                ssh = (f'ssh -F /dev/null -i {IDENTITY} -p {t["port"]} -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes '
                       f'-o GlobalKnownHostsFile=/dev/null -o UserKnownHostsFile={known} {t["benutzer"]}@{t["host"]} -s sftp')
                self.extra = ['-o', f'sftp.command={ssh}']
                env['RESTIC_REPOSITORY'] = f'sftp:{t["benutzer"]}@{t["host"]}:{base}'
            else:
                MNT.mkdir(mode=0o700, parents=True, exist_ok=True)
                cred = self.work / 'cifs'
                fd = os.open(cred, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
                with os.fdopen(fd, 'w') as f:
                    f.write(f'username={t.get("benutzer", "")}\npassword={t.get("passwort", "")}\n' + (f'domain={t["domaene"]}\n' if t.get('domaene') else ''))
                opts = f'credentials={cred},vers=3.1.1,seal,sec=ntlmssp,nosuid,nodev,noexec,uid=0,gid=0,file_mode=0600,dir_mode=0700'
                if t.get('gast'):
                    opts = 'guest,vers=3.1.1,nosuid,nodev,noexec,uid=0,gid=0,file_mode=0600,dir_mode=0700'
                r = subprocess.run([MOUNT, '-t', 'cifs', f'//{t["host"]}/{t["freigabe"]}', str(MNT), '-o', opts], capture_output=True, text=True, timeout=60)
                if r.returncode != 0:
                    raise Fehler('Freigabe konnte nicht eingehängt werden: ' + (r.stderr.strip() or r.stdout.strip())[:150])
                self.mounted = True
                env['RESTIC_REPOSITORY'] = str(MNT / base) if base else str(MNT)
        self.env = env
        return self

    def __exit__(self, *exc):
        if self.mounted:
            subprocess.run([UMOUNT, str(MNT)], capture_output=True, timeout=60)
        shutil.rmtree(self.work, ignore_errors=True)
        return False

    def run(self, *args: str, timeout: int = 6 * 3600) -> subprocess.CompletedProcess:
        return subprocess.run([RESTIC, *self.extra, *args], env=self.env, capture_output=True, text=True, timeout=timeout)

    def ok(self, *args: str, timeout: int = 6 * 3600) -> str:
        r = self.run(*args, timeout=timeout)
        if r.returncode != 0:
            raise Fehler('restic ' + args[0] + ': ' + _clean((r.stderr or r.stdout).strip().splitlines()[-1] if (r.stderr or r.stdout).strip() else f'Fehler {r.returncode}'))
        return r.stdout


def _clean(text: str) -> str:
    """Meldungen ohne Passwörter oder URLs mit Zugangsdaten."""
    text = re.sub(r'://[^/@\s]+@', '://***@', text)
    return re.sub(r'[\x00-\x08\x0b-\x1f]', '', text)[:300]


# ---- Einrichten --------------------------------------------------------------------------------------------------
def new_password() -> str:
    alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
    raw = ''.join(secrets.choice(alphabet) for _ in range(40))
    return '-'.join(raw[i:i + 5] for i in range(0, 40, 5))


def _fingerprint_cert(path: Path) -> str:
    import hashlib
    import ssl
    try:
        der = ssl.PEM_cert_to_DER_cert(path.read_text(encoding='ascii'))
    except (OSError, ValueError):
        return ''
    d = hashlib.sha256(der).hexdigest().upper()
    return ':'.join(d[i:i + 2] for i in range(0, len(d), 2))


def _qr(text: str) -> str:
    if not Path(QRENCODE).is_file():
        return ''
    r = subprocess.run([QRENCODE, '-t', 'SVG', '-l', 'M', '-m', '1', '-o', '-', text], capture_output=True, text=True, timeout=10)
    return r.stdout if r.returncode == 0 and r.stdout.lstrip().startswith('<?xml') else ''


def key_section(ziel: dict) -> str:
    """Abschnitt „Schlüssel“ des Blatts: SSH-Schlüssel für das NAS (mit QR-Code), Fingerabdrücke von NAS und Zertifizierungsstelle."""
    from html import escape as h
    out = ''
    target = None
    if ziel.get('art') == 'ziel':
        try:
            target = _ablage_target(ziel.get('id', ''), ziel.get('direkt'))
        except (Fehler, ablage.Fehler):
            target = None
    if target and target['art'] == 'sftp' and IDENTITY.is_file():
        private = IDENTITY.read_text(encoding='ascii').strip()
        pub = Path(str(IDENTITY) + '.pub').read_text(encoding='ascii').strip() if Path(str(IDENTITY) + '.pub').is_file() else ''
        out += ('<h2>SSH-Schlüssel für den Netzwerkspeicher</h2><p>Privater Schlüssel des Servers (für die Wiederherstellung auf einer neuen VM: '
                '<code>sudo bash installer/install.sh --wiederherstellen</code> fragt danach). Der QR-Code enthält denselben Text.</p>'
                f'<pre style="font-size:9pt;white-space:pre-wrap;word-break:break-all">{h(private)}</pre>{_qr(private)}'
                + (f'<p>Öffentlicher Teil (steht am NAS beim Benutzer): <code style="font-size:9pt;word-break:break-all">{h(pub)}</code></p>' if pub else ''))
        if target.get('hostkey'):
            out += f'<p>Fingerabdruck des NAS ({h(target["host"])}): <code>{h(ablage.fingerprint(target["hostkey"]))}</code></p>'
    fp = _fingerprint_cert(CA_CERT)
    if fp:
        out += f'<h2>Zertifizierungsstelle der Suite</h2><p>Fingerabdruck (SHA-256): <code style="font-size:10pt;word-break:break-all">{fp}</code></p>'
    return out


def sheet(password: str, ziel: dict, repo_id: str, practice: str) -> str:
    """Schlüsselblatt für den Admin (HTML zum Drucken): Passwort der Sicherung, Ablage, SSH-Schlüssel, Fingerabdrücke.
    Wird nach der Bestätigung „gedruckt“ vom Server gelöscht."""
    from html import escape as h
    where = {'ziel': f'Netzwerkspeicher „{ziel.get("id")}“, Ordner {ziel.get("ordner")}', 'rest': f'REST-Server {ziel.get("url")}',
             'lokal': f'Ordner {ziel.get("pfad")}'}[ziel['art']]
    return ('<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Schlüsselblatt</title>'
            '<style>body{font:12pt/1.5 sans-serif;max-width:42rem;margin:2rem auto}code{font-size:14pt}td{padding:.3rem .6rem;vertical-align:top}</style></head><body>'
            f'<h1>Schlüsselblatt für den Admin – Kienzle-Suite</h1><p>{h(practice)} · erstellt {time.strftime("%d.%m.%Y %H:%M")}</p>'
            '<p><strong>Im Praxistresor aufbewahren.</strong> Ohne dieses Passwort lässt sich die Sicherung nicht lesen – auch nicht vom Hersteller. '
            'Wer dieses Blatt hat, kann die Sicherung lesen.</p>'
            f'<table><tr><td>Passwort</td><td><code>{h(password)}</code></td></tr><tr><td>Ablage</td><td>{h(where)}</td></tr>'
            f'<tr><td>Repository-ID</td><td><code>{h(repo_id)}</code></td></tr><tr><td>Programm</td><td>restic (https://restic.net)</td></tr></table>'
            + key_section(ziel) +
            '<h2>Nach einem Totalausfall</h2><ol><li>Neue VM mit Ubuntu Server 26.04 anlegen, Kienzle-Suite herunterladen.</li>'
            '<li><code>sudo bash installer/install.sh --wiederherstellen</code> und die Angaben von diesem Blatt eingeben.</li></ol>'
            '<h2>Von Hand (ohne Suite)</h2><p>Auf einem Rechner mit restic: <code>restic -r &lt;Ablage&gt; snapshots</code>, Passwort von oben eingeben, '
            'dann <code>restic -r &lt;Ablage&gt; restore latest --target /tmp/wiederhergestellt</code>. Die Datenbanken liegen unter '
            '<code>var/lib/kienzle/sicherung/stand/</code>.</p></body></html>')


def setup(ziel: dict, practice: str) -> str:
    z = check_target(ziel)
    ETC.mkdir(mode=0o700, parents=True, exist_ok=True)
    if (ETC / 'passwort').exists() and settings().get('ziel'):
        raise Fehler('Die Sicherung ist schon eingerichtet. Ein neues Ziel bitte über „Ziel ändern“.')
    password = new_password()
    _write(ETC / 'passwort', password + '\n', 0o600)
    with Repo(z) as repo:
        r = repo.run('init')
        if r.returncode != 0 and 'already' not in (r.stderr + r.stdout).lower():
            raise Fehler('Sicherung konnte nicht angelegt werden: ' + _clean((r.stderr or r.stdout).strip()))
        cfg = json.loads(repo.ok('cat', 'config', '--json'))
    current = settings()
    _write(ETC / 'einstellungen.json', {**current, 'ziel': z, 'aufbewahren': current.get('aufbewahren', KEEP_DEFAULT), 'repository_id': cfg.get('id', '')}, 0o600)
    _write(STATE / 'blatt.html', sheet(password, z, cfg.get('id', ''), practice), 0o640, 'ks-core')
    set_status(eingerichtet=int(time.time()))
    return 'Sicherung eingerichtet. Bitte jetzt das Schlüsselblatt drucken.'


def new_sheet(practice: str) -> str:
    """Schlüsselblatt erneut erzeugen (z. B. nach neuem SSH-Schlüssel oder für eine zweite Ausfertigung)."""
    s = settings()
    if not s.get('ziel') or not (ETC / 'passwort').is_file():
        raise Fehler('Die Sicherung ist noch nicht eingerichtet.')
    password = (ETC / 'passwort').read_text(encoding='utf-8').strip()
    _write(STATE / 'blatt.html', sheet(password, s['ziel'], s.get('repository_id', ''), practice), 0o640, 'ks-core')
    return 'Schlüsselblatt erzeugt. Bitte drucken und danach als gedruckt bestätigen.'


# ---- Zeitplan und Benachrichtigung -------------------------------------------------------------------------------
def schedule() -> dict:
    z = settings().get('zeitplan') or {}
    return {'haeufigkeit': z.get('haeufigkeit') if z.get('haeufigkeit') in FREQ else 'taeglich', 'uhrzeit': z.get('uhrzeit', '02:30'),
            'wochentag': z.get('wochentag', 6), 'tag': z.get('tag', 1)}


def calendar(z: dict) -> str:
    """systemd-OnCalendar für den Zeitplan."""
    if z['haeufigkeit'] == 'woechentlich':
        return f'{DAYS[z["wochentag"]]} *-*-* {z["uhrzeit"]}:00'
    if z['haeufigkeit'] == 'monatlich':
        return f'*-*-{z["tag"]:02d} {z["uhrzeit"]}:00'
    return f'*-*-* {z["uhrzeit"]}:00'


def set_schedule(z: dict) -> str:
    if not isinstance(z, dict) or z.get('haeufigkeit') not in FREQ or not re.fullmatch(r'([01]\d|2[0-3]):[0-5]\d', str(z.get('uhrzeit', ''))) \
            or not isinstance(z.get('wochentag', 6), int) or not 0 <= z.get('wochentag', 6) <= 6 or not isinstance(z.get('tag', 1), int) or not 1 <= z.get('tag', 1) <= 28:
        raise Fehler('Zeitplan ungültig (Uhrzeit HH:MM, Wochentag 0–6, Tag 1–28).')
    clean = {'haeufigkeit': z['haeufigkeit'], 'uhrzeit': z['uhrzeit'], 'wochentag': z.get('wochentag', 6), 'tag': z.get('tag', 1)}
    _write(ETC / 'einstellungen.json', {**settings(), 'zeitplan': clean}, 0o600)
    _write(TIMER_DROPIN, f'# Kienzle-Suite: Zeitplan der Sicherung (Verwaltung → Sicherung)\n[Timer]\nOnCalendar=\nOnCalendar={calendar(clean)}\n', 0o644)
    if Path(SYSTEMCTL).is_file():
        subprocess.run([SYSTEMCTL, 'daemon-reload'], capture_output=True, timeout=60)
        subprocess.run([SYSTEMCTL, 'restart', 'kienzle-sicherung.timer'], capture_output=True, timeout=60)
    label = {'taeglich': 'täglich', 'woechentlich': 'wöchentlich (' + ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][clean['wochentag']] + ')',
             'monatlich': f'monatlich (am {clean["tag"]}.)'}[clean['haeufigkeit']]
    return f'Zeitplan gespeichert: {label} um {clean["uhrzeit"]} Uhr.'


def set_recipients(addrs) -> str:
    from .verbindungen import EMAIL
    if not isinstance(addrs, list) or len(addrs) > 5 or not all(isinstance(a, str) and EMAIL.match(a) for a in addrs):
        raise Fehler('Empfänger: bis zu 5 E-Mail-Adressen.')
    _write(ETC / 'einstellungen.json', {**settings(), 'mail_an': addrs}, 0o600)
    return 'Empfänger gespeichert.' if addrs else 'Keine Benachrichtigung per E-Mail.'


def notify(problems: list[str]) -> None:
    """Fehler der Sicherung an die eingetragenen Empfänger melden (zentrale E-Mail-Verbindung). Fehler beim Versand nur im Status."""
    to = settings().get('mail_an') or []
    if not problems or not to:
        return
    from . import verbindungen
    conf = KIENZLE_ETC / 'core' / 'core.json'
    host = (json.loads(conf.read_text(encoding='utf-8')).get('hostnames') or ['?'])[0] if conf.is_file() else '?'
    body = ('Bei der Datensicherung der Kienzle-Suite ist ein Fehler aufgetreten:\n\n' + '\n'.join('- ' + p for p in problems)
            + f'\n\nDetails: https://{host}/verwaltung/sicherung\n\nDiese Nachricht wurde automatisch verschickt.\n')
    try:
        verbindungen.send_mail(verbindungen.load().get('mail'), to, 'Kienzle-Suite: Fehler bei der Datensicherung', body)
        set_status(letzte_mail={'ok': True, 'zeit': int(time.time())})
    except (verbindungen.Fehler, OSError, ValueError) as e:
        set_status(letzte_mail={'ok': False, 'zeit': int(time.time()), 'meldung': _clean(str(e))})


# ---- Sichern -----------------------------------------------------------------------------------------------------
def _registry() -> list[dict]:
    try:
        return json.loads(MODULES.read_text(encoding='utf-8')).get('modules', [])
    except FileNotFoundError:
        return []


def prepare() -> tuple[list[str], list[str], list[str]]:
    """Stimmige Kopien anlegen. Liefert (Pfade, Ausschlüsse, Hinweise)."""
    stand = STATE / 'stand'
    shutil.rmtree(stand, ignore_errors=True)
    (stand / 'core').mkdir(mode=0o700, parents=True)
    notes = []
    db = DATA / 'core' / 'core.sqlite'
    if db.is_file():
        con = sqlite3.connect(f'file:{db}?mode=ro', uri=True, timeout=30)
        try:
            con.execute('VACUUM INTO ?', (str(stand / 'core' / 'core.sqlite'),))
        finally:
            con.close()
    # Der SSH-Schlüssel des Netzwerkspeichers kommt mit: Nach einem Totalausfall erreicht die neue VM das NAS sofort wieder.
    paths = [str(stand), str(KIENZLE_ETC), str(DATA / 'core')] + ([str(DATA / 'ablage' / '.ssh')] if (DATA / 'ablage' / '.ssh').is_dir() else [])
    # Nie in die Sicherung: das Passwort selbst, das Blatt mit dem Passwort, die laufende Datenbank (dafür der Stand oben).
    excludes = [str(ETC / 'passwort'), str(STATE / 'blatt.html'), str(DATA / 'core' / 'core.sqlite'), str(DATA / 'core' / 'core.sqlite-wal'),
                str(DATA / 'core' / 'core.sqlite-shm'), str(DATA / 'core' / 'anfragen')]
    for m in _registry():
        b = m.get('backup')
        if not b:
            continue
        mid = m['module']['id']
        hook = b.get('snapshot')
        if hook:
            path = Path(f'/opt/kienzle/{mid}/current') / hook
            r = subprocess.run([RUNUSER_BIN, '-u', f'ks-{mid}', '--', str(path)], capture_output=True, text=True, timeout=1800) if path.is_file() else None
            if r is None or r.returncode != 0:
                notes.append(f'{mid}: Schnappschuss-Haken fehlgeschlagen; gesichert wird der aktuelle Stand der Dateien.')
        paths += [p for p in b.get('paths', []) if Path(p).exists()]
    return paths, excludes, notes


def backup() -> dict:
    s = settings()
    if not s.get('ziel'):
        raise Fehler('Die Sicherung ist noch nicht eingerichtet.')
    started = time.time()
    paths, excludes, notes = prepare()
    exfile = Path(tempfile.mkstemp(prefix='kienzle-ausschluss-')[1])
    exfile.write_text('\n'.join(excludes) + '\n')
    try:
        with Repo(s['ziel']) as repo:
            out = repo.ok('backup', '--json', '--host', HOST, '--tag', 'kienzle', '--exclude-file', str(exfile), *paths)
    except (Fehler, OSError, subprocess.SubprocessError) as e:
        set_status(letzte_sicherung={'ok': False, 'zeit': int(time.time()), 'meldung': _clean(str(e)), 'hinweise': notes})
        raise
    finally:
        exfile.unlink(missing_ok=True)
        shutil.rmtree(STATE / 'stand', ignore_errors=True)
    summary = next((json.loads(line) for line in reversed(out.splitlines()) if '"summary"' in line), {})
    result = {'ok': True, 'zeit': int(time.time()), 'dauer': int(time.time() - started), 'snapshot': summary.get('snapshot_id', '')[:16],
              'bytes': summary.get('total_bytes_processed', 0), 'neu': summary.get('data_added', 0), 'hinweise': notes}
    set_status(letzte_sicherung=result)
    return result


def forget() -> str:
    s = settings()
    keep = {**KEEP_DEFAULT, **{k: v for k, v in s.get('aufbewahren', {}).items() if k in KEEP_DEFAULT and isinstance(v, int) and 0 <= v <= 1000}}
    with Repo(s['ziel']) as repo:
        repo.ok('forget', '--host', HOST, '--tag', 'kienzle', '--prune', *[a for k, v in keep.items() for a in (f'--keep-{k}', str(v))])
    set_status(letztes_aufraeumen={'ok': True, 'zeit': int(time.time())})
    return 'Alte Sicherungen nach den Aufbewahrungsregeln entfernt.'


def check(read_subset: str | None = None) -> str:
    s = settings()
    try:
        with Repo(s['ziel']) as repo:
            repo.ok('check', *(['--read-data-subset', read_subset] if read_subset else []))
    except Fehler as e:
        set_status(letzte_pruefung={'ok': False, 'zeit': int(time.time()), 'meldung': _clean(str(e))})
        raise
    set_status(letzte_pruefung={'ok': True, 'zeit': int(time.time()), 'umfang': read_subset or 'Struktur'})
    return 'Sicherung geprüft: in Ordnung.'


def probe() -> str:
    """Datenbanken der letzten Sicherung in einen Testordner zurückholen und auf Unversehrtheit prüfen."""
    s = settings()
    tmp = Path(tempfile.mkdtemp(prefix='kienzle-probe-'))
    try:
        with Repo(s['ziel']) as repo:
            repo.ok('restore', 'latest', '--host', HOST, '--tag', 'kienzle', '--target', str(tmp), '--include', str(STATE / 'stand'))
        dbs = list((tmp / str(STATE / 'stand').lstrip('/')).rglob('*.sqlite'))
        if not dbs:
            raise Fehler('In der letzten Sicherung fehlt der Stand der Datenbanken.')
        for db in dbs:
            con = sqlite3.connect(f'file:{db}?mode=ro', uri=True)
            try:
                result = con.execute('PRAGMA integrity_check').fetchone()[0]
            finally:
                con.close()
            if result != 'ok':
                raise Fehler(f'Datenbank {db.name} in der Sicherung ist beschädigt.')
    except (Fehler, sqlite3.DatabaseError, OSError) as e:
        set_status(letzte_probe={'ok': False, 'zeit': int(time.time()), 'meldung': _clean(str(e))})
        raise Fehler(str(e)) from None
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
    set_status(letzte_probe={'ok': True, 'zeit': int(time.time()), 'datenbanken': len(dbs)})
    return f'Probe-Wiederherstellung: {len(dbs)} Datenbank(en) unversehrt.'


def snapshots() -> list[dict]:
    s = settings()
    with Repo(s['ziel']) as repo:
        data = json.loads(repo.ok('snapshots', '--json', '--host', HOST, '--tag', 'kienzle') or '[]')
    out = [{'id': str(x.get('short_id') or x.get('id', ''))[:16], 'zeit': str(x.get('time', ''))[:19]} for x in data]
    set_status(schnappschuesse=out[-60:])
    return out


def daily(now: float | None = None) -> list[str]:
    """Lauf nach Zeitplan (Timer): sichern; höchstens wöchentlich aufräumen und prüfen; höchstens monatlich
    zusätzlich 5 % der Daten lesen und die Datenbanken probeweise wiederherstellen. Fehler gehen per E-Mail raus."""
    now = now or time.time()
    clean_exports()
    done, problems = [], []
    try:
        backup()
        done.append('gesichert')
    except Fehler as e:
        notify([f'Sicherung fehlgeschlagen: {e}'])
        raise
    st = read_status()
    week = (st.get('letztes_aufraeumen') or {}).get('zeit', 0) < now - 6.5 * 86400
    month = (st.get('letzte_probe') or {}).get('zeit', 0) < now - 27 * 86400
    if week:
        for step, fn in (('aufgeräumt', forget), ('geprüft', lambda: check('5%' if month else None))):
            try:
                fn()
                done.append(step)
            except Fehler as e:
                done.append(step + ' (Fehler)')
                problems.append(f'{step.capitalize()}: {e}')
    if month:
        try:
            probe()
            done.append('Probe')
        except Fehler as e:
            done.append('Probe (Fehler)')
            problems.append(f'Probe-Wiederherstellung: {e}')
    try:
        snapshots()
    except Fehler:
        pass
    notify(problems)
    return done


# ---- Wiederherstellen --------------------------------------------------------------------------------------------
def restore_part(snapshot: str, part: str, stop: list[str], start: list[str]) -> str:
    """Ein Teil (Basis oder Modul) auf den Stand eines Schnappschusses: Dienste anhalten, Daten zurück, starten."""
    if not re.fullmatch(r'[0-9a-f]{8,64}|latest', snapshot):
        raise Fehler('Schnappschuss ungültig.')
    s = settings()
    if part == 'core':
        includes, mapping = [str(STATE / 'stand' / 'core'), str(DATA / 'core')], [(STATE / 'stand' / 'core' / 'core.sqlite', DATA / 'core' / 'core.sqlite')]
    else:
        m = next((x for x in _registry() if x['module']['id'] == part and x.get('backup')), None)
        if not m:
            raise Fehler('Für dieses Modul gibt es keine Sicherung.')
        includes, mapping = list(m['backup']['paths']), []
    tmp = Path(tempfile.mkdtemp(prefix='kienzle-zurueck-'))
    try:
        with Repo(s['ziel']) as repo:
            repo.ok('restore', snapshot, '--host', HOST, '--target', str(tmp), *[a for i in includes for a in ('--include', i)])
        for cmd in stop:
            _quiet(cmd)
        for inc in includes:
            src = tmp / inc.lstrip('/')
            if not src.exists() or inc.startswith(str(STATE)):
                continue
            dest = Path(inc)
            for item in src.iterdir() if src.is_dir() else []:
                target = dest / item.name
                if item.name in ('anfragen', 'core.sqlite-wal', 'core.sqlite-shm'):
                    continue
                if target.is_dir() and not target.is_symlink():
                    shutil.rmtree(target)
                elif target.exists() or target.is_symlink():
                    target.unlink()
                (shutil.copytree if item.is_dir() else shutil.copy2)(item, target)
        for src, dest in mapping:
            restored = tmp / str(src).lstrip('/')
            if restored.is_file():
                for side in ('-wal', '-shm'):
                    Path(str(dest) + side).unlink(missing_ok=True)
                shutil.copy2(restored, dest)
        _fix_owner(part)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
        for cmd in start:
            _quiet(cmd)
    set_status(letzte_wiederherstellung={'ok': True, 'zeit': int(time.time()), 'teil': part, 'snapshot': snapshot[:16]})
    return f'{"Basis" if part == "core" else part}: Daten aus Sicherung {snapshot[:8]} wiederhergestellt.'


def _quiet(cmd: str) -> None:
    try:
        subprocess.run(cmd.split(), capture_output=True, timeout=120)
    except (OSError, subprocess.SubprocessError):
        pass


def _module_units(part: str) -> list[str]:
    m = next((x for x in _registry() if x['module']['id'] == part), None)
    return [u for u in ((m or {}).get('system') or {}).get('services', []) if re.fullmatch(rf'kienzle-{re.escape(part)}[a-z0-9.-]*\.(service|timer|path|socket)', u)]


def _fix_owner(part: str) -> None:
    if E('KIENZLE_SICHERUNG_CHOWN', '1') != '1':
        return
    user = 'ks-core' if part == 'core' else f'ks-{part}'
    base = DATA / part
    for p in [base, *base.rglob('*')]:
        if not p.is_symlink():
            shutil.chown(p, user, user)


def install_ssh_key(text: str) -> None:
    """Privaten SSH-Schlüssel vom Schlüsselblatt als Schlüssel des Netzwerkspeichers (ks-ablage) einsetzen."""
    text = text.strip().replace('\r', '')
    if not re.fullmatch(r'-----BEGIN OPENSSH PRIVATE KEY-----\n[A-Za-z0-9+/=\n]{100,5000}\n-----END OPENSSH PRIVATE KEY-----', text):
        raise Fehler('Das ist kein privater SSH-Schlüssel im OpenSSH-Format.')
    IDENTITY.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    _write(IDENTITY, text + '\n', 0o600)
    if E('KIENZLE_SICHERUNG_CHOWN', '1') == '1':
        shutil.chown(IDENTITY, 'ks-ablage', 'ks-ablage')
    keygen = shutil.which('ssh-keygen')
    if keygen:
        r = subprocess.run([keygen, '-y', '-f', str(IDENTITY)], capture_output=True, text=True, timeout=30)
        if r.returncode != 0:
            raise Fehler('Der SSH-Schlüssel lässt sich nicht lesen (abgeschrieben? Passwort-geschützt?).')
        _write(Path(str(IDENTITY) + '.pub'), r.stdout.strip() + ' ks-ablage\n', 0o644)


def full_restore(ziel: dict, password: str, ssh_key: str | None = None) -> list[str]:
    """Nach einem Totalausfall (installer/install.sh --wiederherstellen): letzte Sicherung vollständig zurückholen.

    ziel: {"art": "lokal", "pfad"} | {"art": "rest", …} | {"art": "ziel", "direkt": <Netzwerkspeicher wie unter Verbindungen>, "ordner"}.
    Danach setzt der Installer Rechte, Dienste und Zugangsdateien der Module neu. Liefert die zurückgeholten Teile.
    """
    if not isinstance(password, str) or not 8 <= len(password.strip()) <= 200:
        raise Fehler('Passwort ungültig.')
    if ziel.get('art') == 'ziel':
        z = {'art': 'ziel', 'id': '', 'direkt': ablage.check_target(ziel.get('direkt')), 'ordner': ablage.remote_dir(str(ziel.get('ordner') or 'kienzle-sicherung'))}
    else:
        z = check_target(ziel)
    if (DATA / 'core' / 'core.sqlite').exists():
        raise Fehler('Auf diesem Server gibt es schon Daten der Suite. Wiederherstellen nur auf einer frischen Installation.')
    ETC.mkdir(mode=0o700, parents=True, exist_ok=True)
    if ssh_key:
        install_ssh_key(ssh_key)
    _write(ETC / 'passwort', password.strip() + '\n', 0o600)
    tmp = Path(tempfile.mkdtemp(prefix='kienzle-komplett-'))
    done = []
    try:
        with Repo(z) as repo:
            repo.ok('snapshots', '--json', timeout=600)
            repo.ok('restore', 'latest', '--host', HOST, '--tag', 'kienzle', '--target', str(tmp))
        stand = tmp / str(STATE / 'stand').lstrip('/')
        if not (stand / 'core' / 'core.sqlite').is_file():
            raise Fehler('In der Sicherung fehlt der Stand der Datenbank der Basis.')
        for src_root, dest_root in ((tmp / str(KIENZLE_ETC).lstrip('/'), KIENZLE_ETC), (tmp / str(DATA).lstrip('/'), DATA)):
            if not src_root.is_dir():
                continue
            for item in sorted(src_root.iterdir()):
                if item.name == 'sicherung' or item.is_symlink():
                    continue
                shutil.copytree(item, dest_root / item.name, dirs_exist_ok=True, symlinks=True) if item.is_dir() else shutil.copy2(item, dest_root / item.name)
                done.append(str(dest_root / item.name))
        for db in stand.rglob('*.sqlite'):
            part = db.relative_to(stand).parts[0]
            dest = DATA / part / db.name
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(db, dest)
        # Einstellungen der Sicherung (mit dem gewählten Ziel) und das eingegebene Passwort gelten weiter.
        old = tmp / str(ETC / 'einstellungen.json').lstrip('/')
        settings_now = json.loads(old.read_text(encoding='utf-8')) if old.is_file() else {}
        if z['art'] != 'ziel':
            settings_now['ziel'] = z
        _write(ETC / 'einstellungen.json', settings_now, 0o600)
        for part in ('core', 'ablage'):
            if (DATA / part).is_dir():
                _fix_owner(part)
    except BaseException:
        (ETC / 'passwort').unlink(missing_ok=True)
        raise
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
    set_status(eingerichtet=int(time.time()), letzte_wiederherstellung={'ok': True, 'zeit': int(time.time()), 'teil': 'alles', 'snapshot': 'latest'})
    return done


# ---- Export der Einstellungen ------------------------------------------------------------------------------------
def _settings_paths(existing: bool = True) -> list[tuple[Path, str]]:
    """(Pfad, Teil): /etc/kienzle der Basis und die [backup] settings der Module."""
    out = [(KIENZLE_ETC, 'core')]
    for m in _registry():
        for p in (m.get('backup') or {}).get('settings', []):
            if Path(p).exists() or not existing:
                out.append((Path(p), m['module']['id']))
    return out


USERS_ARC = 'benutzer/core.sqlite'


def clean_exports(max_age: float = EXPORT_HOURS * 3600) -> None:
    d = STATE / 'export'
    for f in d.glob('*.kse') if d.is_dir() else []:
        if f.stat().st_mtime < time.time() - max_age:
            f.unlink(missing_ok=True)
    s = read_status().get('export')
    if s and not (d / str(s.get('datei', ''))).is_file():
        set_status(export=None)


def export_settings(password: str, with_backup_password: bool = False, with_users: bool = True) -> str:
    """Alle Einstellungen (Basis und Module) als eine mit dem Passwort verschlüsselte Datei (Argon2id + XChaCha20-Poly1305)."""
    import io
    import tarfile
    if not isinstance(password, str) or len(password) < 12 or len(password) > 200 or re.search(r'[\x00-\x1f]', password):
        raise Fehler('Passwort: mindestens 12 Zeichen, keine Steuerzeichen.')
    skip = set() if with_backup_password else {str(ETC / 'passwort')}
    skip.add(str(ETC / 'blatt.html'))
    buf = io.BytesIO()
    listing = []
    with tarfile.open(fileobj=buf, mode='w:gz') as tar:
        for path, part in _settings_paths():
            for f in [path] if path.is_file() else sorted(x for x in path.rglob('*') if x.is_file() and not x.is_symlink()):
                if str(f) in skip or f.name.endswith(('.neu', '.teil')):
                    continue
                tar.add(f, arcname=str(f).lstrip('/'), recursive=False)
                listing.append({'pfad': str(f), 'teil': part})
        db = DATA / 'core' / 'core.sqlite'
        if with_users and db.is_file():
            # Benutzerkonten, Rechte, Passkeys und Einstellungen der Basis: stimmiger Stand der Datenbank.
            snap = Path(tempfile.mkdtemp(prefix='kienzle-export-')) / 'core.sqlite'
            try:
                con = sqlite3.connect(f'file:{db}?mode=ro', uri=True, timeout=30)
                try:
                    con.execute('VACUUM INTO ?', (str(snap),))
                finally:
                    con.close()
                tar.add(snap, arcname=USERS_ARC, recursive=False)
                listing.append({'pfad': str(db), 'teil': 'benutzer'})
            finally:
                shutil.rmtree(snap.parent, ignore_errors=True)
        info = json.dumps({'format': 1, 'zeit': int(time.time()), 'dateien': listing, 'mit_sicherungspasswort': with_backup_password,
                           'mit_benutzern': with_users}, ensure_ascii=False).encode()
        ti = tarfile.TarInfo('kienzle-einstellungen.json')
        ti.size, ti.mtime = len(info), int(time.time())
        tar.addfile(ti, io.BytesIO(info))
    out_dir = STATE / 'export'
    out_dir.mkdir(mode=0o750, parents=True, exist_ok=True)
    if E('KIENZLE_SICHERUNG_CHOWN', '1') == '1':
        shutil.chown(out_dir, 'root', 'ks-core')
    for old in out_dir.glob('*.kse'):
        old.unlink()
    name = time.strftime('kienzle-einstellungen-%Y%m%d-%H%M%S.kse')
    target = out_dir / name
    r = subprocess.run([TRESOR, 'verschluesseln', str(target)], input=password.encode() + b'\n' + buf.getvalue(), capture_output=True, timeout=300)
    if r.returncode != 0:
        target.unlink(missing_ok=True)
        raise Fehler('Verschlüsseln fehlgeschlagen: ' + _clean(r.stderr.decode(errors='replace').strip()))
    os.chmod(target, 0o640)
    if E('KIENZLE_SICHERUNG_CHOWN', '1') == '1':
        shutil.chown(target, 'root', 'ks-core')
    set_status(export={'datei': name, 'zeit': int(time.time()), 'groesse': target.stat().st_size, 'dateien': len(listing), 'mit_sicherungspasswort': with_backup_password})
    return f'Einstellungen exportiert ({len(listing)} Dateien). Die Datei liegt {EXPORT_HOURS} Stunden zum Herunterladen bereit.'


def _restore_users(src) -> None:
    """Datenbank der Basis aus dem Export einsetzen (nach Prüfung). Der Installer startet die Dienste danach neu."""
    tmp = DATA / 'core' / '.core.sqlite.import'
    (DATA / 'core').mkdir(parents=True, exist_ok=True)
    with open(tmp, 'wb') as f:
        shutil.copyfileobj(src, f)
    try:
        con = sqlite3.connect(f'file:{tmp}?mode=ro', uri=True)
        try:
            ok = con.execute('PRAGMA integrity_check').fetchone()[0] == 'ok' and con.execute("SELECT count(*) FROM sqlite_master WHERE name='users'").fetchone()[0] == 1
        finally:
            con.close()
    except sqlite3.DatabaseError:
        ok = False
    if not ok:
        tmp.unlink(missing_ok=True)
        raise Fehler('Die Benutzerdatenbank im Export ist beschädigt.')
    for side in ('-wal', '-shm'):
        (DATA / 'core' / ('core.sqlite' + side)).unlink(missing_ok=True)
    os.chmod(tmp, 0o600)
    if E('KIENZLE_SICHERUNG_CHOWN', '1') == '1':
        shutil.chown(tmp, 'ks-core', 'ks-core')
    os.replace(tmp, DATA / 'core' / 'core.sqlite')


def _exists(fn, name: str) -> bool:
    try:
        fn(name)
        return True
    except KeyError:
        return False


def import_settings(path: Path, password: str, apply: bool, target: Path | None = None) -> list[str]:
    """Export entschlüsseln und prüfen; mit apply=True die Dateien an ihren Platz zurücklegen, sonst nach target auspacken."""
    import tarfile
    tmp = Path(tempfile.mkdtemp(prefix='kienzle-import-'))
    try:
        plain = tmp / 'daten.tar.gz'
        r = subprocess.run([TRESOR, 'entschluesseln', str(path), str(plain)], input=password.encode() + b'\n', capture_output=True, timeout=300)
        if r.returncode != 0:
            raise Fehler(_clean(r.stderr.decode(errors='replace').strip()) or 'Entschlüsseln fehlgeschlagen.')
        # Nur /etc/kienzle und die angemeldeten Einstellungen der hier installierten Module – nichts sonst unter /var/lib.
        allowed = [str(KIENZLE_ETC).lstrip('/')] + [str(p).lstrip('/') for p, part in _settings_paths(existing=False) if part != 'core']
        done = []
        with tarfile.open(plain, 'r:gz') as tar:
            members = tar.getmembers()
            for m in members:
                n = m.name
                if n in ('kienzle-einstellungen.json', USERS_ARC) and m.isfile():
                    continue
                if not m.isfile() or n.startswith('/') or '..' in n.split('/') or not any(n == a or n.startswith(a.rstrip('/') + '/') for a in allowed):
                    raise Fehler(f'Unerwarteter Eintrag in der Datei: {n[:80]}')
            dest_root = Path('/') if apply else target
            if dest_root is None:
                raise Fehler('Zielordner fehlt.')
            for m in members:
                if m.name == 'kienzle-einstellungen.json' and apply:
                    continue
                if m.name == USERS_ARC and apply:
                    _restore_users(tar.extractfile(m))
                    done.append(str(DATA / 'core' / 'core.sqlite'))
                    continue
                dest = (dest_root / m.name)
                dest.parent.mkdir(parents=True, exist_ok=True)
                src = tar.extractfile(m)
                tmpfile = dest.with_name('.' + dest.name + '.neu')
                with open(tmpfile, 'wb') as f:
                    shutil.copyfileobj(src, f)
                os.chmod(tmpfile, m.mode & 0o777)
                if apply and E('KIENZLE_SICHERUNG_CHOWN', '1') == '1':
                    # Nach Namen, nicht nach Nummer: Auf einer neuen VM haben die Benutzer andere Nummern.
                    import grp
                    import pwd
                    user = m.uname if m.uname and _exists(pwd.getpwnam, m.uname) else 'root'
                    group = m.gname if m.gname and _exists(grp.getgrnam, m.gname) else 'root'
                    shutil.chown(tmpfile, user, group)
                os.replace(tmpfile, dest)
                done.append('/' + m.name)
        return done
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


# ---- Anfragen des Webinterfaces ----------------------------------------------------------------------------------
STOP = {'core': ['systemctl stop kienzle-core-php.service']}
START = {'core': ['systemctl start kienzle-core-php.service']}


def handle() -> None:
    uid = app_uid()
    try:
        fd = os.open(REQUEST, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        return
    try:
        info = os.fstat(fd)
        raw = os.read(fd, 16385) if stat.S_ISREG(info.st_mode) and info.st_size <= 16384 and (uid < 0 or info.st_uid == uid) else None
    finally:
        os.close(fd)
        REQUEST.unlink(missing_ok=True)
    try:
        req = json.loads(raw) if raw else None
        if not isinstance(req, dict):
            raise Fehler('Anfrage ungültig.')
        set_status(laeuft=str(req.get('action'))[:20])
        msg = dispatch(req)
        set_status(laeuft='', letzte={'ok': True, 'meldung': msg, 'zeit': int(time.time())})
    except (Fehler, ablage.Fehler, ValueError, TypeError, KeyError, OSError, subprocess.SubprocessError) as e:
        msg = str(e) if isinstance(e, (Fehler, ablage.Fehler)) else 'Vorgang fehlgeschlagen.'
        set_status(laeuft='', letzte={'ok': False, 'meldung': _clean(msg), 'zeit': int(time.time())})


def dispatch(req: dict) -> str:
    a = req.get('action')
    if a == 'einrichten':
        return setup(req.get('ziel'), str(req.get('praxis', ''))[:80])
    if a == 'jetzt':
        r = backup()
        snapshots()
        return f'Gesichert ({r["bytes"] // 1048576} MB geprüft, {r["neu"] // 1048576} MB neu).'
    if a == 'pruefen':
        return check('5%' if req.get('lesen') is True else None)
    if a == 'probe':
        return probe()
    if a == 'aufraeumen':
        return forget()
    if a == 'aufbewahren':
        keep = req.get('aufbewahren')
        if not isinstance(keep, dict) or set(keep) != set(KEEP_DEFAULT) or not all(isinstance(v, int) and 0 <= v <= 1000 for v in keep.values()) or not any(keep.values()):
            raise Fehler('Aufbewahrung ungültig.')
        _write(ETC / 'einstellungen.json', {**settings(), 'aufbewahren': keep}, 0o600)
        return 'Aufbewahrung gespeichert.'
    if a == 'nas-schnappschuesse':
        _write(ETC / 'einstellungen.json', {**settings(), 'nas_schnappschuesse': req.get('bestaetigt') is True}, 0o600)
        return 'Gespeichert.'
    if a == 'zeitplan':
        return set_schedule(req.get('zeitplan'))
    if a == 'mail-an':
        return set_recipients(req.get('an'))
    if a == 'blatt-neu':
        return new_sheet(str(req.get('praxis', ''))[:80])
    if a == 'blatt-gedruckt':
        (STATE / 'blatt.html').unlink(missing_ok=True)
        return 'Schlüsselblatt entfernt. Das Passwort steht nur noch auf dem Ausdruck und auf dem Server (nur root).'
    if a == 'wiederherstellen':
        part = str(req.get('teil', ''))
        if part != 'core' and not re.fullmatch(r'[a-z][a-z0-9-]{1,28}', part):
            raise Fehler('Teil ungültig.')
        units = ' '.join(_module_units(part)) if part != 'core' else ''
        return restore_part(str(req.get('snapshot', '')), part, STOP.get(part, [f'systemctl stop {units}'] if units else []),
                            START.get(part, [f'systemctl start {units}'] if units else []))
    if a == 'export':
        return export_settings(str(req.get('passwort', '')), req.get('mit_sicherungspasswort') is True, req.get('mit_benutzern', True) is not False)
    if a == 'export-loeschen':
        clean_exports(-1)
        return 'Export-Datei gelöscht.'
    if a == 'schnappschuesse':
        return f'{len(snapshots())} Sicherungen vorhanden.'
    raise Fehler('Anfrage ungültig.')
