#!/usr/bin/env python3
"""Arbeitsprozess für einen Vorgang am Pad: Text seitenweise zeigen, Unterschrift aufnehmen, Ergebnis speichern.

Aufruf (vom Dienst): python3 padauftrag.py <Vorgangs-Nummer>
Ablauf und Gerät sind getrennt: ablauf() steuert nur über das Gerät (PadGeraet für das echte Pad, in den Tests ein Ersatz)."""
from __future__ import annotations

import ctypes
import json
import os
import sqlite3
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import padformular  # noqa: E402
import padseiten  # noqa: E402
import padtexte  # noqa: E402

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


def pdf_erzeugen(c: sqlite3.Connection, vid: int, ordner: str) -> bool:
    """PDF des unterschriebenen Vorgangs (padpdf). Ein Fehler hält die Unterschrift nicht auf; er steht im Protokoll und am Vorgang."""
    try:
        import padpdf
        padpdf.vorgang_pdf(c, vid, ordner)
        log(f'Vorgang {vid}: PDF erzeugt.')
        return True
    except Exception as exc:  # noqa: BLE001
        log(f'Vorgang {vid}: PDF nicht erzeugt ({exc})')
        c.execute('UPDATE vorgaenge SET meldung=? WHERE id=?', (f'PDF nicht erzeugt: {exc}'[:300], vid))
        c.commit()
        return False


class Vorschau:
    """Was der Patient gerade am Pad sieht, für die Seite (Vorschlag A): <daten>/vorschau/<vorgang>.png (verkleinert) und .json
    (Seite, Seitenzahl, Stiftpunkte). Nur im Datenordner (0600), nach dem Vorgang gelöscht."""

    def __init__(self, ordner: str, vid: int):
        self.ordner, self.vid, self.stand = ordner, vid, {}
        try:
            os.makedirs(ordner, mode=0o700, exist_ok=True)
        except OSError:
            self.ordner = ''

    def _schreiben(self, name: str, daten: bytes) -> None:
        if not self.ordner:
            return
        ziel = os.path.join(self.ordner, name)
        tmp = ziel + '.tmp'
        try:
            with open(tmp, 'wb') as fh:
                fh.write(daten)
            os.replace(tmp, ziel)
        except OSError:
            pass

    def seite(self, bild, nr: int, von: int, art: str) -> None:
        import io
        klein = bild.copy()
        klein.thumbnail((640, 400))
        puffer = io.BytesIO()
        klein.save(puffer, 'PNG', optimize=True)
        self._schreiben(f'{self.vid}.png', puffer.getvalue())
        self.stand = {'seite': nr, 'seiten': von, 'art': art, 'punkte': 0}
        self.melden()

    def punkte(self, n: int) -> None:
        if self.stand and n != self.stand.get('punkte'):
            self.stand['punkte'] = n
            self.melden()

    def melden(self) -> None:
        import json
        self._schreiben(f'{self.vid}.json', json.dumps({**self.stand, 'zeit': int(time.time())}).encode())

    def weg(self) -> None:
        for e in ('png', 'json'):
            try:
                os.remove(os.path.join(self.ordner, f'{self.vid}.{e}'))
            except OSError:
                pass


class Abbruch(Exception):
    """Der Vorgang wurde auf der Seite abgebrochen oder zurückgenommen: Pad räumen, nichts mehr eintragen."""


def ablauf(c: sqlite3.Connection, vorgang: sqlite3.Row, geraet, ausgabe: str, jetzt=time.time, leerlauf: int = LEERLAUF) -> str:
    """Führt den Vorgang am Gerät aus. Rückgabe: 'unterschrieben', 'zurueck' (am Pad abgebrochen/Zeit abgelaufen), 'abgebrochen' (Seite) oder 'fehler'."""
    vorschau = Vorschau(os.path.join(os.path.dirname(os.path.abspath(ausgabe)), 'vorschau'), int(vorgang['id']))
    # Bedienwörter am Pad in der Sprache der Vorlage (Sprachfassung aus dem Editor).
    padtexte.SPRACHE = str(vorgang['sprache']) if 'sprache' in vorgang.keys() and vorgang['sprache'] else 'de'
    try:
        return _ablauf(c, vorgang, geraet, ausgabe, jetzt, leerlauf, vorschau)
    finally:
        vorschau.weg()
        padtexte.SPRACHE = 'de'


