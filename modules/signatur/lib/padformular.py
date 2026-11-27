"""Bausteine (Editor C) am Pad: Seiten aufteilen, zeichnen, Antippen auswerten, Pflichtfelder, Bildschirmtastatur.

Die Seiten werden bei jedem Antippen neu gezeichnet (Häkchen, Auswahl, geänderte E-Mail). Die Aufteilung hängt nicht von den
Antworten ab, damit nichts springt. Tastflächen (Hotspots) heißen: 'Abbrechen', 'Zurück', 'Weiter', 'a:<id>' (Ankreuzfeld),
'o:<id>:<n>' (Möglichkeit n), 'e:<id>:<feld>' (E-Mail/Telefon ändern), auf der Tastatur 'k:<taste>'. Höchstens 32 Tastflächen
je Seite (die Grenze des Pads ist nicht dokumentiert); mehr Möglichkeiten verteilen sich auf weitere Seiten.
Antworten: {id: bool (Ankreuzfeld) | int|None (Einzelauswahl) | [int] (Mehrfachauswahl) | {feld: wert} (Patientendaten)}."""
from __future__ import annotations

import os
import re

from PIL import Image, ImageDraw

import padseiten as ps

TEXT, ZEILE = 28, 40
KASTEN = 34
LUECKE = 16
INHALT_OBEN = ps.KOPF
INHALT_UNTEN = ps.HOEHE - ps.FUSS - 46   # darüber ein Hinweis (rot) bei fehlenden Pflichtangaben
BREITE_INHALT = ps.BREITE - 2 * ps.RAND
MAX_TASTEN = 32
ROT = (180, 35, 24)
FELDER = {'email': 'E-Mail', 'telefon': 'Telefon'}
TEL = re.compile(r'^[0-9 +()/.-]{3,40}$')
MAIL = re.compile(r'^[^\s@]{1,64}@[^\s@]{1,190}\.[^\s@]{2,}$')


# ---- Text mit Auszeichnung (**fett**, *kursiv*, __unterstrichen__) ----

def laeufe(zeile: str) -> list[tuple[str, str]]:
    """Zerlegt eine Zeile in (Text, Stil) mit Stil '' | 'f' (fett) | 'k' (kursiv) | 'u' (unterstrichen)."""
    out: list[tuple[str, str]] = []
    for teil in re.split(r'(\*\*.+?\*\*|__.+?__|(?<![*\w])\*(?!\s).+?(?<!\s)\*(?![*\w]))', zeile):
        if not teil:
            continue
        if teil.startswith('**') and teil.endswith('**') and len(teil) > 4:
            out.append((teil[2:-2], 'f'))
        elif teil.startswith('__') and teil.endswith('__') and len(teil) > 4:
            out.append((teil[2:-2], 'u'))
        elif teil.startswith('*') and teil.endswith('*') and len(teil) > 2:
            out.append((teil[1:-1], 'k'))
        else:
            out.append((teil, ''))
    return out


def _font(stil: str, groesse: int):
    return ps.schrift(groesse, stil == 'f')


def umbrechen_laeufe(zeile: str, breite: int, groesse: int) -> list[list[tuple[str, str]]]:
    """Bricht eine ausgezeichnete Zeile an Wortgrenzen um; jede Ergebniszeile ist eine Liste von (Wort mit Leerzeichen, Stil)."""
    woerter: list[tuple[str, str]] = []
    for text, stil in laeufe(zeile):
        for w in re.findall(r'\S+\s*|\s+', text):
            woerter.append((w, stil))
    zeilen, aktuell, x = [], [], 0.0
    for w, stil in woerter:
        lang = _font(stil, groesse).getlength(w.rstrip())
        if aktuell and x + lang > breite:
            zeilen.append(aktuell)
            aktuell, x = [], 0.0
            w = w.lstrip()
            if not w:
                continue
        aktuell.append((w, stil))
        x += _font(stil, groesse).getlength(w)
    if aktuell:
        zeilen.append(aktuell)
    return zeilen or [[]]


