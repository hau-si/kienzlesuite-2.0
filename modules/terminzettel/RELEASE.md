# Terminzettel – Release-Notizen

## 1.1.1-beta.1

- Neue Vorgaben: QR-Code mittig unter dem Termin, erste Zeile des Kopfes 2×2, Adressbereich 1×1, Termine 2×2. Bereits gespeicherte Layouts bleiben unverändert.
- Tooltip beim Kalender-Titel (Präfix): erklärt, dass der Text im Kalendereintrag steht, der beim Scannen des QR-Codes entsteht.

## 1.1.0-beta.1

- Gestaltung an die Suite angepasst (Petrol, Karten, Kopfzeile mit Logo, gleiche Knöpfe und Eingabefelder).
- Der Kopf des Bons ist beim ersten Öffnen mit den Praxisdaten der Basis vorbelegt (Name, Straße, Ort, Kontakt aus Verwaltung → Server und Name) und bleibt frei änderbar; ein gespeicherter Kopf wird nicht überschrieben. Beim ersten Besuch wird dieses Layout angelegt, damit schon ohne Speichern gedruckt wird.
- Nicht auf einem echten Bondrucker geprüft; das neue Aussehen nicht in einem echten Browser angesehen.

## 1.0.1-beta.1

- Behoben: Nach der Installation meldete der Gesundheitstest „Das Modul antwortet nach dem Start nicht“, weil er das Programm
  `gxps2pdf` verlangte, das Ubuntu nicht liefert (Paket libgxps-utils enthält `xpstopdf`). Der Test verlangt jetzt nur noch
  die Programme für PDF, PostScript und Texterkennung; für XPS wird `xpstopdf` bzw. `gxps2pdf` genommen, was vorhanden ist.
- Nicht auf echtem Ubuntu 26.04 geprüft; die Ursache ist aus der Programmliste abgeleitet, nicht aus dem Protokoll der VM.

## 1.0.0

- Der T2med-Terminzettel (t2med-terminzettel 1.3.1 von Dr. Thomas Kienzle) ist ein eigenes Modul der Kienzle-Suite 2.0
  und nicht mehr Teil der Kasse. Installation über Verwaltung → Module.
- Virtueller Drucker „Terminzettel“ (IPP und Windows-Freigabe über die Druckverwaltung der Basis): T2med druckt das
  Terminblatt (PDF, PostScript, XPS; Bild-PDFs mit Texterkennung), das Modul gibt einen Bon mit Kalender-QR je Termin aus.
- Der Bondrucker kommt aus Verwaltung → Drucker → „Wofür welcher Drucker?“, nicht aus dem Modul.
- Layout (Kopf, Überschrift, Termine, Endtext, QR) mit Live-Vorschau und Testdruck: Seitenleiste → Einstellungen → Terminzettel.
  Recht „Layout“; Admins des Moduls haben es immer.
- Aufträge werden nur im Arbeitsspeicher des Druckdienstes verarbeitet und nach dem Lesen sofort entfernt; es gehen keine
  Patientendaten in Druckauftrag-Namen, Protokolle oder Fehlermeldungen.
- Nicht übernommen: Layout-Einstellungen aus der alten Kasse bzw. /etc/terminzettel (einmal neu einstellen).
- Nur mit nachgestelltem Drucker (ohne echten Bondrucker, ohne Windows-/Mac-Arbeitsplatz) geprüft.
