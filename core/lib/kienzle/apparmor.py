"""Lokale AppArmor-Ergänzungen für Programme, die Module über `hooks.apparmor` anmelden (nur root).

Ubuntu 26.04 schränkt z. B. `gs` und `qpdf` per AppArmor ein. Braucht ein Modul sie für seinen Datenordner, ergänzt die
Basis `/etc/apparmor.d/local/<programm>` um einen eigenen, markierten Abschnitt (übernommen aus kienzle-suite-beta,
scripts/install-server.sh). Andere Zeilen der Datei bleiben unberührt; ohne Bedarf wird der Abschnitt entfernt.
"""
from __future__ import annotations

import os
import shutil
import subprocess
from pathlib import Path

from .manifest import APPARMOR

E = os.environ.get
DIR = Path(E('KIENZLE_APPARMOR_DIR', '/etc/apparmor.d'))
PARSER = E('KIENZLE_APPARMOR_PARSER', '/usr/sbin/apparmor_parser')
BEGIN, END = '# Kienzle-Suite Anfang (automatisch, nicht von Hand ändern)', '# Kienzle-Suite Ende'


def wanted(registry: dict) -> dict[str, list[str]]:
    out: dict[str, list[str]] = {p: [] for p in sorted(APPARMOR)}
    for m in registry.get('modules', []):
        for prog in (m.get('hooks') or {}).get('apparmor', []):
            if prog in out:
                out[prog].append(f'/var/lib/kienzle/{m["module"]["id"]}/** rwk,')
    return out


def section(rules: list[str]) -> str:
    return '\n'.join([BEGIN, *sorted(set(rules)), END]) + '\n' if rules else ''


def apply(registry: dict) -> list[str]:
    """Abschnitte schreiben; liefert die geänderten Programme. Ohne AppArmor (Container) geschieht nichts."""
    if not (DIR / 'local').is_dir():
        return []
    changed = []
    for prog, rules in wanted(registry).items():
        if not (DIR / prog).is_file():
            continue
        local = DIR / 'local' / prog
        old = local.read_text(encoding='utf-8') if local.is_file() else ''
        lines, inside = [], False
        for line in old.splitlines():
            if line == BEGIN:
                inside = True
            elif line == END:
                inside = False
            elif not inside:
                lines.append(line)
        new = ('\n'.join(lines) + '\n' if lines else '') + section(rules)
        if new == old:
            continue
        tmp = local.with_name('.' + prog + '.neu')
        tmp.write_text(new, encoding='utf-8')
        os.chmod(tmp, 0o644)
        os.replace(tmp, local)
        changed.append(prog)
        if shutil.which(PARSER) or Path(PARSER).is_file():
            subprocess.run([PARSER, '-r', str(DIR / prog)], capture_output=True, timeout=60)
    return changed
