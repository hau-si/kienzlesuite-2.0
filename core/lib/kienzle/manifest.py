"""Modul-Beschreibung `module.toml` lesen und streng prüfen.

Nur dieses Programm liest `module.toml`. Alle anderen Teile der Basis (Webinterface, root-Dienste, Installer) lesen
seine geprüfte, vereinheitlichte Ausgabe. Format und Regeln: docs/MODULE-TOML.md.

Grundsätze:
- Unbekannte Schlüssel und falsche Typen sind Fehler (Tippfehler fallen auf, nichts wird stillschweigend ignoriert).
- Alle Fehler werden gesammelt und gemeinsam gemeldet, jeweils mit dem Ort in der Datei.
- Fehlermeldungen geben fremde Eingaben nur gekürzt und von Steuerzeichen bereinigt wieder.
"""
from __future__ import annotations

import functools
import os
import re
import stat
import tomllib
from pathlib import Path

MAX_BYTES = 65536
BASE_DATA = '/var/lib/kienzle'
BASE_RUN = '/run/kienzle'

ID = re.compile(r'^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$')
ID_MIN = 2
ID_MAX = 29                     # Systembenutzer „ks-<id>“ höchstens 32 Zeichen
VERSION = re.compile(r'^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})(?:-([0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*))?$')
API = re.compile(r'^[1-9]\d{0,3}$')
PACKAGE = re.compile(r'^[a-z0-9][a-z0-9+.-]{1,62}$')
WEB_PATH = re.compile(r'^/[a-z0-9][a-z0-9-]{0,30}$')
RIGHT = re.compile(r'^[a-z][a-z0-9_]{0,30}$')
PRINTER = re.compile(r'^[A-Za-z0-9_-]{1,40}$')
REL_PATH = re.compile(r'^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$')
PORT = re.compile(r'^(\d{1,5})(?:-(\d{1,5}))?$')
CONTROL = re.compile(r'[\x00-\x1f\x7f-\x9f  ]')

# IDs, die Benutzer, Datenordner oder Dienste der Basis belegen.
RESERVED_IDS = frozenset({'core', 'kienzle', 'admin', 'ablage', 'sicherung', 'drucker', 'zertifikat', 'firewall',
                          'update', 'updates', 'beta', 'stabil', 'requests', 'run'})
# Pfade des Webinterfaces der Basis.
RESERVED_PATHS = frozenset({'/anmelden', '/abmelden', '/verwaltung', '/einrichtung', '/konto', '/hilfe', '/assets',
                            '/api', '/_auth', '/_health', '/core', '/kienzle', '/oidc', '/bestaetigen', '/ca.crt'})
# Ports, die die Basis selbst verwaltet: SSH, HTTPS, Samba (Drucker), IPP (CUPS).
RESERVED_PORTS = {('tcp', 22): 'SSH', ('tcp', 443): 'HTTPS der Basis', ('tcp', 445): 'Samba der Basis',
                  ('tcp', 631): 'CUPS der Basis', ('udp', 631): 'CUPS der Basis'}
RESERVED_RIGHTS = frozenset({'admin'})
APPARMOR = frozenset({'gs', 'qpdf'})
HOOKS = ('install', 'update', 'remove', 'busy', 'health')
# Zentrale Verbindungen der Basis, die ein Modul anmelden kann (Zugangsdaten bekommen nur anmeldende Module).
CONNECTIONS = ('t2med', 'mail', 'ablage', 'letterxpress')
SHARES = ('samba', 'ipp')
FORMAT = re.compile(r'^[a-z]+/[a-z0-9.+-]{1,60}$')

LIMITS = {'packages': 50, 'services': 20, 'firewall': 20, 'rights': 30, 'printers': 10, 'paths': 20,
          'connections': len(CONNECTIONS)}

