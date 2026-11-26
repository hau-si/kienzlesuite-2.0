# Release-Notizen Signatur

## 0.1.0-beta.29

- Neu: `lib/padablauf.py` (Ablauftest am Pad mit mehreren Seiten, Wegwerf-Datenbank): `sudo systemctl stop kienzle-signatur-pad; sudo -u ks-signatur python3 /opt/kienzle/signatur/current/lib/padablauf.py <IP> 1002`; danach Dienst wieder starten. Zeigt im Protokoll Aufnahmestart, Stiftereignisse und Ergebnis. Mit `PADABLAUF=direkt` (vor `python3`: `sudo -u ks-signatur env PADABLAUF=direkt python3 …`) ohne Textseiten, gleich die Unterschriftsseite. Nur Werkzeug zur Fehlersuche, nicht am Pad geprüft.

## 0.1.0-beta.28

- **Pad-Dienst an den funktionierenden Aufnahmetest angeglichen** (im Test `padsign.py` kommen die Stiftpunkte an, im Dienst bisher keine): Anzeigeziel wird wie im Test nach dem Löschen der Seite gesetzt, kein Statusabruf mehr zwischen Aufnahmestart und Unterschrift. **Nicht geprüft:** echtes Pad; falls weiterhin 0 Stiftereignisse, Protokoll `journalctl -u kienzle-signatur-pad` schicken.

## 0.1.0-beta.27

- **Aufnahmetest mit Varianten (Fehlersuche „keine Stiftpunkte“):** Der einfache Test (`padsign.py`) nimmt am Delta eine Unterschrift auf (Rohdaten 1268 Bytes), der Pad-Dienst bekommt dagegen keine Stiftpunkte. Der Test lässt sich jetzt mit `PADSIGN=bild` (Seite wie im Dienst, als Bild) und/oder `PADSIGN=stop` (mit Anhalten statt Bestätigen abschließen) starten und meldet am Ende die Zahl der Stiftereignisse. Damit lässt sich eingrenzen, was im Dienst anders ist. Nicht am echten Pad geprüft.

## 0.1.0-beta.26

- **Fehlersuche Pad: Bereich der Stiftpunkte im Protokoll.** Bei „OK“ nennt die Protokollzeile jetzt zusätzlich, in welchem Bereich (x, y) und mit welchem größten Druck Stiftpunkte ankamen („Bereich keine“, wenn das Pad nichts meldet). Daran sieht man, ob die Punkte außerhalb des Unterschriftsfelds liegen oder gar nicht ankommen. Nicht am echten Pad geprüft.

## 0.1.0-beta.25

- **Patient immer oben rechts wie in der Kasse:** Der Kasten ist stets sichtbar; ohne Vorwahl steht darin das Namensfeld (manuelle Eingabe), nach Übernahme aus T2med oder Kasse der Name mit „Anderen Patienten wählen“. Darunter die Dokumentauswahl.
- **IGeL-Vertrag der Kasse ist fest:** Die älteste Vorlage der Art IGeL kann nicht gelöscht oder abgeschaltet werden und bleibt Art IGeL; der Text ist frei bearbeitbar.
- **Vorgangsliste wie in der Kasse:** Links die Vorgänge der letzten 7 Tage, neuester oben, mit „Zum Pad senden“ und „Löschen“; „Abbrechen“ nur, solange der Vorgang am Pad liegt (dann kein Löschen). Löschen geht nun in jedem Status außer „am Pad“.
- Einrichtungsleitfaden: erster Punkt „Benötigte Hardware“ (signotec Delta, Ethernet oder PoE-Ethernet). Nicht auf dem Server geprüft.

## 0.1.0-beta.24

- **Start aus T2med: HTTP 403 behoben (wahrscheinliche Ursache):** Die Kasse nimmt den öffentlichen T2med-Demo-Schlüssel, wenn unter Verwaltung → Verbindungen → T2med kein API-Schlüssel steht; Signatur schickte dann einen leeren Schlüssel und T2med lehnte den Abruf des Patienten ab. Signatur macht es jetzt wie die Kasse. Bei 401/403 nennt die Meldung (und `starter.log` am PC) den API-Schlüssel und den Drittanbieter-Zugriff als Ursache. Nicht gegen echtes T2med geprüft.

## 0.1.0-beta.23

