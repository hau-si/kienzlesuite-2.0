#!/usr/bin/env python3
"""Erzeugt das GOÄ-Verzeichnis der Kasse (public/assets/goae-verzeichnis.json) aus dem amtlichen Text.

Quelle: Gebührenordnung für Ärzte, Anlage „Gebührenverzeichnis für ärztliche Leistungen“, als Markdown aus
https://github.com/bundestag/gesetze (Datei g/goae_1982/index.md; Spiegel von gesetze-im-internet.de).
Die GOÄ ist als Verordnung ein amtliches Werk (§ 5 UrhG) und frei nutzbar.

Aufruf:  python3 scripts/goae-verzeichnis.py <pfad/zu/index.md> > public/assets/goae-verzeichnis.json

Ausgabe: {"stand": …, "punktwert": 5.82873, "eintraege": [[nummer, abschnitt, punkte, einfachsatz_cent, rahmen, text, nur_einfach], …]}
nur_einfach = 1: Zuschlag, nur mit dem einfachen Gebührensatz berechnungsfähig.
rahmen: standard (2,3/3,5), technical (1,8/2,5: Abschnitt A, E, O) oder lab (1,15/1,3: Abschnitt M und Nr. 437), § 5 GOÄ.
"""
import json
import re
import sys
from decimal import ROUND_HALF_UP, Decimal

PUNKTWERT = Decimal('5.82873')  # Cent, § 5 Abs. 1 GOÄ
# Abschnitte laut Übersicht der Anlage (Nummernbereiche).
ABSCHNITTE = [('B', 1, 109), ('C', 200, 449), ('D', 450, 498), ('E', 500, 569), ('F', 600, 793), ('G', 800, 887),
              ('H', 1001, 1168), ('I', 1200, 1386), ('J', 1400, 1639), ('K', 1700, 1860), ('L', 2000, 3321),
              ('M', 3500, 4787), ('N', 4800, 4873), ('O', 5000, 5855), ('P', 6000, 6018)]
# Druckfehler der Quelle: Lithium steht dort als 4114 (Nummer doppelt), amtlich 4214 (nach 4213).
KORREKTUREN = {('4114', 'Lithium'): '4214'}
# Zuschlag K 1: Die Quelle bricht die Zeile um, Punktzahl und Rest stehen in der nächsten Tabellenzeile.
ZEILENBRUCH = {'K1': (' 4. Lebensjahr', '120')}
# Die DM-Spalte der Quelle (alter Stand) passt hier nicht zur Punktzahl; maßgeblich ist die Punktzahl.
DM_ABWEICHUNG = {'2518', '2542'}
NUMMER = re.compile(r'^(?:\d{1,4}[a-z]?|[A-K](?: ?\d)?)$')
# Zuschläge, die nur mit dem einfachen Gebührensatz berechnet werden dürfen (Abschnitt B II, V, VI und C VI, VIII).
NUR_EINFACH = {'401', '404', '405', '406'} | {str(n) for n in range(440, 450)}


def abschnitt(nummer: str) -> str:
    if not nummer[0].isdigit():
        return 'B'  # Zuschläge A bis K2 zu Beratungen und Besuchen (Abschnitt B II, V, VI)
    n = int(re.match(r'\d+', nummer).group())
    for buchstabe, von, bis in ABSCHNITTE:
        if von <= n <= bis:
            return buchstabe
    raise ValueError('Nummer ohne Abschnitt: ' + nummer)


def abschnitt_a(text: str) -> set:
    """Nummern aus Abschnitt A (Gebühren in besonderen Fällen: technischer Rahmen bis 2,5)."""
    teil = text.split('**A. Gebühren in besonderen Fällen**', 1)[1].split('**B. ', 1)[0]
    teil = re.sub(r'\s+', ' ', teil.split('werden:', 1)[1])
    nummern = set()
    for von, bis in re.findall(r'(\d+[a-z]?)(?: bis (\d+))?', re.sub(r'in Abschnitt [A-P]', '', teil)):
        if bis:
            nummern.update(str(n) for n in range(int(von), int(bis) + 1))
        else:
            nummern.add(von)
    return nummern