def _bloecke(vorgang: sqlite3.Row) -> list:
    """Bausteine des Vorgangs (Editor C), sonst leer (ältere Vorgänge: nur Text)."""
    if 'bloecke' not in vorgang.keys() or not vorgang['bloecke']:
        return []
    try:
        b = json.loads(vorgang['bloecke'])
    except ValueError:
        return []
    return b if isinstance(b, list) and all(isinstance(x, dict) and isinstance(x.get('id'), str) for x in b) else []


def _ablauf(c: sqlite3.Connection, vorgang: sqlite3.Row, geraet, ausgabe: str, jetzt, leerlauf: int, vorschau: Vorschau) -> str:
    if _bloecke(vorgang):
        return _formular(c, vorgang, geraet, ausgabe, jetzt, leerlauf, vorschau)
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
        vorschau.seite(s['bild'], i + 1, len(seiten), 'unterschrift' if i == letzte else 'text')
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
        vorschau.punkte(int((getattr(geraet, 'stift', None) or {}).get('n', 0)))
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
            zweite = _zweite(c, vorgang, geraet, ausgabe, jetzt, leerlauf, vorschau)
            if zweite:
                return zweite
            geraet.danke(padseiten.hinweisseite('Vielen Dank'))
            status_setzen(c, vid, 'unterschrieben', '', zeit=int(jetzt()))
            pdf_erzeugen(c, vid, ausgabe)
            return 'unterschrieben'


def _zweite(c: sqlite3.Connection, vorgang: sqlite3.Row, geraet, ausgabe: str, jetzt, leerlauf: int, vorschau: Vorschau) -> str | None:
    """Zweite Unterschrift (z. B. Ärztin/Arzt bei der Aufklärung), wenn die Vorlage eine verlangt. None = erledigt oder nicht nötig,
    sonst das Ergebnis des Ablaufs (zurueck, fehler, abgebrochen). Gespeichert als <vorgang>-2.png/.sdb."""
    bez = str(vorgang['zweite']).strip() if 'zweite' in vorgang.keys() and vorgang['zweite'] else ''
    if not bez:
        return None
    vid = int(vorgang['id'])
    sign = padseiten.unterschriftsseite(vorgang['titel'], 'Zweite Unterschrift: ' + bez, 'Unterschrift ' + bez, zurueck=False)
    geraet.zeige(sign['bild'])
    vorschau.seite(sign['bild'], 1, 1, 'unterschrift')
    ids = geraet.knoepfe(sign['knoepfe'])
    namen = {ids[n]: n for n in ids}
    geraet.unterschrift_starten(sign['feld'])
    zuletzt = jetzt()
    while True:
        if c.execute('SELECT status FROM vorgaenge WHERE id=?', (vid,)).fetchone()['status'] != 'am_pad':
            geraet.raeumen()
            return 'abgebrochen'
        if jetzt() - zuletzt > leerlauf:
            geraet.raeumen()
            status_setzen(c, vid, 'wartet', f'Zweite Unterschrift ({bez}) fehlt (Zeit abgelaufen).')
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
            continue
        taste = namen[wert]
        if taste == 'Abbrechen':
            geraet.raeumen()
            status_setzen(c, vid, 'wartet', f'Am Pad abgebrochen (zweite Unterschrift {bez} fehlt).')
            return 'zurueck'
        if taste == 'Löschen':
            geraet.unterschrift_neu()
        elif taste == 'OK':
            if geraet.bestaetigen(ausgabe, f'{vid}-2') is None:
                h = getattr(geraet, 'hinweis', '') or 'Bitte zuerst im Feld unterschreiben.'
                c.execute('UPDATE vorgaenge SET meldung=? WHERE id=? AND status="am_pad"', (h[:300], vid))
                c.commit()
                continue
            log(f'Vorgang {vid}: zweite Unterschrift erfasst.')
            return None


