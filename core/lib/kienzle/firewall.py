"""Firewall Praxisherz (root-Dienst, Aufruf über core/bin/kienzle-firewall).

Übernommen aus kienzle-suite-beta (scripts/firewall-apply.py) und verallgemeinert:
- Das Webinterface legt nur eine kleine Anfragedatei ab. Dieser Dienst prüft sie streng und erzeugt die Regeln aus
  festen Vorlagen, nie aus Text der Anfrage. Ports der Module stehen nicht in der Anfrage, sondern werden aus dem
  root-eigenen Modulverzeichnis (modules.json) nachgeschlagen; die Anfrage nennt nur, welche freigeschaltet sind.
- Eigene nftables-Tabelle „inet kienzle“; andere Regeln des Servers bleiben unberührt.
- Schutz vor Aussperren: Ohne Bestätigung binnen WAIT Sekunden werden die vorherigen Regeln wiederhergestellt.
- Vorgabe ist „alles zu“ (policy drop); wahlweise werden nur die verwalteten Ports gesperrt (Beta-Verhalten).
"""
from __future__ import annotations

import ipaddress
import json
import os
import pwd
import re
import stat
import subprocess
import time

E = os.environ.get
NFT = E('KIENZLE_FW_NFT', '/usr/sbin/nft')
SS = E('KIENZLE_FW_SS', '/usr/bin/ss')
REQUEST = E('KIENZLE_FW_REQUEST', '/var/lib/kienzle/core/anfragen/firewall.json')
CONFIRM = E('KIENZLE_FW_CONFIRM', '/var/lib/kienzle/core/anfragen/firewall-bestaetigt')
STATE = E('KIENZLE_FW_STATE', '/var/lib/kienzle/firewall')
RULES = E('KIENZLE_FW_RULES', '/etc/kienzle/firewall.nft')
MODULES = E('KIENZLE_FW_MODULES', '/var/lib/kienzle/core/modules.json')
WAIT = int(E('KIENZLE_FW_WAIT', '120'))
TABLE = 'kienzle'
WEB_PORT = 443
IPP_PORT = 631
SAMBA_PORT = 445

ALLOWED_RANGES = [ipaddress.ip_network(n) for n in
                  ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '169.254.0.0/16', 'fc00::/7', 'fe80::/10')]
CUSTOM = re.compile(r'^(tcp|udp) (\d{1,5})(?:-(\d{1,5}))?$')
MODULE_PORT = re.compile(r'^([a-z][a-z0-9-]{1,28})/([1-9]\d?)$')


class Rejected(Exception):
    pass


def app_uid() -> int:
    """UID des Webinterfaces (ks-core); nur dessen Anfragen werden angenommen. -1 = nicht prüfen (nur Tests)."""
    if 'KIENZLE_FW_UID' in os.environ:
        return int(os.environ['KIENZLE_FW_UID'])
    return pwd.getpwnam('ks-core').pw_uid


def status(state: str, message: str, **extra) -> None:
    os.makedirs(STATE, mode=0o755, exist_ok=True)
    data = {'state': state, 'message': message, 'time': int(time.time()), **extra}
    tmp = os.path.join(STATE, '.status.tmp')
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False)
    os.chmod(tmp, 0o644)
    os.replace(tmp, os.path.join(STATE, 'status.json'))


def read_small(path: str, uid: int, limit: int = 8192) -> str:
    """Nur echte, kleine Dateien des Webinterfaces lesen (kein Symlink, keine fremden Dateien)."""
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_size > limit:
            raise Rejected('Anfrage ungültig.')
        if uid >= 0 and info.st_uid != uid:
            raise Rejected('Anfrage stammt nicht vom Webinterface.')
        return os.read(fd, limit + 1).decode('utf-8', 'strict')
    finally:
        os.close(fd)


def clean_networks(values) -> list[str]:
    if not isinstance(values, list) or not values or len(values) > 20:
        raise Rejected('Es müssen 1 bis 20 Netze angegeben werden.')
    out = []
    for value in values:
        if not isinstance(value, str) or len(value) > 50:
            raise Rejected('Netz ungültig.')
        try:
            net = ipaddress.ip_network(value.strip(), strict=False)
        except ValueError:
            raise Rejected('Netz ungültig: ' + re.sub(r'[^0-9a-fA-F:./]', '', value)[:50]) from None
        if not any(net.version == r.version and net.subnet_of(r) for r in ALLOWED_RANGES):
            raise Rejected('Nur private Netze sind erlaubt: ' + str(net))
        if str(net) not in out:
            out.append(str(net))
    return out


def clean_custom(values) -> list[str]:
    if values is None:
        return []
    if not isinstance(values, list) or len(values) > 20:
        raise Rejected('Höchstens 20 eigene Ports.')
    out = []
    for v in values:
        m = CUSTOM.match(v) if isinstance(v, str) else None
        if not m:
            raise Rejected('Eigener Port ungültig (Beispiel: tcp 8080 oder udp 5000-5100).')
        lo, hi = int(m.group(2)), int(m.group(3) or m.group(2))
        if not 1 <= lo <= hi <= 65535:
            raise Rejected('Portnummer außerhalb 1–65535.')
        entry = f'{m.group(1)} {lo}' + (f'-{hi}' if hi != lo else '')
        if entry not in out:
            out.append(entry)
    return out


