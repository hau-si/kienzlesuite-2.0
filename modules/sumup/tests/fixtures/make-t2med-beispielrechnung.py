#!/usr/bin/env python3
"""Erzeugt fixtures/t2med-beispielrechnung.pdf: eine vollständig erfundene Privatrechnung im Aufbau der T2med-Liquidation
(JasperReports-Vorlage, A4, zwei Seiten, Textebene). Alle Angaben sind Platzhalter: keine echte Praxis, Person oder Bankverbindung,
keine Bilder (kein Briefkopf, kein Logo). Aufruf: python3 make-t2med-beispielrechnung.py  (schreibt neben das Skript)."""
import os
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas

H = A4[1]
NUMBER, DATE, PATIENT, BIRTH = "IG2026-0001", "28.05.2026", "Max Mustermann", "01.01.1990"
ROWS = [  # Datum, Ziffer, Text (ein oder zwei Zeilen), Wert, Faktor, Betrag
    ("28.05.2026", "1", ["Beratung auch telefonisch"], "4,66 €", "2,30", "10,72 €"),
    ("28.05.2026", "7", ["Untersuchung, Organsystem", "Haut/Brust/Bauch/Bewegungsorgane"], "9,33 €", "2,30", "21,45 €"),
    ("28.05.2026", "75", ["Befundbericht, ausführlich"], "7,58 €", "2,30", "17,43 €"),
    ("28.05.2026", "1200", ["Refraktionsbestimmung (einfacher Sehtest)"], "3,44 €", "2,30", "7,91 €"),
    ("28.05.2026", "1228", ["Farbsinnprüfung, Farbtafeln n. Ishihara"], "3,56 €", "2,30", "8,18 €"),
    ("28.05.2026", "1400", ["Hörprüfung, genau"], "4,43 €", "2,30", "10,19 €"),
]
TOTAL = "75,88 €"

def build(path):
    c = canvas.Canvas(path, pagesize=A4)
    c.setTitle("Beispielrechnung (erfunden)"); c.setAuthor("Beispielpraxis"); c.setCreator("make-t2med-beispielrechnung.py")
    def t(x, y, s, size=10, bold=False, right=False):
        c.setFont("Helvetica-Bold" if bold else "Helvetica", size)
        (c.drawRightString if right else c.drawString)(x, H - y, s)
    t(71, 168, "Dr. med. Erika Beispiel | Musterstraße 1 | 12345 Musterstadt", 7)
    for i, line in enumerate(["Herrn", PATIENT, "Beispielweg 2", "12345 Musterstadt"]): t(71, 192 + 13 * i, line)
    for i, (k, v) in enumerate([("Bank:", "Beispielbank"), ("IBAN:", "DE89 3704 0044 0532 0130 00"), ("BIC:", "BSPLDEXXXXX")]): t(355, 266 + 15 * i, k); t(389, 266 + 15 * i, v)
    for i, (k, v) in enumerate([("Re.-Nr.:", NUMBER), ("Re.-Datum:", DATE), ("Patient:", PATIENT + " , " + BIRTH)]): t(71, 303 + 15 * i, k); t(125, 303 + 15 * i, v)
    t(71, 360, "LIQUIDATION", 12, True)
    t(71, 387, "Sehr geehrter Herr Mustermann ,")
    t(71, 410, "ich erlaube mir folgende ärztliche Leistungen vom 28.05.2026 bis 28.05.2026 in Rechnung zu stellen:")
    y = 444
    for x, s in [(76, "Datum"), (139.5, "Ziffer"), (172, "Leistungstext"), (434, "Wert"), (460, "Faktor"), (505, "Betrag")]: t(x, y, s, 9, True)
    y += 19
    for datum, ziffer, text, wert, faktor, betrag in ROWS:
        t(71, y, datum, 9); t(147, y, ziffer, 9); t(172, y, text[0], 9); t(430, y, wert, 9); t(466, y, faktor, 9); t(560, y, betrag, 9, right=True)
        for extra in text[1:]: y += 10.5; t(172, y, extra, 9)
        y += 15
    t(380, y + 12, "Gesamtsumme:", 9, True); t(560, y + 12, TOTAL, 9, True, right=True)
    t(300, 810, "Seite   1 / 2", 9, right=False)
    c.showPage()
    t(71, 70, "Re.-Nr.: " + NUMBER + "           Re.-Datum: " + DATE + "        Patient: " + PATIENT, 8)
    t(71, 100, "Diagnosen:", 9, True); t(71, 114, "Tauglichkeitsuntersuchung (Beispiel)", 9)
    t(71, 170, "Bitte überweisen Sie den Betrag von " + TOTAL + " ohne Abzug bis zum 27.06.2026 auf das unten aufgeführte")
    t(71, 183, "Konto.")
    t(71, 210, "Mit freundlichen Grüßen"); t(71, 223, "Dr. med. Erika Beispiel")
    t(300, 810, "Seite   2 / 2", 9)
    c.showPage(); c.save()

if __name__ == "__main__":
    build(os.path.join(os.path.dirname(os.path.abspath(__file__)), "t2med-beispielrechnung.pdf"))
