#!/usr/bin/env python3
"""PDF eines unterschriebenen Vorgangs (Entwurf C): Praxis links, Patient rechts, Titel, Dokumenttext, unten links der
Unterschriftsnachweis (Zeitpunkt, Pad, Vorgang, Prüfsumme der Unterschriftsdaten), rechts die Unterschrift.

Echtes Text-PDF mit den Standardschriften Helvetica (WinAnsi, deckt Umlaute, €, „“, –, • ab), die Unterschrift als Graustufenbild.
Enthält der Text Zeichen außerhalb von WinAnsi (Sprachfassungen: Türkisch, Polnisch, Rumänisch, Kyrillisch, Griechisch), wird DejaVu Sans
eingebettet (TrueType, Identity-H, mit ToUnicode zum Suchen und Kopieren).
Ohne Zusatzpakete außer Pillow. Aufruf: padpdf.vorgang_pdf(verbindung, vorgangs_id, ordner_unterschriften)."""
from __future__ import annotations

import hashlib
import io
import json
import os
import struct
import time
import zlib

SCHRIFTEN = ('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf')


class TrueType:
    """Das Nötigste aus einer TrueType-Datei: Glyphen-Nummer und Breite je Zeichen, Maße für den FontDescriptor."""

    def __init__(self, pfad: str):
        with open(pfad, 'rb') as fh:
            self.roh = fh.read()
        r = self.roh
        n = struct.unpack('>H', r[4:6])[0]
        t = {r[12 + 16 * i:16 + 16 * i].decode('latin-1'): struct.unpack('>II', r[20 + 16 * i:28 + 16 * i]) for i in range(n)}
        kopf = t['head'][0]
        self.em = struct.unpack('>H', r[kopf + 18:kopf + 20])[0]
        self.bbox = [v * 1000 // self.em for v in struct.unpack('>hhhh', r[kopf + 36:kopf + 44])]
        hh = t['hhea'][0]
        self.aufwaerts, self.abwaerts = (v * 1000 // self.em for v in struct.unpack('>hh', r[hh + 4:hh + 8]))
        anzahl = struct.unpack('>H', r[hh + 34:hh + 36])[0]
        hm = t['hmtx'][0]
        self.breiten = [struct.unpack('>H', r[hm + 4 * i:hm + 4 * i + 2])[0] for i in range(anzahl)]
        self.cmap: dict[int, int] = {}
        cm = t['cmap'][0]
        tabellen = {}
        for i in range(struct.unpack('>H', r[cm + 2:cm + 4])[0]):
            pid, eid, off = struct.unpack('>HHI', r[cm + 4 + 8 * i:cm + 12 + 8 * i])
            tabellen[(pid, eid)] = cm + off
        if (3, 10) in tabellen and struct.unpack('>H', r[tabellen[(3, 10)]:tabellen[(3, 10)] + 2])[0] == 12:
            o = tabellen[(3, 10)]
            for g in range(struct.unpack('>I', r[o + 12:o + 16])[0]):
                a, e, s = struct.unpack('>III', r[o + 16 + 12 * g:o + 28 + 12 * g])
                for cp in range(a, e + 1):
                    self.cmap[cp] = s + cp - a
        else:
            o = tabellen[(3, 1)]
            seg = struct.unpack('>H', r[o + 6:o + 8])[0] // 2
            ende = struct.unpack('>%dH' % seg, r[o + 14:o + 14 + 2 * seg])
            anf = struct.unpack('>%dH' % seg, r[o + 16 + 2 * seg:o + 16 + 4 * seg])
            delta = struct.unpack('>%dh' % seg, r[o + 16 + 4 * seg:o + 16 + 6 * seg])
            ro = o + 16 + 6 * seg
            versatz = struct.unpack('>%dH' % seg, r[ro:ro + 2 * seg])
            for i in range(seg):
                for cp in range(anf[i], ende[i] + 1):
                    if cp == 0xFFFF:
                        continue
                    if versatz[i] == 0:
                        g = (cp + delta[i]) & 0xFFFF
                    else:
                        p = ro + 2 * i + versatz[i] + 2 * (cp - anf[i])
                        g = struct.unpack('>H', r[p:p + 2])[0]
                        g = (g + delta[i]) & 0xFFFF if g else 0
                    self.cmap[cp] = g
        self.benutzt: dict[int, str] = {}

    def glyphe(self, ch: str) -> int:
        g = self.cmap.get(ord(ch), self.cmap.get(ord('?'), 0))
        self.benutzt.setdefault(g, ch)
        return g

    def breite(self, ch: str) -> float:
        g = self.cmap.get(ord(ch), self.cmap.get(ord('?'), 0))
        return self.breiten[min(g, len(self.breiten) - 1)] * 1000 / self.em


_SCHRIFT: tuple[TrueType, TrueType] | None = None   # gesetzt, solange ein PDF mit eingebetteter Schrift entsteht


def braucht_unicode(*texte: str) -> bool:
    try:
        for s in texte:
            s.replace(' ', ' ').replace('−', '-').encode('cp1252')
    except UnicodeEncodeError:
        return True
    return False

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


def _sauber(s: str) -> str:
    return ''.join(ch for ch in s if ch >= ' ' or ch == '\t').replace('\t', '    ').replace(' ', ' ')


def breite(s: str, groesse: float, fett: bool = False) -> float:
    if _SCHRIFT:
        f = _SCHRIFT[1 if fett else 0]
        return sum(f.breite(ch) for ch in _sauber(s)) * groesse / 1000
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


def _info_text(s: str) -> bytes:
    """Text für das Info-Verzeichnis: WinAnsi, sonst UTF-16 mit Byte-Order-Mark."""
    return b'<feff' + _sauber(s).encode('utf-16-be').hex().encode() + b'>' if braucht_unicode(s) else _pdf_text(s)


class Seite:
    def __init__(self) -> None:
        self.ops: list[bytes] = []

    def text(self, x: float, y: float, s: str, groesse: float, fett: bool = False, farbe=DUNKEL, rechts: bool = False) -> None:
        if rechts:
            x -= breite(s, groesse, fett)
        r, g, b = farbe
        if _SCHRIFT:
            f = _SCHRIFT[1 if fett else 0]
            roh = b'<' + ''.join('%04x' % f.glyphe(ch) for ch in _sauber(s)).encode() + b'>'
        else:
            roh = _pdf_text(s)
        self.ops.append(b'BT /%s %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td %s Tj ET' % (b'F2' if fett else b'F1', groesse, r, g, b, x, y, roh))

    def linie(self, x1: float, y1: float, x2: float, y2: float, dicke: float, farbe=DUNKEL) -> None:
        self.ops.append(b'%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S' % (*farbe, dicke, x1, y1, x2, y2))

    def flaeche(self, x: float, y: float, b: float, h: float, farbe) -> None:
        self.ops.append(b'%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f' % (*farbe, x, y, b, h))

    def bild(self, x: float, y: float, b: float, h: float, name: bytes = b'Im1') -> None:
        self.ops.append(b'q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q' % (b, h, x, y, name))


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
    global _SCHRIFT
    praxis = d.get('praxis') or {}
    if braucht_unicode(d.get('titel') or '', d.get('text') or '', d.get('patient') or '', d.get('zweite') or '', *(str(v) for v in praxis.values())):
        _SCHRIFT = (TrueType(SCHRIFTEN[0]), TrueType(SCHRIFTEN[1]))
    try:
        _erzeugen(d, ziel)
    finally:
        _SCHRIFT = None


def _erzeugen(d: dict, ziel: str) -> None:
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
    if d.get('zweite'):
        # Zweite Unterschrift (z. B. Ärztin/Arzt) unter dem Block, rechts neben einer Beschriftung.
        y2 = oben - hoehe - 24
        if y2 - 80 < UNTEN - 30:
            seite, y2 = neue_seite(False)
        linie2 = y2 - 70
        if d.get('bild2'):
            bb, bh, _ = d['bild2']
            f = min((BREITE - RAND - links) / bb, 60.0 / bh)
            seite.bild(links, linie2 + 4, bb * f, bh * f, b'Im2')
        seite.linie(links, linie2, BREITE - RAND, linie2, 0.8)
        seite.text(links, linie2 - 13, d['zweite'], 9, farbe=GRAU)
        seite.text(RAND + 10, linie2 + 20, 'Zweite Unterschrift', 9, True)
    for i, s in enumerate(seiten):
        s.text(BREITE - RAND, 40, f'Seite {i + 1} von {len(seiten)}', 8, farbe=GRAU, rechts=True)
    daten = _zusammensetzen(seiten, d.get('bild'), titel, patient, int(d.get('zeit') or time.time()), d.get('bild2'))
    tmp = ziel + '.tmp'
    with open(tmp, 'wb') as fh:
        fh.write(daten)
    os.chmod(tmp, 0o600)
    os.replace(tmp, ziel)


def _schrift_einbetten(neu, strom, f: TrueType, name: bytes) -> int:
    """Type0-Schrift (Identity-H) mit eingebetteter TrueType-Datei, Breiten der benutzten Glyphen und ToUnicode."""
    datei = neu(strom(b'/Length1 %d /Filter /FlateDecode' % len(f.roh), zlib.compress(f.roh, 9)))
    beschr = neu(b'<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>'
                 % (name, *f.bbox, f.aufwaerts, f.abwaerts, f.aufwaerts, datei))
    glyphen = sorted(f.benutzt)
    w = b' '.join(b'%d [%d]' % (g, round(f.breiten[min(g, len(f.breiten) - 1)] * 1000 / f.em)) for g in glyphen)
    cid = neu(b'<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> '
              b'/FontDescriptor %d 0 R /CIDToGIDMap /Identity /DW 600 /W [%s] >>' % (name, beschr, w))
    zeilen = b'\n'.join(b'<%04x> <%s>' % (g, f.benutzt[g].encode('utf-16-be').hex().encode()) for g in glyphen)
    cmap = (b'/CIDInit /ProcSet findresource begin 12 dict begin begincmap /CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def '
            b'/CMapName /Adobe-Identity-UCS def /CMapType 2 def 1 begincodespacerange <0000> <ffff> endcodespacerange\n'
            + b''.join(b'%d beginbfchar\n' % len(glyphen[i:i + 100]) + b'\n'.join(zeilen.split(b'\n')[i:i + 100]) + b'\nendbfchar\n' for i in range(0, len(glyphen), 100))
            + b'endcmap CMapName currentdict /CMap defineresource pop end end')
    tu = neu(strom(b'/Filter /FlateDecode', zlib.compress(cmap, 9)))
    return neu(b'<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>' % (name, cid, tu))


def _zusammensetzen(seiten: list[Seite], bild, titel: str, patient: str, zeit: int, bild2=None) -> bytes:
    objekte: list[bytes] = []

    def neu(inhalt: bytes) -> int:
        objekte.append(inhalt)
        return len(objekte)

    def strom(kopf: bytes, roh: bytes) -> bytes:
        return b'<< ' + kopf + b' /Length %d >>\nstream\n' % len(roh) + roh + b'\nendstream'

    katalog = neu(b'')
    seitenbaum = neu(b'')
    if _SCHRIFT:
        f1, f2 = (_schrift_einbetten(neu, strom, s, name) for s, name in zip(_SCHRIFT, (b'DejaVuSans', b'DejaVuSans-Bold')))
    else:
        f1 = neu(b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>')
        f2 = neu(b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>')
    xobj = b''
    eintraege = b''
    for name, b in ((b'Im1', bild), (b'Im2', bild2)):
        if b:
            bb, bh, roh = b
            im = neu(strom(b'/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode' % (bb, bh), zlib.compress(roh, 9)))
            eintraege += b' /%s %d 0 R' % (name, im)
    if eintraege:
        xobj = b' /XObject <<' + eintraege + b' >>'
    kinder = []
    for s in seiten:
        inhalt = neu(strom(b'/Filter /FlateDecode', zlib.compress(b'\n'.join(s.ops), 9)))
        kinder.append(neu(b'<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >>%s >> >>'
                          % (seitenbaum, BREITE, HOEHE, inhalt, f1, f2, xobj)))
    objekte[katalog - 1] = b'<< /Type /Catalog /Pages %d 0 R >>' % seitenbaum
    objekte[seitenbaum - 1] = b'<< /Type /Pages /Kids [%s] /Count %d >>' % (b' '.join(b'%d 0 R' % k for k in kinder), len(kinder))
    datum = time.strftime('D:%Y%m%d%H%M%S', time.localtime(zeit))
    info = neu(b'<< /Title %s /Subject %s /Producer (Praxisherz Signatur) /CreationDate (%s) >>' % (_info_text(titel), _info_text(patient), datum.encode()))
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
    png2 = os.path.join(ordner, f'{vid}-2.png')
    zweite = str(v['zweite']) if 'zweite' in spalten and v['zweite'] else ''
    ziel = os.path.join(ordner, f'{vid}.pdf')
    erzeugen({
        'titel': v['titel'], 'text': v['text'], 'patient': v['patient'], 'geburtsdatum': v['geburtsdatum'] if 'geburtsdatum' in spalten else '',
        'praxis': praxis if isinstance(praxis, dict) else {}, 'zeit': v['unterschrieben'], 'pad': pad, 'vorgang': vid, 'pruefsumme': pruefsumme,
        'bild': unterschriftsbild(png) if os.path.isfile(png) else None,
        'zweite': zweite, 'bild2': unterschriftsbild(png2) if zweite and os.path.isfile(png2) else None,
    }, ziel)
    return ziel
