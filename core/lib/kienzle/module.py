"""Modulverwaltung der Basis (root-Dienst, Aufruf über core/bin/kienzle-module). Konzept: docs/ARCHITEKTUR.md, „Module“.

Quellen eines Moduls:
- mitgeliefert: im Release der Basis unter /opt/kienzle/core/current/modules/<id>/ (ohne das Beispielmodul),
- GitHub: Releases „<id>-v<version>“ derselben Quelle wie die Updates der Basis (Signatur wie dort, siehe update.py).

Installieren bzw. Aktualisieren:
 1. Modulordner holen (Kopie bzw. Download mit Prüfung jeder Datei), module.toml prüfen, Schnittstelle (core_api) prüfen.
 2. Beim Aktualisieren: kein Vorgang, solange hooks.busy „beschäftigt“ meldet; Daten-Schnappschuss des Datenordners.
 3. Ubuntu-Pakete aus [system] packages installieren, Systembenutzer ks-<id>, Datenordner (0700) und Socket-Ordner anlegen.
 4. Programm nach /opt/kienzle/<id>/releases/<version>/<id>/ (nur root beschreibbar), Verweis „current“ atomar umsetzen.
 5. FastCGI-Module: eigene php-fpm-Instanz als ks-<id> (Dienst kienzle-<id>-php.service, gehärtet wie die Basis).
    Eigene Dienste des Moduls aus <modul>/systemd/<name> (nur mit User=ks-<id>).
 6. Modulverzeichnis, nginx, AppArmor, Verbindungen und virtuelle Drucker neu schreiben (kienzle-web module).
 7. Haken install bzw. update als ks-<id>, Dienste starten, hooks.health binnen 60 Sekunden.
 8. Bei Fehler: alter Verweis und Daten-Schnappschuss zurück (Aktualisieren) bzw. Modul wieder entfernen (Erstinstallation).
Entfernen: hooks.remove, Dienste und Einheiten entfernen, Programm löschen; Daten und Benutzer nur auf ausdrücklichen Wunsch.
Haken laufen nie als root.
"""
from __future__ import annotations

import fcntl
import glob
import json
import os
import pwd
import re
import shutil
import stat
import subprocess
import time
from pathlib import Path

from . import manifest, release, update

E = os.environ.get
STATE = Path(E('KIENZLE_MODULE_STATE', '/var/lib/kienzle/module'))
REQUEST = Path(E('KIENZLE_MODULE_REQUEST', '/var/lib/kienzle/core/anfragen/module.json'))
PROGRAMS = Path(E('KIENZLE_MODULE_PROGRAMME', '/opt/kienzle'))
BUNDLED = Path(E('KIENZLE_MODULE_MITGELIEFERT', '/opt/kienzle/core/current/modules'))
DATA = Path(E('KIENZLE_MODULE_DATEN', '/var/lib/kienzle'))
ETC = Path(E('KIENZLE_MODULE_ETC', '/etc/kienzle/module'))
UNITS = Path(E('KIENZLE_MODULE_UNITS', '/etc/systemd/system'))
TMPFILES = Path(E('KIENZLE_MODULE_TMPFILES', '/etc/tmpfiles.d'))
RUN = Path(E('KIENZLE_MODULE_RUN', '/run/kienzle'))
SPOOL = Path(E('KIENZLE_MODULE_SPOOL', '/var/spool/kienzle/ablage'))
WEB = E('KIENZLE_MODULE_WEB', str(Path(__file__).resolve().parents[2] / 'bin' / 'kienzle-web'))
SYSTEMCTL = E('KIENZLE_MODULE_SYSTEMCTL', '/usr/bin/systemctl')
APT = E('KIENZLE_MODULE_APT', '/usr/bin/apt-get')
RUNUSER = E('KIENZLE_MODULE_RUNUSER', '/usr/sbin/runuser')
PHPFPM = E('KIENZLE_MODULE_PHPFPM', '')
OWNER = E('KIENZLE_MODULE_OWNER', '')          # nur Tests: Eigentümer statt ks-<id> (ohne root)
HEALTH_SECONDS = int(E('KIENZLE_MODULE_HEALTH_SECONDS', '60'))
SYSTEMD_RUN = Path(E('KIENZLE_SYSTEMD_RUN', '/run/systemd/system'))
KEEP_RELEASES = 3
KEEP_SNAPSHOTS = 3
EXCLUDED = frozenset({'beispiel'})            # Beispielmodul nur für Tests, nie installierbar
ID = re.compile(r'^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$')
UNIT_NAME = re.compile(r'^kienzle-[a-z0-9-]+\.(service|timer|path|socket)$')


class Fehler(Exception):
    pass


def without_systemd() -> bool:
    return E('KIENZLE_OHNE_SYSTEMD') == '1' or not SYSTEMD_RUN.is_dir()