def zeichne_laeufe(bild: Image.Image, x: float, y: float, teile: list[tuple[str, str]], groesse: int, farbe=ps.DUNKEL) -> None:
    d = ImageDraw.Draw(bild)
    for w, stil in teile:
        f = _font(stil, groesse)
        lang = f.getlength(w)
        if stil == 'k':
            # Kursiv ohne eigene Schriftdatei: Wort einzeln zeichnen und neigen.
            b, h = int(lang + groesse * 0.4) + 2, int(groesse * 1.5)
            maske = Image.new('L', (b, h), 0)
            ImageDraw.Draw(maske).text((int(groesse * 0.25), 0), w, font=f, fill=255)
            maske = maske.transform(maske.size, getattr(Image, 'Transform', Image).AFFINE, (1, 0.22, -groesse * 0.2, 0, 1, 0), resample=getattr(Image, 'Resampling', Image).BICUBIC)
            bild.paste(Image.new('RGB', maske.size, farbe), (int(x - groesse * 0.25), int(y)), maske)
        else:
            d.text((x, y), w, font=f, fill=farbe)
            if stil == 'u':
                d.line((x, y + groesse + 3, x + f.getlength(w.rstrip()), y + groesse + 3), fill=farbe, width=2)
        x += lang


# ---- Aufteilung in Seiten ----

