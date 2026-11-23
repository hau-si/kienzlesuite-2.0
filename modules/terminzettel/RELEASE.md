# Terminzettel – Release-Notizen

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
