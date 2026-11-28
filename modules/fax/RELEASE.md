# Release-Notizen Fax

## 0.1.0-beta.17

- Menüweg in Hinweisen: „Praxis → Arbeitsplätze“ statt „Einrichten → Arbeitsplätze“ (die Basis hat die Gruppe „Einrichten“ ab beta.57 unter „Praxis“ eingeordnet). Mit Tests geprüft.

## 0.1.0-beta.16

- Faxdrucker: Der Hinweis nennt jetzt den Praxisherz-Agenten (Einrichten → Arbeitsplätze), der die Faxdrucker auf jedem Windows-PC selbst einrichtet und neue Drucker selbst übernimmt; die Handeinrichtung steht als Notweg eingeklappt darunter. Nach einer Änderung der Druckerliste bleibt der Abgleich unter Praxis → Drucker nötig.
- Menüwege nach der neuen Seitenleiste (Praxis → Drucker/Verbindungen/Praxis, Betrieb → Module), ungenutzte Stilregeln entfernt. Mit Tests geprüft, nicht auf der VM.

## 0.1.0-beta.15

- Texte: „die Suite“/„der Suite“ heißt jetzt überall „Praxisherz“. Nur Texte geändert, mit Tests geprüft, nicht auf der VM.

## 0.1.0-beta.14

- **Neuer Name: „Praxisherz – freie Software für die Arztpraxis“** (vorher Kienzle-Suite) in Texten, Hinweisen und Dienstbeschreibungen. Mit Tests geprüft, nicht auf der VM.

## 0.1.0-beta.13

- **„Erneut senden“ direkt in Sendeprotokoll und Fehlerberichte:** Statt wie bisher über die Dokumentliste von „Fax senden“ (Quelle „Sendefehler“) gibt es jetzt einen Knopf **„Erneut senden“** direkt an jedem Eintrag, mit Rückfrage; er sendet an denselben Empfänger mit denselben Einstellungen (Auflösung, ECM) erneut. Der Knopf erscheint nur, solange das Original noch vorliegt.
- **Original auch bei erfolgreich gesendeten Faxen aufbewahrt:** Der Fax-Worker (Modul Telefon, ab 1.3.20) legt das Original jetzt auch bei Erfolg zusätzlich ab (`sendeberichte/eingang/`, analog zum bisherigen Verhalten bei Fehlern). Das Modul Fax hebt es **7 Tage** auf (`Fax::PROTOKOLL_ORIGINAL_TAGE`, fest, unabhängig von der Aufbewahrungsfrist des Sendeprotokolls selbst); danach bleibt nur noch der Bericht, das Original wird beim Aufräumen gelöscht.
- Hover-Vorschau im Faxeingang vergrößert (360px → 480px Breite).
- Drag-and-Drop-Bereich auf „Fax senden“ vergrößert (mehr Innenabstand, Mindesthöhe).
- Braucht Modul Telefon ≥ 0.1.0-beta.21 (Worker-Änderung); mit einer älteren Version fehlt nur der Resend-Knopf im Sendeprotokoll, sonst unverändert nutzbar.

## 0.1.0-beta.12

- **Windows-Freigabe „Fax-Ausgang“ für sofortige Faxübernahme:** Das Modul bietet den Ordner `ablage` als Freigabe an (`[[fileshare]]`, Basis 0.22.0-beta.13). Ein Admin schaltet sie unter Verwaltung → Drucker ein; danach genügt es, ein PDF oder Bild auf `\\<Server>\Fax-Ausgang` zu ziehen. Die Pfad-Einheit `kienzle-fax-lauschen.path` überwacht den Ordner, die Datei erscheint in „Fax senden“ (Quelle NAS) nach etwa ein bis zwei Sekunden. Dateien, die noch kopiert werden, werden erst übernommen, wenn sie sich eine Sekunde nicht mehr ändern.
- Zugriff mit Anmeldung: Benutzer `ks-fax` und das beim Einschalten vergebene Kennwort (in Windows beim Verbinden „andere Anmeldedaten“ wählen und speichern lassen). Nur aus Netzen erreichbar, für die die Firewall „Drucken über Windows-Freigabe (445)“ freigegeben ist. Nicht am echten Arbeitsplatz geprüft.

## 0.1.0-beta.11