def _elemente(bloecke: list[dict], bilder: str) -> list[dict]:
    """Zeichenbare Elemente mit Höhe; 'tasten' = Zahl der Tastflächen, 'umbruch' erzwingt eine neue Seite."""
    out: list[dict] = []
    for b in bloecke:
        bid, typ = b.get('id', ''), b.get('typ')
        start = len(out)
        if typ == 'text':
            for z in str(b.get('text', '')).split('\n'):
                ueber = z.startswith('# ')
                g = 34 if ueber else TEXT
                inhalt = z[2:] if ueber else z
                vor = ''
                m = re.match(r'^(- |\d{1,2}\. )(.*)$', inhalt)
                if m and not ueber:
                    vor, inhalt = ('•' if m.group(1) == '- ' else m.group(1).strip()), m.group(2)
                if not inhalt.strip():
                    out.append({'art': 'leer', 'h': ZEILE // 2})
                    continue
                for teile in umbrechen_laeufe(('**' + inhalt + '**') if ueber else inhalt, BREITE_INHALT - (44 if vor else 0), g):
                    out.append({'art': 'zeile', 'teile': teile, 'g': g, 'vor': vor, 'h': ZEILE + (8 if ueber else 0)})
                    vor = ''
        elif typ == 'patientendaten':
            for f in b.get('felder', []):
                out.append({'art': 'feld', 'id': bid, 'feld': f, 'aenderbar': bool(b.get('aenderbar')), 'h': 66, 'tasten': 1 if b.get('aenderbar') else 0})
        elif typ == 'ankreuz':
            zeilen = umbrechen_laeufe(str(b.get('text', '')) + (' *' if b.get('pflicht') else ''), BREITE_INHALT - 60, TEXT)
            out.append({'art': 'ankreuz', 'id': bid, 'zeilen': zeilen, 'h': max(len(zeilen) * ZEILE, KASTEN) + 14, 'tasten': 1})
        elif typ in ('einzel', 'mehrfach'):
            for teile in umbrechen_laeufe('**' + str(b.get('frage', '')) + (' *' if b.get('pflicht') else '') + '**', BREITE_INHALT, TEXT):
                out.append({'art': 'zeile', 'teile': teile, 'g': TEXT, 'vor': '', 'h': ZEILE})
            for n, o in enumerate(b.get('optionen', [])):
                out.append({'art': 'option', 'id': bid, 'n': n, 'mehrfach': typ == 'mehrfach', 'text': str(o), 'h': 56, 'tasten': 1})
        elif typ == 'liste':
            for n, e in enumerate(b.get('eintraege', [])):
                vor = f'{n + 1}.' if b.get('nummeriert') else '•'
                for i, teile in enumerate(umbrechen_laeufe(str(e), BREITE_INHALT - 44, TEXT)):
                    out.append({'art': 'zeile', 'teile': teile, 'g': TEXT, 'vor': vor if i == 0 else '', 'einzug': True, 'h': ZEILE})
        elif typ == 'bild':
            pfad = os.path.join(bilder, str(b.get('datei', '')))
            if re.fullmatch(r'[0-9a-f]{64}\.(png|jpg)', str(b.get('datei', ''))) and os.path.isfile(pfad):
                try:
                    with Image.open(pfad) as im:
                        im.load()
                        bild = im.convert('RGB')
                except OSError:
                    bild = None
                if bild is not None:
                    breite = BREITE_INHALT * max(20, min(100, int(b.get('breite', 100)))) / 100
                    faktor = min(breite / bild.width, 380 / bild.height)
                    bild = bild.resize((max(1, int(bild.width * faktor)), max(1, int(bild.height * faktor))))
                    out.append({'art': 'bild', 'bild': bild, 'h': bild.height + 10})
            if b.get('text'):
                out.append({'art': 'zeile', 'teile': [(str(b['text']), '')], 'g': 22, 'vor': '', 'h': 32, 'grau': True})
        elif typ == 'trenner':
            out.append({'art': 'linie', 'h': 30})
        elif typ == 'seite':
            out.append({'art': 'umbruch', 'h': 0})
        if typ in ('ankreuz', 'einzel', 'mehrfach', 'patientendaten'):
            for e in out[start:]:
                e['gruppe'] = bid   # Frage und Möglichkeiten bleiben auf einer Seite (wenn sie auf eine passen)
        if len(out) > start:
            out[-1]['h'] += LUECKE
    return out


def seiten(bloecke: list[dict], bilder: str = '') -> list[list[dict]]:
    """Teilt die Elemente auf Seiten auf (Höhe des Inhaltsbereichs, höchstens MAX_TASTEN - 3 Tastflächen je Seite)."""
    out: list[list[dict]] = [[]]
    y, tasten = 0, 0
    platz = INHALT_UNTEN - INHALT_OBEN
    elemente = _elemente(bloecke, bilder)
    gruppe = {}
    for e in elemente:
        if e.get('gruppe'):
            gruppe[e['gruppe']] = gruppe.get(e['gruppe'], 0) + e['h']
    begonnen: set = set()
    for e in elemente:
        g = e.get('gruppe')
        if g and g not in begonnen:
            begonnen.add(g)
            if out[-1] and y + gruppe[g] > platz and gruppe[g] <= platz:
                out.append([])
                y, tasten = 0, 0
        if e['art'] == 'umbruch':
            if out[-1]:
                out.append([])
                y, tasten = 0, 0
            continue
        if out[-1] and (y + e['h'] > platz or tasten + e.get('tasten', 0) > MAX_TASTEN - 3):
            out.append([])
            y, tasten = 0, 0
        e['y'] = y
        out[-1].append(e)
        y += e['h']
        tasten += e.get('tasten', 0)
    return out


# ---- Antworten und Pflichtfelder ----

def antworten_start(bloecke: list[dict]) -> dict:
    a: dict = {}
    for b in bloecke:
        if b.get('typ') == 'ankreuz':
            a[b['id']] = False
        elif b.get('typ') == 'einzel':
            a[b['id']] = None
        elif b.get('typ') == 'mehrfach':
            a[b['id']] = []
        elif b.get('typ') == 'patientendaten':
            a[b['id']] = {f: str((b.get('werte') or {}).get(f, '')) for f in b.get('felder', [])}
    return a


def fehlend(bloecke: list[dict], antworten: dict) -> list[str]:
    """Kennungen der Pflicht-Bausteine ohne Antwort."""
    out = []
    for b in bloecke:
        if not b.get('pflicht'):
            continue
        w = antworten.get(b['id'])
        if (b['typ'] == 'ankreuz' and w is not True) or (b['typ'] == 'einzel' and w is None) or (b['typ'] == 'mehrfach' and not w):
            out.append(b['id'])
    return out


def antippen(bloecke: list[dict], antworten: dict, name: str) -> None:
    """Wertet eine Tastfläche des Formulars aus (Ankreuzfeld umschalten, Möglichkeit wählen)."""
    teile = name.split(':')
    typen = {b['id']: b['typ'] for b in bloecke}
    if teile[0] == 'a' and typen.get(teile[1]) == 'ankreuz':
        antworten[teile[1]] = not antworten.get(teile[1])
    elif teile[0] == 'o' and len(teile) == 3 and teile[2].isdigit():
        bid, n = teile[1], int(teile[2])
        if typen.get(bid) == 'einzel':
            antworten[bid] = None if antworten.get(bid) == n else n
        elif typen.get(bid) == 'mehrfach':
            gew = list(antworten.get(bid) or [])
            antworten[bid] = sorted(set(gew) ^ {n})


def seite_von(seitenliste: list[list[dict]], bid: str) -> int:
    for i, s in enumerate(seitenliste):
        if any(e.get('id') == bid for e in s):
            return i
    return 0


# ---- Zeichnen ----

def _kasten(d: ImageDraw.ImageDraw, x: float, y: float, rund: bool, an: bool) -> None:
    box = (x, y, x + KASTEN, y + KASTEN)
    if rund:
        d.ellipse(box, outline=ps.GRUEN, width=4, fill='white')
        if an:
            d.ellipse((x + 9, y + 9, x + KASTEN - 9, y + KASTEN - 9), fill=ps.GRUEN)
    else:
        d.rounded_rectangle(box, radius=6, outline=ps.GRUEN, width=4, fill=ps.GRUEN if an else 'white')
        if an:
            d.line((x + 8, y + 18, x + 15, y + 26, x + 27, y + 9), fill='white', width=5)


def zeichne_seite(titel: str, seite: list[dict], nr: int, anzahl: int, antworten: dict, hinweis: str = '') -> dict:
    """Bild und Tastflächen {name: (links, oben, breite, höhe)} einer Formularseite."""
    bild = Image.new('RGB', (ps.BREITE, ps.HOEHE), 'white')
    d = ImageDraw.Draw(bild)
    ps.kopfzeile(d, titel, f'Seite {nr + 1} von {anzahl}')
    tasten: dict[str, tuple[int, int, int, int]] = {}
    x0 = ps.RAND
    for e in seite:
        y = INHALT_OBEN + e['y']
        art = e['art']
        if art == 'zeile':
            x = x0 + (44 if e.get('einzug') or e.get('vor') else 0)
            if e.get('vor'):
                d.text((x0 + (12 if e['vor'] == '•' else 0), y), e['vor'], font=ps.schrift(e['g']), fill=ps.GRUEN)
            zeichne_laeufe(bild, x, y, e['teile'], e['g'], (110, 110, 110) if e.get('grau') else ps.DUNKEL)
        elif art == 'feld':
            wert = (antworten.get(e['id']) or {}).get(e['feld'], '')
            d.rounded_rectangle((x0, y, x0 + BREITE_INHALT, y + 54), radius=10, outline=(202, 216, 210), width=2)
            d.text((x0 + 16, y + 27), f"{FELDER.get(e['feld'], e['feld'])}: {wert or '–'}", font=ps.schrift(TEXT), fill=ps.DUNKEL, anchor='lm')
            if e['aenderbar']:
                r = (x0 + BREITE_INHALT - 190, y + 6, 180, 42)
                d.rounded_rectangle((r[0], r[1], r[0] + r[2], r[1] + r[3]), radius=10, fill=ps.HELL, outline=ps.GRUEN, width=2)
                d.text((r[0] + r[2] / 2, r[1] + r[3] / 2), 'Ändern', font=ps.schrift(26, True), fill=ps.GRUEN, anchor='mm')
                tasten[f"e:{e['id']}:{e['feld']}"] = r
        elif art == 'ankreuz':
            _kasten(d, x0, y + 3, False, antworten.get(e['id']) is True)
            for i, teile in enumerate(e['zeilen']):
                zeichne_laeufe(bild, x0 + 56, y + i * ZEILE, teile, TEXT)
            tasten[f"a:{e['id']}"] = (x0 - 8, y - 6, BREITE_INHALT + 16, e['h'] - LUECKE + 6)
        elif art == 'option':
            w = antworten.get(e['id'])
            an = (e['n'] in (w or [])) if e['mehrfach'] else w == e['n']
            _kasten(d, x0 + 12, y + 6, not e['mehrfach'], an)
            d.text((x0 + 64, y + 4), e['text'], font=ps.schrift(TEXT), fill=ps.DUNKEL)
            tasten[f"o:{e['id']}:{e['n']}"] = (x0, y, BREITE_INHALT, 50)
        elif art == 'bild':
            bild.paste(e['bild'], (int(x0), int(y)))
        elif art == 'linie':
            d.line((x0, y + 12, x0 + BREITE_INHALT, y + 12), fill=(202, 216, 210), width=3)
    if hinweis:
        d.text((ps.BREITE / 2, INHALT_UNTEN + 20), hinweis, font=ps.schrift(26, True), fill=ROT, anchor='mm')
    namen = ['Abbrechen'] + (['Zurück'] if nr > 0 else []) + ['Weiter']
    reihe = ps._knopfreihe(namen, 'Weiter')
    for n, r in reihe.items():
        ps.knopf(d, r, 'Weiter zur Unterschrift' if n == 'Weiter' and nr == anzahl - 1 else n, n == 'Weiter')
    tasten.update(reihe)
    return {'bild': bild, 'knoepfe': tasten}


# ---- Bildschirmtastatur (E-Mail, Telefon) ----

TASTATUR = {
    'abc': ['qwertzuiop', 'asdfghjkl', 'yxcvbnm@.'],
    '123': ['1234567890', '-_.@+'],
    'tel': ['123', '456', '789', '+0/', '- '],
}


def zeichne_tastatur(feld: str, wert: str, modus: str, hinweis: str = '') -> dict:
    """Tastatur für E-Mail (Ebenen „abc“ und „123“) oder Telefon („tel“). Tastflächen 'k:<zeichen>', 'k:ebene', 'k:zurueck', 'k:OK',
    'k:Abbrechen'. Höchstens 32 Tastflächen."""
    bild = Image.new('RGB', (ps.BREITE, ps.HOEHE), 'white')
    d = ImageDraw.Draw(bild)
    ps.kopfzeile(d, f'{FELDER.get(feld, feld)} ändern', 'Tastatur')
    d.rounded_rectangle((ps.RAND, ps.KOPF, ps.BREITE - ps.RAND, ps.KOPF + 70), radius=12, outline=ps.GRUEN, width=3)
    d.text((ps.RAND + 20, ps.KOPF + 35), (wert or '') + '|', font=ps.schrift(34), fill=ps.DUNKEL, anchor='lm')
    if hinweis:
        d.text((ps.BREITE / 2, ps.KOPF + 96), hinweis, font=ps.schrift(24, True), fill=ROT, anchor='mm')
    tasten: dict[str, tuple[int, int, int, int]] = {}
    reihen = TASTATUR[modus]
    oben, h = ps.KOPF + 120, 70
    for r, reihe in enumerate(reihen):
        b = 104 if modus != 'tel' else 150
        start = (ps.BREITE - len(reihe) * (b + 10)) / 2
        for i, z in enumerate(reihe):
            rect = (int(start + i * (b + 10)), oben + r * (h + 10), b, h)
            d.rounded_rectangle((rect[0], rect[1], rect[0] + b, rect[1] + h), radius=10, fill=ps.HELL, outline=ps.GRUEN, width=2)
            d.text((rect[0] + b / 2, rect[1] + h / 2), 'Leer' if z == ' ' else z, font=ps.schrift(32, True), fill=ps.GRUEN, anchor='mm')
            tasten['k:' + z] = rect
    namen = (['k:ebene'] if modus != 'tel' else []) + ['k:zurueck', 'k:Abbrechen', 'k:OK']
    beschr = {'k:ebene': '123' if modus == 'abc' else 'abc', 'k:zurueck': '⌫', 'k:Abbrechen': 'Abbrechen', 'k:OK': 'OK'}
    reihe = ps._knopfreihe(namen, 'k:OK')
    for n, r in reihe.items():
        ps.knopf(d, r, beschr[n], n == 'k:OK')
    tasten.update(reihe)
    return {'bild': bild, 'knoepfe': tasten}


def tippen(wert: str, modus: str, taste: str) -> tuple[str, str]:
    """Neue Eingabe und Ebene nach einer Taste (nur Zeichentasten, Ebene und Zurück)."""
    if taste == 'k:zurueck':
        return wert[:-1], modus
    if taste == 'k:ebene':
        return wert, '123' if modus == 'abc' else 'abc'
    z = taste[2:]
    if len(z) == 1 and len(wert) < (40 if modus == 'tel' else 120):
        return wert + z, modus
    return wert, modus


def pruefe_eingabe(feld: str, wert: str) -> str:
    """Leer = in Ordnung, sonst ein kurzer Hinweis für das Pad."""
    w = wert.strip()
    if feld == 'telefon' and w and not TEL.match(w):
        return 'Bitte nur Ziffern, Leerzeichen und + / - verwenden.'
    if feld == 'email' and w and not MAIL.match(w):
        return 'Bitte eine gültige E-Mail-Adresse eingeben.'
    return ''


# ---- Text für das PDF (mit Antworten) ----

def ohne_auszeichnung(t: str) -> str:
    t = re.sub(r'^# ', '', t, flags=re.M).replace('**', '').replace('__', '')
    return re.sub(r'(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])', r'\1', t)


def text_mit_antworten(bloecke: list[dict], antworten: dict) -> str:
    """Klartext für das PDF: Kästchen „[X]“/„[ ]“, Auswahl „(X)“/„( )“, geänderte Kontaktdaten gekennzeichnet (nur WinAnsi-Zeichen)."""
    teile = []
    for b in bloecke:
        typ, bid = b.get('typ'), b.get('id')
        w = antworten.get(bid)
        if typ == 'text':
            teile.append(ohne_auszeichnung(str(b.get('text', ''))))
        elif typ == 'patientendaten':
            alt = b.get('werte') or {}
            zeilen = []
            for f in b.get('felder', []):
                neu = (w or {}).get(f, alt.get(f, ''))
                zeilen.append(f"{FELDER.get(f, f)}: {neu or '-'}" + (' (am Pad geändert)' if neu != alt.get(f, '') else ''))
            teile.append('\n'.join(zeilen))
        elif typ == 'ankreuz':
            teile.append(('[X] ' if w is True else '[ ] ') + str(b.get('text', '')))
        elif typ in ('einzel', 'mehrfach'):
            gew = (w or []) if typ == 'mehrfach' else ([w] if w is not None else [])
            sym = ('[X] ', '[ ] ') if typ == 'mehrfach' else ('(X) ', '( ) ')
            teile.append(str(b.get('frage', '')) + '\n' + '\n'.join((sym[0] if n in gew else sym[1]) + str(o) for n, o in enumerate(b.get('optionen', []))))
        elif typ == 'liste':
            teile.append('\n'.join((f'{n + 1}. ' if b.get('nummeriert') else '- ') + str(e) for n, e in enumerate(b.get('eintraege', []))))
        elif typ == 'bild':
            teile.append('[Bild' + (': ' + str(b['text']) if b.get('text') else '') + ']')
        elif typ == 'trenner':
            teile.append('———')
    return '\n\n'.join(t for t in teile if t).strip()


def antwort_kurz(b: dict, w) -> str:
    """Antwort eines Bausteins als kurzer Text für den Akteneintrag ({{antwort}})."""
    typ = b.get('typ')
    if typ == 'ankreuz':
        return 'ja' if w is True else 'nein'
    if typ == 'einzel':
        opt = b.get('optionen', [])
        return str(opt[w]) if isinstance(w, int) and 0 <= w < len(opt) else '-'
    if typ == 'mehrfach':
        opt = b.get('optionen', [])
        gew = [str(opt[n]) for n in (w or []) if isinstance(n, int) and 0 <= n < len(opt)]
        return ', '.join(gew) if gew else '-'
    if typ == 'patientendaten':
        alt = b.get('werte') or {}
        return ', '.join(f"{FELDER.get(f, f)} {(w or {}).get(f, alt.get(f, '')) or '-'}" for f in b.get('felder', []))
    return ''


def akte_text(bloecke: list[dict], antworten: dict) -> str:
    """Eintrag für die T2med-Akte: nur Bausteine mit eigenem Akteneintrag, {{antwort}} = Antwort am Pad (eine Zeile je Baustein)."""
    zeilen = []
    for b in bloecke:
        vorlage = str(b.get('akte') or '').strip()
        if not vorlage:
            continue
        zeile = re.sub(r'\{\{\s*antwort\s*\}\}', lambda _m: antwort_kurz(b, antworten.get(b.get('id'))), vorlage)
        zeilen.append(ohne_auszeichnung(zeile).replace('\n', ' ').strip())
    return '\n'.join(z for z in zeilen if z)[:4000]


def kontakt_aenderungen(bloecke: list[dict], antworten: dict) -> tuple[dict, dict]:
    """(alt, neu) der am Pad geänderten E-Mail/Telefon-Werte (nur änderbare Felder); neu enthält nur Geändertes."""
    alt, neu = {}, {}
    for b in bloecke:
        if b.get('typ') != 'patientendaten' or not b.get('aenderbar'):
            continue
        werte = b.get('werte') or {}
        for f in b.get('felder', []):
            v = str((antworten.get(b['id']) or {}).get(f, werte.get(f, ''))).strip()
            if v != str(werte.get(f, '')):
                alt[f], neu[f] = str(werte.get(f, '')), v
    return alt, neu
