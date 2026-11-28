"""Servernamen und Praxisname ändern (root-Dienst, Aufruf über core/bin/kienzle-servername).

Das Webinterface (Einrichten → Server) legt eine Anfrage ab. Der Dienst prüft die Namen, schreibt core.json, stellt das
Server-Zertifikat für die neuen Namen aus (die Zertifizierungsstelle bleibt, die Arbeitsplätze vertrauen weiter) und
schreibt nginx neu. Lehnt nginx ab oder schlägt etwas fehl, kommt der vorige Stand zurück.
"""
from __future__ import annotations

import ipaddress
import json
import os
import re
import shutil
import stat
import subprocess
import time
from pathlib import Path

E = os.environ.get
CONFIG = Path(E('KIENZLE_CORE_CONFIG', '/etc/kienzle/core/core.json'))
STATE = Path(E('KIENZLE_SERVERNAME_STATE', '/var/lib/kienzle/servername'))
REQUEST = Path(E('KIENZLE_SERVERNAME_REQUEST', '/var/lib/kienzle/core/anfragen/servername.json'))
CA_DIR = E('KIENZLE_CA_DIR', '/etc/kienzle/ca')
BIN = Path(E('KIENZLE_SERVERNAME_BIN', str(Path(__file__).resolve().parents[2] / 'bin')))
TIMEDATECTL = E('KIENZLE_TIMEDATECTL', '/usr/bin/timedatectl')
HOST = re.compile(r'^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$')


class Fehler(Exception):
    pass


def app_uid() -> int:
    if 'KIENZLE_SERVERNAME_UID' in os.environ:
        return int(os.environ['KIENZLE_SERVERNAME_UID'])
    import pwd
    return pwd.getpwnam('ks-core').pw_uid


def check_names(names) -> list[str]:
    if not isinstance(names, list) or not 1 <= len(names) <= 10:
        raise Fehler('Bitte 1 bis 10 Namen bzw. Adressen angeben.')
    out = []
    for n in names:
        n = str(n).strip().lower()
        try:
            ip = ipaddress.ip_address(n)
            if ip.is_loopback or ip.is_unspecified or ip.is_multicast:
                raise Fehler(f'{n}: diese Adresse geht nicht.')
            n = str(ip)
        except ValueError:
            if not HOST.match(n) or n.replace('.', '').isdigit():
                raise Fehler(f'„{n[:60]}“ ist kein gültiger Name.') from None
        if n not in out:
            out.append(n)
    return out


def _write(path: Path, data: dict, mode: int) -> None:
    tmp = path.with_name('.' + path.name + '.neu')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=1)
    os.chmod(tmp, mode)
    if E('KIENZLE_SERVERNAME_CHOWN', '1') == '1':
        shutil.chown(tmp, 'root', 'ks-core')
    os.replace(tmp, path)


def _run(*args: str) -> subprocess.CompletedProcess:
    env = {**os.environ, 'KIENZLE_NGINX_RELOAD': E('KIENZLE_NGINX_RELOAD', 'systemctl reload nginx.service')}
    return subprocess.run([str(BIN / args[0]), *args[1:]], capture_output=True, text=True, timeout=120, env=env)


def outside(names: list[str]) -> list[str]:
    from .zertifikat import Zertifikate
    z = Zertifikate(CA_DIR)
    return z.outside(names) if z.exists() else []


