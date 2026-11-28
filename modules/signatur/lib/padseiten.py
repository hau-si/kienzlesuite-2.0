"""Seiten für das Pad: der Vertragstext als Bilder mit Schaltflächen (Abbrechen, Zurück, Weiter) und eine Unterschriftsseite.

Alles wird mit Pillow selbst gezeichnet; das Pad zeigt nur fertige Bilder. Die Berührungsflächen (Hotspots) stehen als
Rechtecke bei jeder Seite, damit der Arbeitsprozess sie dem Pad melden kann."""
from __future__ import annotations

from PIL import Image, ImageDraw, ImageFont
from padtexte import t

BREITE, HOEHE = 1280, 800
RAND = 48
FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
FONT_FETT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
KOPF = 110
FUSS = 130
TEXT_GROESSE = 30
ZEILE = 44
GRUEN, GRAU, DUNKEL, HELL = (18, 105, 93), (110, 110, 110), (25, 25, 25), (236, 240, 239)


def schrift(groesse: int, fett: bool = False) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(FONT_FETT if fett else FONT, groesse)


def umbrechen(text: str, font: ImageFont.FreeTypeFont, breite: int) -> list[str]:
    """Bricht Text an Wortgrenzen um; leere Zeilen bleiben als Absatzabstand erhalten."""
    zeilen: list[str] = []
    for absatz in text.split('\n'):
        if not absatz.strip():
            zeilen.append('')
            continue
        aktuell = ''
        for wort in absatz.split(' '):
            probe = (aktuell + ' ' + wort).strip() if aktuell else wort
            if font.getlength(probe) <= breite or not aktuell:
                aktuell = probe
            else:
                zeilen.append(aktuell)
                aktuell = wort
        zeilen.append(aktuell)
    return zeilen


def seiten_aufteilen(text: str) -> list[list[str]]:
    font = schrift(TEXT_GROESSE)
    zeilen = umbrechen(text, font, BREITE - 2 * RAND)
    pro_seite = (HOEHE - KOPF - FUSS - 20) // ZEILE
    seiten = [zeilen[i:i + pro_seite] for i in range(0, len(zeilen), pro_seite)] or [[]]
    return seiten


def knopf(d: ImageDraw.ImageDraw, rechteck: tuple[int, int, int, int], text: str, hauptknopf: bool = False) -> None:
    l, o, b, h = rechteck
    d.rounded_rectangle((l, o, l + b, o + h), radius=18, fill=GRUEN if hauptknopf else HELL, outline=GRUEN, width=3)
    f = schrift(32, True)
    d.text((l + b / 2, o + h / 2), t(text), font=f, fill=(255, 255, 255) if hauptknopf else GRUEN, anchor='mm')


def _knopfreihe(namen: list[str], haupt: str) -> dict[str, tuple[int, int, int, int]]:
    """Rechtecke (links, oben, breite, hoehe) der Schaltflächen, gleichmäßig im Fußbereich verteilt."""
    luecke = 24
    breite = (BREITE - 2 * RAND - luecke * (len(namen) - 1)) // len(namen)
    return {n: (RAND + i * (breite + luecke), HOEHE - FUSS + 14, breite, FUSS - 40) for i, n in enumerate(namen)}


def kopfzeile(d: ImageDraw.ImageDraw, titel: str, rechts: str) -> None:
    d.text((RAND, 40), titel, font=schrift(38, True), fill=GRUEN, anchor='lm')
    d.text((BREITE - RAND, 40), rechts, font=schrift(26), fill=GRAU, anchor='rm')
    d.line((RAND, KOPF - 24, BREITE - RAND, KOPF - 24), fill=GRUEN, width=3)


def textseiten(titel: str, text: str) -> list[dict]:
    """Eine Seite je Textabschnitt: {'bild': Image, 'knoepfe': {name: rechteck}}. Letzte Seite hat „Weiter“ zur Unterschrift."""
    teile = seiten_aufteilen(text)
    out = []
    for i, zeilen in enumerate(teile):
        bild = Image.new('RGB', (BREITE, HOEHE), 'white')
        d = ImageDraw.Draw(bild)
        kopfzeile(d, titel, t('Seite {a} von {b}', a=i + 1, b=len(teile)))
        f = schrift(TEXT_GROESSE)
        y = KOPF
        for z in zeilen:
            d.text((RAND, y), z, font=f, fill=DUNKEL)
            y += ZEILE
        namen = ['Abbrechen'] + (['Zurück'] if i > 0 else []) + ['Weiter']
        rechtecke = _knopfreihe(namen, 'Weiter')
        for n, r in rechtecke.items():
            knopf(d, r, 'Weiter zur Unterschrift' if n == 'Weiter' and i == len(teile) - 1 else n, n == 'Weiter')
        out.append({'bild': bild, 'knoepfe': rechtecke})
    return out


def unterschriftsseite(titel: str, patient: str, beschriftung: str = 'Unterschrift', zurueck: bool = True) -> dict:
    """Seite mit Unterschriftsfeld: 'feld' ist das Rechteck für die Unterschrift."""
    bild = Image.new('RGB', (BREITE, HOEHE), 'white')
    d = ImageDraw.Draw(bild)
    kopfzeile(d, titel, patient)
    d.text((RAND, KOPF + 6), t('Bitte unterschreiben Sie im Feld und bestätigen Sie mit „OK“.'), font=schrift(30), fill=DUNKEL)
    feld = (RAND, KOPF + 70, BREITE - 2 * RAND, HOEHE - KOPF - FUSS - 100)
    d.rounded_rectangle((feld[0], feld[1], feld[0] + feld[2], feld[1] + feld[3]), radius=16, outline=GRUEN, width=4, fill=(250, 252, 252))
    ly = feld[1] + feld[3] - 70
    d.line((feld[0] + 30, ly, feld[0] + feld[2] - 30, ly), fill=GRAU, width=2)
    d.text((feld[0] + 34, ly + 8), t(beschriftung), font=schrift(22), fill=GRAU)
    namen = ['Abbrechen', 'Zurück', 'Löschen', 'OK'] if zurueck else ['Abbrechen', 'Löschen', 'OK']
    rechtecke = _knopfreihe(namen, 'OK')
    for n, r in rechtecke.items():
        knopf(d, r, n, n == 'OK')
    return {'bild': bild, 'knoepfe': rechtecke, 'feld': feld}


def hinweisseite(text: str) -> Image.Image:
    bild = Image.new('RGB', (BREITE, HOEHE), 'white')
    d = ImageDraw.Draw(bild)
    d.text((BREITE / 2, HOEHE / 2), t(text), font=schrift(44, True), fill=GRUEN, anchor='mm')
    return bild
