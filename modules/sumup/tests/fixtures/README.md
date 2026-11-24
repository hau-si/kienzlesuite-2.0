# Beispielrechnung aus T2med (erfunden)

`t2med-beispielrechnung.pdf` zeigt den Aufbau einer T2med-Privatrechnung („Liquidation“, JasperReports-Vorlage, A4, zwei Seiten, mit Textebene), wie sie
der virtuelle Drucker „SumUp-Rechnung“ an die Kasse liefert. **Alle Angaben sind erfunden** (Praxis „Dr. med. Erika Beispiel“, Patient „Max Mustermann“,
Test-IBAN DE89 3704 0044 0532 0130 00); es gibt keinen Briefkopf und kein Logo. Neu erzeugen: `python3 make-t2med-beispielrechnung.py`.

Was die Kasse (`T2medInvoice::parse`) daraus liest:

| Feld | Stelle im PDF | Beispiel |
|---|---|---|
| Rechnungsnummer | `Re.-Nr.:` (Seite 1, und in der Kopfzeile von Seite 2) | `IG2026-0001` |
| Rechnungsdatum | `Re.-Datum:` | `28.05.2026` |
| Patient, Geburtsdatum | `Patient:  Name , TT.MM.JJJJ` | `Max Mustermann , 01.01.1990` |
| Betrag | `Gesamtsumme:` und „Bitte überweisen Sie den Betrag von … €“ (beide müssen übereinstimmen) | `75,88 €` |

Seite 1 enthält Anschrift, Bankblock (Bank, IBAN, BIC), die Positionstabelle (Datum, Ziffer, Leistungstext, Wert, Faktor, Betrag) und die Gesamtsumme; Seite 2 Diagnosen,
Zahlungsaufforderung und Grußformel. Die Kasse nutzt Nummer, Datum, Betrag und Patient; Positionen und Bankdaten werden nicht ausgewertet.
Echte Rechnungen niemals ins Repository legen; für neue Fälle dieses Beispiel anpassen.
