"""Druckerverwaltung der Basis (root-Dienst, Aufruf über core/bin/kienzle-drucker). Konzept: docs/PLAN.md, M8.

- Nur dieser Dienst ruft lpadmin auf. Weder das Webinterface (ks-core) noch ein Modul ist Mitglied der Gruppe lpadmin.
- Drucker der Praxis: Bondrucker als RAW über socket://…:9100, A4-Drucker über IPP Everywhere, Suche mit lpinfo.
- Virtuelle Drucker der Module aus [[printers]]: Backend (0755, läuft damit als Benutzer lp) und PPD (0644) aus dem
  Programmordner des Moduls, Warteschlange mit Kennung von Praxisherz, Freigabe per IPP und/oder Samba.
- Entfernt werden nur Warteschlangen mit der Kennung von Praxisherz.
- Samba gibt nur die Warteschlangen frei, die hier ausdrücklich dafür markiert sind (/etc/samba/kienzle-drucker.conf).
Übernommen aus kienzle-suite-beta, src/PrinterAdmin.php (Druckerarten, Suche, URI-Prüfung, Auswertung von lpstat).
"""
from __future__ import annotations

import ipaddress
import json
import os
import pwd
import re
import shutil
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
SAMBA_NET = Path(E('KIENZLE_DRUCKER_SAMBA_NETZ', '/etc/samba/kienzle-netz.conf'))
UNITS = Path(E('KIENZLE_DRUCKER_UNITS', '/etc/systemd/system'))
SYSTEMCTL = E('KIENZLE_DRUCKER_SYSTEMCTL', '/usr/bin/systemctl')
DATA = Path(E('KIENZLE_DRUCKER_DATA', '/var/lib/kienzle'))
# Auftragsordner der Ablage (Netzwerkspeicher): Druckprogramme von Modulen müssen dort ihre Aufträge abgeben dürfen (z. B. PDF der Kasse).
ABLAGE_SPOOL = Path(E('KIENZLE_ABLAGE_SPOOL', '/var/spool/kienzle/ablage'))
PORTS = range(47100, 47200)
SYSTEMD_RUN = Path(E('KIENZLE_SYSTEMD_RUN', '/run/systemd/system'))   # gibt es nur, wenn systemd läuft (wie sd_booted)


def without_systemd() -> bool:
    return E('KIENZLE_OHNE_SYSTEMD') == '1' or not SYSTEMD_RUN.is_dir()                     # IPP-Everywhere-Drucker der Module, nur localhost
BIN = {k: E(f'KIENZLE_{k.upper()}', v) for k, v in {'lpadmin': '/usr/sbin/lpadmin', 'lpinfo': '/usr/sbin/lpinfo', 'lpstat': '/usr/bin/lpstat',
                                                     'lp': '/usr/bin/lp', 'cupsenable': '/usr/sbin/cupsenable', 'cupsaccept': '/usr/sbin/cupsaccept',
                                                     'smbcontrol': '/usr/bin/smbcontrol', 'cancel': '/usr/bin/cancel',
                                                     'testparm': '/usr/bin/testparm', 'smbpasswd': '/usr/bin/smbpasswd',
                                                     'ippeveprinter': '/usr/sbin/ippeveprinter', 'runuser': '/usr/sbin/runuser'}.items()}
if not Path(BIN['ippeveprinter']).is_file() and Path('/usr/bin/ippeveprinter').is_file():
    BIN['ippeveprinter'] = '/usr/bin/ippeveprinter'
JOB = re.compile(r'^[A-Za-z0-9_-]{1,40}-\d{1,9}$')
WORKGROUP = re.compile(r'^[A-Za-z0-9][A-Za-z0-9-]{0,14}$')
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


PPD_REV = 'durchreichen-1'


