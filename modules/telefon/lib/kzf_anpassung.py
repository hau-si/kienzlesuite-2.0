"""Anpassungen von Kienzlefon 2.7 an das Modul telefon, ohne den Code von Thomas zu ändern (kienzlefon/HERKUNFT.md).

- Auftragsjournal der Rufgruppen im Datenordner (<daten>/kienzlefon/control) statt /var/lib/kienzlefon/control.
- Prozesskennung von Asterisk: Kienzlefon fragt systemd nach asterisk.service; das Modul betreibt eine eigene Instanz
  (kienzle-telefon-asterisk) mit -C <daten>/asterisk/etc/asterisk.conf. Gesucht wird deshalb der Asterisk-Prozess mit genau
  dieser Konfiguration (ohne Konsolenaufrufe mit -r/-rx). Das Format bleibt „<boot-id>:<pid>:<startzeit>“ wie bei Kienzlefon.
"""
from __future__ import annotations

import os
import re
from pathlib import Path

DATA = Path(os.environ.get('KIENZLE_DATEN', '/var/lib/kienzle/telefon'))
KONSOLE = {'-r', '-R', '-rx', '-x'}


def asterisk_prozess(conf: Path | None = None) -> str:
    from kienzlefon.telephony_control import SourceError
    conf = str(conf or DATA / 'asterisk' / 'etc' / 'asterisk.conf')
    boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
    treffer = []
    for p in Path('/proc').iterdir():
        if not p.name.isdigit():
            continue
        try:
            argv = (p / 'cmdline').read_bytes().split(b'\0')
            args = [a.decode('utf-8', 'replace') for a in argv if a]
            if not args or Path(args[0]).name != 'asterisk' or conf not in args or KONSOLE & set(args):
                continue
            name, _, tail = (p / 'stat').read_text().partition(') ')
            if name == f'{p.name} (asterisk' and len(tail.split()) > 19:
                treffer.append((int(p.name), tail.split()[19]))
        except OSError:
            continue
    if len(treffer) != 1 or not re.fullmatch(r'[0-9a-f-]{36}', boot):
        raise SourceError('Asterisk-Prozess des Moduls nicht eindeutig gefunden')
    return f'{boot}:{treffer[0][0]}:{treffer[0][1]}'


def anpassen() -> None:
    """Vor dem Laden von control_setup/control_api-Hauptprogramm aufrufen (kienzlefon muss im Suchpfad liegen)."""
    import kienzlefon.control_api as control_api
    import kienzlefon.telephony_ami as telephony_ami
    control_api.STATE_DIR = DATA / 'kienzlefon' / 'control'
    telephony_ami.process_key = asterisk_prozess
