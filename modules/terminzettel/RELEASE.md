# Terminzettel – Release-Notizen

## 1.3.0-beta.2

- Fehler behoben: Nach dem Update zeigte der Browser noch das alte Skript und den alten Stil (eine Stunde Zwischenspeicher). Das alte Skript las die neuen Auswahlleisten falsch (immer die größte Stufe), daher wirkte die Vorschau viel zu groß und die Seite zerstört. Skript und Stil tragen jetzt eine Versionskennung in der Adresse und laden nach jeder Änderung neu.
- Formular kompakter: kleinere Auswahlleisten, „F“ statt „Fett“, weniger Abstand, Beschriftungen statt Zwischenüberschriften.

## 1.3.0-beta.1

- Einstellungsseite neu geordnet: je ein Block für Kopf, Termine, Endtext, Kalender-QR und Drucken, in der Reihenfolge des Bons. Schriftgröße und Fett stehen als kleine Auswahlleiste direkt beim jeweiligen Text (statt vieler Aufklapplisten).
- Praxisname (erste Zeile) und Adressblock sind jetzt getrennte Felder mit eigener Schrift. Bereits gespeicherte Einstellungen werden beim Öffnen automatisch aufgeteilt (erste Zeile des alten Kopfes = Praxisname, Rest = Adressblock; Größe und Fett bleiben erhalten).
- Neu: Praxislogo. Auf der Einstellungsseite hochladen (PNG, JPEG oder GIF bis 2 MB); es wird in Schwarz-Weiß umgerechnet und oben mittig auf den Bon gedruckt, Breite Klein/Mittel/Groß, die Vorschau zeigt es mit. Ein Logo ohne lesbare Datei verhindert den Ausdruck nie. Nicht auf echtem Bondrucker getestet (Raster `GS v 0`, wie bisher bei anderen Grafiken).

## 1.2.4-beta.2

- Windows-Skript: Anschluss und Drucker werden bis zu viermal angelegt (Pause nach dem Spooler-Neustart, sonst Fehler 0x800703eb), am Ende zeigt es den angelegten Drucker. Nicht getestet (auf dem Windows-PC des Anwenders klappte der Befehl `Add-Printer` beim zweiten Versuch).

## 1.2.4-beta.1

- Virtuelle Drucker bieten nur noch das Papierformat des Moduls an (neu in module.toml: `paper`, Vorgabe A4; Kasse A4, Terminzettel A6) statt A6/A5/A4/Letter. Nicht mit Windows getestet.

## 1.2.3-beta.2

- Windows-Skript korrigiert: Der Druckspooler wird nach dem Aktivieren der Windows-Funktion neu gestartet und es wird auf den laufenden Dienst gewartet (vorher „Spoolerdienst ist nicht erreichbar“). Nicht getestet.

## 1.2.3-beta.1

- Windows-Anleitung robuster: Das PowerShell-Skript startet den Druckspooler neu und entfernt zuerst den alten Drucker (behebt „Das Handle ist ungültig“ in den Druckereigenschaften); neuer Hinweis zur Fehlerbehebung. Nicht getestet (vom Anwender am Windows-PC bestätigt: Neueinrichtung per IPP-Adresse mit IPP-Class-Treiber funktioniert).

## 1.2.2-beta.1

- Die Anleitung auf der Einstellungsseite folgt dem Format der Kasse (SumUp-Rechnung): Windows mit „Microsoft IPP Class Driver“ (nicht mehr „Generic / Text Only“), Adresse per IP, PowerShell-Befehle, Mac mit PPD. Nicht getestet (Tests auf Wunsch nicht ausgeführt).

## 1.2.1-beta.1

- Neu: Auf der Einstellungsseite steht „Letzte Druckaufträge“: Zeit, Art des Auftrags (PDF, PostScript, XPS, Text), Größe und Ergebnis („Bon an die Warteschlange übergeben“ oder die Fehlermeldung). Es werden nur feste Texte protokolliert, keine Patientendaten. Kommt nach dem Drucken aus T2med kein Eintrag, erreicht der Auftrag den virtuellen Drucker nicht.
- Nicht auf der echten VM getestet.

## 1.2.0-beta.1

- Die Papierbreite des Bondruckers (58/80 mm) kommt zentral aus der Basis (Verwaltung → Drucker → Wofür welcher Drucker?, ab Basis 0.21.0-beta.1); das Feld „Bondrucker-Modell“ entfällt, die Seite zeigt die eingestellte Breite an. Ändert sich die Breite, wird das gespeicherte Layout für den Druckdienst automatisch neu erzeugt. Bei einer älteren Basis gilt 58 mm.
- Nicht auf echtem Bondrucker getestet.

## 1.1.3-beta.1

- Behoben: Druckt T2med mit dem Windows-Treiber „Generic / Text Only“ (reiner Text), wurde der Auftrag vom virtuellen Drucker „Terminzettel“ nicht angenommen, weil er nur PDF, PostScript und XPS erlaubte. Jetzt werden auch `text/plain` und rohe Aufträge (`application/octet-stream`, so sendet der Text-Treiber über Samba/IPP) angenommen.
- Reiner Text aus dem Windows-Treiber (Windows-1252, Umlaute) wird erkannt, wenn er kein gültiges UTF-8 ist.
- Nicht auf der echten VM mit T2med getestet. Nach dem Update den Drucker „Terminzettel“ neu synchronisieren lassen (Basis startet den virtuellen Drucker mit der neuen Formatliste).

## 1.1.2-beta.1

- Behoben: Die Größe „Klein“ des Kalender-QR-Codes (160 Druckpunkte) war für den Kalendereintrag zu klein, der QR-Code wurde weder in der Vorschau noch beim Druck erzeugt. Die Größen sind jetzt Klein 224, Mittel 292 und Groß 384 Druckpunkte (je mindestens 3 Punkte pro Modul).
- Die Vorschau erklärt jetzt, warum ein QR-Code nicht auf den Bon passt, und weist darauf hin, wenn Änderungen noch nicht gespeichert sind (gedruckt wird nur der gespeicherte Stand, z. B. das Papierformat 80 mm).
- Nicht auf echtem Drucker getestet.

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
