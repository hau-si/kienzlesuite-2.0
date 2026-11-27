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
PDF_FEHLER: set[int] = set()
ABLAGE = {'proc': None, 'zuletzt': 0.0}
ABLAGE_TAKT = 10.0  # Sekunden zwischen zwei Ablage-Läufen (PHP: lib/haken.php ablage)  # Vorgänge, deren PDF sich nicht erzeugen ließ (nicht bei jedem Durchlauf erneut versuchen)


def halt(*_):
    global STOP
    STOP = True


def beim_start(c) -> None:
    """Nach einem Neustart läuft kein Arbeitsprozess mehr: Vorgänge „am Pad“ gehen auf „wartet“ zurück."""
    n = c.execute("UPDATE vorgaenge SET status='wartet', meldung='Dienst wurde neu gestartet. Bitte erneut am Pad zeigen.' WHERE status='am_pad'").rowcount
    c.commit()
    if n:
        padauftrag.log(f'{n} Vorgang/Vorgänge zurückgesetzt.')


def pdf_nachholen(c, ordner: str | None = None) -> int:
    """Unterschriebene Vorgänge ohne PDF (z. B. vor dieser Version unterschrieben): PDF erzeugen. Je Durchlauf höchstens einer."""
    ordner = ordner or os.path.join(padauftrag.DATEN, 'unterschriften')
    for r in c.execute("SELECT id FROM vorgaenge WHERE status IN ('unterschrieben','uebertragen') ORDER BY id DESC").fetchall():
        vid = int(r['id'])
        if vid in PDF_FEHLER or os.path.exists(os.path.join(ordner, f'{vid}.pdf')) or not os.path.isfile(os.path.join(ordner, f'{vid}.png')):
            continue
        if not padauftrag.pdf_erzeugen(c, vid, ordner):
            PDF_FEHLER.add(vid)
        return 1
    return 0


def ablage_offen(c) -> bool:
    """Unterschriebene Vorgänge, deren PDF noch in T2med abgelegt werden muss (direkt oder über die Kasse)."""
    try:
        return c.execute("SELECT COUNT(*) FROM vorgaenge WHERE status='unterschrieben' AND ablage IN ('','an_kasse')").fetchone()[0] > 0
    except Exception:  # noqa: BLE001 - Spalte fehlt, solange die Datenbank noch nicht auf Schema 8 ist
        return False


def ablage_anstossen(c, jetzt=time.monotonic, starten=None) -> bool:
    """Startet den Ablage-Lauf (PHP, braucht T2med-Zugang und Verschlüsselung der Suite) höchstens alle ABLAGE_TAKT Sekunden, nie doppelt."""
    proc = ABLAGE['proc']
    if proc is not None and proc.poll() is None:
        return False
    ABLAGE['proc'] = None
    if jetzt() - ABLAGE['zuletzt'] < ABLAGE_TAKT or not ablage_offen(c):
        return False
    ABLAGE['zuletzt'] = jetzt()
    starten = starten or (lambda: subprocess.Popen(['/usr/bin/php', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'haken.php'), 'ablage']))
    ABLAGE['proc'] = starten()
    return True


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
    pdf_nachholen(c)
    try:
        ablage_anstossen(c)
    except OSError as exc:
        padauftrag.log(f'Ablage nicht gestartet: {exc}')


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