SCHEMA_SECTIONS = {
    'module': {'id', 'name', 'version', 'core_api', 'description'},
    'system': {'packages', 'services', 'user', 'data'},
    'web': {'path', 'upstream', 'protocol', 'entry', 'starter', 'einstellungen'},
    'firewall': {'name', 'port', 'proto', 'scope'},
    'rights': {'id', 'label'},
    'backup': {'paths', 'snapshot', 'settings'},
    'printers': {'name', 'label', 'ppd', 'backend', 'command', 'formats', 'paper', 'share'},
    'connections': {'id', 'purpose', 'required'},
    'setup': {'overview'},
    'hooks': {*HOOKS, 'apparmor'},
}
TABLES = {'module', 'system', 'web', 'backup', 'setup', 'hooks'}
ARRAYS = {'firewall', 'rights', 'printers', 'connections'}
REQUIRED_TABLES = ('module', 'system')


@functools.cache
def supported_api() -> frozenset[str]:
    """API-Versionen, die diese Basis anbietet (Datei core/API, eine Version je Zeile)."""
    path = Path(__file__).resolve().parents[2] / 'API'
    versions = {line.strip() for line in path.read_text(encoding='utf-8').splitlines() if line.strip()}
    if not versions or not all(API.match(v) for v in versions):
        raise RuntimeError(f'{path}: ungültige API-Angabe')
    return frozenset(versions)


def shown(value, limit: int = 60) -> str:
    """Fremden Wert gekürzt und ohne Steuerzeichen für eine Fehlermeldung."""
    text = CONTROL.sub('?', str(value))
    return text if len(text) <= limit else text[:limit] + '…'


class Invalid(Exception):
    """module.toml ist ungültig; `errors` enthält alle gefundenen Fehler."""

    def __init__(self, errors: list[str]):
        super().__init__('\n'.join(errors))
        self.errors = errors