# ---- Protokoll und Status ----------------------------------------------------------------------------------------
def read_status() -> dict:
    try:
        data = json.loads((STATE / 'status.json').read_text(encoding='utf-8'))
        return data if isinstance(data, dict) else {}
    except (OSError, ValueError):
        return {}


def set_status(**changes) -> dict:
    s = read_status()
    s.update(changes)
    s['time'] = int(time.time())
    update._write_json(STATE / 'status.json', s)
    return s


def auto_enabled() -> bool:
    return read_status().get('auto') is not False


def auto_update() -> list[str]:
    """Module mit neuerer Version selbst aktualisieren (täglich nach der Prüfung, außer abgeschaltet).
    Ein beschäftigtes Modul (Haken busy) wird übersprungen und am nächsten Tag erneut versucht."""
    if not auto_enabled():
        return []
    done: list[str] = []
    STATE.mkdir(mode=0o755, parents=True, exist_ok=True)
    with open(STATE / '.sperre', 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        for row in read_status().get('installed', []):
            up = row.get('update') if isinstance(row, dict) else None
            if not up:
                continue
            mid = row['id']
            try:
                msg = install(mid, up['version'])
                log(f'{mid}: automatisch aktualisiert.')
                set_status(running=None, last={'ok': True, 'message': 'Automatisch: ' + msg[:380], 'time': int(time.time())})
                done.append(mid)
            except (Fehler, update.Fehler, release.Fehler, OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError) as e:
                msg = str(e) if isinstance(e, (Fehler, update.Fehler, release.Fehler)) else f'Vorgang fehlgeschlagen ({type(e).__name__}).'
                log(f'{mid}: automatische Aktualisierung abgebrochen: {msg}')
                set_status(running=None, last={'ok': False, 'message': f'Automatisch, {mid}: {msg}'[:400], 'time': int(time.time())})
        if done:
            check()
    return done


def log(line: str) -> None:
    STATE.mkdir(mode=0o755, parents=True, exist_ok=True)
    path = STATE / 'module.log'
    with open(path, 'a', encoding='utf-8') as f:
        f.write(time.strftime('%Y-%m-%d %H:%M:%S ') + re.sub(r'[\x00-\x08\x0b-\x1f\x7f]', '', line)[:500] + '\n')
    os.chmod(path, 0o644)
    if path.stat().st_size > 262144:
        lines = path.read_text(encoding='utf-8', errors='replace').splitlines()[-500:]
        path.write_text('\n'.join(lines) + '\n', encoding='utf-8')


def _run(cmd: list[str], timeout: int = 600, env: dict | None = None, cwd: str | None = None) -> subprocess.CompletedProcess:
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, env=env, cwd=cwd)
    except (OSError, subprocess.SubprocessError) as e:
        return subprocess.CompletedProcess(cmd, 127, '', str(e)[:300])


def _systemctl(*args: str) -> subprocess.CompletedProcess | None:
    if without_systemd():
        return None
    return _run([SYSTEMCTL, *args], timeout=120)


# ---- Bestand und Angebot -----------------------------------------------------------------------------------------
def installed() -> dict[str, dict]:
    """Installierte Module: id → geprüfte Beschreibung (aus /opt/kienzle/<id>/current)."""
    out = {}
    for folder in manifest.installed_folders(PROGRAMS):
        try:
            m = manifest.check_folder(folder)
        except manifest.Invalid:
            continue
        out[m['module']['id']] = m
    return out


def bundled() -> dict[str, dict]:
    """Mit der Basis mitgelieferte Module: id → geprüfte Beschreibung."""
    out = {}
    if not BUNDLED.is_dir():
        return out
    for folder in sorted(BUNDLED.iterdir()):
        if folder.name in EXCLUDED or folder.is_symlink() or not folder.is_dir() or not (folder / 'module.toml').is_file():
            continue
        try:
            m = manifest.check_folder(folder)
        except manifest.Invalid:
            continue
        out[m['module']['id']] = m
    return out


def _summary(m: dict) -> dict:
    return {'id': m['module']['id'], 'name': m['module']['name'], 'version': m['module']['version'], 'description': m['module'].get('description') or '',
            'path': (m.get('web') or {}).get('path'), 'core_api': m['module']['core_api']}