def ppd_text(p: dict) -> str:
    """PPD der CUPS-Warteschlange vor einem virtuellen Drucker: PDF und PostScript werden unverändert durchgereicht.
    Mit „-m everywhere“ bietet CUPS zusätzlich Raster- und Bildformate an; der Windows-IPP-Treiber wählt dann ein Bildformat,
    und das PDF hat keine Textebene mehr (Texterkennung nötig)."""
    name = re.sub(r'[^A-Za-z0-9_-]', '', str(p['name'])) or 'Drucker'
    label = re.sub(r'[^\x20-\x7e]', '?', f'Kienzle-Suite {p["name"]}')[:60].replace('"', "'")
    paper = p.get('paper') if p.get('paper') in ('A4', 'A5', 'A6') else 'A4'          # nur das Papierformat des Moduls anbieten
    sizes = tuple(s for s in (('A6', 298, 420), ('A5', 420, 595), ('A4', 595, 842)) if s[0] == paper)
    lines = ['*PPD-Adobe: "4.3"', '*FormatVersion: "4.3"', '*FileVersion: "1.0"', '*LanguageVersion: English', '*LanguageEncoding: ISOLatin1',
             f'*PCFileName: "{name[:8].upper()}.PPD"', '*Manufacturer: "Kienzle-Suite"', f'*Product: "({label})"', f'*ModelName: "{label}"',
             f'*ShortNickName: "{label}"', f'*NickName: "{label} (PDF/PostScript)"', '*PSVersion: "(3010.000) 0"', '*LanguageLevel: "3"',
             '*ColorDevice: False', '*DefaultColorSpace: Gray', '*FileSystem: False', '*Throughput: "1"', '*LandscapeOrientation: Plus90',
             '*TTRasterizer: Type42', '*cupsVersion: 1.1', '*cupsManualCopies: True', '*cupsMaxCopies: 99',
             # Nur diese drei Filterzeilen (wie im früheren Rechnungseingang): Jede weitere Umwandlung (z. B. über „application/vnd.cups-pdf“)
             # lässt CUPS zusätzlich JPEG, PNG, TIFF und Raster anbieten; Windows schickt dann Bilder statt PDF mit Textebene.
             '*cupsFilter2: "application/pdf application/pdf 0 -"', '*cupsFilter2: "application/postscript application/postscript 0 -"',
             '*cupsFilter2: "application/vnd.cups-postscript application/postscript 0 -"']
    for key, label2 in (('PageSize', 'Original page size'), ('PageRegion', 'Original page region')):
        lines += [f'*OpenUI *{key}/{label2}: PickOne', f'*OrderDependency: 10 AnySetup *{key}', f'*Default{key}: {paper}']
        lines += [f'*{key} {n}/{n}: "<</PageSize[{w} {h}]>>setpagedevice"' for n, w, h in sizes]
        lines.append(f'*CloseUI: *{key}')
    lines.append(f'*DefaultImageableArea: {paper}')
    lines += [f'*ImageableArea {n}/{n}: "0 0 {w} {h}"' for n, w, h in sizes]
    lines.append(f'*DefaultPaperDimension: {paper}')
    lines += [f'*PaperDimension {n}/{n}: "{w} {h}"' for n, w, h in sizes]
    lines += ['*DefaultFont: Courier', '*Font Courier: Standard "(001.007)" Standard ROM', '']
    return '\n'.join(lines)


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


PRINTER_SIDE = ('unable to query printer', 'unable to connect to printer', 'everywhere', 'unable to get printer attributes', 'not found')


def explain(error: str) -> str:
    e = error.lower()
    # Zuerst Meldungen über den Drucker selbst: „lpadmin: Unable to query printer: Connection refused“ heißt, der *Drucker*
    # nimmt die automatische Einrichtung nicht an – CUPS läuft dann sehr wohl.
    if 'name resolution' in e or 'nodename nor servname' in e or 'unknown host' in e:
        return ('Der Name des Druckers lässt sich auf dem Server nicht auflösen (mDNS „.local“ geht oft nicht). '
                'Bitte die IP-Adresse des Druckers eintragen, z. B. 192.0.2.80, und dem Drucker im Router eine feste Adresse geben.')
    if any(x in e for x in PRINTER_SIDE):
        return ('Der Drucker antwortet nicht auf die automatische Einrichtung (IPP Everywhere/AirPrint). Im Webmenü des Druckers '
                '„IPP“ bzw. „AirPrint“ einschalten, oder die vollständige Adresse aus der Suche bzw. vom Drucker eintragen (z. B. ipp://192.0.2.80/ipp/print).')
    if any(x in e for x in ('scheduler is not running', 'unable to connect to server', 'cups.sock', 'connection refused')):
        return 'Der Druckdienst (CUPS) läuft nicht. Bitte den Installer erneut ausführen.'
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
    """Warteschlangen mit Adresse, Zustand, Beschreibung und ob Praxisherz sie verwaltet (Kennung in der Beschreibung)."""
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


def jobs() -> list[dict]:
    """Offene Aufträge aller Warteschlangen (ohne Titel: der könnte Namen enthalten)."""
    out = []
    for line in run('lpstat', '-o', timeout=15, allow_empty=True).splitlines():
        m = re.match(r'^(\S+)\s+(\S+)\s+(\d+)\s+(.+)$', line.strip())
        if m and JOB.match(m.group(1)):
            out.append({'id': m.group(1), 'drucker': m.group(1).rsplit('-', 1)[0], 'benutzer': m.group(2)[:40], 'groesse': int(m.group(3)), 'zeit': m.group(4)[:40]})
    return out