class _Checker:
    def __init__(self):
        self.errors: list[str] = []

    def error(self, where: str, message: str) -> None:
        self.errors.append(f'{where}: {message}')

    # ---- einfache Typen -------------------------------------------------------------------------------------
    def text(self, table: dict, key: str, where: str, *, required=True, limit=80):
        if key not in table:
            if required:
                self.error(where, f'„{key}“ fehlt.')
            return None
        value = table[key]
        if not isinstance(value, str):
            self.error(f'{where}.{key}', 'muss ein Text sein.')
            return None
        value = value.strip()
        if not value:
            self.error(f'{where}.{key}', 'darf nicht leer sein.')
            return None
        if CONTROL.search(value):
            self.error(f'{where}.{key}', 'enthält Steuerzeichen.')
            return None
        if len(value) > limit:
            self.error(f'{where}.{key}', f'ist zu lang (höchstens {limit} Zeichen).')
            return None
        return value

    def pattern(self, table: dict, key: str, where: str, regex: re.Pattern, example: str, *, required=True):
        if key not in table:
            if required:
                self.error(where, f'„{key}“ fehlt.')
            return None
        value = table[key]
        if not isinstance(value, str) or not regex.match(value):
            self.error(f'{where}.{key}', f'„{shown(value)}“ ist ungültig. Beispiel: {example}')
            return None
        return value

    def string_list(self, table: dict, key: str, where: str, limit: int):
        value = table.get(key, [])
        if not isinstance(value, list) or not all(isinstance(v, str) for v in value):
            self.error(f'{where}.{key}', 'muss eine Liste von Texten sein.')
            return []
        if len(value) > limit:
            self.error(f'{where}.{key}', f'höchstens {limit} Einträge.')
            return []
        seen = []
        for item in value:
            if item in seen:
                self.error(f'{where}.{key}', f'„{shown(item)}“ ist doppelt.')
            else:
                seen.append(item)
        return seen

    def rel_path(self, value, where: str):
        """Relativer Pfad im Modulordner: keine absoluten Pfade, kein „..“, nur einfache Zeichen."""
        if not isinstance(value, str) or len(value) > 200 or not REL_PATH.match(value) \
                or any(part in ('.', '..') for part in value.split('/')):
            self.error(where, f'„{shown(value)}“ ist kein gültiger Pfad im Modulordner. Beispiel: bin/install')
            return None
        return value

    def keys(self, table: dict, allowed: set, where: str) -> None:
        for key in table:
            if key not in allowed:
                self.error(where, f'unbekannter Schlüssel „{shown(key, 40)}“. Erlaubt: {", ".join(sorted(allowed))}.')

    # ---- Abschnitte -----------------------------------------------------------------------------------------
    def check(self, data: dict) -> dict | None:
        for key, value in data.items():
            if key not in SCHEMA_SECTIONS:
                self.error('module.toml', f'unbekannter Abschnitt „{shown(key, 40)}“.')
            elif key in TABLES and not isinstance(value, dict):
                self.error(key, f'muss ein Abschnitt [{key}] sein.')
            elif key in ARRAYS and not (isinstance(value, list) and all(isinstance(v, dict) for v in value)):
                self.error(key, f'muss als [[{key}]] angegeben werden.')
        for key in REQUIRED_TABLES:
            if not isinstance(data.get(key), dict):
                self.error('module.toml', f'Abschnitt [{key}] fehlt.')
        if self.errors:
            return None
        for key in data:
            for item in (data[key] if key in ARRAYS else [data[key]]):
                self.keys(item, SCHEMA_SECTIONS[key], key)

        out: dict = {}
        out['module'] = module = self.module(data['module'])
        mid = module.get('id')
        out['system'] = self.system(data['system'], mid)
        out['web'] = self.web(data['web'], mid) if 'web' in data else None
        out['firewall'] = self.firewall(data.get('firewall', []))
        out['rights'] = self.rights(data.get('rights', []))
        out['backup'] = self.backup(data['backup'], mid) if 'backup' in data else None
        out['printers'] = self.printers(data.get('printers', []))
        out['connections'] = self.connections(data.get('connections', []))
        out['setup'] = self.setup(data.get('setup', {}))
        out['hooks'] = self.hooks(data.get('hooks', {}))
        return None if self.errors else out

    def module(self, t: dict) -> dict:
        out = {}
        mid = self.pattern(t, 'id', 'module', ID, 'sumup oder kienzle-zeit')
        if mid is not None:
            if not ID_MIN <= len(mid) <= ID_MAX:
                self.error('module.id', f'{ID_MIN} bis {ID_MAX} Zeichen.')
            elif mid in RESERVED_IDS:
                self.error('module.id', f'„{mid}“ ist für die Basis reserviert.')
            else:
                out['id'] = mid
        out['name'] = self.text(t, 'name', 'module')
        out['version'] = self.pattern(t, 'version', 'module', VERSION, '2.0.0 oder 2.1.0-beta.3')
        api = self.pattern(t, 'core_api', 'module', API, '"1" (als Text)')
        if api is not None and api not in supported_api():
            self.error('module.core_api', f'Version {api} wird von dieser Basis nicht unterstützt '
                                          f'(unterstützt: {", ".join(sorted(supported_api()))}).')
        out['core_api'] = api
        out['description'] = self.text(t, 'description', 'module', required=False, limit=300)
        return out

    def system(self, t: dict, mid: str | None) -> dict:
        packages = self.string_list(t, 'packages', 'system', LIMITS['packages'])
        for p in packages:
            if not PACKAGE.match(p):
                self.error('system.packages', f'Paketname „{shown(p)}“ ist ungültig.')
        services = self.string_list(t, 'services', 'system', LIMITS['services'])
        if mid:
            unit = re.compile(r'^kienzle-' + re.escape(mid) + r'(?:-[a-z0-9]+)*\.(?:service|timer|path|socket)$')
            for s in services:
                if not unit.match(s):
                    self.error('system.services', f'„{shown(s)}“ muss „kienzle-{mid}-….service“ heißen.')
        user = self.text(t, 'user', 'system', limit=32)
        if user is not None and mid and user != f'ks-{mid}':
            self.error('system.user', f'muss „ks-{mid}“ sein.')
        data = self.text(t, 'data', 'system', limit=100)
        if data is not None and mid and data != f'{BASE_DATA}/{mid}':
            self.error('system.data', f'muss „{BASE_DATA}/{mid}“ sein.')
        return {'packages': packages, 'services': services, 'user': user, 'data': data}

    def web(self, t: dict, mid: str | None) -> dict:
        path = self.pattern(t, 'path', 'web', WEB_PATH, '/kasse')
        if path in RESERVED_PATHS:
            self.error('web.path', f'„{path}“ ist für die Basis reserviert.')
        upstream = self.text(t, 'upstream', 'web', limit=100)
        if upstream is not None and mid and upstream != f'unix:{BASE_RUN}/{mid}/web.sock':
            self.error('web.upstream', f'muss „unix:{BASE_RUN}/{mid}/web.sock“ sein.')
        protocol = t.get('protocol', 'http')
        if protocol not in ('http', 'fastcgi'):
            self.error('web.protocol', 'muss „http“ oder „fastcgi“ sein.')
        entry = None
        if protocol == 'fastcgi':
            if 'entry' not in t:
                self.error('web', '„entry“ fehlt (Einstiegsdatei für FastCGI, z. B. public/index.php).')
            else:
                entry = self.rel_path(t['entry'], 'web.entry')
        elif 'entry' in t:
            self.error('web.entry', 'nur mit protocol = "fastcgi".')
        starter = t.get('starter', False)
        if not isinstance(starter, bool):
            self.error('web.starter', 'muss true oder false sein.')
            starter = False
        settings = t.get('einstellungen')
        if settings is not None and (not isinstance(settings, str) or not re.fullmatch(r'/|/[A-Za-z0-9][A-Za-z0-9._-]{0,60}(/[A-Za-z0-9][A-Za-z0-9._-]{0,60}){0,3}', settings)):
            self.error('web.einstellungen', 'muss ein Pfad unter dem Webpfad sein, z. B. „/settings.php“.')
            settings = None
        return {'path': path, 'upstream': upstream, 'protocol': protocol, 'entry': entry, 'starter': starter, 'einstellungen': settings}

    def firewall(self, items: list) -> list:
        if len(items) > LIMITS['firewall']:
            self.error('firewall', f'höchstens {LIMITS["firewall"]} Einträge.')
            return []
        out = []
        for n, t in enumerate(items, 1):
            where = f'firewall[{n}]'
            name = self.text(t, 'name', where)
            proto = t.get('proto')
            if proto not in ('tcp', 'udp'):
                self.error(f'{where}.proto', 'muss „tcp“ oder „udp“ sein.')
                proto = None
            scope = t.get('scope', 'nets')
            if scope not in ('nets', 'any'):
                self.error(f'{where}.scope', 'muss „nets“ (nur Praxisnetze) oder „any“ (von überall) sein.')
            low = high = None
            port = t.get('port')
            if isinstance(port, bool):
                port = None
            if isinstance(port, int):
                low = high = port
            elif isinstance(port, str) and (m := PORT.match(port)):
                low, high = int(m.group(1)), int(m.group(2) or m.group(1))
            if low is None or not 1 <= low <= high <= 65535:
                self.error(f'{where}.port', f'„{shown(port)}“ ist ungültig. Beispiel: 8766 oder "10000-20000" (1–65535).')
                continue
            if proto:
                for (rp, rport), label in RESERVED_PORTS.items():
                    if rp == proto and low <= rport <= high:
                        self.error(f'{where}.port', f'{proto} {rport} gehört der Basis ({label}).')
            for other in out:
                if other['proto'] == proto and other['from'] <= high and low <= other['to']:
                    self.error(f'{where}.port', f'überschneidet sich mit „{other["name"]}“.')
            out.append({'name': name, 'proto': proto, 'from': low, 'to': high, 'scope': scope})
        return out

    def rights(self, items: list) -> list:
        if len(items) > LIMITS['rights']:
            self.error('rights', f'höchstens {LIMITS["rights"]} Einträge.')
            return []
        out, seen = [], set()
        for n, t in enumerate(items, 1):
            where = f'rights[{n}]'
            rid = self.pattern(t, 'id', where, RIGHT, 'paid')
            if rid in RESERVED_RIGHTS:
                self.error(f'{where}.id', f'„{rid}“ ist reserviert (Admin-Rechte vergibt die Basis).')
            elif rid in seen:
                self.error(f'{where}.id', f'„{rid}“ ist doppelt.')
            seen.add(rid)
            out.append({'id': rid, 'label': self.text(t, 'label', where)})
        return out

    def backup(self, t: dict, mid: str | None) -> dict:
        if 'paths' not in t:
            self.error('backup', '„paths“ fehlt.')
        raw = self.string_list(t, 'paths', 'backup', LIMITS['paths'])
        if 'paths' in t and t['paths'] == []:
            self.error('backup.paths', 'mindestens ein Pfad.')
        paths = self.data_paths(raw, mid, 'backup.paths')
        # Einstellungen des Moduls (Dateien oder Ordner): gehen in den zentralen, verschlüsselten Export der Einstellungen.
        settings = self.data_paths(self.string_list(t, 'settings', 'backup', LIMITS['paths']), mid, 'backup.settings') if 'settings' in t else []
        snapshot = self.rel_path(t['snapshot'], 'backup.snapshot') if 'snapshot' in t else None
        return {'paths': paths, 'snapshot': snapshot, 'settings': settings}

    def data_paths(self, raw: list, mid: str | None, where: str) -> list:
        out = []
        if mid:
            base = f'{BASE_DATA}/{mid}'
            for p in raw:
                parts = p.split('/')
                if not p.startswith('/') or '..' in parts or '.' in parts or '' in parts[1:] \
                        or not (p == base or p.startswith(base + '/')) or CONTROL.search(p):
                    self.error(where, f'„{shown(p)}“ liegt nicht im Datenordner {base}.')
                else:
                    out.append(p)
        return out

    def printers(self, items: list) -> list:
        if len(items) > LIMITS['printers']:
            self.error('printers', f'höchstens {LIMITS["printers"]} Einträge.')
            return []
        out, seen = [], set()
        for n, t in enumerate(items, 1):
            where = f'printers[{n}]'
            name = self.pattern(t, 'name', where, PRINTER, 'vKasse')
            if name is not None and name.lower() in seen:
                self.error(f'{where}.name', f'„{name}“ ist doppelt.')
            seen.add((name or '').lower())
            label = self.text(t, 'label', where)
            # Zwei Arten: IPP-Everywhere-Drucker (command, empfohlen) oder CUPS-Warteschlange mit PPD (ppd, optional backend).
            ppd = command = None
            if 'command' in t and 'ppd' in t:
                self.error(where, 'entweder „command“ (IPP Everywhere) oder „ppd“, nicht beides.')
            elif 'command' in t:
                command = self.rel_path(t['command'], f'{where}.command')
                if 'backend' in t:
                    self.error(f'{where}.backend', 'nur zusammen mit „ppd“.')
            elif 'ppd' in t:
                ppd = self.rel_path(t['ppd'], f'{where}.ppd')
            else:
                self.error(where, '„command“ oder „ppd“ fehlt.')
            formats = self.string_list(t, 'formats', where, 5) if 'formats' in t else (['application/pdf'] if command else [])
            for f in formats:
                if not FORMAT.match(f):
                    self.error(f'{where}.formats', f'„{shown(f)}“ ist kein Dateityp (z. B. application/pdf).')
            if formats and not command:
                self.error(f'{where}.formats', 'nur zusammen mit „command“.')
            paper = str(t.get('paper', 'A4')) if command else None
            if 'paper' in t and (not command or paper not in ('A4', 'A5', 'A6')):
                self.error(f'{where}.paper', 'nur zusammen mit „command“; erlaubt: A4, A5, A6.')
            backend = self.rel_path(t['backend'], f'{where}.backend') if 'backend' in t and ppd else None
            share = self.string_list(t, 'share', where, len(SHARES))
            for s in share:
                if s not in SHARES:
                    self.error(f'{where}.share', f'„{shown(s)}“ unbekannt. Erlaubt: {", ".join(SHARES)}.')
            out.append({'name': name, 'label': label, 'ppd': ppd, 'backend': backend, 'command': command, 'formats': formats, 'paper': paper, 'share': share})
        return out

    def connections(self, items: list) -> list:
        if len(items) > LIMITS['connections']:
            self.error('connections', f'höchstens {LIMITS["connections"]} Einträge.')
            return []
        out, seen = [], set()
        for n, t in enumerate(items, 1):
            where = f'connections[{n}]'
            cid = t.get('id')
            if cid not in CONNECTIONS:
                self.error(f'{where}.id', f'„{shown(cid)}“ unbekannt. Erlaubt: {", ".join(CONNECTIONS)}.')
                cid = None
            elif cid in seen:
                self.error(f'{where}.id', f'„{cid}“ ist doppelt.')
            seen.add(cid)
            purpose = self.text(t, 'purpose', where, limit=120)
            required = t.get('required', True)
            if not isinstance(required, bool):
                self.error(f'{where}.required', 'muss true oder false sein.')
            out.append({'id': cid, 'purpose': purpose, 'required': required is True})
        return out

    def setup(self, t: dict) -> dict:
        return {'overview': self.rel_path(t['overview'], 'setup.overview') if 'overview' in t else None}

    def hooks(self, t: dict) -> dict:
        out = {h: (self.rel_path(t[h], f'hooks.{h}') if h in t else None) for h in HOOKS}
        apparmor = self.string_list(t, 'apparmor', 'hooks', len(APPARMOR))
        for prog in apparmor:
            if prog not in APPARMOR:
                self.error('hooks.apparmor', f'„{shown(prog)}“ ist nicht freigegeben. Erlaubt: {", ".join(sorted(APPARMOR))}.')
        out['apparmor'] = apparmor
        return out