def check() -> dict:
    """Angebot ermitteln (mitgeliefert und GitHub) und mit dem Bestand für das Webinterface ablegen."""
    have = installed()
    offer: dict[str, dict] = {}
    for mid, m in bundled().items():
        offer[mid] = {**_summary(m), 'source': 'mitgeliefert', 'signed': None}
    error = None
    try:
        sig = '.manifest.json.sig'
        for r in update.source().releases():
            mid = r['part']
            if mid == 'core' or mid in EXCLUDED or not ID.match(mid) or (r['prerelease'] and update.channel() != 'beta'):
                continue
            signed = any(a.endswith(sig) for a in r['assets'])
            if not signed and not update.allow_unsigned(r['prerelease']):
                continue
            known = offer.get(mid)
            try:
                if known and not release.newer(r['version'], known['version']):
                    continue
            except release.Fehler:
                continue
            base = known or {'id': mid, 'name': (have.get(mid) or {}).get('module', {}).get('name', mid), 'description': '', 'path': None, 'core_api': None}
            offer[mid] = {**base, 'version': r['version'], 'source': 'github', 'signed': signed}
    except (update.Fehler, OSError, ValueError, KeyError, TypeError) as e:
        error = str(e)[:200]
    rows = []
    for mid, m in sorted(have.items()):
        o = offer.get(mid)
        newer = o if o and release.newer(o['version'], m['module']['version']) else None
        rows.append({**_summary(m), 'update': {'version': newer['version'], 'source': newer['source'], 'signed': newer['signed']} if newer else None,
                     'releases': local_releases(mid)})
    available = [o for mid, o in sorted(offer.items()) if mid not in have]
    try:
        ensure_running()
        states = service_states()
        for r in rows:
            r['dienste'] = states.get(r['id'], [])
    except (OSError, subprocess.SubprocessError):
        pass
    return set_status(installed=rows, available=available, checked_at=int(time.time()), error=error)


def local_releases(mid: str) -> list[str]:
    folder = PROGRAMS / mid / 'releases'
    out = [p.name for p in folder.iterdir() if p.is_dir() and not p.is_symlink() and release.VERSION.match(p.name)] if folder.is_dir() else []
    return sorted(out, key=release.parse_version)


# ---- Bausteine ---------------------------------------------------------------------------------------------------
def fetch(mid: str, version: str | None, current: str | None, work: Path) -> tuple[Path, str]:
    """Modulordner nach work holen; liefert (Ordner, Herkunft). Ohne version: mitgelieferte Fassung, sonst die neueste."""
    shutil.rmtree(work, ignore_errors=True)
    work.mkdir(mode=0o700, parents=True)
    b = bundled().get(mid)
    if b and (version is None or version == b['module']['version']):
        target = work / mid
        shutil.copytree(BUNDLED / mid, target, symlinks=True)
        return target, 'mitgeliefert'
    if version is None:
        st = check()
        offers = [o for o in st.get('available', []) if o['id'] == mid] + [r['update'] for r in st.get('installed', []) if r['id'] == mid and r.get('update')]
        if not offers:
            raise Fehler(f'Modul „{mid}“ ist weder mitgeliefert noch als Release verfügbar.')
        version = offers[0]['version']
        if b and version == b['module']['version']:
            return fetch(mid, version, current, work)
    signed = update.fetch_release(mid, version, current, work / 'release')
    folder = work / 'release' / 'modules' / mid
    if not (folder / 'module.toml').is_file():
        raise Fehler('Release enthält das Modul nicht (modules/<id>/ fehlt).')
    log(f'{mid} {version}: ' + ('Signatur, Manifest und alle Dateien geprüft.' if signed else 'OHNE Signatur (zugelassen); Manifest und alle Dateien geprüft.'))
    return folder, 'github'


def validate(folder: Path, mid: str) -> dict:
    try:
        m = manifest.check_folder(folder)
    except manifest.Invalid as e:
        raise Fehler('module.toml ungültig: ' + '; '.join(e.errors)[:400]) from None
    if m['module']['id'] != mid:
        raise Fehler(f'Ordner enthält „{m["module"]["id"]}“, nicht „{mid}“.')
    if mid in EXCLUDED:
        raise Fehler('Das Beispielmodul ist nur für Tests.')
    for name in m['system']['services']:
        unit = folder / 'systemd' / name
        if not unit.is_file() or unit.is_symlink():
            raise Fehler(f'Dienst {name}: Datei systemd/{name} fehlt im Modul.')
        text = unit.read_text(encoding='utf-8')
        users = re.findall(r'(?m)^\s*User\s*=\s*(\S+)\s*$', text)
        if name.endswith('.service') and users != [f'ks-{mid}']:
            raise Fehler(f'Dienst {name}: muss genau „User=ks-{mid}“ enthalten.')
        if re.search(r'(?m)^\s*(Group|SupplementaryGroups|DynamicUser|PermissionsStartOnly|AmbientCapabilities)\s*=', text) or '+/' in text or '!/' in text:
            raise Fehler(f'Dienst {name}: Gruppen, Rechte und Befehle mit Vorrechten sind nicht erlaubt.')
    return m