def cancel(job: str) -> str:
    if not JOB.match(job) or job not in {j['id'] for j in jobs()}:
        raise Fehler('Auftrag nicht gefunden (schon gedruckt?).')
    run('cancel', job, timeout=30)
    return f'Auftrag {job} abgebrochen.'


def cancel_all(name: str) -> str:
    if name not in {q['name'] for q in queues()}:
        raise Fehler('Drucker nicht gefunden.')
    run('cancel', '-a', name, timeout=30)
    return f'Alle Aufträge von „{name}“ abgebrochen.'


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
def ensure_cups() -> None:
    """CUPS starten, falls er gerade nicht läuft (Ubuntu startet ihn bei Bedarf und beendet ihn im Leerlauf)."""
    try:
        if 'scheduler is running' in run('lpstat', '-r', timeout=15, allow_empty=True):
            return
    except Fehler:
        pass
    if not without_systemd() and SYSTEMCTL and Path(SYSTEMCTL).is_file():
        subprocess.run([SYSTEMCTL, 'start', 'cups.socket', 'cups.service'], capture_output=True, timeout=60)
        for _ in range(20):
            try:
                if 'scheduler is running' in run('lpstat', '-r', timeout=15, allow_empty=True):
                    return
            except Fehler:
                pass
            time.sleep(0.5)


def generic_driver() -> str | None:
    """Treiber für A4-Drucker ohne IPP Everywhere (viele Laserdrucker sprechen PCL bzw. PostScript über Port 9100)."""
    try:
        models = run('lpinfo', '-m', timeout=60)
    except Fehler:
        return None
    for want in ('drv:///sample.drv/generpcl.ppd', 'drv:///sample.drv/generic.ppd'):
        if any(line.split(' ', 1)[0] == want for line in models.splitlines()):
            return want
    return None


def resolve_local(host: str) -> str:
    """Namen wie „Drucker.local“ (mDNS) in eine IP-Adresse auflösen; der Server hat oft kein mDNS im Namensdienst."""
    try:
        ipaddress.ip_address(host)
        return host
    except ValueError:
        pass
    if not host.lower().endswith('.local'):
        return host
    try:
        socket.getaddrinfo(host, None)
        return host
    except OSError:
        pass
    tool = shutil.which('avahi-resolve-host-name', path='/usr/bin:/bin')
    if tool:
        try:
            out = subprocess.run([tool, '-4', '-n', host], capture_output=True, text=True, timeout=15).stdout.split()
            if len(out) >= 2 and re.match(r'^\d{1,3}(\.\d{1,3}){3}$', out[1]):
                return out[1]
        except (OSError, subprocess.SubprocessError):
            pass
    raise Fehler(f'Der Name „{host}“ lässt sich auf dem Server nicht auflösen. Bitte stattdessen die IP-Adresse des Druckers eintragen '
                 '(am Drucker oder im Router nachsehen) und dem Drucker im Router eine feste Adresse geben.')


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
        m = re.match(r'^([a-z]+://(?:[^/@]*@)?)(\[[^\]]+\]|[^/:]+)(.*)$', addr)
        if m and not addr.startswith(('dnssd:', 'usb:')) and not m.group(2).startswith('['):
            addr = m.group(1) + resolve_local(m.group(2)) + m.group(3)
        uri = addr
    else:
        if not is_host(addr):
            raise Fehler('Bitte die IP-Adresse oder den Namen des Druckers eintragen, z. B. 192.0.2.80.')
        addr = resolve_local(addr)
        if not probe(addr, spec['port']):
            raise Fehler(f'Unter {addr} antwortet kein ' + ('IPP-Drucker (Port 631)' if kind == 'office' else 'Bondrucker (Port 9100)')
                         + '. Ist der Drucker eingeschaltet, im Netzwerk und die Adresse richtig?')
        uri = f'ipp://{bracket(addr)}/ipp/print' if kind == 'office' else f'socket://{bracket(addr)}:9100'
    _check_share(share)
    ensure_cups()
    existing = {q['name'].lower(): q for q in queues()}
    if name.lower() in existing and not existing[name.lower()]['managed']:
        raise Fehler(f'Es gibt bereits einen Drucker „{name}“, der nicht von Praxisherz angelegt wurde. Bitte einen anderen Namen wählen.')
    if name.lower() in existing and existing[name.lower()]['modul']:
        raise Fehler(f'„{name}“ ist ein virtueller Drucker eines Moduls.')
    args = ['-p', name, '-v', uri, '-m', 'everywhere' if kind == 'office' else 'raw', '-D', f'{MARK} ' + ('Praxisdrucker' if kind == 'office' else 'Bondrucker'),
            '-L', spec['label'], '-o', f'printer-is-shared={"true" if "ipp" in share else "false"}', '-E']
    if kind == 'office':
        args += ['-o', 'media-default=iso_a4_210x297mm']
    note = ''
    try:
        run('lpadmin', *args, timeout=90)
    except Fehler as first:
        # A4-Drucker ohne IPP Everywhere (oder mit anderem Pfad): andere übliche Adressen, zuletzt ein allgemeiner Treiber über Port 9100.
        if kind != 'office' or re.match(r'^[a-z]+://', addr) and not addr.startswith('ipp://'):
            raise
        host = addr if not re.match(r'^[a-z]+://', addr) else re.sub(r'^ipp://\[?([^\]/:]+).*$', r'\1', addr)
        tries = [(f'ipps://{bracket(host)}/ipp/print', 'everywhere'), (f'ipp://{bracket(host)}/ipp', 'everywhere')]
        driver = generic_driver() if probe(host, 9100) else None
        if driver:
            tries.append((f'socket://{bracket(host)}:9100', driver))
        for try_uri, model in tries:
            if try_uri == uri:
                continue
            try:
                run('lpadmin', *[try_uri if a == uri else model if a == 'everywhere' else a for a in args], timeout=90)
                uri = try_uri
                if not model.startswith('everywhere'):
                    note = ' Ohne IPP Everywhere, mit allgemeinem Treiber über Port 9100: bitte eine Testseite drucken.'
                break
            except Fehler:
                continue
        else:
            raise first from None
    _set_share(name, share)
    return f'Drucker „{name}“ angelegt ({address(uri)}).{note}'


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
        raise Fehler('Nur Drucker von Praxisherz lassen sich hier freigeben.')
    if q.get('modul'):
        share = sorted(set(share) | {'ipp'})   # virtuelle Drucker der Module: IPP ist immer an
    run('lpadmin', '-p', name, '-o', f'printer-is-shared={"true" if "ipp" in share else "false"}')
    _set_share(name, share)
    return f'Freigabe für „{name}“ gespeichert.'


