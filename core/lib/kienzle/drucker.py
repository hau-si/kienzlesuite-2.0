"""Druckerverwaltung der Basis (root-Dienst, Aufruf über core/bin/kienzle-drucker). Konzept: docs/PLAN.md, M8.

- Nur dieser Dienst ruft lpadmin auf. Weder das Webinterface (ks-core) noch ein Modul ist Mitglied der Gruppe lpadmin.
- Drucker der Praxis: Bondrucker als RAW über socket://…:9100, A4-Drucker über IPP Everywhere, Suche mit lpinfo.
- Virtuelle Drucker der Module aus [[printers]]: Backend (0755, läuft damit als Benutzer lp) und PPD (0644) aus dem
  Programmordner des Moduls, Warteschlange mit Kennung der Suite, Freigabe per IPP und/oder Samba.
- Entfernt werden nur Warteschlangen mit der Kennung der Suite.
- Samba gibt nur die Warteschlangen frei, die hier ausdrücklich dafür markiert sind (/etc/samba/kienzle-drucker.conf).
Übernommen aus kienzle-suite-beta, src/PrinterAdmin.php (Druckerarten, Suche, URI-Prüfung, Auswertung von lpstat).
"""
from __future__ import annotations

import ipaddress
import json
import os
import re
import socket
import stat
import subprocess
import time
from pathlib import Path

E = os.environ.get
STATE = Path(E('KIENZLE_DRUCKER_STATE', '/var/lib/kienzle/drucker'))
REQUEST = Path(E('KIENZLE_DRUCKER_REQUEST', '/var/lib/kienzle/core/anfragen/drucker.json'))
MODULES = Path(E('KIENZLE_DRUCKER_MODULES', '/var/lib/kienzle/core/modules.json'))
PROGRAMS = Path(E('KIENZLE_DRUCKER_PROGRAMME', '/opt/kienzle'))
BACKENDS = Path(E('KIENZLE_DRUCKER_BACKENDS', '/usr/lib/cups/backend'))
PPDS = Path(E('KIENZLE_DRUCKER_PPDS', '/usr/share/ppd/kienzle'))
SAMBA = Path(E('KIENZLE_DRUCKER_SAMBA', '/etc/samba/kienzle-drucker.conf'))
BIN = {k: E(f'KIENZLE_{k.upper()}', v) for k, v in {'lpadmin': '/usr/sbin/lpadmin', 'lpinfo': '/usr/sbin/lpinfo', 'lpstat': '/usr/bin/lpstat',
                                                     'lp': '/usr/bin/lp', 'cupsenable': '/usr/sbin/cupsenable', 'cupsaccept': '/usr/sbin/cupsaccept',
                                                     'smbcontrol': '/usr/bin/smbcontrol'}.items()}
MARK = 'Kienzle-Suite'
KINDS = {
    'tm-m10': {'label': 'Bondrucker Epson TM-m10 (58 mm)', 'port': 9100, 'name': 'TM-m10'},
    'tm-m30iii': {'label': 'Bondrucker Epson TM-m30III (80 mm)', 'port': 9100, 'name': 'TM-m30III'},
    'bon': {'label': 'Bondrucker (ESC/POS, RAW über Port 9100)', 'port': 9100, 'name': 'Bondrucker'},
    'office': {'label': 'A4-Drucker im Netzwerk (IPP / AirPrint)', 'port': 631, 'name': 'Praxisdrucker'},
}
NAME = re.compile(r'^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$')
URI = re.compile(r'^(socket|ipp|ipps|dnssd|usb|lpd)://[A-Za-z0-9%._~:/?&=@+\-]{1,300}$')
HOST = re.compile(r'^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,62})(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}))*$')
SHARES = ('samba', 'ipp')


class Fehler(Exception):
    pass


def app_uid() -> int:
    if 'KIENZLE_DRUCKER_UID' in os.environ:
        return int(os.environ['KIENZLE_DRUCKER_UID'])
    import pwd
    return pwd.getpwnam('ks-core').pw_uid


def _write(path: Path, data, mode: int = 0o644) -> None:
    path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    tmp = path.with_name('.' + path.name + '.neu')
    tmp.write_text(data if isinstance(data, str) else json.dumps(data, ensure_ascii=False, indent=1), encoding='utf-8')
    os.chmod(tmp, mode)
    os.replace(tmp, path)


