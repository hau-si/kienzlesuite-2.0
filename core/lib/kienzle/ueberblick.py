"""Einrichtungs-Überblicke der Module einsammeln (root-Dienst, Aufruf über core/bin/kienzle-ueberblick).

Der Haken `[setup] overview` läuft als Modul-Benutzer (`ks-<id>`), nie als root und nie im Webinterface. Seine Ausgabe
wird geprüft (Format: docs/MODULE-TOML.md) und als /var/lib/kienzle/ueberblick/<id>.json abgelegt; die Seite
Verwaltung → Einrichtung liest nur diese Dateien.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import time
from pathlib import Path

# runuser liegt in /usr/sbin; der Dienst hat nicht immer sbin im PATH: absoluten Pfad bestimmen.
RUNUSER_BIN = next((c for c in ('/usr/sbin/runuser', '/usr/bin/runuser', '/sbin/runuser') if os.path.exists(c)), 'runuser')

E = os.environ.get
STATE = Path(E('KIENZLE_UEBERBLICK_STATE', '/var/lib/kienzle/ueberblick'))
MODULES = Path(E('KIENZLE_UEBERBLICK_MODULES', '/var/lib/kienzle/core/modules.json'))
PROGRAMS = Path(E('KIENZLE_UEBERBLICK_PROGRAMME', '/opt/kienzle'))
REQUEST = Path(E('KIENZLE_UEBERBLICK_REQUEST', '/var/lib/kienzle/core/anfragen/ueberblick.json'))
RUNAS = E('KIENZLE_UEBERBLICK_RUNAS', '1') == '1'
STATES = {'done', 'open', 'error'}
MAX_ITEMS = 30


def check_output(raw: str, web_path: str | None) -> list[dict]:
    """Ausgabe eines Hakens prüfen; liefert die bereinigten Punkte oder wirft ValueError."""
    data = json.loads(raw)
    if not isinstance(data, dict) or not isinstance(data.get('items'), list) or len(data['items']) > MAX_ITEMS:
        raise ValueError('Format: {"items": [...]}, höchstens 30 Punkte.')
    out = []
    for item in data['items']:
        if not isinstance(item, dict) or item.get('state') not in STATES or not isinstance(item.get('title'), str):
            raise ValueError('Jeder Punkt braucht title und state (done, open, error).')
        title = re.sub(r'[\x00-\x1f\x7f]', '', item['title']).strip()[:120]
        hint = re.sub(r'[\x00-\x1f\x7f]', '', str(item.get('hint', ''))).strip()[:300]
        link = str(item.get('link', ''))
        if link and (not web_path or not (link == web_path or link.startswith(web_path.rstrip('/') + '/') or link.startswith(web_path.rstrip('/') + '#'))
                     or not re.fullmatch(r'/[A-Za-z0-9/_.#?=&%-]{0,300}', link) or '..' in link):
            raise ValueError(f'link muss mit dem eigenen Pfad {web_path} beginnen.')
        if not title:
            raise ValueError('title leer.')
        out.append({'title': title, 'state': item['state'], 'hint': hint, 'link': link})
    return out


def _write(path: Path, data: dict) -> None:
    STATE.mkdir(mode=0o755, parents=True, exist_ok=True)
    tmp = path.with_name('.' + path.name + '.neu')
    tmp.write_text(json.dumps(data, ensure_ascii=False), encoding='utf-8')
    os.chmod(tmp, 0o644)
    os.replace(tmp, path)


def collect() -> int:
    REQUEST.unlink(missing_ok=True)
    try:
        registry = json.loads(MODULES.read_text(encoding='utf-8')).get('modules', [])
    except FileNotFoundError:
        registry = []
    known = set()
    for m in registry:
        mid = m['module']['id']
        hook = (m.get('setup') or {}).get('overview')
        if not hook:
            continue
        known.add(mid)
        result = {'modul': mid, 'name': m['module'].get('name', mid), 'zeit': int(time.time()), 'items': [], 'fehler': ''}
        path = PROGRAMS / mid / 'current' / hook
        try:
            if not path.is_file():
                raise ValueError('Haken fehlt.')
            cmd = [RUNUSER_BIN, '-u', f'ks-{mid}', '--', str(path)] if RUNAS else [str(path)]
            r = subprocess.run(cmd, capture_output=True, text=True, timeout=20, env={'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8'})
            if r.returncode != 0:
                raise ValueError(f'Haken endete mit Fehler {r.returncode}.')
            if len(r.stdout) > 65536:
                raise ValueError('Ausgabe zu groß.')
            result['items'] = check_output(r.stdout, (m.get('web') or {}).get('path'))
        except (ValueError, OSError, subprocess.SubprocessError) as e:
            result['fehler'] = str(e)[:200]
        _write(STATE / f'{mid}.json', result)
    for f in STATE.glob('*.json') if STATE.is_dir() else []:
        if f.stem not in known:
            f.unlink()
    return len(known)


def handle() -> None:
    collect()