def remove(name: str) -> str:
    q = {x['name']: x for x in queues()}.get(name)
    if not q:
        raise Fehler('Drucker nicht gefunden.')
    if not q['managed']:
        raise Fehler('Dieser Drucker wurde nicht von Praxisherz angelegt und wird deshalb nicht entfernt.')
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
    data = (b'\x1b@Praxisherz\nTestseite ' + time.strftime('%d.%m.%Y %H:%M').encode() + b'\n\n\n\n\x1dV\x00') if raw else _test_pdf()
    out = run('lp', '-d', name, '-t', 'Praxisherz Testseite', *(['-o', 'raw'] if raw else []), data=data, timeout=30)
    m = re.search(r'request id is (\S+)', out)
    return f'Testseite an „{name}“ geschickt' + (f' (Auftrag {m.group(1)}).' if m else '.')


def _test_pdf() -> bytes:
    text = 'Praxisherz - Testseite ' + time.strftime('%d.%m.%Y %H:%M')
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


def unit_name(sch: str) -> str:
    return f'kienzle-drucker-{sch.removeprefix("kienzle-")}.service'


def unit_text(p: dict, port: int) -> str:
    """systemd-Einheit für einen IPP-Everywhere-Drucker eines Moduls: ippeveprinter als Modul-Benutzer, nur localhost."""
    mid = p['modul']
    spool = DATA / mid / 'druckauftraege'
    cmd = PROGRAMS / mid / 'current' / p['command']
    return (f'# Kienzle-Suite: virtueller Drucker „{p["name"]}“ des Moduls {mid} (von kienzle-drucker erzeugt).\n[Unit]\n'
            f'Description=Kienzle-Suite Drucker {p["name"]} ({mid})\nAfter=network.target avahi-daemon.service\nWants=avahi-daemon.service\n\n'
            f'[Service]\nUser=ks-{mid}\nGroup=ks-{mid}\nEnvironment=KIENZLE_DRUCKER={p["name"]}\n'
            f'ExecStartPre=/usr/bin/mkdir -p {spool}\n'
            f'ExecStart={BIN["ippeveprinter"]} -p {port} -n localhost -r off -c {cmd} -f {",".join(p["formats"])} -d {spool} {p["scheme"]}\n'
            'Restart=on-failure\nNoNewPrivileges=yes\nProtectSystem=strict\nProtectHome=yes\nPrivateTmp=yes\n'
            f'ReadWritePaths={DATA / mid} -{ABLAGE_SPOOL / mid}\n'
            'RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX\nCapabilityBoundingSet=\n\n[Install]\nWantedBy=multi-user.target\n')


