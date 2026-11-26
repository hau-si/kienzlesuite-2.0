#!/usr/bin/env python3
"""Aufnahmetest für das signotec Delta: zeigt eine Testseite mit Unterschriftsfeld und drei Schaltflächen
(Abbrechen, Löschen, OK), nimmt die Unterschrift auf und speichert sie als PNG und als Rohdaten.

Aufruf:  python3 padsign.py <IP> [Port] [Bibliotheksordner] [Ausgabeordner]
Die Datei im Ausgabeordner (Vorgabe: Bibliotheksordner) heißt unterschrift-test.png und unterschrift-test.sdb.

Fehlersuche (Umgebungsvariable PADSIGN, Wörter mit Komma):  bild = Seite wie im Pad-Dienst als Bild statt Text anzeigen;
stop = mit STSignatureStop statt STSignatureConfirm abschließen. Am Ende steht, wie viele Stiftereignisse ankamen."""
from __future__ import annotations

import ctypes
import os
import sys
import threading
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import padtest  # noqa: E402

CALLBACK = ctypes.CFUNCTYPE(None, ctypes.c_int, ctypes.c_void_p, ctypes.c_int, ctypes.c_void_p)
EV_DISCONNECT, EV_HOTSPOT, EV_TIMEOUT = 0, 1, 2
FOREGROUND, BACKGROUND = 0, 1
PNG, GLATT = 1, 0x0400
WARTEZEIT = 120


def pruefen(lib, name: str, rc: int) -> int:
    if rc < 0:
        raise RuntimeError(f'{name}: {padtest.fehlertext(lib, rc)}')
    return rc


