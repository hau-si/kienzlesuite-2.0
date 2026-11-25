#!/usr/bin/env python3
"""Erzeugt eine fiktive Text-PDF-Rechnung mit unbekannter Beschriftung der Rechnungsnummer („Beleg-Nr.:“) auf stdout. Nur für Tests."""
import io, sys
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas

buffer = io.BytesIO()
c = canvas.Canvas(buffer, pagesize=A4)
c.setFont("Helvetica", 11)
rows = ["Praxis Beispiel | Musterstraße 1 | 12345 Musterstadt", "Frau", "Erika Musterfrau", "",
        "Beleg-Nr.:      RE2026-0042", "Re.-Datum:    15.03.2026", "Patient:      Erika Musterfrau , 12.04.1980", "",
        "LIQUIDATION", "", "15.03.2026   1   Beratung auch telefonisch   4,66 €   2,30   10,72 €",
        "Gesamtsumme:   42,50 €", "", "Bitte überweisen Sie den Betrag von 42,50 € ohne Abzug bis zum 14.04.2026."]
y = 780
for row in rows:
    c.drawString(60, y, row)
    y -= 22
c.save()
sys.stdout.buffer.write(buffer.getvalue())
