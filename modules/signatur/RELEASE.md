# Release-Notizen Signatur

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