def main(argv: list[str]) -> int:
    if not argv:
        print(__doc__)
        return 2
    ip, port = argv[0], (argv[1] if len(argv) > 1 else '1002')
    ordner = argv[2] if len(argv) > 2 else padtest.ORDNER
    ausgabe = argv[3] if len(argv) > 3 else ordner
    if ordner not in os.environ.get('LD_LIBRARY_PATH', '').split(':'):
        try:
            padtest.tiff_verweis(ordner)
        except OSError as exc:
            padtest.schritt(f'Hinweis: libtiff.so.5 nicht anlegbar ({exc})')
        os.environ['LD_LIBRARY_PATH'] = ordner + ':' + os.environ.get('LD_LIBRARY_PATH', '')
        os.execv(sys.executable, [sys.executable, os.path.abspath(__file__), ip, port, ordner, ausgabe])
    os.chdir(ordner)
    lib = padtest.lade(ordner)
    W, I = ctypes.c_wchar_p, ctypes.c_int
    for name, args in {
        'STDisplayGetWidth': [], 'STDisplayGetHeight': [], 'STDisplaySetTarget': [I], 'STControlSetCallback': [CALLBACK, ctypes.c_void_p],
        'STDisplaySetTextInRect': [I, I, I, I, I, W, I], 'STSensorSetSignRect': [I, I, I, I], 'STSensorAddHotSpot': [I, I, I, I],
        'STSensorClearHotSpots': [], 'STSignatureStart': [], 'STSignatureRetry': [], 'STSignatureConfirm': [], 'STSignatureCancel': [I],
        'STSignatureGetSignData': [ctypes.c_void_p, ctypes.POINTER(I)],
        'STSignatureSaveAsFileEx': [W, I, I, I, I, I, ctypes.c_uint, I],
        'STDisplaySetFont': [W, I, I],
    }.items():
        f = getattr(lib, name)
        f.argtypes = args
        f.restype = None if name == 'STControlSetCallback' else I
    varianten = set(os.environ.get('PADSIGN', '').split(','))
    ereignis: list[tuple[int, int]] = []
    stift = [0]
    signal = threading.Event()

    def rueckruf(art, daten, groesse, _):
        if art == 4:
            stift[0] += 1
            return
        wert = ctypes.cast(daten, ctypes.POINTER(ctypes.c_int))[0] if daten and groesse > 0 else 0
        ereignis.append((art, wert))
        signal.set()
    cb = CALLBACK(rueckruf)  # Verweis halten, sonst räumt Python die Funktion ab
    try:
        pruefen(lib, 'Suche', lib.STDeviceSetComPort(f'IP={ip}:{port}'))
        if lib.STDeviceGetCount() < 1:
            padtest.schritt('Kein Pad gefunden.')
            return 1
        pruefen(lib, 'Pad öffnen', lib.STDeviceOpen(0, True))
        try:
            lib.STControlSetCallback(cb, None)
            b, h = lib.STDisplayGetWidth(), lib.STDisplayGetHeight()
            padtest.schritt(f'Display: {b} x {h}')
            pruefen(lib, 'Display leeren', lib.STDisplayErase())
            pruefen(lib, 'Ziel', lib.STDisplaySetTarget(FOREGROUND))
            lib.STDisplaySetFont('DejaVu Sans', 30, 0)
            pruefen(lib, 'Text', lib.STDisplaySetTextInRect(40, 30, b - 80, 120, 0, 'Testseite der Kienzle-Suite. Bitte unten im Feld unterschreiben.', 0))
            if 'bild' in varianten:
                import padseiten
                seite = padseiten.unterschriftsseite('Test', 'Testpatient')
                pfad = os.path.join(ordner, 'seite-test.png')
                seite['bild'].save(pfad)
                lib.STDisplaySetImageFromFile.argtypes = [I, I, W]
                pruefen(lib, 'Bild', lib.STDisplaySetImageFromFile(0, 0, pfad))
                feld = seite['feld']
                tasten = {}
                for name, rechteck in seite['knoepfe'].items():
                    tasten[pruefen(lib, 'Schaltfläche', lib.STSensorAddHotSpot(*rechteck))] = name
            else:
                feld = (40, 170, b - 80, h - 170 - 150)
                lib.STDisplaySetTextInRect(feld[0], feld[1] + feld[3] - 60, feld[2], 60, 0, 'x ' + '_' * 60, 0)
                tasten = {}
                breite = (b - 80 - 2 * 30) // 3
                for i, text in enumerate(('Abbrechen', 'Löschen', 'OK')):
                    links = 40 + i * (breite + 30)
                    lib.STDisplaySetTextInRect(links, h - 120, breite, 90, 1, '[ ' + text + ' ]', 0)
                    tasten[pruefen(lib, 'Schaltfläche', lib.STSensorAddHotSpot(links, h - 130, breite, 110))] = text
            pruefen(lib, 'Unterschriftsfeld', lib.STSensorSetSignRect(*feld))
            pruefen(lib, 'Aufnahme starten', lib.STSignatureStart())
            padtest.schritt('Bitte auf dem Pad unterschreiben und mit OK bestätigen (Wartezeit 2 Minuten).')
            ende = time.monotonic() + WARTEZEIT
            while time.monotonic() < ende:
                signal.wait(1)
                signal.clear()
                while ereignis:
                    art, wert = ereignis.pop(0)
                    if art == EV_DISCONNECT:
                        padtest.schritt('FEHLER Pad getrennt.')
                        return 1
                    if art != EV_HOTSPOT:
                        continue
                    taste = tasten.get(wert)
                    padtest.schritt(f'Schaltfläche: {taste}')
                    if taste == 'Löschen':
                        pruefen(lib, 'Löschen', lib.STSignatureRetry())
                    elif taste == 'Abbrechen':
                        lib.STSignatureCancel(0)
                        padtest.schritt('Abgebrochen.')
                        return 1
                    elif taste == 'OK':
                        padtest.schritt(f'Stiftereignisse bisher: {stift[0]}')
                        if 'stop' in varianten:
                            padtest.schritt(f'Stopp: {lib.STSignatureStop()}')
                        else:
                            pruefen(lib, 'Bestätigen', lib.STSignatureConfirm())
                        groesse = ctypes.c_int(0)
                        pruefen(lib, 'Rohdaten (Größe)', lib.STSignatureGetSignData(None, ctypes.byref(groesse)))
                        puffer = (ctypes.c_ubyte * groesse.value)()
                        pruefen(lib, 'Rohdaten', lib.STSignatureGetSignData(puffer, ctypes.byref(groesse)))
                        os.makedirs(ausgabe, exist_ok=True)
                        with open(os.path.join(ausgabe, 'unterschrift-test.sdb'), 'wb') as f:
                            f.write(bytes(puffer[:groesse.value]))
                        png = os.path.join(ausgabe, 'unterschrift-test.png')
                        pruefen(lib, 'Bild speichern', lib.STSignatureSaveAsFileEx(png, 300, 0, 0, PNG, 0, 0x000000, GLATT))
                        padtest.schritt(f'Gespeichert: {png} ({os.path.getsize(png)} Bytes), Rohdaten {groesse.value} Bytes')
                        lib.STSensorClearHotSpots()
                        lib.STDisplayErase()
                        padtest.schritt('OK')
                        return 0
            padtest.schritt('Zeit abgelaufen, keine Bestätigung.')
            lib.STSignatureCancel(0)
            return 1
        finally:
            lib.STDeviceClose(0)
    except RuntimeError as exc:
        padtest.schritt('FEHLER ' + str(exc))
        return 1
    finally:
        lib.STControlExit()


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