def zeilen(text: str):
    """Tabellenzeilen der Anlage: Liste der Zellen (Text ohne Umbrüche)."""
    anlage = text.split('## Anlage Gebührenverzeichnis', 1)[1]
    zeile, zelle = None, None
    for line in anlage.split('\n'):
        if line.startswith('*    *'):
            if zeile is not None:
                yield zeile
            zeile, zelle = [], None
            rest = line[6:].strip()
            zeile.append(rest)
            zelle = 0
        elif zeile is not None and re.match(r'^    \*( |$)', line):
            zeile.append(line[5:].strip())
            zelle = len(zeile) - 1
        elif zeile is not None and line.startswith('        ') and line.strip():
            zeile[zelle] = (zeile[zelle] + ' ' + line.strip()).strip()
        elif zeile is not None and line.strip() and not line.startswith(' '):
            yield zeile
            zeile = None
    if zeile is not None:
        yield zeile


def main(pfad: str) -> None:
    with open(pfad, encoding='utf-8') as datei:
        text = datei.read()
    stand = re.search(r'Zuletzt geändert durch\n:\s+(.+)', text)
    technisch = abschnitt_a(text) | {'437'}
    eintraege, gesehen, ohne, gruppe = [], set(), [], None
    for zellen in zeilen(text):
        if zellen and zellen[0] == '':
            zellen = zellen[1:]  # Tabellen mit leerer Vorspalte (z. B. Abschnitt C, D, E)
        if len(zellen) < 3:
            continue
        # Laborkataloge (Abschnitt M): Eine Zeile ohne Nummer nennt Leistung und Punktzahl („Mikroskopische
        # Einzelbestimmung, je Meßgröße … 60“), die folgenden Nummern ohne Punktzahl gehören dazu.
        if not NUMMER.match(zellen[0]):
            if zellen[0] and zellen[1].replace('.', '').isdigit():
                gruppe = (re.sub(r'\s+', ' ', zellen[0]).strip(), zellen[1].replace('.', ''))
            continue
        nummer, leistung = zellen[0].replace(' ', ''), re.sub(r'\s+', ' ', zellen[1]).strip()
        nummer = KORREKTUREN.get((nummer, leistung), nummer)
        punkte = zellen[2].replace('.', '')
        if nummer in ZEILENBRUCH and not punkte:
            leistung, punkte = leistung + ZEILENBRUCH[nummer][0], ZEILENBRUCH[nummer][1]
        if punkte.isdigit():
            gruppe = None
        elif not punkte and gruppe:
            leistung, punkte = leistung + ' – ' + gruppe[0], gruppe[1]
        if not punkte.isdigit() or not leistung:
            ohne.append(nummer)
            continue
        # Gegenprobe mit der DM-Spalte der Quelle (Punktwert damals 11,4 Pfennig), soweit vorhanden.
        dm = zellen[3].replace('.', '').replace(',--', ',00') if len(zellen) > 3 and zellen[2] == punkte else ''
        if nummer not in DM_ABWEICHUNG and re.fullmatch(r'\d+,\d\d', dm) and abs(int(dm.replace(',', '')) - round(int(punkte) * 11.4)) > 1:
            raise ValueError(f'Punktzahl passt nicht zur DM-Spalte: {nummer} ({punkte} Punkte, {dm} DM)')
        if nummer in gesehen:
            raise ValueError('Nummer doppelt: ' + nummer)
        gesehen.add(nummer)
        teil = abschnitt(nummer)
        rahmen = 'lab' if teil == 'M' or nummer == '437' else 'technical' if teil in ('E', 'O') or nummer in technisch else 'standard'
        cent = int((int(punkte) * PUNKTWERT).quantize(Decimal(1), rounding=ROUND_HALF_UP))
        einfach = not nummer[0].isdigit() or nummer in NUR_EINFACH
        eintraege.append([nummer, teil, int(punkte), cent, rahmen, leistung, 1 if einfach else 0])
    print('ohne Punktzahl:', ' '.join(ohne), file=sys.stderr)
    if len(eintraege) < 2000:
        raise ValueError(f'Zu wenige Einträge gelesen: {len(eintraege)}')
    json.dump({'quelle': 'GOÄ, Anlage Gebührenverzeichnis (amtlicher Text, gesetze-im-internet.de)',
               'stand': stand.group(1).strip() if stand else '', 'punktwert': float(PUNKTWERT), 'eintraege': eintraege},
              sys.stdout, ensure_ascii=False, separators=(',', ':'))
    sys.stdout.write('\n')


if __name__ == '__main__':
    main(sys.argv[1])