def check_text(text: str) -> dict:
    """Inhalt einer module.toml prüfen (ohne Dateien). Liefert die vereinheitlichte Beschreibung oder wirft Invalid."""
    if len(text.encode('utf-8')) > MAX_BYTES:
        raise Invalid([f'module.toml: größer als {MAX_BYTES // 1024} KiB.'])
    try:
        data = tomllib.loads(text)
    except tomllib.TOMLDecodeError as e:
        raise Invalid([f'module.toml: kein gültiges TOML ({shown(e, 120)}).']) from None
    checker = _Checker()
    result = checker.check(data)
    if result is None:
        raise Invalid(checker.errors)
    return result


def _referenced(manifest: dict) -> list[tuple[str, str, bool]]:
    """(Ort, relativer Pfad, muss ausführbar sein) aller Dateien, auf die die Beschreibung verweist."""
    refs = []
    for hook in HOOKS:
        if manifest['hooks'][hook]:
            refs.append((f'hooks.{hook}', manifest['hooks'][hook], True))
    if manifest['backup'] and manifest['backup']['snapshot']:
        refs.append(('backup.snapshot', manifest['backup']['snapshot'], True))
    if manifest['setup']['overview']:
        refs.append(('setup.overview', manifest['setup']['overview'], True))
    if manifest['web'] and manifest['web']['entry']:
        refs.append(('web.entry', manifest['web']['entry'], False))
    for n, p in enumerate(manifest['printers'], 1):
        if p['ppd']:
            refs.append((f'printers[{n}].ppd', p['ppd'], False))
        if p.get('command'):
            refs.append((f'printers[{n}].command', p['command'], True))
        if p['backend']:
            refs.append((f'printers[{n}].backend', p['backend'], True))
    return refs


