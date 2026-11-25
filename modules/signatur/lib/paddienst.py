#!/usr/bin/env python3
"""Dauerdienst der Signatur: startet für jeden Vorgang „am Pad“ einen Arbeitsprozess (padauftrag.py), je Pad höchstens einen.

Die Pad-Bibliothek kann je Prozess nur ein Pad öffnen, deshalb ein Prozess je Pad. Läuft als ks-signatur (systemd)."""
from __future__ import annotations

import os
import signal
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import padauftrag  # noqa: E402

TAKT = 2.0
LAUFEND: dict[int, tuple[subprocess.Popen, int]] = {}  # pad_id -> (Prozess, Vorgang)
STOP = False


def halt(*_):
    global STOP
    STOP = True


def beim_start(c) -> None:
    """Nach einem Neustart läuft kein Arbeitsprozess mehr: Vorgänge „am Pad“ gehen auf „wartet“ zurück."""
    n = c.execute("UPDATE vorgaenge SET status='wartet', meldung='Dienst wurde neu gestartet. Bitte erneut am Pad zeigen.' WHERE status='am_pad'").rowcount
    c.commit()
    if n:
        padauftrag.log(f'{n} Vorgang/Vorgänge zurückgesetzt.')


def einmal(c, starten=None) -> None:
    """Ein Durchlauf: beendete Prozesse abräumen, für freie Pads den ältesten Vorgang „am Pad“ starten."""
    starten = starten or (lambda vid: subprocess.Popen([sys.executable, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'padauftrag.py'), str(vid)]))
    for pad_id, (proc, vid) in list(LAUFEND.items()):
        if proc.poll() is None:
            continue
        del LAUFEND[pad_id]
        # Endete der Prozess, ohne den Vorgang abzuschließen (Absturz), darf er nicht „am Pad“ hängen bleiben.
        if padauftrag.status_setzen(c, vid, 'fehler', 'Der Pad-Prozess ist unerwartet beendet worden.'):
            padauftrag.log(f'Vorgang {vid}: Prozess beendet (Code {proc.returncode}) ohne Ergebnis.')
    rows = c.execute("SELECT v.id, v.pad_id FROM vorgaenge v JOIN pads p ON p.id=v.pad_id WHERE v.status='am_pad' AND p.aktiv=1 ORDER BY v.erstellt, v.id").fetchall()
    for r in rows:
        if r['pad_id'] in LAUFEND:
            continue
        LAUFEND[r['pad_id']] = (starten(r['id']), r['id'])
        padauftrag.log(f"Vorgang {r['id']}: Pad {r['pad_id']} wird bedient.")


def main() -> int:
    signal.signal(signal.SIGTERM, halt)
    signal.signal(signal.SIGINT, halt)
    os.makedirs(padauftrag.DATEN, exist_ok=True)
    c = padauftrag.db_oeffnen(padauftrag.DATEN)
    beim_start(c)
    padauftrag.log('Pad-Dienst gestartet.')
    while not STOP:
        try:
            einmal(c)
        except Exception as exc:  # noqa: BLE001 - der Dienst darf wegen eines Fehlers nicht enden
            padauftrag.log(f'Fehler im Durchlauf: {exc}')
        time.sleep(TAKT)
    for proc, _ in LAUFEND.values():
        proc.terminate()
    return 0


if __name__ == '__main__':
    sys.exit(main())