def packages(m: dict) -> None:
    wanted = m['system']['packages']
    if not wanted or not APT:
        return
    missing = []
    for p in wanted:
        r = _run(['/usr/bin/dpkg-query', '-W', '-f=${Status}', p], timeout=30)
        if r.returncode != 0 or 'install ok installed' not in r.stdout:
            missing.append(p)
    if not missing:
        return
    log(f'{m["module"]["id"]}: Pakete installieren: {" ".join(missing)}')
    env = {**os.environ, 'DEBIAN_FRONTEND': 'noninteractive'}
    r = _run([APT, 'install', '-y', '-q', '--no-install-recommends', *missing], timeout=1800, env=env)
    if r.returncode != 0:
        _run([APT, 'update', '-q'], timeout=600, env=env)
        r = _run([APT, 'install', '-y', '-q', '--no-install-recommends', *missing], timeout=1800, env=env)
    if r.returncode != 0:
        raise Fehler('Pakete ließen sich nicht installieren: ' + (r.stderr or r.stdout).strip()[-200:])


def owner(mid: str) -> str:
    return OWNER or f'ks-{mid}'


def account(mid: str) -> None:
    """Systembenutzer, Datenordner (0700) und Socket-Ordner (0750, Gruppe www-data) des Moduls."""
    user = owner(mid)
    data = DATA / mid
    if not OWNER:
        try:
            pwd.getpwnam(user)
        except KeyError:
            r = _run(['/usr/sbin/useradd', '--system', '--home-dir', str(data), '--no-create-home', '--shell', '/usr/sbin/nologin', '--user-group', user], timeout=60)
            if r.returncode != 0:
                raise Fehler(f'Benutzer {user} ließ sich nicht anlegen: ' + r.stderr.strip()[:200]) from None
    data.mkdir(mode=0o700, parents=True, exist_ok=True)
    if data.is_symlink():
        raise Fehler('Datenordner ist ein Verweis.')
    os.chmod(data, 0o700)
    if os.geteuid() == 0:
        shutil.chown(data, user, user)
    (TMPFILES).mkdir(parents=True, exist_ok=True)
    web_group = 'www-data' if not OWNER else user
    _write(TMPFILES / f'kienzle-{mid}.conf', f'# Kienzle-Suite, Modul {mid}: Socket-Ordner (nur das Modul und nginx).\nd {RUN}/{mid} 0750 {user} {web_group} -\n', 0o644)
    run = RUN / mid
    run.mkdir(mode=0o750, parents=True, exist_ok=True)
    os.chmod(run, 0o750)
    if os.geteuid() == 0:
        shutil.chown(run, user, web_group)


