#!/usr/bin/env python3
"""Ablauftest für das signotec Delta: spielt den Ablauf des Pad-Dienstes (Textseiten, Weiter, Unterschrift, OK) mit einem
Testvorgang in einer Wegwerf-Datenbank nach. Es wird nichts in der echten Datenbank verändert.

Aufruf (Pad-Dienst vorher anhalten):  sudo -u ks-signatur python3 padablauf.py <IP> [Port]
Danach:  sudo systemctl start kienzle-signatur-pad

Fehlersuche (Umgebungsvariable PADABLAUF): direkt = ohne Textseiten, gleich die Unterschriftsseite (ohne vorherigen Tastendruck am Pad)."""
from __future__ import annotations

import os
import shutil
import sqlite3
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import padauftrag  # noqa: E402
import padseiten  # noqa: E402
import padtest  # noqa: E402


def main(argv: list[str]) -> int:
    if not argv:
        print(__doc__)
        return 2
    ip, port = argv[0], int(argv[1]) if len(argv) > 1 else 1002
    ordner = os.path.join(padauftrag.DATEN, 'signotec')
    if ordner not in os.environ.get('LD_LIBRARY_PATH', '').split(':'):
        try:
            padtest.tiff_verweis(ordner)
        except OSError as exc:
            padauftrag.log(f'Hinweis: libtiff.so.5 nicht anlegbar ({exc})')
        os.environ['LD_LIBRARY_PATH'] = ordner + ':' + os.environ.get('LD_LIBRARY_PATH', '')
        os.execv(sys.executable, [sys.executable, os.path.abspath(__file__), *argv])
    os.chdir(ordner)
    if 'direkt' in os.environ.get('PADABLAUF', '').split(','):
        padseiten.textseiten = lambda titel, text: []
        padauftrag.log('Variante direkt: nur die Unterschriftsseite.')
    tmp = tempfile.mkdtemp(prefix='padablauf-')
    geraet = None
    try:
        c = sqlite3.connect(os.path.join(tmp, 'test.db'))
        c.row_factory = sqlite3.Row
        c.execute('CREATE TABLE vorgaenge (id INTEGER PRIMARY KEY, status TEXT, meldung TEXT, unterschrieben INTEGER, titel TEXT, text TEXT, patient TEXT, pad_id INTEGER)')
        text = '\n\n'.join(f'Absatz {i}: Dies ist ein Testtext für den Ablauftest am Pad. ' * 4 for i in range(1, 15))
        c.execute("INSERT INTO vorgaenge VALUES (1, 'am_pad', '', 0, 'Ablauftest', ?, 'Testpatient', 1)", (text,))
        c.commit()
        v = c.execute('SELECT * FROM vorgaenge WHERE id=1').fetchone()
        geraet = padauftrag.PadGeraet(ordner, ip, port, os.path.join(tmp, 'pad'))
        padauftrag.log('Bitte am Pad: mit „Weiter“ durch die Seiten, dann unterschreiben und „OK“.')
        ergebnis = padauftrag.ablauf(c, v, geraet, os.path.join(tmp, 'unterschriften'), leerlauf=180)
        padauftrag.log(f'Ergebnis: {ergebnis}; Meldung: {c.execute("SELECT meldung FROM vorgaenge WHERE id=1").fetchone()[0]!r}')
        return 0 if ergebnis == 'unterschrieben' else 1
    except Exception as exc:  # noqa: BLE001
        padauftrag.log(f'FEHLER {exc}')
        return 1
    finally:
        if geraet:
            geraet.schliessen()
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
