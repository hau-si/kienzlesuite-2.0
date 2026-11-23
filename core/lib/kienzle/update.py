"""Updater der Kienzle-Suite (root-Dienst, Aufruf über core/bin/kienzle-update). Konzept: docs/ARCHITEKTUR.md, „Updates“.

Ablauf einer Aktualisierung der Basis:
 1. Release-Liste der Quelle lesen (GitHub-Releases von hau-si/kienzlesuite-2.0), nur Kanal stabil bzw. beta.
 2. Manifest, Signatur und Archiv laden; Signatur, Manifest und Archiv prüfen; nur neuere Versionen.
 3. Archiv in einen Arbeitsordner auspacken und jede Datei gegen das Manifest prüfen.
 4. Kein Update, solange ein Modul „beschäftigt“ meldet (hooks.busy).
 5. Dienste anhalten, Daten-Schnappschuss (Datenordner und Einstellungen der Basis).
 6. Installer des neuen Releases ausführen: Versionsordner /opt/kienzle/core/releases/<version>, Verweis „current“.
 7. Prüfung nach dem Start: /_health antwortet mit der neuen Version.
 8. Bei Fehler oder Abbruch (auch Stromausfall: Prüfung beim nächsten Start) Rücksprung auf den vorherigen Stand.
Aus kienzle-suite-beta (scripts/self-update.sh, src/Updater.php) übernommen: Anfragedatei, Status und Protokoll,
nur HTTPS, Größenbegrenzung, Rücksicherung bei Fehler, Sperre bei laufender Kartenzahlung (jetzt hooks.busy).
"""
from __future__ import annotations

import fcntl
import json
import os
import pwd
import re
import shutil
import stat
import subprocess
import time
import urllib.parse
import urllib.request
from pathlib import Path

# runuser liegt in /usr/sbin; der Dienst hat nicht immer sbin im PATH: absoluten Pfad bestimmen.
RUNUSER_BIN = next((c for c in ('/usr/sbin/runuser', '/usr/bin/runuser', '/sbin/runuser') if os.path.exists(c)), 'runuser')

from . import release

E = os.environ.get
STATE = Path(E('KIENZLE_UPDATE_STATE', '/var/lib/kienzle/update'))
REQUEST = Path(E('KIENZLE_UPDATE_REQUEST', '/var/lib/kienzle/core/anfragen/update.json'))
KEYS = Path(E('KIENZLE_UPDATE_KEYS', '/etc/kienzle/release-keys'))
CORE = Path(E('KIENZLE_UPDATE_CORE', '/opt/kienzle/core'))
DATA = Path(E('KIENZLE_UPDATE_DATA', '/var/lib/kienzle/core'))
SETTINGS = Path(E('KIENZLE_UPDATE_SETTINGS', '/etc/kienzle/core'))
MODULES = Path(E('KIENZLE_UPDATE_MODULES', '/var/lib/kienzle/core/modules.json'))
SOURCE = E('KIENZLE_UPDATE_QUELLE', 'github:hau-si/kienzlesuite-2.0')
STOP = E('KIENZLE_UPDATE_STOP', 'systemctl stop kienzle-core-php.service')
START = E('KIENZLE_UPDATE_START', 'systemctl restart kienzle-core-php.service nginx.service')
HEALTH = E('KIENZLE_UPDATE_HEALTH', '')            # leer = /_health über den ersten Servernamen
HEALTH_SECONDS = int(E('KIENZLE_UPDATE_HEALTH_SECONDS', '60'))
KEEP_RELEASES = 3
KEEP_SNAPSHOTS = 5
MAX_LIST = 2 * 1024 * 1024


class Fehler(Exception):
    pass


def app_uid() -> int:
    if 'KIENZLE_UPDATE_UID' in os.environ:
        return int(os.environ['KIENZLE_UPDATE_UID'])
    return pwd.getpwnam('ks-core').pw_uid


# ---- Protokoll und Status ----------------------------------------------------------------------------------------
def _write_json(path: Path, data: dict, mode: int = 0o644) -> None:
    path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    tmp = path.with_name('.' + path.name + '.neu')
    with open(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, mode), 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=1)
    os.chmod(tmp, mode)
    os.replace(tmp, path)