def _formular(c: sqlite3.Connection, vorgang: sqlite3.Row, geraet, ausgabe: str, jetzt, leerlauf: int, vorschau: Vorschau) -> str:
    """Bausteine am Pad (Editor C): Ankreuzen und Auswählen durch Antippen, Pflichtfelder vor der Unterschrift, E-Mail/Telefon über
    die Bildschirmtastatur. Nach „OK“ werden die Antworten gespeichert, der Text des Vorgangs mit Antworten ersetzt (PDF) und
    geänderte Kontaktdaten für T2med vorgemerkt."""
    vid = int(vorgang['id'])
    bloecke = _bloecke(vorgang)
    bilder = os.path.join(os.path.dirname(os.path.abspath(ausgabe)), 'bilder')
    form = padformular.seiten(bloecke, bilder)
    antworten = padformular.antworten_start(bloecke)
    sign = padseiten.unterschriftsseite(vorgang['titel'], vorgang['patient'])
    letzte = len(form)              # Index der Unterschriftsseite
    idx, hinweis, tastatur = 0, '', None   # tastatur: {'id', 'feld', 'wert', 'modus', 'hinweis'}
    zuletzt = jetzt()

    def zeigen() -> dict:
        if tastatur is not None:
            s = padformular.zeichne_tastatur(tastatur['feld'], tastatur['wert'], tastatur['modus'], tastatur['hinweis'])
            art = 'tastatur'
        elif idx == letzte:
            s, art = sign, 'unterschrift'
        else:
            s, art = padformular.zeichne_seite(vorgang['titel'], form[idx], idx, len(form), antworten, hinweis), 'text'
        geraet.zeige(s['bild'])
        vorschau.seite(s['bild'], min(idx, letzte) + 1, letzte + 1, art)
        ids = geraet.knoepfe(s['knoepfe'])
        if tastatur is None and idx == letzte:
            geraet.unterschrift_starten(sign['feld'])
        return {ids[n]: n for n in ids}

    namen = zeigen()
    while True:
        if c.execute('SELECT status FROM vorgaenge WHERE id=?', (vid,)).fetchone()['status'] != 'am_pad':
            geraet.raeumen()
            return 'abgebrochen'
        if jetzt() - zuletzt > leerlauf:
            geraet.raeumen()
            status_setzen(c, vid, 'wartet', 'Am Pad nicht unterschrieben (Zeit abgelaufen).')
            return 'zurueck'
        ev = geraet.ereignis(1.0)
        vorschau.punkte(int((getattr(geraet, 'stift', None) or {}).get('n', 0)))
        if ev is None:
            continue
        art, wert = ev
        zuletzt = jetzt()
        if art == EV_DISCONNECT:
            status_setzen(c, vid, 'fehler', 'Verbindung zum Pad getrennt.')
            return 'fehler'
        if art != EV_HOTSPOT or wert not in namen:
            continue
        taste = namen[wert]
        if tastatur is not None:
            if taste == 'k:Abbrechen':
                tastatur = None
            elif taste == 'k:OK':
                fehler = padformular.pruefe_eingabe(tastatur['feld'], tastatur['wert'])
                if fehler:
                    tastatur['hinweis'] = fehler
                else:
                    antworten.setdefault(tastatur['id'], {})[tastatur['feld']] = tastatur['wert'].strip()
                    tastatur = None
            else:
                tastatur['wert'], tastatur['modus'] = padformular.tippen(tastatur['wert'], tastatur['modus'], taste)
                tastatur['hinweis'] = ''
            namen = zeigen()
            continue
        log(f'Vorgang {vid}: Taste {taste} (Seite {idx + 1} von {letzte + 1})')
        if taste == 'Abbrechen':
            geraet.raeumen()
            status_setzen(c, vid, 'wartet', 'Am Pad abgebrochen.')
            return 'zurueck'
        if taste.startswith('e:'):
            _, bid, feld = taste.split(':', 2)
            tastatur = {'id': bid, 'feld': feld, 'wert': (antworten.get(bid) or {}).get(feld, ''), 'modus': 'tel' if feld == 'telefon' else 'abc', 'hinweis': ''}
        elif taste.startswith(('a:', 'o:')):
            padformular.antippen(bloecke, antworten, taste)
            hinweis = ''
        elif taste == 'Weiter':
            if idx == letzte - 1:
                offen = padformular.fehlend(bloecke, antworten)
                if offen:
                    idx = padformular.seite_von(form, offen[0])
                    hinweis = 'Bitte die mit * markierten Angaben ausfüllen.'
                    namen = zeigen()
                    continue
            idx, hinweis = idx + 1, ''
        elif taste == 'Zurück':
            if idx == letzte:
                geraet.unterschrift_abbrechen()
            idx, hinweis = max(0, idx - 1), ''
        elif taste == 'Löschen':
            geraet.unterschrift_neu()
            continue
        elif taste == 'OK':
            ergebnis = geraet.bestaetigen(ausgabe, vid)
            if ergebnis is None:
                h = getattr(geraet, 'hinweis', '') or 'Bitte zuerst im Feld unterschreiben.'
                c.execute('UPDATE vorgaenge SET meldung=? WHERE id=? AND status="am_pad"', (h[:300], vid))
                c.commit()
                continue
            zweite = _zweite(c, vorgang, geraet, ausgabe, jetzt, leerlauf, vorschau)
            if zweite:
                return zweite
            geraet.danke(padseiten.hinweisseite('Vielen Dank'))
            antworten_speichern(c, vorgang, bloecke, antworten)
            status_setzen(c, vid, 'unterschrieben', '', zeit=int(jetzt()))
            pdf_erzeugen(c, vid, ausgabe)
            return 'unterschrieben'
        namen = zeigen()