def apply(names: list[str], practice: str | None, new_ca: bool = False) -> str:
    names = check_names(names)
    out = outside(names)
    if out and not new_ca:
        raise Fehler(f'{", ".join(out)} liegt außerhalb der Namen, für die die Zertifizierungsstelle von Praxisherz Zertifikate ausstellen darf '
                     '(z. B. *.internal, *.local, *.lan, *.home.arpa, *.fritz.box). Anderen Namen wählen – oder „neue Zertifizierungsstelle“ '
                     'ankreuzen; dann müssen alle Arbeitsplätze sie neu einspielen.')
    if practice is not None and (len(practice) > 60 or re.search(r'[\x00-\x1f<>]', practice)):
        raise Fehler('Praxisname: höchstens 60 Zeichen, ohne < und >.')
    old = json.loads(CONFIG.read_text(encoding='utf-8'))
    new = {**old, 'hostnames': names, **({'practice': practice.strip()} if practice is not None else {})}
    if new == old:
        return 'Keine Änderung.'
    _write(CONFIG, new, 0o640)
    cert = _run('kienzle-zertifikat', 'neue-ca' if out and new_ca else 'erneuern')
    web = _run('kienzle-web', 'neu') if cert.returncode in (0, 3) else None
    if web is None or web.returncode != 0:
        _write(CONFIG, old, 0o640)
        _run('kienzle-zertifikat', 'erneuern')
        _run('kienzle-web', 'neu')
        reason = (cert.stderr if web is None else web.stderr).strip().splitlines()
        raise Fehler('Nicht übernommen: ' + (reason[-1][:200] if reason else 'unbekannter Fehler') + '. Der vorige Stand ist wieder aktiv.')
    return ('Gespeichert. Praxisherz ist jetzt erreichbar unter: ' + ', '.join(f'https://{n}/' for n in names)
            + ('. NEUE Zertifizierungsstelle: bitte auf allen Arbeitsplätzen neu einspielen '
               '(Paket unter Einrichten → Arbeitsplätze erneut ausführen oder /ca.crt von Hand).' if out and new_ca else ''))


def set_clock(delta) -> str:
    """Uhr um delta Sekunden stellen (abgeleitet aus einem Code der Authenticator-App, höchstens 24 Stunden).
    Die Zeitsynchronisation bleibt danach eingeschaltet; klappt sie später, hält sie die Uhr richtig."""
    if not isinstance(delta, int) or isinstance(delta, bool) or abs(delta) > 86400:
        raise Fehler('Abweichung ungültig.')
    last = (json.loads((STATE / 'status.json').read_text(encoding='utf-8')).get('uhr') or {}) if (STATE / 'status.json').is_file() else {}
    if last.get('zeit', 0) > time.time() + delta - 120 and last.get('zeit', 0) < time.time() + 600:
        raise Fehler('Die Uhr wurde eben erst gestellt. Bitte einen Moment warten.')
    target = time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(time.time() + delta))
    subprocess.run([TIMEDATECTL, 'set-ntp', 'false'], capture_output=True, timeout=30)
    r = subprocess.run([TIMEDATECTL, 'set-time', target], capture_output=True, text=True, timeout=30)
    subprocess.run([TIMEDATECTL, 'set-ntp', 'true'], capture_output=True, timeout=30)
    if r.returncode != 0:
        raise Fehler('Uhrzeit konnte nicht gestellt werden: ' + (r.stderr.strip()[:150] or '?'))
    status(uhr={'zeit': int(time.time()), 'delta': delta})
    return f'Uhrzeit um {delta:+d} Sekunden korrigiert.'


def status(**changes) -> None:
    STATE.mkdir(mode=0o755, parents=True, exist_ok=True)
    p = STATE / 'status.json'
    try:
        s = json.loads(p.read_text(encoding='utf-8'))
    except (OSError, ValueError):
        s = {}
    s.update(changes)
    tmp = p.with_name('.status.neu')
    tmp.write_text(json.dumps(s, ensure_ascii=False), encoding='utf-8')
    os.chmod(tmp, 0o644)
    os.replace(tmp, p)


def handle() -> None:
    try:
        fd = os.open(REQUEST, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        return
    try:
        info = os.fstat(fd)
        raw = os.read(fd, 4097) if stat.S_ISREG(info.st_mode) and info.st_size <= 4096 and info.st_uid == app_uid() else None
    finally:
        os.close(fd)
        REQUEST.unlink(missing_ok=True)
    try:
        req = json.loads(raw) if raw else None
        if not isinstance(req, dict):
            raise Fehler('Anfrage ungültig.')
        if req.get('aktion') == 'uhrzeit':
            msg = set_clock(req.get('delta'))
            status(letzte={'ok': True, 'meldung': msg, 'zeit': int(time.time())})
            return
        practice = req.get('praxis')
        msg = apply(req.get('namen'), str(practice) if practice is not None else None, req.get('neue_ca') is True)
        status(letzte={'ok': True, 'meldung': msg, 'zeit': int(time.time())})
    except (Fehler, ValueError, OSError, subprocess.SubprocessError) as e:
        status(letzte={'ok': False, 'meldung': str(e)[:300] if isinstance(e, Fehler) else 'Vorgang fehlgeschlagen.', 'zeit': int(time.time())})