def _port_open(port: int) -> bool:
    try:
        with socket.create_connection(('127.0.0.1', port), timeout=1):
            return True
    except OSError:
        return False


def _start_unit(unit: str, p: dict, port: int) -> None:
    if without_systemd():                            # Container ohne systemd (Tests): Drucker direkt starten
        spool = DATA / p['modul'] / 'druckauftraege'
        subprocess.run([BIN['runuser'], '-u', f'ks-{p["modul"]}', '--', 'mkdir', '-p', str(spool)], capture_output=True, timeout=30)
        log = open(STATE / f'{p["scheme"]}.log', 'ab')     # noqa: SIM115 – bleibt für den Hintergrundprozess offen
        subprocess.Popen([BIN['runuser'], '-u', f'ks-{p["modul"]}', '--', BIN['ippeveprinter'], '-p', str(port), '-n', 'localhost', '-r', 'off',
                          '-c', str(PROGRAMS / p['modul'] / 'current' / p['command']), '-f', ','.join(p['formats']), '-d', str(spool), p['scheme']],
                         stdout=log, stderr=log, start_new_session=True, env={**os.environ, 'KIENZLE_DRUCKER': p['name']})
    elif SYSTEMCTL and Path(SYSTEMCTL).is_file():
        subprocess.run([SYSTEMCTL, 'daemon-reload'], capture_output=True, timeout=60)
        subprocess.run([SYSTEMCTL, 'enable', unit], capture_output=True, timeout=60)
        subprocess.run([SYSTEMCTL, 'restart', unit], capture_output=True, timeout=60)
    for _ in range(int(E('KIENZLE_DRUCKER_WARTEN', '40'))):
        if _port_open(port):
            return
        time.sleep(0.25)
    raise Fehler(f'Der Drucker antwortet nicht auf Port {port}.')


def _stop_unit(unit: str) -> None:
    path = UNITS / unit
    if SYSTEMCTL and Path(SYSTEMCTL).is_file() and not without_systemd():
        subprocess.run([SYSTEMCTL, 'disable', '--now', unit], capture_output=True, timeout=60)
    path.unlink(missing_ok=True)
    if SYSTEMCTL and Path(SYSTEMCTL).is_file() and not without_systemd():
        subprocess.run([SYSTEMCTL, 'daemon-reload'], capture_output=True, timeout=60)


def listed(m: dict) -> list[dict]:
    """Drucker aus der veränderbaren Druckerliste eines Moduls ([printer_list]): Namen aus <Datenordner>/<file>, sonst die
    Vorgabe. Namen müssen mit dem Präfix beginnen; mehr als „max“ Namen und doppelte werden ignoriert."""
    pl = m.get('printer_list')
    if not pl:
        return []
    mid = m['module']['id']
    names = None
    path = DATA / mid / pl['file']
    try:
        info = path.lstat()
        if stat.S_ISREG(info.st_mode) and info.st_size <= 65536:
            data = json.loads(path.read_text(encoding='utf-8'))
            raw = data.get('drucker') if isinstance(data, dict) else None
            if isinstance(raw, list):
                names = [str(x.get('name') if isinstance(x, dict) else x) for x in raw]
    except (OSError, ValueError):
        names = None
    if names is None:
        names = list(pl['default'])
    rx = re.compile(r'^' + re.escape(pl['prefix']) + r'[A-Za-z0-9_-]{0,%d}$' % (39 - len(pl['prefix'])))
    out, seen = [], set()
    for name in names:
        if rx.match(name) and name.lower() not in seen and len(out) < pl['max']:
            seen.add(name.lower())
            out.append({'name': name, 'label': pl['label'], 'ppd': None, 'backend': None, 'command': pl['command'], 'formats': pl['formats'],
                        'paper': pl['paper'], 'share': pl['share']})
    return out