def read_status() -> dict:
    try:
        return json.loads((STATE / 'status.json').read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return {}


def set_status(**changes) -> dict:
    s = read_status()
    s.update(changes)
    s['time'] = int(time.time())
    _write_json(STATE / 'status.json', s)
    return s


def log(line: str) -> None:
    STATE.mkdir(mode=0o755, parents=True, exist_ok=True)
    path = STATE / 'update.log'
    with open(path, 'a', encoding='utf-8') as f:
        f.write(time.strftime('%Y-%m-%d %H:%M:%S ') + re.sub(r'[\x00-\x08\x0b-\x1f\x7f]', '', line)[:500] + '\n')
    os.chmod(path, 0o644)
    if path.stat().st_size > 262144:
        lines = path.read_text(encoding='utf-8', errors='replace').splitlines()[-500:]
        path.write_text('\n'.join(lines) + '\n', encoding='utf-8')


def _settings() -> dict:
    try:
        data = json.loads((STATE / 'einstellungen.json').read_text(encoding='utf-8'))
    except (OSError, ValueError):
        data = {}
    return data if isinstance(data, dict) else {}


def channel() -> str:
    c = _settings().get('kanal')
    return c if c in release.CHANNELS else 'stabil'


SIGNATUR = ('pflicht', 'beta', 'aus')
DEFAULT_SIGNATUR = E('KIENZLE_UPDATE_SIGNATUR_VORGABE', 'aus')     # Signatur vorerst optional; „pflicht“ wählbar


def signature_mode() -> str:
    """Signaturprüfung: aus (Vorgabe, Entscheidung vom 22.11.2026) | beta (unsignierte Vorabversionen im Beta-Kanal) | pflicht (jede Version
    darf unsigniert sein). Eine vorhandene, aber falsche Signatur wird in jeder Stufe abgelehnt."""
    s = _settings()
    mode = s.get('signatur') if s.get('signatur') in SIGNATUR else ('beta' if s.get('unsigniert') is True else DEFAULT_SIGNATUR)
    return 'pflicht' if mode == 'beta' and channel() != 'beta' else mode


def allow_unsigned(prerelease: bool = True) -> bool:
    mode = signature_mode()
    return mode == 'aus' or (mode == 'beta' and prerelease)


# ---- Quellen -----------------------------------------------------------------------------------------------------
class _HttpsOnly(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if urllib.parse.urlparse(newurl).scheme != 'https':
            raise Fehler('Weiterleitung auf eine unverschlüsselte Adresse abgelehnt.')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class GitHub:
    TAG = re.compile(r'^(core|[a-z][a-z0-9-]{1,28})-v(\d[0-9A-Za-z.-]*)$')

    def __init__(self, repo: str):
        if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repo):
            raise Fehler('Ungültige Update-Quelle.')
        self.repo = repo
        self.opener = urllib.request.build_opener(_HttpsOnly())

    def _get(self, url: str, limit: int, accept: str) -> bytes:
        if not url.startswith('https://'):
            raise Fehler('Nur HTTPS-Adressen.')
        req = urllib.request.Request(url, headers={'User-Agent': 'kienzle-suite-update', 'Accept': accept})
        try:
            with self.opener.open(req, timeout=60) as r:
                data = r.read(limit + 1)
        except OSError as e:
            raise Fehler(f'Download fehlgeschlagen: {str(e)[:120]}') from None
        if len(data) > limit:
            raise Fehler('Download zu groß.')
        return data

    def releases(self) -> list[dict]:
        raw = self._get(f'https://api.github.com/repos/{self.repo}/releases?per_page=100', MAX_LIST, 'application/vnd.github+json')
        out = []
        for r in json.loads(raw):
            m = self.TAG.match(str(r.get('tag_name', '')))
            if not m or r.get('draft'):
                continue
            assets = {a['name']: a['browser_download_url'] for a in r.get('assets', []) if isinstance(a, dict) and 'name' in a}
            out.append({'part': m.group(1), 'version': m.group(2), 'prerelease': bool(r.get('prerelease')),
                        'notes': str(r.get('body') or '')[:4000], 'published': str(r.get('published_at') or '')[:20], 'assets': assets})
        return out

    def fetch(self, url: str, limit: int) -> bytes:
        return self._get(url, limit, 'application/octet-stream')


class Folder:
    """Quelle für Tests: Ordner mit releases.json (Felder wie oben, assets: Name → Dateiname) und den Dateien."""

    def __init__(self, path: str):
        self.path = Path(path)

    def releases(self) -> list[dict]:
        return json.loads((self.path / 'releases.json').read_text(encoding='utf-8'))

    def fetch(self, url: str, limit: int) -> bytes:
        p = self.path / Path(url).name
        if p.stat().st_size > limit:
            raise Fehler('Download zu groß.')
        return p.read_bytes()


def source():
    kind, _, value = E('KIENZLE_UPDATE_QUELLE', SOURCE).partition(':')
    if kind == 'github':
        return GitHub(value)
    if kind == 'ordner':
        return Folder(value)
    raise Fehler('Unbekannte Update-Quelle.')


# ---- Zustand der Installation ------------------------------------------------------------------------------------
def installed(part: str = 'core') -> str:
    return (CORE / 'current' / 'VERSION').read_text(encoding='utf-8').strip()


def local_releases() -> list[str]:
    out = []
    for p in (CORE / 'releases').iterdir() if (CORE / 'releases').is_dir() else []:
        if p.is_dir() and not p.is_symlink() and release.VERSION.match(p.name):
            out.append(p.name)
    return sorted(out, key=release.parse_version)


def candidates(listing: list[dict], part: str, current: str, chan: str) -> list[dict]:
    """Neuere Releases dieses Teils im gewählten Kanal (beta sieht auch stabile), neueste zuerst."""
    out = []
    for r in listing:
        try:
            if r['part'] != part or not release.newer(r['version'], current):
                continue
        except release.Fehler:
            continue
        if r['prerelease'] and chan != 'beta':
            continue
        out.append(r)
    return sorted(out, key=lambda r: release.parse_version(r['version']), reverse=True)


def check() -> dict:
    """Nach neuen Versionen sehen und das Ergebnis für das Webinterface ablegen."""
    current = installed()
    try:
        listing = source().releases()
        found = candidates(listing, 'core', current, channel())
        error = None
    except (Fehler, OSError, ValueError, KeyError, TypeError) as e:
        found, error = [], str(e)[:200]
    sig = release.names('core', '0')['signature'].removeprefix('core-0')

    def entry(r):
        return {**{k: r[k] for k in ('version', 'prerelease', 'notes', 'published')}, 'signed': any(a.endswith(sig) for a in r['assets'])}
    # Ohne Signatur nur anzeigen, was sich auch installieren ließe.
    shown = [r for r in found if entry(r)['signed'] or allow_unsigned(r['prerelease'])]
    return set_status(installed={'core': current}, channel=channel(), unsigned=signature_mode() != 'pflicht', signatur=signature_mode(), checked_at=int(time.time()), error=error,
                      local=local_releases(), available={'core': [entry(r) for r in shown[:10]]})


# ---- Laden und prüfen (Basis und Module) -------------------------------------------------------------------------
def fetch_release(part: str, version: str, current: str | None, work: Path) -> bool:
    """Release `part`-`version` aus der Quelle laden, Signatur (soweit vorhanden bzw. verlangt), Manifest und jede Datei
    prüfen und nach `work` auspacken. current: installierte Version (nur neuere erlaubt) oder None (Erstinstallation).
    Liefert, ob das Release signiert war."""
    listing = source().releases()
    entry = next((r for r in candidates(listing, part, current or '0.0.0-0', channel()) if r['version'] == version), None)
    if not entry:
        raise Fehler(f'Version {version} ist im Kanal „{channel()}“ nicht verfügbar.')
    n = release.names(part, version)
    if n['archive'] not in entry['assets'] or n['manifest'] not in entry['assets']:
        raise Fehler('Release unvollständig (Archiv oder Manifest fehlt).')
    s = source()
    manifest_raw = s.fetch(entry['assets'][n['manifest']], 1024 * 1024)
    if n['signature'] in entry['assets']:
        # Vorhandene Signatur muss immer stimmen – auch wenn unsignierte Versionen zugelassen sind.
        release.verify_signature(manifest_raw, s.fetch(entry['assets'][n['signature']], 1024), release.trusted_keys(KEYS))
        signed = True
    elif allow_unsigned(entry['prerelease']):
        signed = False
    else:
        raise Fehler('Release ist nicht signiert. Unsignierte Versionen nur nach ausdrücklicher Freigabe (Verwaltung → Updates → Signaturprüfung).')
    manifest = release.parse_manifest(manifest_raw, part)
    if manifest['version'] != version or (manifest['channel'] == 'beta' and channel() != 'beta'):
        raise Fehler('Manifest passt nicht zur angeforderten Version bzw. zum Kanal.')
    if not signed and manifest['channel'] != 'beta' and signature_mode() != 'aus':
        raise Fehler('Unsigniert sind nur Beta-Versionen erlaubt.')
    archive = s.fetch(entry['assets'][n['archive']], release.MAX_ARCHIVE)
    release.check_archive(archive, manifest)
    release.extract(archive, manifest, work)
    return signed


# ---- Aktualisieren -----------------------------------------------------------------------------------------------
def _run(cmd: str | list, timeout: int = 1800) -> subprocess.CompletedProcess:
    args = cmd.split() if isinstance(cmd, str) else cmd
    r = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
    for line in (r.stdout + r.stderr).splitlines()[-40:]:
        log('  ' + line)
    return r


def busy_modules() -> list[str]:
    """Module, deren hooks.busy „beschäftigt“ (75) meldet."""
    try:
        data = json.loads(MODULES.read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return []
    out = []
    for m in data.get('modules', []):
        hook = (m.get('hooks') or {}).get('busy')
        mid = m['module']['id']
        path = Path(f'/opt/kienzle/{mid}/current') / hook if hook else None
        if path and path.is_file():
            r = subprocess.run([RUNUSER_BIN, '-u', f'ks-{mid}', '--', str(path)], capture_output=True, timeout=30)
            if r.returncode == 75:
                out.append(mid)
    return out


def snapshot(version: str) -> Path:
    target = STATE / 'schnappschuesse' / 'core' / f'{version}-{time.time_ns()}'
    target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    shutil.copytree(DATA, target / 'daten', symlinks=True, ignore=shutil.ignore_patterns('anfragen'))
    shutil.copytree(SETTINGS, target / 'einstellungen', symlinks=True)
    os.chmod(target, 0o700)
    snaps = sorted((STATE / 'schnappschuesse' / 'core').iterdir(), key=lambda p: int(p.name.rsplit('-', 1)[1]))
    for old in snaps[:-KEEP_SNAPSHOTS]:
        shutil.rmtree(old, ignore_errors=True)
    return target


def latest_snapshot(version: str) -> Path | None:
    """Neuester Schnappschuss, der beim Wechsel weg von `version` entstand (Name „<version>-<zeit>“)."""
    folder = STATE / 'schnappschuesse' / 'core'
    found = [p for p in folder.iterdir() if p.is_dir() and p.name.rsplit('-', 1)[0] == version] if folder.is_dir() else []
    return max(found, key=lambda p: int(p.name.rsplit('-', 1)[1]), default=None)


def restore(snap: Path) -> None:
    for name, dest in (('daten', DATA), ('einstellungen', SETTINGS)):
        src = snap / name
        if not src.is_dir():
            continue
        for item in dest.iterdir():
            if item.name == 'anfragen':
                continue
            shutil.rmtree(item) if item.is_dir() and not item.is_symlink() else item.unlink()
        for item in src.iterdir():
            (shutil.copytree if item.is_dir() and not item.is_symlink() else shutil.copy2)(item, dest / item.name, **({'symlinks': True} if item.is_dir() else {}))


def healthy(version: str) -> bool:
    if (CORE / 'current' / 'VERSION').read_text(encoding='utf-8').strip() != version:
        return False
    deadline = time.time() + HEALTH_SECONDS
    while True:
        if HEALTH:
            r = subprocess.run(HEALTH.split(), capture_output=True, text=True, timeout=30)
            ok = r.returncode == 0 and f'"version":"{version}"' in r.stdout.replace(' ', '')
        else:
            host = json.loads((SETTINGS / 'core.json').read_text(encoding='utf-8'))['hostnames'][0]
            r = subprocess.run(['curl', '-fsS', '--noproxy', '*', '--max-time', '5', '--cacert', '/etc/kienzle/ca/ca.crt', '--connect-to', f'{host}:443:127.0.0.1:443',
                                f'https://{host}/_health'], capture_output=True, text=True, timeout=30)
            ok = r.returncode == 0 and f'"version":"{version}"' in r.stdout.replace(' ', '')
        if ok:
            return True
        if time.time() > deadline:
            return False
        time.sleep(2)


def _switch_back(previous: str) -> None:
    """Vorherige Version wieder einsetzen: deren Installer, falls vorhanden (ab 0.4.0), sonst nur den Verweis."""
    old = CORE / 'releases' / previous
    if (old / 'installer' / 'install.sh').is_file():
        _run(['bash', str(old / 'installer' / 'install.sh'), '--aktualisieren'])
    else:
        tmp = CORE / '.current.zurueck'
        if tmp.is_symlink() or tmp.exists():
            tmp.unlink()
        os.symlink(f'releases/{previous}', tmp)
        os.replace(tmp, CORE / 'current')
        _run(START)


def rollback(previous: str, snap: str | None, reason: str) -> None:
    log(f'Rücksprung auf {previous}: {reason}')
    _run(STOP)
    if snap and Path(snap).is_dir():
        restore(Path(snap))
        log('Daten aus dem Schnappschuss wiederhergestellt.')
    _switch_back(previous)
    ok = healthy(previous)
    set_status(state='failed', step='', message=f'{reason} Vorherige Version {previous} wiederhergestellt' + ('.' if ok else ' – sie antwortet aber nicht, bitte prüfen.'))


def cleanup(current: str, previous: str) -> None:
    keep = set(local_releases()[-KEEP_RELEASES:]) | {current, previous}
    for v in local_releases():
        if v not in keep:
            shutil.rmtree(CORE / 'releases' / v, ignore_errors=True)
            log(f'Alte Version {v} entfernt.')


def pre_backup() -> None:
    """Vor dem Update eine restic-Sicherung, falls eingerichtet (ARCHITEKTUR.md, Updates Punkt 4). Ein Fehler hält das
    Update nicht auf – der lokale Daten-Schnappschuss danach ist der Rückweg –, steht aber im Protokoll."""
    cmd = E('KIENZLE_UPDATE_SICHERUNG', '/opt/kienzle/core/current/bin/kienzle-sicherung')
    if not cmd or not Path(cmd).is_file() or not Path(E('KIENZLE_SICHERUNG_ETC', '/etc/kienzle/sicherung'), 'einstellungen.json').is_file():
        return
    try:
        r = subprocess.run([cmd, 'jetzt'], capture_output=True, text=True, timeout=6 * 3600)
        log('Sicherung vor dem Update: ' + ('erfolgreich.' if r.returncode == 0 else 'fehlgeschlagen – ' + (r.stderr.strip() or r.stdout.strip())[-200:]))
    except (OSError, subprocess.SubprocessError) as e:
        log(f'Sicherung vor dem Update fehlgeschlagen: {e}')


def install(version: str, rollback_to_local: bool = False) -> None:
    """Basis auf `version` bringen. rollback_to_local: auf eine hier aufbewahrte ältere Version zurück (von Hand)."""
    current = installed()
    work = STATE / 'arbeit' / f'core-{version}'
    shutil.rmtree(work, ignore_errors=True)
    set_status(state='running', step='laden', from_version=current, to_version=version, snapshot=None, message=f'Aktualisierung auf {version} läuft.')
    log(f'Aktualisierung {current} → {version} gestartet.')
    try:
        if rollback_to_local:
            if version not in local_releases() or not release.newer(current, version):
                raise Fehler('Diese Version ist hier nicht aufbewahrt oder nicht älter als die installierte.')
            src = CORE / 'releases' / version
            if not (src / 'installer' / 'install.sh').is_file():
                raise Fehler('Diese Version lässt sich nicht von Hand einsetzen (zu alt).')
            target_snap = latest_snapshot(version)
            if target_snap is None:
                raise Fehler(f'Für {version} gibt es keinen Daten-Schnappschuss; ein Rücksprung würde die Datenbank beschädigen.')
        else:
            if not release.newer(version, current):
                raise Fehler(f'{version} ist nicht neuer als die installierte Version {current}.')
            signed = fetch_release('core', version, current, work)
            src = work
            log('Signatur, Manifest und alle Dateien geprüft.' if signed else
                f'OHNE Signatur (vom Admin zugelassen: {"alle Versionen" if signature_mode() == "aus" else "Beta-Versionen"}); Manifest und alle Dateien geprüft.')
        busy = busy_modules()
        if busy:
            raise Fehler('Gerade beschäftigt: ' + ', '.join(busy) + '. Bitte später erneut versuchen.')
    except (Fehler, release.Fehler, OSError, ValueError, KeyError) as e:
        shutil.rmtree(work, ignore_errors=True)
        log(f'Abgebrochen: {e}')
        set_status(state='failed', step='', message=f'Nicht aktualisiert: {e}')
        return

    set_status(step='sichern')
    pre_backup()
    _run(STOP)
    snap = snapshot(current)
    set_status(step='installieren', snapshot=str(snap))
    log(f'Schnappschuss {snap.name} angelegt, starte Installer.')
    if rollback_to_local:
        restore(target_snap)
        log(f'Daten von {version} aus Schnappschuss {target_snap.name} eingesetzt (spätere Änderungen gehen verloren).')
    r = _run(['bash', str(src / 'installer' / 'install.sh'), '--aktualisieren'])
    if r.returncode != 0:
        rollback(current, str(snap), f'Installer von {version} fehlgeschlagen.')
        shutil.rmtree(work, ignore_errors=True)
        return
    set_status(step='pruefen-nach-start')
    if not healthy(version):
        rollback(current, str(snap), f'Version {version} antwortet nach dem Start nicht.')
        shutil.rmtree(work, ignore_errors=True)
        return
    shutil.rmtree(work, ignore_errors=True)
    cleanup(version, current)
    log(f'Aktualisierung auf {version} abgeschlossen.')
    set_status(state='done', step='', installed={'core': version}, local=local_releases(), message=f'Aktualisiert auf {version}.',
               available={'core': [a for a in read_status().get('available', {}).get('core', []) if release.newer(a['version'], version)]})


def recover() -> None:
    """Beim Start: War eine Aktualisierung mitten im Lauf (Absturz, Stromausfall), auf den vorherigen Stand zurück."""
    s = read_status()
    if s.get('state') != 'running':
        return
    previous, snap = s.get('from_version'), s.get('snapshot')
    if not previous or not release.VERSION.match(str(previous)):
        set_status(state='failed', message='Abgebrochene Aktualisierung ohne bekannten Ausgangsstand.')
        return
    if s.get('step') in ('laden', 'pruefen'):     # noch nichts verändert
        set_status(state='failed', step='', message='Aktualisierung wurde unterbrochen; es wurde nichts verändert.')
        return
    rollback(previous, snap, 'Aktualisierung wurde unterbrochen (Neustart).')


# ---- Anfragen des Webinterfaces ----------------------------------------------------------------------------------
def handle() -> None:
    uid = app_uid()
    try:
        fd = os.open(REQUEST, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        return
    try:
        info = os.fstat(fd)
        raw = os.read(fd, 4097) if stat.S_ISREG(info.st_mode) and info.st_size <= 4096 and (uid < 0 or info.st_uid == uid) else None
    finally:
        os.close(fd)
        try:
            os.unlink(REQUEST)
        except OSError:
            pass
    try:
        req = json.loads(raw) if raw else None
    except ValueError:
        req = None
    if not isinstance(req, dict):
        set_status(state='failed', message='Anfrage ungültig.')
        return
    STATE.mkdir(mode=0o755, parents=True, exist_ok=True)
    with open(STATE / '.sperre', 'w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)   # nie zwei Aktualisierungen gleichzeitig
        except OSError:
            set_status(message='Es läuft bereits ein Vorgang des Updaters.')
            return
        _dispatch(req)


def _dispatch(req: dict) -> None:
    action = req.get('action')
    if action == 'pruefen':
        check()
    elif action == 'kanal' and req.get('kanal') in release.CHANNELS and isinstance(req.get('unsigniert', False), bool) and req.get('signatur', 'aus') in SIGNATUR:
        mode = req.get('signatur') if 'signatur' in req else ('beta' if req.get('unsigniert') is True else signature_mode())
        if mode == 'beta' and req['kanal'] != 'beta':
            mode = 'pflicht'
        _write_json(STATE / 'einstellungen.json', {'kanal': req['kanal'], 'signatur': mode})
        log(f'Kanal {req["kanal"]}, Signaturprüfung ' + {'pflicht': 'Pflicht', 'beta': 'für Beta-Versionen abgeschaltet', 'aus': 'ABGESCHALTET'}[mode])
        check()
    elif action in ('installieren', 'zurueck') and isinstance(req.get('version'), str) and release.VERSION.match(req['version']):
        install(req['version'], rollback_to_local=action == 'zurueck')
    else:
        set_status(state='failed', message='Anfrage ungültig.')