def _check_file(root: Path, rel: str, executable: bool) -> str | None:
    """Datei im Modulordner: jeder Teil des Pfads ohne Verweis (Symlink), am Ende eine reguläre Datei."""
    current = root
    for part in rel.split('/'):
        current = current / part
        try:
            info = os.lstat(current)
        except FileNotFoundError:
            return f'Datei „{rel}“ fehlt.'
        except OSError:
            return f'Datei „{rel}“ ist nicht lesbar.'
        if stat.S_ISLNK(info.st_mode):
            return f'„{rel}“ enthält einen Verweis (Symlink); erlaubt sind nur echte Dateien und Ordner.'
    if not stat.S_ISREG(info.st_mode):
        return f'„{rel}“ ist keine Datei.'
    if executable and not info.st_mode & 0o100:
        return f'„{rel}“ ist nicht ausführbar.'
    if info.st_mode & 0o022:
        return f'„{rel}“ ist für Gruppe oder andere beschreibbar.'
    return None


def check_folder(folder: str | os.PathLike) -> dict:
    """Modulordner prüfen: module.toml, Ordnername = ID, alle genannten Dateien vorhanden und sicher."""
    root = Path(folder)
    path = root / 'module.toml'
    try:
        info = os.lstat(path)
    except OSError:
        raise Invalid([f'{root}: module.toml fehlt.']) from None
    if not stat.S_ISREG(info.st_mode):
        raise Invalid([f'{root}: module.toml ist keine echte Datei.'])
    if info.st_size > MAX_BYTES:
        raise Invalid([f'module.toml: größer als {MAX_BYTES // 1024} KiB.'])
    try:
        text = path.read_text(encoding='utf-8')
    except UnicodeDecodeError:
        raise Invalid(['module.toml: keine UTF-8-Datei.']) from None
    manifest = check_text(text)
    errors = []
    mid = manifest['module']['id']
    if root.resolve().name != mid:
        errors.append(f'module.id: Der Modulordner muss „{mid}“ heißen (ist „{shown(root.resolve().name)}“).')
    for where, rel, executable in _referenced(manifest):
        problem = _check_file(root, rel, executable)
        if problem:
            errors.append(f'{where}: {problem}')
    if errors:
        raise Invalid(errors)
    return manifest