def sync() -> list[str]:
    """Virtuelle Drucker an modules.json angleichen: fehlende anlegen, veraltete und verwaiste entfernen.

    Drei Arten: command → IPP-Everywhere-Drucker (ippeveprinter als ks-<id> auf localhost) mit CUPS-Warteschlange
    „everywhere“; ppd mit backend → Backend und PPD aus dem Modulordner; ppd ohne backend → Ausgabe der Filter verwerfen
    (Backend kienzle-leer der Basis)."""
    registry = _read(MODULES, {}).get('modules', [])
    wanted = {}
    for m in registry:
        mid = m['module']['id']
        for p in [*(m.get('printers') or []), *listed(m)]:
            if p['name'] in wanted or p['name'].lower() in {w.lower() for w in wanted}:
                continue                                      # Name schon von einem anderen Drucker belegt
            wanted[p['name']] = {**p, 'command': p.get('command'), 'formats': p.get('formats') or ['application/pdf'], 'modul': mid, 'scheme': scheme(mid, p['name'])}
    installed = _read(STATE / 'installiert.json', {})
    shares_now = _read(STATE / 'freigaben.json', {})
    notes = []
    current = {q['name']: q for q in queues()}
    used_ports = {i.get('port') for n, i in installed.items() if n in wanted}
    for name, p in wanted.items():
        base = PROGRAMS / p['modul'] / 'current'
        q = current.get(name)
        if q and not q['managed']:
            notes.append(f'{name}: Name schon von einem fremden Drucker belegt, nicht angelegt.')
            continue
        old = installed.get(name, {})
        try:
            if p['command']:
                cmd = base / p['command']
                info = cmd.lstat()
                if not stat.S_ISREG(info.st_mode):
                    raise Fehler(f'{p["command"]}: keine reguläre Datei.')
                port = old.get('port') if old.get('art') == 'ipp' else None
                if port is None:
                    port = next((x for x in PORTS if x not in used_ports and not _port_open(x)), None)
                    if port is None:
                        raise Fehler('kein freier Port mehr.')
                    used_ports.add(port)
                unit = unit_name(p['scheme'])
                text = unit_text(p, port)
                changed = not (UNITS / unit).is_file() or (UNITS / unit).read_text(encoding='utf-8') != text
                if changed:
                    _write(UNITS / unit, text, 0o644)
                if changed or not _port_open(port):
                    _start_unit(unit, p, port)
                ppd = PPDS / f'{p["scheme"]}.ppd'
                ppd_new = ppd_text(p)
                if not ppd.is_file() or ppd.read_text(encoding='utf-8') != ppd_new:
                    _write(ppd, ppd_new, 0o644)
                    changed = True
                if old.get('modell') != PPD_REV:
                    changed = True                    # Warteschlange von „everywhere“ auf die Durchreich-PPD umstellen
                uri, model = f'ipp://localhost:{port}/ipp/print', ['-P', str(ppd)]
                if old.get('backend'):
                    Path(old['backend']).unlink(missing_ok=True)
                entry = {'modul': p['modul'], 'scheme': p['scheme'], 'art': 'ipp', 'port': port, 'unit': unit, 'ppd': str(ppd), 'modell': PPD_REV}
            else:
                ppd = PPDS / f'{p["scheme"]}.ppd'
                changed = _install_file(base / p['ppd'], ppd, 0o644)
                if p.get('backend'):
                    changed |= _install_file(base / p['backend'], BACKENDS / p['scheme'], 0o755)
                    uri = f'{p["scheme"]}:/{name}'
                else:
                    (BACKENDS / p['scheme']).unlink(missing_ok=True)
                    uri = f'kienzle-leer:/{name}'
                if old.get('unit'):
                    _stop_unit(old['unit'])
                model = ['-P', str(ppd)]
                entry = {'modul': p['modul'], 'scheme': p['scheme'], 'art': 'ppd', 'ppd': str(ppd), 'backend': str(BACKENDS / p['scheme']) if p.get('backend') else ''}
        except (OSError, Fehler) as e:
            notes.append(f'{name}: {e}')
            continue
        # Virtuelle Drucker sind immer per IPP freigegeben (Standardweg für alle Betriebssysteme). Samba ist optional: neue Drucker
        # übernehmen die Vorgabe des Moduls, bei bekannten bleibt die Wahl des Admins auch nach einem Abgleich erhalten.
        samba_an = 'samba' in (shares_now[name] if name in shares_now else p['share'])
        share = ['ipp', 'samba'] if samba_an else ['ipp']
        if not q or changed or q['uri'] != uri:
            run('lpadmin', '-p', name, '-v', uri, *model, '-D', f'{MARK} Modul {p["modul"]}: {p["label"]}'[:127],
                '-L', 'auf diesem Server', '-o', 'printer-is-shared=true', '-E', timeout=90)
            notes.append(f'{name}: angelegt bzw. aktualisiert.')
        installed[name] = entry
        _set_share(name, share)
    for name, q in current.items():
        if q['modul'] and name not in wanted:
            run('lpadmin', '-x', name, timeout=30)
            _set_share(name, None)
            notes.append(f'{name}: entfernt (Modul {q["modul"]} nicht mehr installiert).')
    for name, info in list(installed.items()):
        if name not in wanted:
            (BACKENDS / info['scheme']).unlink(missing_ok=True)
            (PPDS / f'{info["scheme"]}.ppd').unlink(missing_ok=True)
            if info.get('unit'):
                _stop_unit(info['unit'])
            installed.pop(name)
    _write(STATE / 'installiert.json', installed, 0o644)
    samba()
    return notes