def module_ports(path: str = '') -> dict[str, dict]:
    """Freischaltbare Ports aller Module: „<modul>/<n>“ → {name, proto, from, to, scope}. Quelle: root-eigenes modules.json."""
    path = path or MODULES
    try:
        info = os.stat(path, follow_symlinks=False)
    except FileNotFoundError:
        return {}
    owner = int(E('KIENZLE_FW_MODULES_OWNER', '0'))   # nur Tests weichen ab
    if not stat.S_ISREG(info.st_mode) or info.st_uid != owner or info.st_mode & 0o022:
        raise Rejected('Modulverzeichnis ist nicht vertrauenswürdig (muss root gehören).')
    with open(path, encoding='utf-8') as f:
        data = json.load(f)
    out = {}
    for m in data.get('modules', []):
        mid = m['module']['id']
        for n, p in enumerate(m.get('firewall', []), 1):
            lo, hi = int(p['from']), int(p['to'])
            if p['proto'] not in ('tcp', 'udp') or p['scope'] not in ('nets', 'any') or not 1 <= lo <= hi <= 65535:
                raise Rejected('Modulverzeichnis enthält ungültige Ports.')
            out[f'{mid}/{n}'] = {'name': p['name'], 'proto': p['proto'], 'from': lo, 'to': hi, 'scope': p['scope']}
    return out


def clean_modules(values, catalog: dict[str, dict]) -> list[str]:
    if values is None:
        return []
    if not isinstance(values, list) or len(values) > 100 or not all(isinstance(v, str) and MODULE_PORT.match(v) and v in catalog for v in values):
        raise Rejected('Unbekannter Modul-Port.')
    return sorted(set(values))


def ssh_ports() -> list[int]:
    """Ports, auf denen sshd lauscht (aus ss, nur wenn der Prozess sshd heißt); sonst 22."""
    try:
        out = subprocess.run([SS, '-H', '-ltnp'], capture_output=True, text=True, timeout=10).stdout
    except (OSError, subprocess.SubprocessError):
        return [22]
    ports = set()
    for line in out.splitlines():
        if '"sshd' in line:
            m = re.search(r'[:\]](\d{1,5})\s', line)
            if m and 1 <= int(m.group(1)) <= 65535:
                ports.add(int(m.group(1)))
    return sorted(ports) or [22]


def port_sets(ssh, ipp, samba, modules: list[dict], custom: list[str]) -> dict:
    """Verwaltete Ports je (Bereich, Protokoll) als nft-Listen („80“, „1000-2000“)."""
    sets = {('nets', 'tcp'): [str(WEB_PORT)], ('nets', 'udp'): [], ('any', 'tcp'): [], ('any', 'udp'): []}
    sets[('nets', 'tcp')] += [str(p) for p in (ssh or [])] + ([str(IPP_PORT)] if ipp else []) + ([str(SAMBA_PORT)] if samba else [])
    for p in modules:
        sets[(p['scope'], p['proto'])].append(str(p['from']) if p['from'] == p['to'] else f'{p["from"]}-{p["to"]}')
    for entry in custom:
        proto, port = entry.split(' ')
        sets[('nets', proto)].append(port)

    def order(p):
        return int(p.split('-')[0])
    return {k: sorted(set(v), key=lambda p: (order(p), p)) for k, v in sets.items()}


def build_rules(nets, ssh=None, ipp=False, samba=False, strict=True, modules=(), custom=()) -> str:
    """Regeln aus festen Vorlagen. Alle Eingaben sind zuvor geprüft: Netze (ipaddress), Ports (int), Modul-Ports (Katalog)."""
    v4 = [n for n in nets if ':' not in n]
    v6 = [n for n in nets if ':' in n]
    sets = port_sets(ssh, ipp, samba, list(modules), list(custom))
    fmt = ', '.join
    lines = [f'table inet {TABLE}', f'delete table inet {TABLE}', f'table inet {TABLE} {{', '  chain input {',
             f'    type filter hook input priority -10; policy {"drop" if strict else "accept"};',
             '    iifname "lo" accept', '    ct state established,related accept', '    ct state invalid drop',
             '    meta l4proto { icmp, ipv6-icmp } accept']
    if strict:
        lines += ['    udp sport 67 udp dport 68 accept', '    ip6 saddr fe80::/10 udp dport 546 accept']
    for proto in ('tcp', 'udp'):
        if sets[('any', proto)]:
            lines.append(f'    {proto} dport {{ {fmt(sets[("any", proto)])} }} accept')
    for family, group in (('ip', v4), ('ip6', v6)):
        if not group:
            continue
        for proto in ('tcp', 'udp'):
            if sets[('nets', proto)]:
                lines.append(f'    {family} saddr {{ {fmt(group)} }} {proto} dport {{ {fmt(sets[("nets", proto)])} }} accept')
        if strict:
            lines.append(f'    {family} saddr {{ {fmt(group)} }} udp dport 5353 accept')
    if not strict:
        for proto in ('tcp', 'udp'):
            if sets[('nets', proto)]:
                lines.append(f'    {proto} dport {{ {fmt(sets[("nets", proto)])} }} drop')
    lines += ['  }', '}', '']
    return '\n'.join(lines)


