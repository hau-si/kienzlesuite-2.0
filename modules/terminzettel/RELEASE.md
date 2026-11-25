# Terminzettel – Release-Notizen

## 1.3.0-beta.13

- **Zeitmessung je Auftrag:** Das Druckprotokoll nennt nach jedem Auftrag die Dauer der Schritte: Umwandlung (PostScript/XPS zu PDF), Textlesen, **Texterkennung** (nur wenn der Beleg keinen Text enthält), Auswertung, Bon aufbauen (mit Logo und QR), Übergabe an den Drucker und gesamt. Bei Fehlern steht die Zeit bis zum Fehler dabei. Es wird nur die Zeit im Modul gemessen, nicht die Wartezeit in der Druckwarteschlange oder im Bondrucker.
- Auf der Seite gibt es unten einen eingeklappten Block **„Letzter Auftrag und Dauer der Schritte“** (jede Zeit in einer eigenen Zeile), zusätzlich zu „Letzte Druckaufträge“.
- Messung am Beispiel: Logo laden und umwandeln etwa 5 ms, Absage-Block etwa 20 ms; sie bremsen den Druck nicht.

## 1.3.0-beta.12

- **Absage-QR neu gestaltet:** Oben ein runder schwarzer **Balken** mit Kalender-Symbol und der Aufforderung in zwei Zeilen, darunter der QR-Code in **dickem, rundem Rahmen**; Balken und Rahmen sind gleich breit. Der Satz „Termin absagen: Code mit dem Handy scannen“ entfällt.
- Neuer Standardtext der Aufforderung: **„Verhindert? Bitte absagen.“** (der alte Standardtext wird bei bestehenden Einstellungen automatisch ersetzt; selbst geänderte Texte bleiben). Das Feld heißt jetzt „Aufforderung im Balken über dem QR-Code“.
- Fehler behoben, den es sonst beim größten QR-Code gegeben hätte: Der Block (QR plus Rahmen) darf höchstens 384 Punkte breit sein; der QR-Code wird dafür passend verkleinert.
- Ohne Schrift oder Bildbibliothek bleibt es beim bisherigen Text über einem schlichten QR-Code. Neuer Test `test_absage_block.py`; echte Ausgabe mit `python3-qrcode` und am Bondrucker nicht geprüft.

## 1.3.0-beta.11

- Einstellungen: Die Formularspalte hat dieselbe Breite wie die Seiten der Basis (860 px), die Vorschau steht rechts daneben. Nicht im Browser geprüft.

## 1.3.0-beta.10

- **Logo-Upload hing beim Hochladen** (Chrome drehte ewig, keine Meldung): Das Logo wird jetzt wie die Pad-Software der Signatur **roh per fetch** gesendet (kein Multipart-Formular mehr), Fehler und Erfolg stehen unter dem Knopf, danach lädt die Seite neu. `uploads = true` ist nicht mehr nötig und wurde wieder entfernt. Die genaue Ursache des Hängens ist nicht geklärt (nicht reproduzierbar ohne VM); der neue Weg ist der, der bei der Signatur auf der VM funktioniert.

## 1.3.0-beta.9

- **Logo-Upload ging nicht** („Bitte zuerst ein Bild auswählen“): Der Modul-Dienst nahm keine Formular-Uploads an. (Ersetzt durch beta.10.)
- Logo: Lage **links, Mitte oder rechts** wählbar (Kopf → „Lage“).
- Neu: **Absage per E-Mail**. Unten auf dem Bon steht ein QR-Code, der eine vorbereitete E-Mail öffnet (Empfänger, Betreff und Nachrichtentext frei wählbar; Größe Klein/Mittel/Groß; Text über dem Code). Standardmäßig aus. Der Nachrichtentext darf höchstens 400 Zeichen haben; ist der Code zu dicht, wird der Bon ohne ihn gedruckt. Nicht mit echtem Drucker und nicht mit einem Handy gescannt; die QR-Erzeugung ist hier nur ohne `python3-qrcode` getestet (Test übersprungen).

## 1.3.0-beta.8

- Drucker umbenannt: „Terminzettel“ → vTermine (PPD `vTermine-PDF.ppd`, Texte angepasst). Am Arbeitsplatz neu einrichten und in T2med neu wählen.

## 1.3.0-beta.7

- Tooltips bleiben im sichtbaren Bereich (verschoben statt schmaler).

## 1.3.0-beta.6

- Verweis auf den Schritt „Virtuelle Drucker“ unter „Arbeitsplatz einrichten“ der Basis; die Anleitung auf der Seite bleibt.
- Neuer Einrichtungs-Überblick (`bin/setup-overview`): Bondrucker zugeordnet, Praxisname im Kopf (optional), erster Terminzettel aus T2med am Bondrucker angekommen. Die Bereiche „Kopf“ und „Drucker“ klappen beim Sprung aus der Übersicht auf.

## 1.3.0-beta.5

- Der Haken „Terminzettel … drucken“ entfällt: Der Bondruck ist mit dem Modul immer aktiv.
- Kurze Beschreibung oben (Terminliste A6 aus T2med → virtueller Drucker → Bondrucker).
- Alle Bereiche sind standardmäßig zugeklappt; neue Tabler-Symbole statt Zeichen.
- „Speichern“ sitzt in einer unten mitlaufenden Leiste statt oben in der Ecke.
- Nicht auf der echten VM geprüft (Aussehen der Symbole, mitlaufende Leiste).

## 1.3.0-beta.4

- Der virtuelle Drucker „Terminzettel“ wird nur noch per IPP freigegeben; Samba ist optional (Verwaltung → Drucker).

## 1.3.0-beta.3

- Die Einstellungsseite steht jetzt im Rahmen der Suite: Die Seitenleiste mit dem Menü bleibt links sichtbar (wie bei der Kasse), die eigene Kopfzeile entfällt.
- Neu aufgebaut: Kopf, Termine, Endtext, Kalender-QR, Letzte Druckaufträge und die Einrichtung der Arbeitsplätze sind Ausklappzeilen im Stil der Basis. Die Vorschau mit „Beispiel-Terminzettel drucken“ bleibt rechts stehen, auch beim Scrollen. „Speichern“ steht oben rechts.
- Das eigene Stylesheet ist kleiner, weil Karten, Knöpfe und Hintergrund jetzt von der Basis kommen. Funktionen und Rechte unverändert.

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