def _write(path: Path, text: str, mode: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name('.' + path.name + '.neu')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        f.write(text)
    os.chmod(tmp, mode)
    os.replace(tmp, path)


def deploy(folder: Path, m: dict) -> Path:
    """Programm in den Versionsordner kopieren (nur root beschreibbar) und „current“ atomar umsetzen. Liefert den alten Ziel-Ordner."""
    mid, version = m['module']['id'], m['module']['version']
    base = PROGRAMS / mid
    (base / 'releases').mkdir(mode=0o755, parents=True, exist_ok=True)
    target = base / 'releases' / version
    tmp = base / 'releases' / f'.neu-{version}-{time.time_ns()}'
    shutil.copytree(folder, tmp / mid, symlinks=False)
    for root, dirs, files in os.walk(tmp):
        for n in dirs:
            os.chmod(os.path.join(root, n), 0o755)
        for n in files:
            p = os.path.join(root, n)
            os.chmod(p, 0o755 if os.stat(p).st_mode & 0o100 else 0o644)
        if os.geteuid() == 0:
            for n in dirs + files:
                os.lchown(os.path.join(root, n), 0, 0)
    os.chmod(tmp, 0o755)
    previous = (base / 'current').resolve() if (base / 'current').is_symlink() else None
    if target.exists():
        # Nur nach einem abgebrochenen früheren Versuch: „current“ zeigt nie hierher (nur neuere Versionen werden eingesetzt).
        shutil.rmtree(target)
    tmp.rename(target)
    link = base / '.current.neu'
    link.unlink(missing_ok=True)
    link.symlink_to(f'releases/{version}/{mid}')
    os.replace(link, base / 'current')
    return previous


def _switch(mid: str, target: Path) -> None:
    base = PROGRAMS / mid
    link = base / '.current.neu'
    link.unlink(missing_ok=True)
    link.symlink_to(os.path.relpath(target, base))
    os.replace(link, base / 'current')


def cleanup(mid: str) -> None:
    keep = set(local_releases(mid)[-KEEP_RELEASES:])
    current = (PROGRAMS / mid / 'current').resolve()
    for p in (PROGRAMS / mid / 'releases').iterdir():
        if p.name.startswith('.') or (p.name not in keep and current.parent != p):
            shutil.rmtree(p, ignore_errors=True)


def phpfpm_binary() -> str:
    if PHPFPM:
        return PHPFPM
    found = sorted(glob.glob('/usr/sbin/php-fpm[0-9]*.[0-9]*'), key=lambda p: [int(x) for x in re.findall(r'\d+', p)])
    if not found:
        raise Fehler('php-fpm ist nicht installiert.')
    return found[-1]


def fpm_conf(m: dict) -> str:
    mid = m['module']['id']
    readable = [f'/opt/kienzle/{mid}/', f'/var/lib/kienzle/{mid}/', '/opt/kienzle/core/', f'/etc/kienzle/verbindungen/module/{mid}.json',
                f'/etc/kienzle/schluessel/{mid}.key', '/var/lib/kienzle/drucker/status.json', f'/var/spool/kienzle/ablage/{mid}/',
                '/etc/kienzle/ca/ca.crt', '/etc/kienzle/ca/server.crt', '/tmp/', '/usr/bin/', '/usr/share/fonts/', '/usr/share/fonts-dejavu-core/']
    return '\n'.join([
        f'; Kienzle-Suite, Modul {mid}: eigene php-fpm-Instanz als ks-{mid}. Von der Modulverwaltung geschrieben; Änderungen gehen verloren.',
        '[global]', 'error_log = syslog', f'syslog.ident = kienzle-{mid}-php', 'log_level = notice', 'daemonize = no', '',
        f'[{mid}]', f'listen = /run/kienzle/{mid}/web.sock',
        f'; Der Ordner /run/kienzle/{mid} (0750, ks-{mid}:www-data) lässt nur das Modul und nginx hinein.', 'listen.mode = 0666',
        'pm = ondemand', 'pm.max_children = 8', 'pm.process_idle_timeout = 60s', 'pm.max_requests = 500', 'clear_env = yes',
        'catch_workers_output = yes', 'decorate_workers_output = no', f'env[KIENZLE_MODUL] = {mid}', 'env[PATH] = /usr/bin:/bin',
        f'env[HOME] = /var/lib/kienzle/{mid}', 'env[TMPDIR] = /tmp',
        'php_admin_value[open_basedir] = ' + ':'.join(readable), 'php_admin_value[date.timezone] = Europe/Berlin',
        'php_admin_value[disable_functions] = exec,passthru,shell_exec,system,popen,pcntl_exec,dl',
        'php_admin_flag[display_errors] = off', 'php_admin_flag[display_startup_errors] = off', 'php_admin_flag[log_errors] = on',
        'php_admin_flag[expose_php] = off', 'php_admin_flag[file_uploads] = off', 'php_admin_value[post_max_size] = 8M',
        'php_admin_value[max_execution_time] = 120', 'php_admin_value[memory_limit] = 256M', 'php_admin_value[session.auto_start] = 0', ''])


def fpm_unit(m: dict, binary: str) -> str:
    mid = m['module']['id']
    return '\n'.join([
        f'# Kienzle-Suite, Modul {mid}: Weboberfläche (php-fpm als ks-{mid}). Von der Modulverwaltung geschrieben.',
        '[Unit]', f'Description=Kienzle-Suite Modul {mid} (Weboberfläche)', 'After=network.target', '', '[Service]', 'Type=notify',
        f'User=ks-{mid}', f'Group=ks-{mid}', f'ExecStart={binary} --nodaemonize --fpm-config {ETC}/{mid}/php-fpm.conf',
        'ExecReload=/bin/kill -USR2 $MAINPID', 'Restart=on-failure', 'UMask=0077',
        '# Härtung wie die Basis; das Modul darf ins Netz (z. B. Zahlungsdienst, T2med) und nur in seine eigenen Ordner schreiben.',
        'NoNewPrivileges=yes', 'ProtectSystem=strict', 'ProtectHome=yes', 'PrivateTmp=yes', 'PrivateDevices=yes', 'ProtectKernelTunables=yes',
        'ProtectKernelModules=yes', 'ProtectKernelLogs=yes', 'ProtectControlGroups=yes', 'ProtectClock=yes', 'ProtectHostname=yes',
        'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6', 'RestrictNamespaces=yes', 'RestrictRealtime=yes', 'RestrictSUIDSGID=yes',
        'LockPersonality=yes', 'SystemCallArchitectures=native', 'CapabilityBoundingSet=',
        f'ReadWritePaths=/var/lib/kienzle/{mid} /run/kienzle/{mid} -/var/spool/kienzle/ablage/{mid}', '', '[Install]', 'WantedBy=multi-user.target', ''])


def units(folder: Path, m: dict) -> list[str]:
    """Einheiten schreiben; liefert die Namen der Dienste, die laufen sollen."""
    mid = m['module']['id']
    names = []
    if m['web'] and m['web']['protocol'] == 'fastcgi':
        _write(ETC / mid / 'php-fpm.conf', fpm_conf(m), 0o644)
        _write(UNITS / f'kienzle-{mid}-php.service', fpm_unit(m, phpfpm_binary() if not without_systemd() else '/usr/sbin/php-fpm'), 0o644)
        names.append(f'kienzle-{mid}-php.service')
    for name in m['system']['services']:
        if not UNIT_NAME.match(name):
            raise Fehler(f'Dienstname {name} ungültig.')
        _write(UNITS / name, (folder / 'systemd' / name).read_text(encoding='utf-8'), 0o644)
        names.append(name)
    # Einheiten einer älteren Version, die es nicht mehr gibt, entfernen.
    for old in UNITS.glob(f'kienzle-{mid}-*'):
        if old.name not in names and old.name != f'kienzle-{mid}-php.service' and not old.name.startswith(f'kienzle-drucker-{mid}-'):
            _systemctl('disable', '--now', old.name)
            old.unlink(missing_ok=True)
    _systemctl('daemon-reload')
    if not without_systemd():
        _run(['/usr/bin/systemd-tmpfiles', '--create', str(TMPFILES / f'kienzle-{mid}.conf')], timeout=60)
    return names


def unit_names(mid: str) -> list[str]:
    return sorted(p.name for p in UNITS.glob(f'kienzle-{mid}-*') if UNIT_NAME.match(p.name) and not p.name.startswith(f'kienzle-drucker-{mid}-'))


def register() -> None:
    """Modulverzeichnis, nginx, AppArmor, Verbindungen und Drucker an die installierten Module anpassen."""
    r = _run([WEB, 'module'], timeout=900)
    if r.returncode != 0:
        raise Fehler('Webinterface ließ sich nicht anpassen: ' + (r.stderr or r.stdout).strip()[-300:])


def hook(m: dict, name: str, env_extra: dict | None = None, timeout: int = 600) -> subprocess.CompletedProcess | None:
    mid = m['module']['id']
    rel = (m.get('hooks') or {}).get(name)
    if not rel:
        return None
    path = PROGRAMS / mid / 'current' / rel
    env = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'HOME': str(DATA / mid), 'KIENZLE_MODUL': mid,
           'KIENZLE_MODUL_VERSION': m['module']['version'], 'KIENZLE_DATEN': str(DATA / mid), **(env_extra or {})}
    cmd = [str(path)] if OWNER else [RUNUSER, '-u', f'ks-{mid}', '--', str(path)]
    return _run(cmd, timeout=timeout, env=env, cwd=str(DATA / mid))