DATEN = E('KIENZLE_DRUCKER_DATEN', '/var/lib/kienzle')   # Basis der Modul-Datenordner (Tests: Wegwerfordner)
FILESHARE_KEY = re.compile(r'^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$')
FILESHARE_USER = re.compile(r'^ks-[a-z][a-z0-9-]*$')


def fileshares() -> list[dict]:
    """Dateifreigaben ([[fileshare]]) der installierten Module mit Schalter (standardmäßig aus): key, modul, name, label, path, user, an."""
    state = _read(STATE / 'dateifreigaben.json', {})
    try:
        taken = {q['name'].lower() for q in queues()}
    except Fehler:
        taken = set()
    out = []
    for m in _read(MODULES, {}).get('modules', []):
        mid = m['module']['id']
        user = (m.get('system') or {}).get('user', '')
        for f in m.get('fileshares') or []:
            key = f'{mid}/{f["id"]}'
            path = str(f.get('path', ''))
            if (not FILESHARE_KEY.match(key) or not NAME.match(str(f.get('name', ''))) or user != f'ks-{mid}' or not FILESHARE_USER.match(user)
                    or not path.startswith(f'{DATEN}/{mid}/') or '..' in path.split('/') or f['name'].lower() in taken):
                continue
            taken.add(f['name'].lower())
            out.append({'key': key, 'modul': mid, 'name': f['name'], 'label': str(f.get('label', ''))[:80], 'path': path, 'user': user,
                        'an': bool(state.get(key))})
    return out


def set_fileshare(key: str, on: bool, password: str = '') -> str:
    known = {f['key']: f for f in fileshares()}
    f = known.get(key)
    if f is None:
        raise Fehler('Freigabe unbekannt.')
    if on and password and not 8 <= len(password) <= 64:
        raise Fehler('Das Kennwort muss 8 bis 64 Zeichen lang sein.')
    if on and ('\n' in password or '\r' in password):
        raise Fehler('Das Kennwort darf keinen Zeilenumbruch enthalten.')
    state = _read(STATE / 'dateifreigaben.json', {})
    if on and not password and not state.get(key):
        raise Fehler('Zum Einschalten bitte ein Kennwort vergeben.')
    if on:
        path = Path(f['path'])
        path.mkdir(mode=0o700, parents=True, exist_ok=True)
        if path.is_symlink():
            raise Fehler('Freigabeordner ungültig.')
        if E('KIENZLE_DRUCKER_CHOWN', '1') == '1':
            pw = pwd.getpwnam(f['user'])
            os.chown(path, pw.pw_uid, pw.pw_gid)
        os.chmod(path, 0o700)
        if password:
            run('smbpasswd', '-a', '-s', f['user'], data=f'{password}\n{password}\n'.encode(), timeout=20)
    if on:
        state[key] = True
    else:
        state.pop(key, None)
        if not any(state.get(o['key']) for o in fileshares() if o['user'] == f['user'] and o['key'] != key):
            try:
                run('smbpasswd', '-x', f['user'], timeout=20)
            except Fehler:
                pass                                    # Konto war nie angelegt
    _write(STATE / 'dateifreigaben.json', state, 0o644)
    return (f'Freigabe „{f["name"]}“ eingeschaltet. Anmeldung in Windows mit Benutzer {f["user"]} und dem vergebenen Kennwort; nur aus den Praxisnetzen, '
            'wenn die Firewall 445 so freigibt.') if on else f'Freigabe „{f["name"]}“ ausgeschaltet, Zugang gelöscht.'


def samba() -> bool:
    """Samba-Freigaben genau für die markierten Warteschlangen von Praxisherz. Liefert True, wenn sich etwas änderte."""
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
    for f in fileshares():
        if not f['an']:
            continue
        label = re.sub(r'[^A-Za-z0-9 ._:()äöüÄÖÜß-]', '', f['label'])[:80]
        parts.append(f'\n[{f["name"]}]\n   comment = {label}\n   path = {f["path"]}\n   guest ok = no\n   valid users = {f["user"]}\n   read only = no\n   browseable = yes\n'
                     f'   force user = {f["user"]}\n   force group = {f["user"]}\n   create mask = 0600\n   directory mask = 0700\n   follow symlinks = no\n'
                     '   wide links = no\n   hide dot files = yes\n   veto files = /.*/\n   ea support = no\n   store dos attributes = no\n'
                     '   map archive = no\n   map hidden = no\n   map system = no\n')
    text = ''.join(parts)
    old = SAMBA.read_text(encoding='utf-8') if SAMBA.is_file() else None
    if old == text:
        return False
    _write(SAMBA, text, 0o644)
    if Path(BIN['smbcontrol']).is_file():
        subprocess.run([BIN['smbcontrol'], 'smbd', 'reload-config'], capture_output=True, timeout=30)
    return True


