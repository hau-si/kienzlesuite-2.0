#!/usr/bin/env python3
"""Verbindungstest zum signotec Delta (Ethernet): Pad suchen, Typ/Seriennummer/Firmware lesen, Testtext anzeigen.

Aufruf:  python3 padtest.py <IP> [Port] [Ordner mit den Bibliotheken]
Ausgabe: eine Zeile je Schritt; Exit-Code 0 nur, wenn alles geklappt hat.
Nutzt die von signotec hochgeladene libSTPadLib.so (wchar_t = 4 Byte unter Linux, LONG = int)."""
from __future__ import annotations

import ctypes
import os
import sys
import time

TYPEN = {21: 'Delta USB', 22: 'Delta seriell', 23: 'Delta IP', 33: 'Alpha IP'}
VERBINDUNG = {1: 'USB', 2: 'seriell', 3: 'Ethernet'}
ORDNER = '/var/lib/kienzle/signatur/signotec'


def tiff_verweis(ordner: str) -> None:
    """libSTPadLib.so verlangt libtiff.so.5; neuere Ubuntu-Versionen haben nur libtiff.so.6. Ein Verweis im
    Bibliotheksordner genügt (die Bibliothek ruft keine TIFF-Funktionen auf, die sich zwischen 5 und 6 geändert hätten)."""
    ziel = os.path.join(ordner, 'libtiff.so.5')
    if os.path.lexists(ziel):
        return
    for kandidat in ('/usr/lib/x86_64-linux-gnu/libtiff.so.6', '/lib/x86_64-linux-gnu/libtiff.so.6'):
        if os.path.exists(kandidat):
            os.symlink(kandidat, ziel)
            return


def lade(ordner: str) -> ctypes.CDLL:
    # Die Bildbibliothek liegt daneben und wird von libSTPadLib.so gesucht.
    ctypes.CDLL(os.path.join(ordner, 'libSTCPImageEngine.so'), mode=ctypes.RTLD_GLOBAL)
    lib = ctypes.CDLL(os.path.join(ordner, 'libSTPadLib.so'))
    W = ctypes.c_wchar_p
    for name, args in {
        'STDeviceSetComPort': [W], 'STDeviceGetCount': [], 'STDeviceGetConnectionType': [ctypes.c_int],
        'STDeviceGetInfo': [ctypes.c_void_p, ctypes.POINTER(ctypes.c_int), ctypes.c_int],
        'STDeviceGetVersion': [ctypes.c_void_p, ctypes.c_int], 'STDeviceGetIPAddress': [ctypes.c_void_p, ctypes.c_int],
        'STDeviceOpen': [ctypes.c_int, ctypes.c_bool], 'STDeviceClose': [ctypes.c_int],
        'STDisplayErase': [], 'STDisplaySetText': [ctypes.c_int, ctypes.c_int, ctypes.c_int, W],
        'STDisplaySetFont': [W, ctypes.c_int, ctypes.c_int], 'STControlSetAppName': [W],
        'STControlGetErrorString': [ctypes.c_void_p, ctypes.POINTER(ctypes.c_int), ctypes.c_int],
        'STControlExit': [],
    }.items():
        f = getattr(lib, name)
        f.argtypes = args
        f.restype = None if name in ('STControlExit', 'STControlSetAppName') else ctypes.c_int
    return lib


def fehlertext(lib: ctypes.CDLL, code: int) -> str:
    n = ctypes.c_int(256)
    puffer = ctypes.create_unicode_buffer(256)
    if lib.STControlGetErrorString(puffer, ctypes.byref(n), code) >= 0 and puffer.value:
        return f'{code} ({puffer.value})'
    return str(code)


def schritt(text: str) -> None:
    print(text, flush=True)


def main(argv: list[str]) -> int:
    if not argv:
        print(__doc__)
        return 2
    ip = argv[0]
    port = argv[1] if len(argv) > 1 else '1002'
    ordner = argv[2] if len(argv) > 2 else ORDNER
    # Der Suchpfad für Bibliotheken wird beim Programmstart gelesen: einmal neu starten, damit der Ordner darin steht.
    if ordner not in os.environ.get('LD_LIBRARY_PATH', '').split(':'):
        try:
            tiff_verweis(ordner)
        except OSError as exc:
            schritt(f'Hinweis: libtiff.so.5 nicht anlegbar ({exc})')
        os.environ['LD_LIBRARY_PATH'] = ordner + ':' + os.environ.get('LD_LIBRARY_PATH', '')
        os.execv(sys.executable, [sys.executable, os.path.abspath(__file__), *argv[:2], ordner])
    # Die Bibliothek sucht STPad.ini und schreibt ihr Protokoll im Arbeitsordner: dafür der Ordner des Dienstbenutzers.
    try:
        os.chdir(ordner)
    except OSError as exc:
        schritt(f'FEHLER Ordner {ordner} nicht erreichbar: {exc}')
        return 1
    try:
        lib = lade(ordner)
    except OSError as exc:
        schritt(f'FEHLER Bibliothek nicht ladbar: {exc}')
        return 1
    lib.STControlSetAppName('DocLibre')
    try:
        rc = lib.STDeviceSetComPort(f'IP={ip}:{port}')
        schritt(f'Suche nur an {ip}:{port}: {"ok" if rc >= 0 else "FEHLER " + fehlertext(lib, rc)}')
        zeit = time.monotonic()
        anzahl = lib.STDeviceGetCount()
        schritt(f'Geräte gefunden: {anzahl} (Suche {time.monotonic() - zeit:.1f} s)')
        if anzahl < 1:
            if anzahl < 0:
                schritt('FEHLER Suche: ' + fehlertext(lib, anzahl))
            schritt('Kein Pad gefunden. Prüfen: Pad im Ethernet-Modus (Servicemenü), IP und Port stimmen, nur ein Programm greift zu.')
            return 1
        serie = ctypes.create_unicode_buffer(16)
        typ = ctypes.c_int(0)
        if lib.STDeviceGetInfo(serie, ctypes.byref(typ), 0) < 0:
            schritt('FEHLER Geräteinfo')
            return 1
        schritt(f'Typ: {TYPEN.get(typ.value, typ.value)}, Seriennummer: {serie.value}')
        version = ctypes.create_unicode_buffer(16)
        if lib.STDeviceGetVersion(version, 0) >= 0:
            schritt(f'Firmware: {version.value}')
        schritt(f'Verbindung: {VERBINDUNG.get(lib.STDeviceGetConnectionType(0), "?")}')
        rc = lib.STDeviceOpen(0, True)
        if rc < 0:
            schritt('FEHLER Pad öffnen: ' + fehlertext(lib, rc))
            return 1
        schritt('Pad geöffnet.')
        try:
            lib.STDisplaySetFont('DejaVu Sans', 32, 0)
            for zeile, text in enumerate(('DocLibre', 'Verbindungstest erfolgreich')):
                rc = lib.STDisplaySetText(40, 60 + 60 * zeile, 0, text)
                if rc < 0:
                    schritt('FEHLER Text anzeigen: ' + fehlertext(lib, rc))
                    return 1
            schritt('Testtext auf dem Pad angezeigt (5 Sekunden).')
            time.sleep(5)
            lib.STDisplayErase()
        finally:
            lib.STDeviceClose(0)
        schritt('OK')
        return 0
    finally:
        lib.STControlExit()


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