- **Neues Layout der Startseite wie in der Kasse:** Links die offenen Vorgänge (am Pad zuerst, mit den Knöpfen zum Vorgang), rechts groß der Patient und darunter die Dokumentauswahl als Liste mit Auswahlpunkten (statt Auswahlfeld) und dem Knopf „Vorgang anlegen“. Auf schmalen Bildschirmen stehen die Spalten untereinander.
- Symbole vor „Start“, „Alle Vorgänge“ und „Einstellungen“ im Seitenkopf. Nicht auf dem Server geprüft.

## 0.1.0-beta.22

- **Vorgewählter Patient oben:** Beim Start aus T2med (und bei der Übergabe aus der Kasse) erscheint oben ein Kasten mit Name und Geburtsdatum des Patienten. Das Patientenfeld in „Neuer Vorgang“ ist dann ausgeblendet; „Anderen Patienten wählen“ im Kasten setzt alles zurück. Nicht auf dem Server geprüft.

## 0.1.0-beta.21

- **Name und Anrede als Platzhalter:** `{{vorname}}`, `{{nachname}}`, `{{anrede}}` (Herr oder Frau) und `{{briefanrede}}` („Sehr geehrter Herr Mustermann“, „Sehr geehrte Frau Musterfrau“, bei divers, unbestimmt oder unbekannt „Guten Tag Vorname Nachname“). Vor- und Nachname sowie das Geschlecht kommen aus T2med und lassen sich in „Neuer Vorgang“ ändern. Einen Titel (Dr., Prof.) kennt die T2med-Schnittstelle nicht.
- **Telefon und E-Mail in T2med übernehmen:** Bei einem Start aus T2med gibt es in „Neuer Vorgang“ das Häkchen „… nach der Unterschrift in T2med übernehmen“. Sind Telefon oder E-Mail geändert, steht der Vorgang mit „Für T2med vorgemerkt“ in der Liste; nach der Unterschrift schreibt der Knopf „Kontaktdaten in T2med übernehmen“ nur diese Angaben (Patient frisch lesen, nur Telefon und E-Mail ersetzen, mit Versionsprüfung zurückschreiben). Es wird nie automatisch geschrieben und nichts gelöscht. Das Zugriffstoken liegt dafür verschlüsselt höchstens 4 Stunden auf dem Server. Bei der Übergabe aus der Kasse gibt es das noch nicht (kommt mit der Rückgabe an die Kasse). Nicht auf dem Server und nicht gegen echtes T2med geprüft: bitte zuerst mit einem Testpatienten.
- **Keine blauen Punkte mehr** im Menü: Auch „Pad-Software hochladen“ ist nur noch ein Hinweis.

## 0.1.0-beta.20

- **Platzhalter für Telefon, E-Mail, Geburtsdatum und Anschrift:** `{{telefon}}`, `{{email}}`, `{{geburtsdatum}}`, `{{adresse}}` stehen in Vorlagen zur Verfügung. Die Daten kommen aus T2med (Start aus T2med und Übergabe aus der Kasse) und lassen sich in „Neuer Vorgang“ unter „Weitere Patientendaten“ noch ändern.
- **Platzhalter per Klick einfügen:** Unter dem Textfeld jeder Vorlage stehen Knöpfe für alle Platzhalter; ein Klick fügt den Platzhalter an der Cursorstelle ein.
- **Übergabe aus der Kasse** öffnet „Neuer Vorgang“ mit Patient, Kontaktdaten und Leistungsliste; Dokument (und Pad) wählt man dort selbst. Nicht auf dem Server geprüft.

## 0.1.0-beta.18

- **Leistungsliste aus der Kasse:** „Neuer Vorgang“ nimmt eine Liste von Leistungen samt Patient entgegen (Übergabe über die Adresse, `#uebergabe=…`). Summe und Leistungen kommen aus der Liste ins Dokument, neuer Platzhalter `{{leistungen}}` (ohne Liste die eingegebene Leistung). Der Vorgang merkt sich Herkunft („kasse“), Verweis auf den Kassenvorgang und die Liste (Schema 4). Das Muster „IGeL-Vertrag“ nutzt `{{leistungen}}`; bereits gespeicherte Vorlagen bleiben unverändert und können den Platzhalter selbst einbauen. PDF, Rückgabe an die Kasse und Speichern in T2med folgen. Nicht auf der VM geprüft.

## 0.1.0-beta.17

- **Abgebrochene Vorgänge wieder aufnehmen:** In der Vorgangsliste hat ein abgebrochener Vorgang jetzt den Knopf „Wieder aufnehmen“. Er kehrt zu „Wartet auf Unterschrift“ zurück, war er schon unterschrieben, zu „Unterschrieben“; am Vorgang steht „Wieder aufgenommen.“ Eine abgelaufene T2med-Verbindung wird wie bisher angezeigt. Nicht im Browser geprüft.