def busy(m: dict) -> bool:
    r = hook(m, 'busy', timeout=30)
    return r is not None and r.returncode == 75


def healthy(m: dict) -> bool:
    deadline = time.time() + HEALTH_SECONDS
    while True:
        r = hook(m, 'health', timeout=30)
        if r is None or r.returncode == 0:
            return True
        if time.time() >= deadline:
            log(f'{m["module"]["id"]}: Gesundheitsprüfung fehlgeschlagen: ' + (r.stderr or r.stdout).strip()[-200:])
            return False
        time.sleep(3)


def start(names: list[str]) -> None:
    for n in names:
        if n.endswith(('.service', '.timer', '.path', '.socket')):
            _systemctl('enable', n)
            _systemctl('restart', n)


def ensure_running() -> list[str]:
    """Dienste installierter Module, die eingeschaltet, aber gestoppt sind, wieder starten (z. B. nach einem unterbrochenen Update).
    Läuft gerade eine Installation oder Aktualisierung, geschieht nichts. Einmalige Dienste (Type=oneshot) werden nicht angefasst."""
    if read_status().get('running'):
        return []
    started: list[str] = []
    for mid in sorted(installed()):
        for n in unit_names(mid):
            if n.endswith('.service'):
                try:
                    if 'Type=oneshot' in (UNITS / n).read_text(encoding='utf-8'):
                        continue
                except OSError:
                    continue
            elif not n.endswith(('.timer', '.path', '.socket')):
                continue
            enabled, active = _systemctl('is-enabled', n), _systemctl('is-active', n)
            if enabled is None or active is None or enabled.returncode != 0 or active.returncode == 0:
                continue
            _systemctl('start', n)
            log(f'{mid}: {n} war gestoppt und wurde wieder gestartet.')
            started.append(n)
    return started


def _long_running(n: str) -> bool:
    """Dauerdienst des Moduls (nicht Type=oneshot)."""
    if not n.endswith('.service'):
        return False
    try:
        return 'Type=oneshot' not in (UNITS / n).read_text(encoding='utf-8')
    except OSError:
        return False


def service_states() -> dict[str, list[dict]]:
    """Je installiertem Modul die Dauerdienste mit Zustand (aktiv: True/False, ohne systemd: None)."""
    out: dict[str, list[dict]] = {}
    for mid in sorted(installed()):
        rows = []
        for n in unit_names(mid):
            if _long_running(n):
                r = _systemctl('is-active', n)
                rows.append({'name': n, 'aktiv': None if r is None else r.returncode == 0})
        out[mid] = rows
    return out