- **Neue Datei im Lauschordner sofort übernehmen:** Neue Pfad-Einheit `kienzle-fax-lauschen.path` startet die Pflege, sobald der Ablage-Dienst eine Datei vom NAS in den Eingang legt (bisher bis zu eine Minute Wartezeit). Zusammen mit dem Lauschdienst der Basis (alle 15 Sekunden, Basis 0.22.0-beta.11) erscheint die Datei nach etwa 15 bis 30 Sekunden. Nicht am echten NAS geprüft.
- Lauschordner: Eine für das Modul nicht lesbare Datei wird nicht mehr als „leer“ gemeldet und in den Fehlerordner geschoben, sondern bleibt liegen und wird im Protokoll genannt. (Ursache der Nichtübernahme war ein Rechtefehler im Ablage-Dienst der Basis, siehe Basis 0.22.0-beta.9.)

## 0.1.0-beta.10

- **Texterkennung empfangener Faxe:** Ein eigener Dienst (`kienzle-fax-ocr`, jede Minute, niedrige Priorität, höchstens 5 Faxe je Lauf) liest neue Faxe mit Tesseract (Deutsch, 300 dpi, höchstens 30 Seiten). Alles bleibt auf dem Server; der Text liegt als `<fax>.txt` (0600) neben dem Fax und wird mit ihm gelöscht. Neue Pakete: `tesseract-ocr`, `tesseract-ocr-deu`.
- **Suche im Faxeingang:** Suchfeld oben; alle Wörter müssen vorkommen (Absender, Name aus dem Telefonbuch, Nummer und erkannter Text). Treffer zeigen einen kurzen Textausschnitt; noch nicht gelesene Faxe zeigen „Text wird erkannt …“.
- Einstellungen → „Texterkennung“: an (Standard) oder aus; aus löscht alle erkannten Texte.
- Ein Fax, das sich nicht lesen lässt, bekommt einen leeren Text (kein endloses Wiederholen); der Grund steht im Protokoll des Moduls.
- Geprüft mit Tests (echtes Ghostscript und Tesseract). **Nicht auf der VM geprüft** (neue Pakete, Dienst mit systemd-Härtung, Dauer bei echten Faxen).

## 0.1.0-beta.9

- **Fehler behoben: Vorschau blieb leer** (bei „Fax senden“ und im Faxeingang). Ghostscript wurde mit `/dev/null` als Eingabe gestartet; `/dev/null` liegt außerhalb der Ordner, die das Modul-PHP öffnen darf (`open_basedir`), deshalb startete Ghostscript auf dem Server gar nicht. Seitenzahl (Ersatzweg) und Zusammenfügen mehrerer Dokumente waren ebenso betroffen. Jetzt nur noch Pipes; Fehler von Ghostscript stehen im Protokoll des Moduls.
- Die Seitentests laufen jetzt mit derselben `open_basedir`-Sperre wie auf dem Server (der Test schlug mit dem alten Stand fehl).
- **Nicht auf der VM geprüft.**

## 0.1.0-beta.8

- Lauschordner: Eine für das Modul nicht lesbare Datei wird nicht mehr als „leer“ gemeldet und in den Fehlerordner geschoben, sondern bleibt liegen und wird im Protokoll genannt. (Ursache der Nichtübernahme war ein Rechtefehler im Ablage-Dienst der Basis, siehe Basis 0.22.0-beta.9.)

## 0.1.0-beta.7

- **„Fax senden“ neu (Entwurf A „Liste mit Häkchen“):** Alle Dokumente aus allen Quellen (Faxdrucker, NAS-Ordner, hochgeladen, Sendefehler) stehen in **einer** Liste, neueste oben, mit Quelle, Zeit und **Seitenzahl**. Das **neueste ist vorgewählt**. Rechts die **Vorschau** (oberer Teil der ersten Seite, in Graustufen wie beim Faxgerät); sie folgt der Maus über der Liste.
- **Mehrere Dokumente an eine Nummer:** Angehakte Dokumente werden in der **Reihenfolge des Anhakens** (Zahl im Kästchen) zu **einem Fax** zusammengefügt (ein Anruf, ein Sendebericht, Titel „Erstes + N weitere“). Unter „Erweitert“: „Jedes Dokument als eigenes Fax“, Auflösung, Fehlerkorrektur. Bei mehr als 15 Seiten erscheint der Hinweis, dass einzelne Faxe zuverlässiger sein können.
- **Empfänger:** Standard ist **ein** Empfänger, weitere über „+ weiterer Empfänger“ (mit × wieder entfernen). Darüber die **Favoriten** (★) und die zuletzt angefaxten Empfänger (⟲) zum Anklicken. Das Namensfeld sucht im Telefonbuch nach **Namensteilen** (Pfeiltasten und Enter) oder nimmt direkt eine **Faxnummer** an; der Name ist dann nicht nötig. „Im Telefonbuch speichern“ erscheint nur bei einer neuen Nummer.
- Der Knopf nennt, was passiert, z. B. „2 Dokumente (3 Seiten) an Labor Beispiel faxen“. Bei einem Fehler bleiben Auswahl und Eingaben erhalten.
- **Telefonbuch:** Stern ★ je Eintrag (und Haken im Bearbeiten-Formular) markiert Favoriten.
- **Faxeingang:** Fährt man mit der Maus über ein Fax, zeigt sich die Vorschau des oberen Seitenteils (öffnet das Fax nicht, es bleibt „neu“).
- Vorschaubilder liegen im Datenordner des Moduls (0700) und werden nach einem Tag gelöscht. Faxdrucker mit fester Nummer belegen den Empfänger weiterhin vor.
- Geprüft mit Tests und im Browser (Chromium) gegen den PHP-Testserver mit erfundenen Daten. **Nicht auf der VM geprüft** (Ghostscript von Ubuntu 26.04 für Seitenzahl, Vorschau und Zusammenfügen, nginx/php-fpm), kein echtes Fax gesendet.

