#!/usr/bin/env python3
"""Arbeitsprozess für einen Vorgang am Pad: Text seitenweise zeigen, Unterschrift aufnehmen, Ergebnis speichern.

Aufruf (vom Dienst): python3 padauftrag.py <Vorgangs-Nummer>
Ablauf und Gerät sind getrennt: ablauf() steuert nur über das Gerät (PadGeraet für das echte Pad, in den Tests ein Ersatz)."""
from __future__ import annotations

import ctypes
import os
import sqlite3
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import padseiten  # noqa: E402

DATEN = os.environ.get('KIENZLE_SIGNATUR_DATEN', '/var/lib/kienzle/signatur')
LEERLAUF = 600  # Sekunden ohne Bedienung am Pad, dann geht der Vorgang zurück auf „wartet“
EV_DISCONNECT, EV_HOTSPOT, EV_SIGNATUR = 0, 1, 4


def log(text: str) -> None:
    print(time.strftime('%H:%M:%S ') + text, flush=True)


def db_oeffnen(daten: str) -> sqlite3.Connection:
    c = sqlite3.connect(os.path.join(daten, 'signatur.db'), timeout=5)
    c.row_factory = sqlite3.Row
    c.execute('PRAGMA busy_timeout=5000')
    return c


def status_setzen(c: sqlite3.Connection, vid: int, status: str, meldung: str, nur_wenn: str = 'am_pad', zeit: int = 0) -> bool:
    """Setzt Status und Meldung, aber nur, wenn der Vorgang noch im erwarteten Zustand ist (Abbrechen von der Seite gewinnt)."""
    cur = c.execute('UPDATE vorgaenge SET status=?, meldung=?, unterschrieben=? WHERE id=? AND status=?', (status, meldung, zeit, vid, nur_wenn))
    c.commit()
    return cur.rowcount == 1


class Abbruch(Exception):
    """Der Vorgang wurde auf der Seite abgebrochen oder zurückgenommen: Pad räumen, nichts mehr eintragen."""


def ablauf(c: sqlite3.Connection, vorgang: sqlite3.Row, geraet, ausgabe: str, jetzt=time.time, leerlauf: int = LEERLAUF) -> str:
    """Führt den Vorgang am Gerät aus. Rückgabe: 'unterschrieben', 'zurueck' (am Pad abgebrochen/Zeit abgelaufen), 'abgebrochen' (Seite) oder 'fehler'."""
    vid = int(vorgang['id'])
    text_seiten = padseiten.textseiten(vorgang['titel'], vorgang['text'])
    sign = padseiten.unterschriftsseite(vorgang['titel'], vorgang['patient'])
    seiten = [*text_seiten, sign]
    letzte = len(seiten) - 1
    idx = 0
    zuletzt = jetzt()

    def zeigen(i: int) -> dict:
        s = seiten[i]
        geraet.zeige(s['bild'])
        ids = geraet.knoepfe(s['knoepfe'])
        if i == letzte:
            geraet.unterschrift_starten(sign['feld'])
        return {ids[n]: n for n in ids}

    namen = zeigen(0)
    while True:
        if c.execute('SELECT status FROM vorgaenge WHERE id=?', (vid,)).fetchone()['status'] != 'am_pad':
            geraet.raeumen()
            return 'abgebrochen'
        if jetzt() - zuletzt > leerlauf:
            geraet.raeumen()
            status_setzen(c, vid, 'wartet', 'Am Pad nicht unterschrieben (Zeit abgelaufen).')
            return 'zurueck'
        ev = geraet.ereignis(1.0)
        if ev is None:
            continue
        art, wert = ev
        zuletzt = jetzt()
        if art == EV_DISCONNECT:
            status_setzen(c, vid, 'fehler', 'Verbindung zum Pad getrennt.')
            return 'fehler'
        if art != EV_HOTSPOT or wert not in namen:
            log(f'Vorgang {vid}: Ereignis {art}/{wert} ignoriert (bekannte Tasten: {sorted(namen)})')
            continue
        taste = namen[wert]
        log(f'Vorgang {vid}: Taste {taste} (Seite {idx + 1} von {len(seiten)})')
        if taste == 'Abbrechen':
            geraet.raeumen()
            status_setzen(c, vid, 'wartet', 'Am Pad abgebrochen.')
            return 'zurueck'
        if taste == 'Weiter':
            idx += 1
            namen = zeigen(idx)
        elif taste == 'Zurück':
            if idx == letzte:
                geraet.unterschrift_abbrechen()
            idx -= 1
            namen = zeigen(idx)
        elif taste == 'Löschen':
            geraet.unterschrift_neu()
        elif taste == 'OK':
            ergebnis = geraet.bestaetigen(ausgabe, vid)
            if ergebnis is None:
                # Noch nichts unterschrieben oder die Bibliothek meldet einen Fehler: weiter warten, Grund an der Liste zeigen.
                hinweis = getattr(geraet, 'hinweis', '') or 'Bitte zuerst im Feld unterschreiben.'
                c.execute('UPDATE vorgaenge SET meldung=? WHERE id=? AND status="am_pad"', (hinweis[:300], vid))
                c.commit()
                continue
            geraet.danke(padseiten.hinweisseite('Vielen Dank'))
            status_setzen(c, vid, 'unterschrieben', '', zeit=int(jetzt()))
            return 'unterschrieben'


