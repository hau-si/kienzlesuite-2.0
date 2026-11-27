#!/usr/bin/env python3
"""PDF eines unterschriebenen Vorgangs (Entwurf C): Praxis links, Patient rechts, Titel, Dokumenttext, unten links der
Unterschriftsnachweis (Zeitpunkt, Pad, Vorgang, Prüfsumme der Unterschriftsdaten), rechts die Unterschrift.

Echtes Text-PDF mit den Standardschriften Helvetica (WinAnsi, deckt Umlaute, €, „“, –, • ab), die Unterschrift als Graustufenbild.
Ohne Zusatzpakete außer Pillow. Aufruf: padpdf.vorgang_pdf(verbindung, vorgangs_id, ordner_unterschriften)."""
from __future__ import annotations

import hashlib
import io
import json
import os
import time
import zlib

# Breiten (1/1000 em) der WinAnsi-Zeichen 32–255, Helvetica und Helvetica-Bold (Standard-14-Schriften der PDF-Betrachter).
_W = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584,
      584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667,
      611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500,
      722, 500, 500, 500, 334, 260, 334, 584, 761, 556, 278, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 278, 611, 278, 278, 222, 222, 333,
      333, 350, 556, 1000, 333, 1000, 500, 333, 944, 278, 500, 667, 278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333, 400,
      584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611, 667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278,
      278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, 556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556,
      556, 278, 278, 278, 278, 556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500]
_WB = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584,
       584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667,
       611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556,
       778, 556, 556, 500, 389, 280, 389, 584, 761, 556, 278, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 278, 611, 278, 278, 278, 278,
       500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 278, 500, 667, 278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333,
       400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611, 722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278,
       278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, 556, 556, 556, 556, 556, 556, 889, 556, 556, 556,
       556, 556, 278, 278, 278, 278, 611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556]

BREITE, HOEHE = 595.28, 841.89
RAND = 56.0
UNTEN = 80.0              # unterster Platz für Text (darunter die Fußzeile)
DUNKEL = (0.114, 0.231, 0.212)
GRUEN = (0.071, 0.412, 0.365)
GRAU = (0.373, 0.420, 0.404)
FLAECHE = (0.965, 0.973, 0.969)


def kodieren(s: str) -> bytes:
    """Text in WinAnsi (cp1252); Zeichen, die es dort nicht gibt, werden zu „?“. Steuerzeichen fallen weg."""
    s = ''.join(ch for ch in s if ch >= ' ' or ch == '\t').replace('\t', '    ').replace(' ', ' ').replace('−', '-')
    return s.encode('cp1252', errors='replace')


def breite(s: str, groesse: float, fett: bool = False) -> float:
    tab = _WB if fett else _W
    return sum(tab[b - 32] if b >= 32 else 0 for b in kodieren(s)) * groesse / 1000


def umbrechen(text: str, groesse: float, maxbreite: float) -> list[str]:
    """Zeilen für den Fließtext: Absätze bleiben, Wörter werden umbrochen, überlange Wörter hart geteilt."""
    zeilen: list[str] = []
    for absatz in text.replace('\r\n', '\n').replace('\r', '\n').split('\n'):
        if not absatz.strip():
            zeilen.append('')
            continue
        einzug = absatz[:len(absatz) - len(absatz.lstrip(' '))]
        zeile = ''
        for wort in absatz.split():
            while breite(wort, groesse) > maxbreite:
                n = len(wort)
                while n > 1 and breite(wort[:n], groesse) > maxbreite:
                    n -= 1
                if zeile:
                    zeilen.append(zeile)
                    zeile = ''
                zeilen.append(wort[:n])
                wort = wort[n:]
            probe = (zeile + ' ' + wort) if zeile else einzug + wort
            if breite(probe, groesse) <= maxbreite:
                zeile = probe
            else:
                zeilen.append(zeile)
                zeile = wort
        zeilen.append(zeile)
    while zeilen and zeilen[-1] == '':
        zeilen.pop()
    return zeilen


def _pdf_text(s: str) -> bytes:
    return b'(' + kodieren(s).replace(b'\\', b'\\\\').replace(b'(', b'\\(').replace(b')', b'\\)') + b')'


class Seite:
    def __init__(self) -> None:
        self.ops: list[bytes] = []

    def text(self, x: float, y: float, s: str, groesse: float, fett: bool = False, farbe=DUNKEL, rechts: bool = False) -> None:
        if rechts:
            x -= breite(s, groesse, fett)
        r, g, b = farbe
        self.ops.append(b'BT /%s %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td %s Tj ET' % (b'F2' if fett else b'F1', groesse, r, g, b, x, y, _pdf_text(s)))

    def linie(self, x1: float, y1: float, x2: float, y2: float, dicke: float, farbe=DUNKEL) -> None:
        self.ops.append(b'%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S' % (*farbe, dicke, x1, y1, x2, y2))

    def flaeche(self, x: float, y: float, b: float, h: float, farbe) -> None:
        self.ops.append(b'%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f' % (*farbe, x, y, b, h))

    def bild(self, x: float, y: float, b: float, h: float) -> None:
        self.ops.append(b'q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q' % (b, h, x, y))