def antworten_speichern(c: sqlite3.Connection, vorgang: sqlite3.Row, bloecke: list, antworten: dict) -> None:
    """Antworten und Text mit Antworten (für PDF und Ansicht) speichern; am Pad geänderte Kontaktdaten für T2med vormerken
    (übernommen wird erst auf Knopfdruck in der Liste, nur bei Start aus T2med mit Patienten-ID)."""
    vid = int(vorgang['id'])
    text = padformular.text_mit_antworten(bloecke, antworten)
    c.execute('UPDATE vorgaenge SET antworten=?, text=? WHERE id=?', (json.dumps(antworten, ensure_ascii=False), text, vid))
    if 'akte' in vorgang.keys():
        c.execute('UPDATE vorgaenge SET akte=? WHERE id=?', (padformular.akte_text(bloecke, antworten), vid))
    alt, neu = padformular.kontakt_aenderungen(bloecke, antworten)
    spalten = vorgang.keys()
    if neu and 'kontakt_neu' in spalten:
        kann = bool(vorgang['patient_id']) and bool(vorgang['kontext']) if 'patient_id' in spalten else False
        voll = {'telefon': neu.get('telefon', ''), 'email': neu.get('email', '')}
        c.execute('UPDATE vorgaenge SET kontakt_alt=?, kontakt_neu=?, kontakt_status=? WHERE id=?',
                  (json.dumps({'telefon': alt.get('telefon', ''), 'email': alt.get('email', '')}, ensure_ascii=False), json.dumps(voll, ensure_ascii=False),
                   'offen' if kann else '', vid))
    c.commit()


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