class PadGeraet:
    """Echtes Pad über die signotec-Bibliothek (ctypes)."""

    def __init__(self, ordner: str, adresse: str, port: int, temp: str):
        import padtest
        self.pt = padtest
        self.temp = temp
        os.makedirs(temp, mode=0o700, exist_ok=True)
        self.lib = padtest.lade(ordner)
        L, W, I = self.lib, ctypes.c_wchar_p, ctypes.c_int
        cb_typ = ctypes.CFUNCTYPE(None, ctypes.c_int, ctypes.c_void_p, ctypes.c_int, ctypes.c_void_p)
        for name, args in {
            'STDisplaySetTarget': [I], 'STControlSetCallback': [cb_typ, ctypes.c_void_p], 'STDisplaySetImageFromFile': [I, I, W],
            'STSensorSetSignRect': [I, I, I, I], 'STSensorAddHotSpot': [I, I, I, I], 'STSensorClearHotSpots': [], 'STSignatureStart': [],
            'STSignatureRetry': [], 'STSignatureConfirm': [], 'STSignatureStop': [], 'STSignatureGetState': [], 'STSignatureCancel': [I], 'STSignatureGetSignData': [ctypes.c_void_p, ctypes.POINTER(I)],
            'STSignatureSaveAsFileEx': [W, I, I, I, I, I, ctypes.c_uint, I],
        }.items():
            f = getattr(L, name)
            f.argtypes = args
            f.restype = None if name == 'STControlSetCallback' else I
        self.queue: list[tuple[int, int]] = []
        self.hinweis = ''
        import threading
        self.signal = threading.Event()

        self.zaehler: dict[int, int] = {}
        self.stift: dict = {}

        def rueckruf(art, daten, groesse, _):
            self.zaehler[art] = self.zaehler.get(art, 0) + 1
            if art == EV_SIGNATUR:
                # Stiftpunkte nicht in die Ereignisliste (sonst ein Eintrag je Punkt); nur Bereich merken (x, y, Druck) für das Protokoll.
                if daten and groesse >= 12:
                    x, y, druck = ctypes.cast(daten, ctypes.POINTER(ctypes.c_int))[0:3]
                    k = self.stift
                    k['n'] = k.get('n', 0) + 1
                    k['x'] = (min(k.get('x', (x, x))[0], x), max(k.get('x', (x, x))[1], x))
                    k['y'] = (min(k.get('y', (y, y))[0], y), max(k.get('y', (y, y))[1], y))
                    k['druck'] = max(k.get('druck', 0), druck)
                return
            wert = ctypes.cast(daten, ctypes.POINTER(ctypes.c_int))[0] if daten and groesse > 0 else 0
            self.queue.append((art, wert))
            self.signal.set()
        self._cb = cb_typ(rueckruf)
        self.pruefen('Suche', L.STDeviceSetComPort(f'IP={adresse}:{port}'))
        if L.STDeviceGetCount() < 1:
            raise RuntimeError(f'Pad nicht erreichbar ({adresse}:{port}). Läuft es im Ethernet-Modus und stimmen Adresse und Port?')
        self.pruefen('Pad öffnen', L.STDeviceOpen(0, True))
        L.STControlSetCallback(self._cb, None)

    def pruefen(self, name: str, rc: int) -> int:
        if rc < 0:
            raise RuntimeError(f'{name}: {self.pt.fehlertext(self.lib, rc)}')
        return rc

    def zeige(self, bild) -> None:
        pfad = os.path.join(self.temp, 'seite.png')
        bild.save(pfad)
        self.pruefen('Bild anzeigen', self.lib.STDisplayErase())
        self.pruefen('Anzeigeziel', self.lib.STDisplaySetTarget(0))   # wie im Aufnahmetest (padsign.py): nach dem Löschen setzen
        self.pruefen('Bild anzeigen', self.lib.STDisplaySetImageFromFile(0, 0, pfad))

    def knoepfe(self, rechtecke: dict) -> dict:
        self.lib.STSensorClearHotSpots()
        return {n: self.pruefen('Schaltfläche', self.lib.STSensorAddHotSpot(*r)) for n, r in rechtecke.items()}

    def unterschrift_starten(self, feld) -> None:
        self.pruefen('Unterschriftsfeld', self.lib.STSensorSetSignRect(*feld))
        rc = self.pruefen('Aufnahme', self.lib.STSignatureStart())
        self.zaehler.pop(EV_SIGNATUR, None)
        self.stift = {}
        log(f'Aufnahme gestartet (Feld {tuple(feld)}, Rückgabe {rc})')

    def unterschrift_neu(self) -> None:
        self.pruefen('Löschen', self.lib.STSignatureRetry())

    def unterschrift_abbrechen(self) -> None:
        self.lib.STSignatureCancel(0)

    def ereignis(self, wartezeit: float):
        if not self.queue:
            self.signal.wait(wartezeit)
            self.signal.clear()
        return self.queue.pop(0) if self.queue else None

    def bestaetigen(self, ausgabe: str, vid: int):
        """Bestätigt die Unterschrift und speichert sie. Bei Misserfolg steht der Grund in self.hinweis (für Seite und Protokoll)."""
        L = self.lib
        self.hinweis = ''
        # Erst anhalten (löscht das Display nicht): Ohne Unterschriftsdaten bleibt das Feld stehen und die Aufnahme läuft weiter.
        # STSignatureConfirm würde das Display leeren, auch wenn nichts erfasst wurde.
        laeuft = L.STSignatureGetState()
        stopp = L.STSignatureStop()
        groesse = ctypes.c_int(0)
        rc = L.STSignatureGetSignData(None, ctypes.byref(groesse))
        log(f'Vorgang {vid}: Aufnahme lief: {laeuft}, Stopp {stopp}, Daten {rc}/{groesse.value} Bytes, Stiftereignisse {self.zaehler.get(EV_SIGNATUR, 0)} (Bereich {self.stift or "keine"}), alle Ereignisse {self.zaehler}')
        if rc < 0 or groesse.value < 1:
            self.hinweis = 'Noch keine Unterschrift erkannt. Bitte im Feld unterschreiben, dann „OK“.'
            L.STSignatureStart()
            return None
        # Die Daten sind seit dem Anhalten zwischengespeichert; Confirm würde nur das Display leeren (das geschieht danach in danke()).
        puffer = (ctypes.c_ubyte * groesse.value)()
        self.pruefen('Rohdaten', L.STSignatureGetSignData(puffer, ctypes.byref(groesse)))
        os.makedirs(ausgabe, mode=0o700, exist_ok=True)
        with open(os.path.join(ausgabe, f'{vid}.sdb'), 'wb') as f:
            f.write(bytes(puffer[:groesse.value]))
        self.pruefen('Bild speichern', L.STSignatureSaveAsFileEx(os.path.join(ausgabe, f'{vid}.png'), 300, 0, 0, 1, 0, 0x000000, 0x0400))
        L.STSensorClearHotSpots()
        return True

    def danke(self, bild) -> None:
        self.zeige(bild)
        time.sleep(2)
        self.lib.STDisplayErase()

    def raeumen(self) -> None:
        self.lib.STSensorClearHotSpots()
        self.lib.STSignatureCancel(0)
        self.lib.STDisplayErase()

    def schliessen(self) -> None:
        try:
            self.lib.STDeviceClose(0)
        finally:
            self.lib.STControlExit()