def refresh_states() -> None:
    """Nur die Dienstzustände in der Statusdatei erneuern (ohne Netzzugriff)."""
    states = service_states()
    rows = read_status().get('installed', [])
    for r in rows:
        if isinstance(r, dict):
            r['dienste'] = states.get(r.get('id'), [])
    set_status(installed=rows)


def service_action(mid: str, restart: bool) -> str:
    """Dienste eines Moduls starten oder neu starten."""
    if mid not in installed():
        raise Fehler('Modul ist nicht installiert.')
    names = [n for n in unit_names(mid) if _long_running(n) or n.endswith(('.timer', '.path', '.socket'))]
    if not names:
        raise Fehler('Dieses Modul hat keinen eigenen Dienst.')
    for n in names:
        _systemctl('restart' if restart else 'start', n)
    log(f'{mid}: Dienste ' + ('neu gestartet.' if restart else 'gestartet.'))
    refresh_states()
    return f'{mid}: Dienste ' + ('neu gestartet.' if restart else 'gestartet.')


def stop(mid: str) -> None:
    for n in unit_names(mid):
        _systemctl('stop', n)


def snapshot(mid: str, version: str) -> Path | None:
    data = DATA / mid
    if not data.is_dir():
        return None
    target = STATE / 'schnappschuesse' / mid / f'{version}-{time.time_ns()}'
    target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    shutil.copytree(data, target, symlinks=True)
    snaps = sorted(target.parent.iterdir())
    for old in snaps[:-KEEP_SNAPSHOTS]:
        shutil.rmtree(old, ignore_errors=True)
    return target


def restore(mid: str, snap: Path) -> None:
    data = DATA / mid
    shutil.rmtree(data, ignore_errors=True)
    shutil.copytree(snap, data, symlinks=True)


# ---- Abläufe -----------------------------------------------------------------------------------------------------
def install(mid: str, version: str | None = None) -> str:
    """Installieren oder aktualisieren. Liefert die Meldung für das Webinterface."""
    if not ID.match(mid) or mid == 'core':
        raise Fehler('Modul-ID ungültig.')
    have = installed().get(mid)
    current = have['module']['version'] if have else None
    work = STATE / 'arbeit' / mid
    set_status(running={'action': 'aktualisieren' if have else 'installieren', 'id': mid, 'step': 'laden', 'since': int(time.time())})
    folder, origin = fetch(mid, version, current, work)
    m = validate(folder, mid)
    new = m['module']['version']
    if current is not None and not release.newer(new, current):
        raise Fehler(f'{mid} {new} ist nicht neuer als die installierte Version {current}.')
    if have and busy(have):
        raise Fehler(f'{have["module"]["name"]} ist gerade beschäftigt (z. B. laufende Zahlung). Bitte später erneut versuchen.')
    log(f'{mid}: {"Aktualisierung " + current + " → " if current else "Installation "}{new} ({origin}) gestartet.')
    set_status(running={'action': 'aktualisieren' if have else 'installieren', 'id': mid, 'step': 'installieren', 'since': int(time.time())})
    snap = snapshot(mid, current) if have else None
    packages(m)
    account(mid)
    if have:
        stop(mid)
    previous = deploy(folder, m)
    try:
        names = units(PROGRAMS / mid / 'current', m)
        register()
        r = hook(m, 'update' if have else 'install', {'KIENZLE_VORHER': current or ''})
        if r is not None and r.returncode != 0:
            raise Fehler(f'Haken {"update" if have else "install"} fehlgeschlagen: ' + (r.stderr or r.stdout).strip()[-300:])
        start(names)
        if not healthy(m):
            raise Fehler('Das Modul antwortet nach dem Start nicht (hooks.health).')
    except Fehler as e:
        log(f'{mid}: Fehler: {e}')
        if have and previous is not None:
            stop(mid)
            _switch(mid, previous)
            if snap is not None:
                restore(mid, snap)
            try:
                old = validate(PROGRAMS / mid / 'current', mid)
                start(units(PROGRAMS / mid / 'current', old))
                register()
            except Fehler as e2:
                log(f'{mid}: Rücksprung unvollständig: {e2}')
            log(f'{mid}: auf {current} zurückgesprungen.')
            raise Fehler(f'{e} Zurück auf {current}.') from None
        _remove(mid, m, delete_data=False, run_hook=False)
        raise
    finally:
        shutil.rmtree(work, ignore_errors=True)
    cleanup(mid)
    log(f'{mid}: {new} {"aktualisiert" if have else "installiert"}.')
    return f'{m["module"]["name"]} {new} ' + ('aktualisiert.' if have else 'installiert. Rechte vergeben unter Verwaltung → Benutzer.')