## 0.1.0-beta.6

- **Fehler behoben: Dateien hochladen hing** („Dateien wählen“ bzw. Ziehen auf die Fläche: die Fläche wurde grau, nach langer Zeit kam „Hochladen nicht möglich“). Ursache war der Formular-Upload (`multipart`), der auf dem Server ohne Meldung hängen bleibt (gleiche Falle wie früher beim Logo im Terminzettel). Jetzt geht jede Datei einzeln roh an `/fax/hochladen` (wie Logo und Pad-Software), mit **Fortschrittsbalken** („Datei 2 von 3: … – 45 %“), Zeitgrenze von 60 Sekunden je Datei und klarer Fehlermeldung. Bis 7 MB je Datei (vorher 3 MB).
- Geprüft mit Tests (auch über einen echten HTTP-Server) und im Browser (Chromium) gegen den PHP-Testserver. **Nicht auf der VM geprüft** (nginx und php-fpm des Moduls).

## 0.1.0-beta.5

- Fehler behoben: Bei „Fax senden“ stand der Reiter „Sendefehler“ doppelt (der Zähler enthielt ihn schon, die Seite hängte ihn nochmals an). Test ergänzt.

## 0.1.0-beta.4

- **E-Mail bei Faxeingang** (Einstellungen → „Benachrichtigung bei Faxeingang“, bis zu fünf Adressen): Bei neuen Faxen geht eine kurze E-Mail hinaus, nur mit Anzahl und Seiten, **ohne Absender, Inhalt oder Anhang** (Faxe enthalten Gesundheitsdaten). Mehrere Faxe innerhalb einer Minute ergeben eine E-Mail. Verschickt wird über den Nachrichten-Ausgang der Basis (Verbindung `nachrichten`, braucht Basis 0.22.0-beta.8 und eine E-Mail-Verbindung unter Verwaltung → Verbindungen).
- Geprüft mit Tests. **Nicht geprüft:** echter Versand, VM.

## 0.1.0-beta.3