def main(argv: list[str]) -> int:
    if len(argv) != 1 or not argv[0].isdigit():
        print(__doc__)
        return 2
    vid = int(argv[0])
    import padtest
    ordner = os.path.join(DATEN, 'signotec')
    if ordner not in os.environ.get('LD_LIBRARY_PATH', '').split(':'):
        try:
            padtest.tiff_verweis(ordner)
        except OSError as exc:
            log(f'Hinweis: libtiff.so.5 nicht anlegbar ({exc})')
        os.environ['LD_LIBRARY_PATH'] = ordner + ':' + os.environ.get('LD_LIBRARY_PATH', '')
        os.execv(sys.executable, [sys.executable, os.path.abspath(__file__), *argv])
    os.chdir(ordner)
    c = db_oeffnen(DATEN)
    v = c.execute('SELECT * FROM vorgaenge WHERE id=?', (vid,)).fetchone()
    if not v or v['status'] != 'am_pad':
        log(f'Vorgang {vid} ist nicht am Pad.')
        return 0
    pad = c.execute('SELECT * FROM pads WHERE id=?', (v['pad_id'],)).fetchone()
    if not pad:
        status_setzen(c, vid, 'fehler', 'Dem Vorgang ist kein Pad zugeordnet.')
        return 1
    geraet = None
    try:
        geraet = PadGeraet(ordner, pad['adresse'], int(pad['port']), os.path.join(DATEN, 'tmp-pad'))
        ergebnis = ablauf(c, v, geraet, os.path.join(DATEN, 'unterschriften'))
        log(f'Vorgang {vid}: {ergebnis}')
        return 0
    except Exception as exc:  # noqa: BLE001 - jeder Fehler muss beim Vorgang landen, sonst hängt er „am Pad“
        log(f'Vorgang {vid}: FEHLER {exc}')
        status_setzen(c, vid, 'fehler', str(exc)[:300])
        return 1
    finally:
        if geraet:
            geraet.schliessen()


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