def _remove(mid: str, m: dict | None, delete_data: bool, run_hook: bool = True) -> None:
    if m and run_hook:
        r = hook(m, 'remove')
        if r is not None and r.returncode != 0:
            log(f'{mid}: Haken remove meldet einen Fehler (wird übergangen): ' + (r.stderr or r.stdout).strip()[-200:])
    for n in unit_names(mid):
        _systemctl('disable', '--now', n)
        (UNITS / n).unlink(missing_ok=True)
    _systemctl('daemon-reload')
    shutil.rmtree(ETC / mid, ignore_errors=True)
    shutil.rmtree(PROGRAMS / mid, ignore_errors=True)
    (TMPFILES / f'kienzle-{mid}.conf').unlink(missing_ok=True)
    shutil.rmtree(RUN / mid, ignore_errors=True)
    try:
        register()
    except Fehler as e:
        log(f'{mid}: {e}')
    if delete_data:
        shutil.rmtree(DATA / mid, ignore_errors=True)
        shutil.rmtree(STATE / 'schnappschuesse' / mid, ignore_errors=True)
        shutil.rmtree(SPOOL / mid, ignore_errors=True)
        if not OWNER:
            _run(['/usr/sbin/userdel', f'ks-{mid}'], timeout=60)


def remove(mid: str, delete_data: bool) -> str:
    m = installed().get(mid)
    if not m:
        raise Fehler('Modul ist nicht installiert.')
    if busy(m):
        raise Fehler(f'{m["module"]["name"]} ist gerade beschäftigt. Bitte später erneut versuchen.')
    set_status(running={'action': 'entfernen', 'id': mid, 'step': 'entfernen', 'since': int(time.time())})
    _remove(mid, m, delete_data)
    log(f'{mid}: entfernt' + (' samt Daten.' if delete_data else '; Daten bleiben unter /var/lib/kienzle/' + mid + '.'))
    return f'{m["module"]["name"]} entfernt.' + ('' if delete_data else ' Die Daten bleiben erhalten; eine erneute Installation übernimmt sie.')


# ---- Anfragen des Webinterfaces ----------------------------------------------------------------------------------
def app_uid() -> int:
    if 'KIENZLE_MODULE_UID' in os.environ:
        return int(os.environ['KIENZLE_MODULE_UID'])
    return pwd.getpwnam('ks-core').pw_uid


def read_request() -> dict | None:
    try:
        fd = os.open(REQUEST, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        return None
    try:
        info = os.fstat(fd)
        raw = os.read(fd, 4097) if stat.S_ISREG(info.st_mode) and info.st_size <= 4096 and info.st_uid == app_uid() else None
    finally:
        os.close(fd)
        REQUEST.unlink(missing_ok=True)
    try:
        req = json.loads(raw) if raw else None
    except ValueError:
        return None
    return req if isinstance(req, dict) else None


def dispatch(req: dict) -> str:
    action = req.get('action')
    if action == 'pruefen':
        check()
        return 'Angebot aktualisiert.'
    if action == 'status':
        refresh_states()
        return 'Status der Module aktualisiert.'
    if action == 'automatisch':
        on = req.get('an') is True
        set_status(auto=on)
        return 'Module werden automatisch aktualisiert.' if on else 'Automatische Modul-Updates sind aus.'
    mid = req.get('id')
    if not isinstance(mid, str) or not ID.match(mid) or mid == 'core':
        raise Fehler('Modul-ID ungültig.')
    version = req.get('version')
    if version is not None and (not isinstance(version, str) or not release.VERSION.match(version)):
        raise Fehler('Version ungültig.')
    if action in ('installieren', 'aktualisieren'):
        if (action == 'installieren') == (mid in installed()):
            raise Fehler('Modul ist bereits installiert.' if action == 'installieren' else 'Modul ist nicht installiert.')
        return install(mid, version)
    if action == 'entfernen':
        return remove(mid, req.get('daten_loeschen') is True)
    if action in ('dienst-start', 'dienst-neustart'):
        return service_action(mid, action == 'dienst-neustart')
    raise Fehler('Anfrage ungültig.')


def handle() -> None:
    STATE.mkdir(mode=0o755, parents=True, exist_ok=True)
    with open(STATE / '.sperre', 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        req = read_request()
        if req is None:
            check()
            return
        try:
            msg = dispatch(req)
            set_status(running=None, last={'ok': True, 'message': msg[:400], 'time': int(time.time())})
        except (Fehler, update.Fehler, release.Fehler, OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError) as e:
            msg = str(e) if isinstance(e, (Fehler, update.Fehler, release.Fehler)) else f'Vorgang fehlgeschlagen ({type(e).__name__}).'
            log(f'Abgebrochen: {msg}')
            set_status(running=None, last={'ok': False, 'message': msg[:400], 'time': int(time.time())})
        check()