- **Obere Leiste mit Symbolen** (Fax senden, Faxeingang, Sendeprotokoll, Fehlerberichte, Telefonbuch, Einstellungen) wie in Signatur und Kasse.
- **Faxdrucker einstellbar:** Standard ist nur noch **ein** Drucker „vFax“. Weitere trägt der Admin unter Einstellungen → Faxdrucker ein (Namen beginnen mit `vFax`, höchstens 10). Die Liste liegt in `drucker.json`; die Basis (ab 0.22.0-beta.4) legt die Drucker beim Abgleichen an. **Nach jeder Änderung** Verwaltung → Drucker → „Virtuelle Drucker der Module abgleichen“ (oder das Modul neu installieren bzw. aktualisieren); der Hinweis steht auf der Seite. **Wer die erste Beta schon nutzt, behält vFax1 bis vFax5** (Erkennung am vorhandenen Telefonbuch), bis er die Liste ändert.
- **Feste Faxnummer je Faxdrucker** (mit Bezeichnung): Dokumente von diesem Drucker tragen die Nummer unter „Fax senden“ schon als Empfänger 1 ein (änderbar). Wahlweise **„sofort senden“**: Dokumente gehen ohne Rückfrage an diese Nummer (Zeitgeber jede Minute).
- **Drag and Drop** unter „Fax senden“: PDF, PostScript und Bilder (JPEG, PNG, GIF, BMP, WebP; ein Bild wird eine A4-Seite) auf die Fläche ziehen oder über „Dateien wählen“; sie landen unter „Hochgeladen“ und sind schon angekreuzt. Höchstens 3 MB je Datei. Word- und LibreOffice-Dokumente werden abgelehnt (bitte als PDF speichern oder auf den Faxdrucker drucken). Neues Paket `php-gd`.
- **NAS** (Verbindung „ablage“ der Basis): **Export** jedes neu empfangenen Faxes als PDF in einen einstellbaren Ordner (Name mit Datum und Absender, wahlweise Unterordner Jahr/Monat) und **Lauschordner**: Dateien in einem NAS-Ordner erscheinen unter „Fax senden“ → „NAS-Ordner“ (Abholen etwa jede Minute, danach auf dem NAS gelöscht; nicht lesbare Dateien landen in `lausch-fehler/`).
- **Aufbewahrung unbegrenzt** wählbar (je Eingang, Protokoll/Fehler, gedruckte Dokumente). Ordner entfernter Drucker werden mitgeräumt.
- **Faxkennung:** Einstellungen verweisen auf Telefon → Einstellungen → Rufnummern → Fax; leer gilt der Praxisname.
- Neuer Zeitgeber `kienzle-fax-pflege` (jede Minute): Ergebnisse abholen, „sofort senden“, Lauschordner, Export, Aufräumen – das geschieht jetzt auch ohne geöffnete Seite.
- Geprüft mit Tests (Druckerliste, Umwandlung von JPEG/PNG, Hochladen, Export- und Lauschordner mit nachgebauter Verbindung, unbegrenzt). **Nicht geprüft:** echte VM, ob `ippeveprinter` den Druckernamen (`KIENZLE_DRUCKER`) an das Druckprogramm weitergibt, Ghostscript-Umwandlung von PostScript (hier nicht installiert), echtes NAS.

## 0.1.0-beta.2

- **Braucht das Modul Telefon** (`requires`): Unter Verwaltung → Module lässt sich Fax erst nach Telefon installieren; die Beschreibung nennt die Abhängigkeit. Eine Praxis nur mit Fax richtet in Telefon nur die Faxleitung ein.
- Eigenes Symbol (Faxgerät mit Hörer).
- Nicht auf der VM geprüft.

## 0.1.0-beta.1

- **Neues Modul „Fax“** auf Grundlage von Kienzlefax 3.5.0 (`kienzlefax.php`, Dr. Thomas Kienzle, MIT-Lizenz). Senden und Empfangen über die Telefonanlage des Moduls Telefon (gemeinsamer Austauschordner der Basis).
- **Faxdrucker vFax1 bis vFax5** (virtuelle Drucker der Basis, IPP und Samba): Am PC drucken, das Dokument erscheint unter „Fax senden“. PostScript wird in PDF gewandelt.
- **Fax senden:** Dokumente wählen, bis zu fünf Empfänger (aus dem Telefonbuch oder neu, auf Wunsch gespeichert), Auflösung und Fehlerkorrektur; je Dokument und Empfänger ein Auftrag im Format von Kienzlefax. Laufende Aufträge mit Stand (wartet, wählt, Seite x von y, Versuch) und Abbrechen, alle 5 Sekunden aktualisiert.
- **Sendeprotokoll** und **Fehlerberichte** (mit Grund und Zahl der Versuche, Bericht als PDF, „Erledigt“ übernimmt ins Protokoll); das Original eines fehlgeschlagenen Faxes lässt sich unter „Sendefehler“ erneut senden.
- **Faxeingang:** empfangene Faxe mit Absender (Name aus dem Telefonbuch), Seiten, „neu“-Markierung, Öffnen und Löschen.
- **Telefonbuch** (SQLite) mit Suche.
- **Aufbewahrung** einstellbar (Vorgabe: Eingang und Berichte 90 Tage, gedruckte, nicht gesendete Dokumente 14 Tage). Berichte und empfangene Faxe holt das Modul sofort aus dem Austauschordner in seinen eigenen Datenordner.
- Rechte `senden`, `eingang`, `telefonbuch`; Einstellungen nur für Admins; keine Anmeldung mehr ohne Passwort wie bei der alten Weboberfläche.
- **Noch nicht übernommen** (folgt): Texterkennung (OCR) empfangener Faxe und Scans, Mail-Hinweis bei neuem Fax, Ablage in T2med.
- Geprüft mit Tests (auch ein durchgehender Faxversand mit Asterisk in der Testumgebung, siehe Modul Telefon). **Nicht geprüft:** echte VM, Drucken von einem Windows-PC auf vFax1 bis vFax5, echtes Faxgerät.
