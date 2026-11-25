# Release-Notizen Signatur

## 0.1.0-beta.11

- **Start aus T2med:** Eigener Startkontext `kienzle-signatur` (zentraler Starter der Basis, Verwaltung → Starter). In T2med als Eintrag anlegen: `kienzle-signatur://?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}`. Das Modul liest den Patienten aus T2med und trägt ihn in „Neuer Vorgang“ ein; der T2med-Kontext wird am Vorgang gemerkt (für das spätere Zurückschreiben, das noch nicht umgesetzt ist). Voraussetzung: T2med unter Verwaltung → Verbindungen eingerichtet.
- Neue Datenbank-Tabelle für einmalige Startcodes (5 Minuten gültig). Neue Tests in `tests/signatur.php`. Nicht mit T2med und nicht im Browser geprüft.

## 0.1.0-beta.10

- **Seiten wie in der Kasse:** Die Signatur hat jetzt eine **Startseite** mit dem Vorgang, der gerade am Pad ist (sonst der zuletzt angelegte offene), samt Patient, Dokument, Pad, Status und Knöpfen, darunter „Neuer Vorgang“. Die **Vorgangsliste** (Knopf „Alle Vorgänge“) hat ein Suchfeld für Patient, Vorgang und Dokumentart sowie Filter nach Dokumentart und Status. **Vorlagen, Pads und Pad-Software** liegen unter „Einstellungen“ (auch in der Seitenleiste der Basis für Admins).
- **Abgebrochene Vorgänge lassen sich löschen** (Knopf „Löschen“ mit Rückfrage, nur bei Status „Abgebrochen“).
- **Fehlersuche bei „OK“ am Pad:** Meldet die Pad-Bibliothek beim Bestätigen einen Fehler oder liefert keine Unterschriftsdaten, steht der Grund (Rückgabewert, Größe) jetzt am Vorgang in der Liste und im Protokoll des Dienstes (`journalctl -u kienzle-signatur-pad`). Ohne Unterschrift im Feld erscheint „Bitte zuerst im Feld unterschreiben.“ Jeder Tastendruck am Pad wird mit Seitennummer protokolliert. Behebt noch nicht die Ursache, dass nach „OK“ das Feld wieder leer war; dafür werden diese Angaben gebraucht. Nicht an echter Hardware geprüft.

## 0.1.0-beta.9

- **Unterschreiben am Pad:** In der Vorgangsliste gibt es den Knopf **„Am Pad zeigen“** (Vorgang braucht ein zugeordnetes, aktives Pad; je Pad immer nur ein Vorgang). Das Pad zeigt dann den Vertragstext seitenweise (Abbrechen, Zurück, Weiter), danach die Unterschriftsseite mit Feld und den Schaltflächen Abbrechen, Zurück, Löschen und OK. Nach OK steht der Vorgang auf „Unterschrieben“; Bild (PNG) und Rohdaten mit Druckstärke liegen im Datenordner unter `unterschriften/<Nummer>.png` und `.sdb`. „Vom Pad nehmen“ räumt das Display wieder.
- Wird am Pad abgebrochen oder 10 Minuten nichts bedient, geht der Vorgang zurück auf „Wartet auf Unterschrift“ mit Hinweis; bei Verbindungsfehlern steht „Fehler“ mit Text, und der Vorgang lässt sich erneut zeigen. Die Liste lädt sich alle 5 Sekunden neu, solange ein Vorgang am Pad ist.
- Neuer Dauerdienst `kienzle-signatur-pad.service` (Benutzer ks-signatur, gehärtet): startet je Pad einen eigenen Arbeitsprozess, weil die Pad-Bibliothek je Prozess nur ein Gerät öffnet. Nach einem Neustart gehen Vorgänge „am Pad“ zurück auf „wartet“. Neue Pakete: python3, python3-pil, fonts-dejavu-core (Modul aktualisieren).
- Noch nicht eingebaut: PDF mit Unterschrift, Ablegen in T2med, Knopf in der Kasse. Nur mit Testgerät geprüft, nicht an echter Hardware und nicht auf der VM; das Aussehen der Pad-Seiten ist ein erster Entwurf.

## 0.1.0-beta.8

- **Mehrere Pads:** Neuer Bereich „Unterschriftenpads“ (nur Admins): je Arbeitsplatz ein Pad mit Name, Arbeitsplatz, Adresse (IP oder Rechnername), Port (Vorgabe 1002) und Haken „aktiv“. Beim Anlegen eines Vorgangs wird bei genau einem aktiven Pad dieses automatisch genommen, bei mehreren muss das Pad gewählt werden. Die Vorgangsliste zeigt eine Spalte „Pad“. Löschen eines Pads entzieht wartenden Vorgängen die Zuordnung.
- Datenbank-Schema 2 (Tabelle `pads`, Spalte `pad_id` an den Vorgängen); bestehende Daten bleiben erhalten.
- Noch nicht eingebaut: der Dienst, der die Vorgänge am Pad anzeigt und die Unterschrift aufnimmt (kommt als Nächstes). Nicht im Browser geprüft.