## 0.1.0-beta.16

- **Unterschrift am Pad: Fehlersuche und Umbau beim „OK“.** Das Protokoll aus beta.15 zeigte „0 Punkte erfasst“ bei sichtbarer Unterschrift und keine Stift-Ereignisse der Pad-Bibliothek. Ob die Zahl von `STSignatureStop` überhaupt die Punktzahl ist, ist unklar; deshalb entscheidet jetzt die Größe der Unterschriftsdaten (`STSignatureGetSignData` nach dem Anhalten). Sind Daten da, werden sie gespeichert (ohne `Confirm`, das nur das Display leert).
- Das Protokoll nennt beim Start der Aufnahme das Unterschriftsfeld, die Rückgabe und ob sie läuft, und bei „OK“ Zustand, Stopp-Rückgabe, Datengröße und die Zahl der Ereignisse je Art (4 = Stiftpunkte). Stiftpunkte füllen die Ereignisliste nicht mehr.
- Nicht am echten Pad geprüft. Bitte nach einem Versuch `sudo journalctl -u kienzle-signatur-pad -n 30 --no-pager` ausgeben.

## 0.1.0-beta.15

- Meldungen („Vorgang angelegt“, Fehler) erscheinen direkt beim angeklickten Knopf statt oben auf der Seite (Skript der Basis, Basis ab beta.58). Nicht im Browser geprüft.

## 0.1.0-beta.14

- **Pads: feste Zeilen statt Eingabefelder.** Eingerichtete Pads erscheinen als Zeile mit Name, Arbeitsplatz, Adresse:Port und Status; am Ende ein **Stift** (Bearbeiten, nur dann erscheinen die Felder) und ein **Mülleimer** (Löschen mit Rückfrage). Neu anlegen bleibt darunter.
- **Pad im Netzwerk suchen** (ähnlich der Druckersuche): Die Suche prüft die Adressen 1–254 eines privaten Netzes (Vorgabe: Netz dieses Servers) auf offenen Port 1002 und bietet Treffer zum Hinzufügen an; das gilt für feste Adressen und DHCP. Es gibt kein Suchprotokoll des Herstellers; gefunden wird, was auf Port 1002 antwortet. Gesucht wird nie außerhalb privater Netze (10.x, 172.16–31.x, 192.168.x). Nicht an echtem Pad und echtem Netz geprüft.
- **Anleitung „Pad ins Netzwerk bringen (Servicemenü)“** für Delta und Alpha: Strom trennen, Stift an die obere rechte Ecke, Strom wieder anschließen, Ethernet und feste Adresse oder DHCP wählen, Adresse eintragen. Nach Angaben von signotec.
- **Pad-Software:** Mit hochgeladener Software zeigt der Bereich „✓ erledigt“ und ist zugeklappt; sonst „offen“.
- Neue Tests in `tests/signatur.php`.

## 0.1.0-beta.13

- **Unterschrift verschwand bei „OK“:** Laut Protokoll lag es nicht an der Taste, sondern daran, dass beim Bestätigen keine Unterschrift erfasst war (Fehler -17, „keine Aufnahme gestartet“). Die Pad-Bibliothek leert beim Bestätigen das ganze Display, dadurch erschien das Feld leer und die Aufnahme begann von vorn. Jetzt wird zuerst angehalten (Display bleibt) und die Zahl der erfassten Punkte geprüft: bei 0 Punkten bleibt das Feld stehen, die Aufnahme läuft weiter und die Liste zeigt „Noch keine Unterschrift erkannt“. Das Protokoll nennt die Zahl der Punkte („N Punkte erfasst“).
- Wird auch beim Unterschreiben 0 Punkte gemeldet, liegt es an der Aufnahme selbst; dann bitte das Protokoll erneut ausgeben. Nicht am echten Pad geprüft.

## 0.1.0-beta.12

- **Fehlersuche Pad:** Im Protokoll des Pad-Dienstes steht jetzt auch jedes Ereignis, das keiner Taste zugeordnet werden konnte, samt den bekannten Tasten. Hinweis: Das bisherige Protokoll stammte noch von einer älteren Version ohne diese Zeilen; bitte Signatur auf beta.12 aktualisieren und den Ablauf mit `sudo journalctl -u kienzle-signatur-pad -n 30 --no-pager` erneut ausgeben.

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