# ---- Windows-Netz: Arbeitsgruppe ---------------------------------------------------------------------------------
def network() -> dict:
    n = _read(STATE / 'netz.json', {})
    return {'arbeitsgruppe': n.get('arbeitsgruppe', 'WORKGROUP')}


def network_conf(n: dict) -> str:
    head = '# Kienzle-Suite: von kienzle-drucker erzeugt (Verwaltung → Drucker → Windows-Netz), nicht von Hand ändern.\n'
    return head + f'   workgroup = {n["arbeitsgruppe"].upper()}\n   server role = standalone server\n'


def _apply_network(n: dict) -> None:
    old = SAMBA_NET.read_text(encoding='utf-8') if SAMBA_NET.is_file() else None
    _write(SAMBA_NET, network_conf(n), 0o644)
    if Path(BIN['testparm']).is_file():
        r = subprocess.run([BIN['testparm'], '-s', '--suppress-prompt'], capture_output=True, text=True, timeout=30)
        if r.returncode != 0:
            if old is None:
                SAMBA_NET.unlink()
            else:
                _write(SAMBA_NET, old, 0o644)
            raise Fehler('Samba lehnt die Einstellung ab: ' + (r.stderr.strip().splitlines() or ['?'])[-1][:150])


def _restart(*units: str) -> None:
    if SYSTEMCTL and Path(SYSTEMCTL).is_file():
        for u in units:
            subprocess.run([SYSTEMCTL, 'restart', u], capture_output=True, timeout=120)


def set_workgroup(name: str) -> str:
    if not WORKGROUP.match(name or ''):
        raise Fehler('Arbeitsgruppe: 1–15 Buchstaben, Ziffern oder Bindestrich.')
    n = {'arbeitsgruppe': name.upper()}
    _apply_network(n)
    _write(STATE / 'netz.json', n, 0o644)
    _restart('smbd.service')
    return f'Arbeitsgruppe {name.upper()} gespeichert.'


# ---- Status und Anfragen -----------------------------------------------------------------------------------------
def set_status(**changes) -> dict:
    s = _read(STATE / 'status.json', {})
    s.update(changes)
    s['zeit'] = int(time.time())
    _write(STATE / 'status.json', s, 0o644)
    return s


def heal_local() -> None:
    """Von Praxisherz angelegte Warteschlangen, deren Adresse einen „.local“-Namen enthält, den der Server nicht auflöst, auf die IP umstellen."""
    try:
        for q in queues():
            m = re.match(r'^((?:socket|ipp|ipps|lpd)://)([A-Za-z0-9._-]+\.local)(.*)$', q['uri'], re.I)
            if not m or not q['managed'] or q['modul']:
                continue
            try:
                socket.getaddrinfo(m.group(2), None)
                continue
            except OSError:
                pass
            ip = resolve_local(m.group(2))
            run('lpadmin', '-p', q['name'], '-v', m.group(1) + ip + m.group(3), timeout=30)
    except (Fehler, OSError, subprocess.SubprocessError):
        pass


def refresh() -> None:
    heal_local()
    try:
        set_status(warteschlangen=[{k: q[k] for k in ('name', 'address', 'state', 'description', 'managed', 'modul', 'share')} for q in queues()],
                   auftraege=jobs()[:200], netz=network(), fehler='',
                   dateifreigaben=[{k: f[k] for k in ('key', 'name', 'label', 'modul', 'an')} for f in fileshares()])
    except Fehler as e:
        set_status(warteschlangen=[], fehler=str(e))


def dispatch(req: dict) -> str:
    a = req.get('action')
    name = str(req.get('name', ''))
    if a in ('entfernen', 'testseite', 'freigabe', 'alle-abbrechen') and not NAME.match(name):
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
    if a == 'dateifreigabe':
        key = str(req.get('key', ''))
        if not FILESHARE_KEY.match(key):
            raise Fehler('Freigabe ungültig.')
        msg = set_fileshare(key, bool(req.get('an')), str(req.get('kennwort', '')))
        samba()
        return msg
    if a == 'testseite':
        return test_page(name)
    if a == 'abbrechen':
        return cancel(str(req.get('auftrag', '')))
    if a == 'alle-abbrechen':
        return cancel_all(name)
    if a == 'arbeitsgruppe':
        msg = set_workgroup(str(req.get('arbeitsgruppe', '')))
        return msg
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