## 0.1.0-beta.7

- Verbindungstest: Das Programm wechselt in den Ordner der Pad-Bibliothek, weil sie dort `STPad.ini` sucht. Vorher brach es ab, wenn der Arbeitsordner für `ks-signatur` nicht lesbar war (Fehler „Permission denied: ./STPad.ini“). Nicht an echter Hardware geprüft.
- **Aufnahmetest:** Neues Programm `lib/padsign.py`. Es zeigt auf dem Pad eine Testseite mit Unterschriftsfeld und den Schaltflächen Abbrechen, Löschen und OK, nimmt die Unterschrift auf und speichert sie als `unterschrift-test.png` und `unterschrift-test.sdb` (Rohdaten mit Druckstärke) im Ordner der Pad-Bibliothek. Aufruf: `sudo -u ks-signatur python3 /opt/kienzle/signatur/current/lib/padsign.py <IP> 1002`. Noch ohne Knopf auf der Seite und ohne PDF. Nicht an echter Hardware geprüft; die Schaltflächen sind nur Text mit Berührungsfläche, das Aussehen ist ein erster Entwurf.

## 0.1.0-beta.6

- **Verbindungstest zum Pad:** Neues Programm `lib/padtest.py`. Es sucht das signotec Delta per IP (Ethernet), liest Typ, Seriennummer und Firmware, öffnet das Pad und zeigt 5 Sekunden einen Testtext. Aufruf auf dem Server: `sudo -u ks-signatur python3 /opt/kienzle/signatur/current/signatur/lib/padtest.py <IP> 1002`. Noch ohne Knopf auf der Seite.
- Die Pad-Bibliothek verlangt `libtiff.so.5`, das es auf Ubuntu 24.04 nicht mehr gibt (dort `libtiff.so.6`). Das Testprogramm legt dafür im Bibliotheksordner einen Verweis an. Lädt die Bibliothek ohne Fehler, ist das für den späteren Dienst die Lösung.
- Nicht an echter Hardware geprüft; lokal nur mit einer nicht vorhandenen IP (meldet „Kein Pad gefunden“).

## 0.1.0-beta.5

- Seite mit Kategorie-Zeile „Signatur“ und Titel „Unterschriften“ wie die anderen Seiten; Formularfelder in voller Kartenbreite; Seitenbreite wie die Basis (940 px). Nicht im Browser geprüft.

## 0.1.0-beta.4
- Pad-Software hochladen: Meldungen bei Fehlern genauer. Kommt keine JSON-Antwort, steht der HTTP-Status mit dem Anfang der Antwort da; interne Fehler des Servers werden mit Typ und Text gemeldet und ins Protokoll geschrieben. Nicht auf der VM geprüft.

## 0.1.0-beta.3
- Pad-Software: Die Anleitung auf der Seite beschreibt das Hochladen Schritt für Schritt (Herunterladen, nicht entpacken, Lizenz, Hochladen, Ergebnis, Neue Version) mit direktem Link zur Version 9.0.1. Nicht auf der VM geprüft.

## 0.1.0-beta.2
- Das Modul heißt jetzt **Signatur** (vorher „Unterschrift“): neue Kennung `signatur`, Adresse `/signatur`, Datenordner `/var/lib/kienzle/signatur`, Benutzer `ks-signatur`.
- Pad-Software von signotec (signoPAD-API Linux) wird nicht mitgeliefert: Der Admin lädt die ZIP-Datei bei signotec herunter und auf der Seite „Signatur“ unter „Pad-Software von signotec“ hoch (Anleitung und Lizenzhinweis dort). Übernommen werden nur die zwei Bibliotheken und der Lizenztext nach Prüfung (ZIP, Dateinamen, Linux x86-64); Entfernen per Knopf.
- Neues Paket `php-zip` (Modul aktualisieren). Nicht an echter Hardware und nicht auf der VM geprüft.

## 0.1.0-beta.1
- Neues Modul: Vorlagen (IGeL-Vertrag, Aufklärung, Datenschutz, eigene) mit Platzhaltern, Vorgänge mit Status (wartet, unterschrieben, übertragen, Fehler, abgebrochen), zeitversetzt nutzbar.
- Noch nicht enthalten: Pad-Anbindung (signotec Delta per IP), PDF-Erzeugung, Druck, Rückschreiben nach T2med, Knopf in der Kasse.
- Nicht an echter Hardware getestet.