def _read(path: Path, default):
    try:
        return json.loads(path.read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return default


def run(name: str, *args: str, timeout: int = 60, allow_empty: bool = False, data: bytes | None = None) -> str:
    env = {'PATH': '/usr/bin:/bin:/usr/sbin:/sbin', 'LANG': 'C', **{k: v for k, v in os.environ.items() if k.startswith('FAKE_')}}   # FAKE_: nur Tests
    r = subprocess.run([BIN[name], *args], capture_output=True, timeout=timeout, input=data, env=env)
    out, err = r.stdout.decode(errors='replace'), r.stderr.decode(errors='replace')
    if r.returncode == 0:
        return out
    msg = (err or out).strip()
    if allow_empty and (msg == '' or 'no destinations' in msg.lower()):
        return ''
    raise Fehler(explain(msg))


def explain(error: str) -> str:
    e = error.lower()
    if any(x in e for x in ('unable to connect', 'connection refused', 'scheduler is not running')):
        return 'Der Druckdienst (CUPS) läuft nicht. Bitte den Installer erneut ausführen.'
    if any(x in e for x in ('everywhere', 'unable to query')):
        return 'Der Drucker hat die automatische Einrichtung (IPP Everywhere/AirPrint) abgelehnt. Im Webmenü des Druckers „IPP“ bzw. „AirPrint“ einschalten.'
    return 'Druckereinrichtung fehlgeschlagen: ' + (re.sub(r'\s+', ' ', error)[:200] if error else 'unbekannter Fehler') + '.'


def address(uri: str) -> str:
    if uri.startswith('usb:'):
        return 'USB'
    if uri.startswith('kienzle-'):
        return 'auf diesem Server'
    if uri.startswith('dnssd:'):
        return 'Netzwerk (automatisch gefunden)'
    m = re.match(r'^[a-z]+://\[?([^\]/:]+)', uri)
    return m.group(1) if m else uri


def friendly(uri: str) -> str:
    from urllib.parse import unquote
    if uri.startswith('dnssd://'):
        return unquote(uri[8:].split('._')[0])
    if uri.startswith('usb://'):
        return unquote(uri[6:].split('?')[0]).replace('/', ' ').strip()
    return 'Gerät unter ' + address(uri)


def is_host(value: str) -> bool:
    try:
        ipaddress.ip_address(value)
        return True
    except ValueError:
        return bool(HOST.match(value))


def bracket(host: str) -> str:
    try:
        return f'[{host}]' if ipaddress.ip_address(host).version == 6 else host
    except ValueError:
        return host


def probe(host: str, port: int) -> bool:
    if E('KIENZLE_DRUCKER_PROBE') == 'ja':
        return True
    try:
        with socket.create_connection((host, port), timeout=3):
            return True
    except OSError:
        return False


# ---- Abfragen ----------------------------------------------------------------------------------------------------
def queues() -> list[dict]:
    """Warteschlangen mit Adresse, Zustand, Beschreibung und ob die Suite sie verwaltet (Kennung in der Beschreibung)."""
    found: dict[str, dict] = {}
    for line in run('lpstat', '-v', timeout=15, allow_empty=True).splitlines():
        m = re.match(r'^\S+ (?:for|für) ([^:\s]+): (\S+)$', line.strip())
        if m:
            found[m.group(1)] = {'name': m.group(1), 'uri': m.group(2), 'state': '', 'description': '', 'managed': False, 'modul': ''}
    current = None
    for line in run('lpstat', '-l', '-p', timeout=15, allow_empty=True).splitlines():
        m = re.match(r'^(?:printer|Drucker) (\S+) (.*)$', line)
        if m:
            current = m.group(1)
            if current in found:
                rest = m.group(2)
                found[current]['state'] = 'gestoppt' if 'disabled' in rest else ('druckt' if 'printing' in rest else 'bereit')
        elif current in found:
            d = re.match(r'^\s+(?:Description|Beschreibung): ?(.*)$', line)
            if d:
                desc = d.group(1).strip()
                found[current]['description'] = desc
                found[current]['managed'] = desc.startswith(MARK)
                mm = re.match(rf'^{MARK} Modul ([a-z][a-z0-9-]{{1,28}}):', desc)
                found[current]['modul'] = mm.group(1) if mm else ''
    shares = _read(STATE / 'freigaben.json', {})
    for q in found.values():
        q['address'] = address(q['uri'])
        q['share'] = [s for s in shares.get(q['name'], []) if s in SHARES]
    return sorted(found.values(), key=lambda q: q['name'].lower())


def discover() -> list[dict]:
    out = {}
    for line in run('lpinfo', '--timeout', '8', '-v', timeout=40).splitlines():
        m = re.match(r'^(network|direct) (\S+)$', line.strip())
        if not m or not URI.match(m.group(2)):
            continue
        uri = m.group(2)
        name = friendly(uri)
        kind = 'tm-m30iii' if re.search(r'TM-?m30', name, re.I) else 'tm-m10' if re.search(r'TM-?m10', name, re.I) else \
            'office' if re.match(r'^(dnssd://.*_ipps?\._tcp|ipps?://)', uri) else None
        out[uri] = {'uri': uri, 'name': name[:80], 'kind': kind, 'connection': 'USB' if uri.startswith('usb:') else 'Netzwerk'}
    return sorted(out.values(), key=lambda d: (d['kind'] is None, d['name']))


# ---- Drucker der Praxis ------------------------------------------------------------------------------------------
def add(kind: str, name: str, addr: str, share: list[str]) -> str:
    spec = KINDS.get(kind)
    if not spec:
        raise Fehler('Bitte die Art des Druckers wählen.')
    name = (name or '').strip() or spec['name']
    if not NAME.match(name):
        raise Fehler(f'Name: nur Buchstaben, Ziffern, - und _ (ohne Leerzeichen, höchstens 40), z. B. {spec["name"]}.')
    addr = (addr or '').strip()
    if re.match(r'^[a-z]+://', addr):
        if not URI.match(addr):
            raise Fehler('Diese Druckeradresse wird nicht unterstützt.')
        uri = addr
    else:
        if not is_host(addr):
            raise Fehler('Bitte die IP-Adresse oder den Namen des Druckers eintragen, z. B. 192.0.2.80.')
        if not probe(addr, spec['port']):
            raise Fehler(f'Unter {addr} antwortet kein ' + ('IPP-Drucker (Port 631)' if kind == 'office' else 'Bondrucker (Port 9100)')
                         + '. Ist der Drucker eingeschaltet, im Netzwerk und die Adresse richtig?')
        uri = f'ipp://{bracket(addr)}/ipp/print' if kind == 'office' else f'socket://{bracket(addr)}:9100'
    _check_share(share)
    existing = {q['name'].lower(): q for q in queues()}
    if name.lower() in existing and not existing[name.lower()]['managed']:
        raise Fehler(f'Es gibt bereits einen Drucker „{name}“, der nicht von der Suite angelegt wurde. Bitte einen anderen Namen wählen.')
    if name.lower() in existing and existing[name.lower()]['modul']:
        raise Fehler(f'„{name}“ ist ein virtueller Drucker eines Moduls.')
    args = ['-p', name, '-v', uri, '-m', 'everywhere' if kind == 'office' else 'raw', '-D', f'{MARK} ' + ('Praxisdrucker' if kind == 'office' else 'Bondrucker'),
            '-L', spec['label'], '-o', f'printer-is-shared={"true" if "ipp" in share else "false"}', '-E']
    if kind == 'office':
        args += ['-o', 'media-default=iso_a4_210x297mm']
    run('lpadmin', *args, timeout=90)
    _set_share(name, share)
    return f'Drucker „{name}“ angelegt ({address(uri)}).'


def _check_share(share) -> None:
    if not isinstance(share, list) or any(s not in SHARES for s in share):
        raise Fehler('Freigabe: nur „samba“ und „ipp“.')


def _set_share(name: str, share: list[str] | None) -> None:
    shares = _read(STATE / 'freigaben.json', {})
    if share:
        shares[name] = sorted(set(share))
    else:
        shares.pop(name, None)
    _write(STATE / 'freigaben.json', shares, 0o644)


def set_share(name: str, share: list[str]) -> str:
    _check_share(share)
    q = {x['name']: x for x in queues()}.get(name)
    if not q or not q['managed']:
        raise Fehler('Nur Drucker der Suite lassen sich hier freigeben.')
    run('lpadmin', '-p', name, '-o', f'printer-is-shared={"true" if "ipp" in share else "false"}')
    _set_share(name, share)
    return f'Freigabe für „{name}“ gespeichert.'


def remove(name: str) -> str:
    q = {x['name']: x for x in queues()}.get(name)
    if not q:
        raise Fehler('Drucker nicht gefunden.')
    if not q['managed']:
        raise Fehler('Dieser Drucker wurde nicht von der Suite angelegt und wird deshalb nicht entfernt.')
    if q['modul']:
        raise Fehler('Virtuelle Drucker der Module verschwinden mit dem Modul.')
    run('lpadmin', '-x', name, timeout=30)
    _set_share(name, None)
    return f'Drucker „{name}“ entfernt.'


def test_page(name: str) -> str:
    q = {x['name']: x for x in queues()}.get(name)
    if not q:
        raise Fehler('Drucker nicht gefunden.')
    raw = 'Bondrucker' in q['description']
    data = (b'\x1b@Kienzle-Suite\nTestseite ' + time.strftime('%d.%m.%Y %H:%M').encode() + b'\n\n\n\n\x1dV\x00') if raw else _test_pdf()
    out = run('lp', '-d', name, '-t', 'Kienzle-Suite Testseite', *(['-o', 'raw'] if raw else []), data=data, timeout=30)
    m = re.search(r'request id is (\S+)', out)
    return f'Testseite an „{name}“ geschickt' + (f' (Auftrag {m.group(1)}).' if m else '.')


def _test_pdf() -> bytes:
    text = 'Kienzle-Suite - Testseite ' + time.strftime('%d.%m.%Y %H:%M')
    content = f'BT /F1 18 Tf 72 760 Td ({text}) Tj ET'.encode()
    objs = [b'<< /Type /Catalog /Pages 2 0 R >>', b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            b'<< /Length ' + str(len(content)).encode() + b' >>\nstream\n' + content + b'\nendstream', b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>']
    out, offsets = b'%PDF-1.4\n', []
    for i, o in enumerate(objs, 1):
        offsets.append(len(out))
        out += f'{i} 0 obj\n'.encode() + o + b'\nendobj\n'
    xref = len(out)
    out += f'xref\n0 {len(objs) + 1}\n0000000000 65535 f \n'.encode() + b''.join(f'{o:010d} 00000 n \n'.encode() for o in offsets)
    return out + f'trailer\n<< /Size {len(objs) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n'.encode()


# ---- Virtuelle Drucker der Module --------------------------------------------------------------------------------
def scheme(mid: str, name: str) -> str:
    return f'kienzle-{mid}-' + re.sub(r'[^a-z0-9-]', '-', name.lower())


def _install_file(src: Path, dest: Path, mode: int) -> bool:
    info = src.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_size > 4 * 1024 * 1024:
        raise Fehler(f'{src.name}: keine reguläre Datei bzw. zu groß.')
    data = src.read_bytes()
    if dest.is_file() and dest.read_bytes() == data and stat.S_IMODE(dest.stat().st_mode) == mode:
        return False
    dest.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    tmp = dest.with_name('.' + dest.name + '.neu')
    tmp.write_bytes(data)
    os.chmod(tmp, mode)
    if E('KIENZLE_DRUCKER_CHOWN', '1') == '1':
        os.chown(tmp, 0, 0)
    os.replace(tmp, dest)
    return True


def sync() -> list[str]:
    """Virtuelle Drucker an modules.json angleichen: fehlende anlegen, veraltete und verwaiste entfernen."""
    registry = _read(MODULES, {}).get('modules', [])
    wanted = {}
    for m in registry:
        mid = m['module']['id']
        for p in m.get('printers') or []:
            wanted[p['name']] = {**p, 'modul': mid, 'scheme': scheme(mid, p['name'])}
    installed = _read(STATE / 'installiert.json', {})
    notes = []
    current = {q['name']: q for q in queues()}
    for name, p in wanted.items():
        base = PROGRAMS / p['modul'] / 'current'
        q = current.get(name)
        if q and not q['managed']:
            notes.append(f'{name}: Name schon von einem fremden Drucker belegt, nicht angelegt.')
            continue
        if not p.get('backend'):
            notes.append(f'{name}: ohne Backend nicht angelegt.')
            continue
        try:
            changed = _install_file(base / p['backend'], BACKENDS / p['scheme'], 0o755)
            changed |= _install_file(base / p['ppd'], PPDS / f'{p["scheme"]}.ppd', 0o644)
        except (OSError, Fehler) as e:
            notes.append(f'{name}: {e}')
            continue
        uri = f'{p["scheme"]}:/{name}'
        if not q or changed or q['uri'] != uri:
            run('lpadmin', '-p', name, '-v', uri, '-P', str(PPDS / f'{p["scheme"]}.ppd'), '-D', f'{MARK} Modul {p["modul"]}: {p["label"]}'[:127],
                '-L', 'auf diesem Server', '-o', f'printer-is-shared={"true" if "ipp" in p["share"] else "false"}', '-E', timeout=60)
            notes.append(f'{name}: angelegt bzw. aktualisiert.')
        installed[name] = {'modul': p['modul'], 'scheme': p['scheme']}
        _set_share(name, p['share'])
    for name, q in current.items():
        if q['modul'] and name not in wanted:
            run('lpadmin', '-x', name, timeout=30)
            _set_share(name, None)
            notes.append(f'{name}: entfernt (Modul {q["modul"]} nicht mehr installiert).')
    for name, info in list(installed.items()):
        if name not in wanted:
            (BACKENDS / info['scheme']).unlink(missing_ok=True)
            (PPDS / f'{info["scheme"]}.ppd').unlink(missing_ok=True)
            installed.pop(name)
    _write(STATE / 'installiert.json', installed, 0o644)
    samba()
    return notes


def samba() -> bool:
    """Samba-Freigaben genau für die markierten Warteschlangen der Suite. Liefert True, wenn sich etwas änderte."""
    shares = _read(STATE / 'freigaben.json', {})
    known = {q['name']: q for q in queues()}
    parts = ['# Kienzle-Suite: von kienzle-drucker erzeugt, nicht von Hand ändern.\n']
    for name in sorted(shares):
        q = known.get(name)
        if not q or not q['managed'] or 'samba' not in shares[name] or not NAME.match(name):
            continue
        comment = re.sub(r'[^A-Za-z0-9 ._:()äöüÄÖÜß-]', '', q['description'])[:80]
        parts.append(f'\n[{name}]\n   comment = {comment}\n   printable = yes\n   printer name = {name}\n   path = /var/spool/samba\n'
                     '   guest ok = yes\n   read only = yes\n   browseable = yes\n')
    text = ''.join(parts)
    old = SAMBA.read_text(encoding='utf-8') if SAMBA.is_file() else None
    if old == text:
        return False
    _write(SAMBA, text, 0o644)
    if Path(BIN['smbcontrol']).is_file():
        subprocess.run([BIN['smbcontrol'], 'smbd', 'reload-config'], capture_output=True, timeout=30)
    return True


# ---- Status und Anfragen -----------------------------------------------------------------------------------------
def set_status(**changes) -> dict:
    s = _read(STATE / 'status.json', {})
    s.update(changes)
    s['zeit'] = int(time.time())
    _write(STATE / 'status.json', s, 0o644)
    return s


def refresh() -> None:
    try:
        set_status(warteschlangen=[{k: q[k] for k in ('name', 'address', 'state', 'description', 'managed', 'modul', 'share')} for q in queues()], fehler='')
    except Fehler as e:
        set_status(warteschlangen=[], fehler=str(e))


def dispatch(req: dict) -> str:
    a = req.get('action')
    name = str(req.get('name', ''))
    if a in ('entfernen', 'testseite', 'freigabe') and not NAME.match(name):
        raise Fehler('Name ungültig.')
    if a == 'suchen':
        found = discover()
        set_status(gefunden=found, gesucht=int(time.time()))
        return f'{len(found)} Drucker gefunden.'
    if a == 'anlegen':
        msg = add(str(req.get('art', '')), name, str(req.get('adresse', '')), req.get('freigabe', []))
        samba()
        return msg
    if a == 'entfernen':
        msg = remove(name)
        samba()
        return msg
    if a == 'freigabe':
        msg = set_share(name, req.get('freigabe', []))
        samba()
        return msg
    if a == 'testseite':
        return test_page(name)
    if a == 'abgleichen':
        notes = sync()
        return 'Virtuelle Drucker abgeglichen.' + (' ' + ' '.join(notes) if notes else '')
    raise Fehler('Anfrage ungültig.')


def handle() -> None:
    uid = app_uid()
    try:
        fd = os.open(REQUEST, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        refresh()
        return
    try:
        info = os.fstat(fd)
        raw = os.read(fd, 8193) if stat.S_ISREG(info.st_mode) and info.st_size <= 8192 and info.st_uid == uid else None
    finally:
        os.close(fd)
        REQUEST.unlink(missing_ok=True)
    try:
        req = json.loads(raw) if raw else None
        if not isinstance(req, dict):
            raise Fehler('Anfrage ungültig.')
        msg = dispatch(req)
        set_status(letzte={'ok': True, 'meldung': msg[:500], 'zeit': int(time.time())})
    except (Fehler, ValueError, TypeError, KeyError, OSError, subprocess.SubprocessError) as e:
        set_status(letzte={'ok': False, 'meldung': str(e)[:300] if isinstance(e, Fehler) else 'Vorgang fehlgeschlagen.', 'zeit': int(time.time())})
    refresh()


def print_job(queue: str, data: bytes, title: str, options: list[str]) -> str:
    """Für Tests und Module ohne PHP: Auftrag mit lp abgeben, liefert die Auftragsnummer."""
    if not NAME.match(queue):
        raise Fehler('Drucker ungültig.')
    out = run('lp', '-d', queue, '-t', re.sub(r'[^A-Za-z0-9 ._/-]', '', title)[:60] or MARK, *options, data=data, timeout=30)
    m = re.search(r'request id is (\S+)', out)
    if not m:
        raise Fehler('Keine Auftragsnummer erhalten.')
    return m.group(1)