def check_all(modules_dir: str | os.PathLike) -> dict:
    """Alle Unterordner mit module.toml prüfen, dazu Konflikte zwischen Modulen.

    Ergebnis ist das Modulverzeichnis, das die Basis als modules.json ablegt."""
    root = Path(modules_dir)
    return check_list(sorted(p for p in root.iterdir() if p.is_dir() and not p.is_symlink() and not p.name.startswith('.')))


def installed_folders(programs: str | os.PathLike = '/opt/kienzle') -> list[Path]:
    """Ordner der installierten Module: /opt/kienzle/<id>/current (Verweis auf releases/<version>/<id>), ohne die Basis."""
    root = Path(programs)
    out = []
    for p in sorted(root.iterdir()) if root.is_dir() else []:
        if p.name != 'core' and ID.match(p.name) and not p.is_symlink() and (p / 'current' / 'module.toml').is_file():
            out.append(p / 'current')
    return out


def check_list(folders: list) -> dict:
    """Die genannten Modulordner prüfen, dazu Konflikte zwischen ihnen (Ergebnis wie check_all)."""
    errors, manifests = [], []
    for folder in folders:
        folder = Path(folder)
        try:
            manifests.append(check_folder(folder))
        except Invalid as e:
            errors += [f'{folder.resolve().name}: {line}' for line in e.errors]
    seen: dict[tuple, str] = {}

    def claim(key: tuple, owner: str, message: str):
        if key in seen and seen[key] != owner:
            errors.append(f'{owner}: {message} (schon verwendet von „{seen[key]}“).')
        seen.setdefault(key, owner)

    ports: list[tuple[str, dict]] = []
    for m in manifests:
        mid = m['module']['id']
        claim(('id', mid), mid, 'doppelte Modul-ID')
        if m['web']:
            claim(('path', m['web']['path']), mid, f'Webpfad {m["web"]["path"]}')
            if m['web']['starter']:
                claim(('starter',), mid, 'Arbeitsplatz-Starter (/api.php)')
        for p in m['printers']:
            claim(('printer', p['name'].lower()), mid, f'Drucker „{p["name"]}“')
        for f in m['firewall']:
            for owner, other in ports:
                if owner != mid and other['proto'] == f['proto'] and other['from'] <= f['to'] and f['from'] <= other['to']:
                    errors.append(f'{mid}: Port {f["proto"]} {f["from"]}-{f["to"]} überschneidet sich mit „{owner}“.')
            ports.append((mid, f))
    if errors:
        raise Invalid(errors)
    return {'core_api': sorted(supported_api()), 'modules': manifests}