def unterschriftsbild(png: str) -> tuple[int, int, bytes] | None:
    """Unterschrift als Graustufen (weißer Rand abgeschnitten): (breite, höhe, Rohdaten) oder None."""
    try:
        from PIL import Image, ImageOps
        with Image.open(png) as roh:
            roh.load()
            if roh.mode in ('RGBA', 'LA', 'P', 'PA'):
                roh = roh.convert('RGBA')
                weiss = Image.new('RGBA', roh.size, (255, 255, 255, 255))
                weiss.alpha_composite(roh)
                roh = weiss
            grau = roh.convert('L')
        kasten = ImageOps.invert(grau).point(lambda v: 255 if v > 10 else 0).getbbox()
        if not kasten:
            return None
        li, ob, re_, un = kasten
        rand = 8
        grau = grau.crop((max(0, li - rand), max(0, ob - rand), min(grau.width, re_ + rand), min(grau.height, un + rand)))
        if grau.width > 1600:
            grau = grau.resize((1600, max(1, grau.height * 1600 // grau.width)))
        return grau.width, grau.height, grau.tobytes()
    except Exception:  # noqa: BLE001 - ein kaputtes Bild darf das PDF nicht verhindern (Nachweis steht trotzdem darin)
        return None


def erzeugen(d: dict, ziel: str) -> None:
    """d: titel, text, patient, geburtsdatum, praxis {name, street, city, contact}, zeit (Unix), pad, vorgang, pruefsumme,
    bild (breite, höhe, Graustufen) oder None. Schreibt das PDF nach ziel (atomar)."""
    praxis = d.get('praxis') or {}
    titel = d.get('titel') or 'Dokument'
    patient = d.get('patient') or ''
    seiten: list[Seite] = []

    def neue_seite(erste: bool) -> tuple[Seite, float]:
        s = Seite()
        seiten.append(s)
        if erste:
            y = HOEHE - 60
            s.text(RAND, y, praxis.get('name', ''), 11, True)
            for i, z in enumerate(x for x in (praxis.get('street', ''), praxis.get('city', ''), praxis.get('contact', '')) if x):
                s.text(RAND, y - 14 - i * 11, z, 8.5, farbe=GRAU)
            s.text(BREITE - RAND, y, patient, 11, True, rechts=True)
            if d.get('geburtsdatum'):
                s.text(BREITE - RAND, y - 14, 'geb. ' + d['geburtsdatum'], 8.5, farbe=GRAU, rechts=True)
            s.linie(RAND, HOEHE - 112, BREITE - RAND, HOEHE - 112, 1.4, GRUEN)
            s.text(RAND, HOEHE - 142, titel, 17, True, GRUEN)
            return s, HOEHE - 172
        s.text(RAND, HOEHE - 52, titel, 10, True, GRUEN)
        s.text(BREITE - RAND, HOEHE - 52, patient, 9, farbe=GRAU, rechts=True)
        s.linie(RAND, HOEHE - 60, BREITE - RAND, HOEHE - 60, 0.8, GRUEN)
        return s, HOEHE - 84

    groesse, abstand = 10.5, 15.0
    seite, y = neue_seite(True)
    for zeile in umbrechen(d.get('text') or '', groesse, BREITE - 2 * RAND):
        if y < UNTEN:
            seite, y = neue_seite(False)
        if zeile:
            seite.text(RAND, y, zeile, groesse)
        y -= abstand
    # Unterschriftsblock (passt er nicht mehr, auf eine neue Seite)
    hoehe = 112.0
    if y - 24 - hoehe < UNTEN - 30:
        seite, y = neue_seite(False)
    oben = y - 24
    seite.flaeche(RAND, oben - hoehe, 228, hoehe, FLAECHE)
    seite.text(RAND + 10, oben - 18, 'Unterschriftsnachweis', 9, True)
    zeit = time.localtime(int(d.get('zeit') or 0)) if d.get('zeit') else None
    nachweis = [
        'Zeitpunkt: ' + (time.strftime('%d.%m.%Y, %H:%M:%S', zeit) if zeit else 'unbekannt'),
        'Gerät: signotec-Pad' + (' „' + d['pad'] + '“' if d.get('pad') else ''),
        'Vorgang: ' + str(d.get('vorgang', '')),
        'Prüfsumme: ' + (d.get('pruefsumme') or 'keine Unterschriftsdaten'),
        'Biometrische Daten: in der Praxis gespeichert' if d.get('pruefsumme') else '',
    ]
    for i, z in enumerate(x for x in nachweis if x):
        seite.text(RAND + 10, oben - 36 - i * 14, z, 8, farbe=GRAU)
    links = RAND + 260
    linie_y = oben - hoehe + 26
    if d.get('bild'):
        bb, bh, _ = d['bild']
        maxb, maxh = BREITE - RAND - links, 72.0
        f = min(maxb / bb, maxh / bh)
        seite.bild(links, linie_y + 4, bb * f, bh * f)
    seite.linie(links, linie_y, BREITE - RAND, linie_y, 0.8)
    seite.text(links, linie_y - 13, patient, 9, farbe=GRAU)
    for i, s in enumerate(seiten):
        s.text(BREITE - RAND, 40, f'Seite {i + 1} von {len(seiten)}', 8, farbe=GRAU, rechts=True)
    daten = _zusammensetzen(seiten, d.get('bild'), titel, patient, int(d.get('zeit') or time.time()))
    tmp = ziel + '.tmp'
    with open(tmp, 'wb') as fh:
        fh.write(daten)
    os.chmod(tmp, 0o600)
    os.replace(tmp, ziel)


def _zusammensetzen(seiten: list[Seite], bild, titel: str, patient: str, zeit: int) -> bytes:
    objekte: list[bytes] = []

    def neu(inhalt: bytes) -> int:
        objekte.append(inhalt)
        return len(objekte)

    def strom(kopf: bytes, roh: bytes) -> bytes:
        return b'<< ' + kopf + b' /Length %d >>\nstream\n' % len(roh) + roh + b'\nendstream'

    katalog = neu(b'')
    seitenbaum = neu(b'')
    f1 = neu(b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>')
    f2 = neu(b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>')
    xobj = b''
    if bild:
        bb, bh, roh = bild
        im = neu(strom(b'/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode' % (bb, bh), zlib.compress(roh, 9)))
        xobj = b' /XObject << /Im1 %d 0 R >>' % im
    kinder = []
    for s in seiten:
        inhalt = neu(strom(b'/Filter /FlateDecode', zlib.compress(b'\n'.join(s.ops), 9)))
        kinder.append(neu(b'<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >>%s >> >>'
                          % (seitenbaum, BREITE, HOEHE, inhalt, f1, f2, xobj)))
    objekte[katalog - 1] = b'<< /Type /Catalog /Pages %d 0 R >>' % seitenbaum
    objekte[seitenbaum - 1] = b'<< /Type /Pages /Kids [%s] /Count %d >>' % (b' '.join(b'%d 0 R' % k for k in kinder), len(kinder))
    datum = time.strftime('D:%Y%m%d%H%M%S', time.localtime(zeit))
    info = neu(b'<< /Title %s /Subject %s /Producer (Praxisherz Signatur) /CreationDate (%s) >>' % (_pdf_text(titel), _pdf_text(patient), datum.encode()))
    aus = io.BytesIO()
    aus.write(b'%PDF-1.4\n%\xe2\xe3\xcf\xd3\n')
    lagen = []
    for i, o in enumerate(objekte, 1):
        lagen.append(aus.tell())
        aus.write(b'%d 0 obj\n' % i + o + b'\nendobj\n')
    xref = aus.tell()
    aus.write(b'xref\n0 %d\n0000000000 65535 f \n' % (len(objekte) + 1))
    for p in lagen:
        aus.write(b'%010d 00000 n \n' % p)
    aus.write(b'trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n' % (len(objekte) + 1, katalog, info, xref))
    return aus.getvalue()


def vorgang_pdf(c, vid: int, ordner: str) -> str:
    """Erzeugt <ordner>/<vid>.pdf aus dem Vorgang, der Unterschrift (<vid>.png) und den Rohdaten (<vid>.sdb). Liefert den Pfad."""
    v = c.execute('SELECT * FROM vorgaenge WHERE id=?', (vid,)).fetchone()
    if not v:
        raise ValueError(f'Vorgang {vid} fehlt.')
    spalten = v.keys()
    pad = ''
    if v['pad_id']:
        p = c.execute('SELECT name FROM pads WHERE id=?', (v['pad_id'],)).fetchone()
        pad = p['name'] if p else ''
    try:
        praxis = json.loads(v['praxis']) if 'praxis' in spalten and v['praxis'] else {}
    except ValueError:
        praxis = {}
    sdb = os.path.join(ordner, f'{vid}.sdb')
    pruefsumme = ''
    if os.path.isfile(sdb):
        with open(sdb, 'rb') as fh:
            h = hashlib.sha256(fh.read()).hexdigest()
        pruefsumme = h[:16] + '…' + h[-8:]
    png = os.path.join(ordner, f'{vid}.png')
    ziel = os.path.join(ordner, f'{vid}.pdf')
    erzeugen({
        'titel': v['titel'], 'text': v['text'], 'patient': v['patient'], 'geburtsdatum': v['geburtsdatum'] if 'geburtsdatum' in spalten else '',
        'praxis': praxis if isinstance(praxis, dict) else {}, 'zeit': v['unterschrieben'], 'pad': pad, 'vorgang': vid, 'pruefsumme': pruefsumme,
        'bild': unterschriftsbild(png) if os.path.isfile(png) else None,
    }, ziel)
    return ziel