def nft(*args, stdin=None):
    return subprocess.run([NFT, *args], input=stdin, capture_output=True, text=True, timeout=30)


def current_table():
    r = nft('list', 'table', 'inet', TABLE)
    return r.stdout if r.returncode == 0 else None


def restore(previous):
    if previous:
        nft('-f', '-', stdin=f'table inet {TABLE}\ndelete table inet {TABLE}\n' + previous)
    else:
        nft('delete', 'table', 'inet', TABLE)


def confirmed(since: float, uid: int) -> bool:
    try:
        fd = os.open(CONFIRM, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        return False
    try:
        info = os.fstat(fd)
        return stat.S_ISREG(info.st_mode) and info.st_mtime >= since - 1 and (uid < 0 or info.st_uid == uid)
    finally:
        os.close(fd)


def _unlink(path):
    try:
        os.unlink(path)
    except OSError:
        pass


def handle() -> None:
    """Eine Anfrage verarbeiten (ausgelöst durch die systemd-.path-Einheit)."""
    started = time.time()
    _unlink(CONFIRM)
    uid = app_uid()
    try:
        raw = read_small(REQUEST, uid)
    except OSError:
        raw = None      # fehlt oder ist ein Symlink/keine reguläre Datei: ohne Wirkung
    except Rejected as e:
        raw = e
    finally:
        _unlink(REQUEST)
    if raw is None:
        return
    try:
        if isinstance(raw, Rejected):
            raise raw
        request = json.loads(raw)
        if not isinstance(request, dict) or request.get('action') not in ('apply', 'disable'):
            raise Rejected('Anfrage ungültig.')
        if request['action'] == 'disable':
            nft('delete', 'table', 'inet', TABLE)
            _unlink(RULES)
            status('off', 'Firewall ausgeschaltet.')
            return
        catalog = module_ports()
        nets = clean_networks(request.get('cidrs'))
        ssh = ssh_ports() if request.get('ssh') is True else None
        ipp = request.get('ipp') is True
        samba = request.get('samba') is True
        strict = request.get('strict') is not False
        chosen = clean_modules(request.get('modules'), catalog)
        custom = clean_custom(request.get('custom'))
        rules = build_rules(nets, ssh, ipp, samba, strict, [catalog[c] for c in chosen], custom)
    except (Rejected, ValueError, UnicodeDecodeError, KeyError, TypeError) as e:
        status('failed', str(e) if isinstance(e, Rejected) else 'Anfrage ungültig.')
        return

    check = nft('-c', '-f', '-', stdin=rules)
    if check.returncode != 0:
        status('failed', 'Regeln werden von nftables abgelehnt: ' + check.stderr.strip()[:200])
        return
    previous = current_table()
    applied = nft('-f', '-', stdin=rules)
    if applied.returncode != 0:
        restore(previous)
        status('failed', 'Regeln konnten nicht geladen werden: ' + applied.stderr.strip()[:200])
        return
    summary = {'networks': nets, 'ssh': ssh is not None, 'ipp': ipp, 'samba': samba, 'strict': strict, 'modules': chosen, 'custom': custom,
               'ports': sorted({WEB_PORT, *(ssh or []), *([IPP_PORT] if ipp else []), *([SAMBA_PORT] if samba else [])})}
    deadline = int(time.time()) + WAIT
    status('pending', f'Firewall geladen. Bitte binnen {WAIT} Sekunden bestätigen, sonst wird sie zurückgesetzt.', deadline=deadline, **summary)
    while time.time() < deadline:
        if confirmed(started, uid):
            os.makedirs(os.path.dirname(RULES), exist_ok=True)
            tmp = RULES + '.neu'
            fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, 'w') as f:
                f.write(rules)
            os.replace(tmp, RULES)
            _unlink(CONFIRM)
            status('active', 'Firewall aktiv und bestätigt. Sie wird beim Start des Servers automatisch geladen.', **summary)
            return
        time.sleep(1)
    restore(previous)
    status('reverted', 'Nicht bestätigt: Die vorherige Firewall-Einstellung wurde wiederhergestellt.')


def boot() -> int:
    """Beim Start: bestätigte Regeln laden."""
    if os.path.isfile(RULES) and not os.path.islink(RULES):
        r = nft('-f', RULES)
        if r.returncode != 0:
            print('Firewall-Regeln konnten nicht geladen werden:', r.stderr.strip(), flush=True)
            return 1
    return 0
