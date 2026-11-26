# Basis – Release-Notizen

## 0.22.0-beta.10

- **Kienzle-Agent (Windows):** Das Paket der Seite „Arbeitsplätze“ richtet jetzt ein Programm für alles ein: Starter für T2med **und** Dienst mit Systemrechten (geplante Aufgabe „Kienzle-Agent“ beim Start des PCs, startet nach Ende oder Aktualisierung neu). Programm in `C:\Program Files\Kienzle`, Einstellungen in `C:\ProgramData\Kienzle` (Benutzer dürfen nur lesen; der eigene Zugang `agent.json` nur System und Administratoren).
- Der Agent meldet sich einmal mit dem Starter-Schlüssel an und bekommt einen **eigenen Zugang je PC** (in der Suite nur als SHA-256). Danach fragt er etwa alle 2 Sekunden `/agent/abholen` und hält den PC selbst aktuell: **Starterprogramm** (lädt neue Fassungen aus der Suite, prüft die Prüfsumme, tauscht sich aus), **Aufrufe aus T2med** (für alle Benutzer, HKLM), `client.json` samt Starter-Schlüssel, **Zertifizierungsstelle** (vertrauenswürdig für den ganzen PC) und **virtuelle Drucker** (wie `install-drucker.ps1`, ohne Rückfragen). Ein Paket ist danach nur noch zur Reparatur nötig.
- **„Akte öffnen“ in T2med** als Dienst der Basis für alle Module: Die Modulseite schickt `POST /arbeitsplatz/akte {modul, csrf, patientennummer}` (CSRF-Marke des Moduls); die Basis baut den GDT-Satz 6301 (wie kienzlefon-t2med) und gibt ihn an den Agent des PCs, der ihn als `C:\Kienzle\GDT\AUFRUFi.gdt` ablegt. Aufrufe verfallen nach 60 Sekunden. Die Patientennummer kommt nicht ins Protokoll.
- **Welcher PC?** Der Agent, der gerade von derselben Adresse wie der Browser fragt; ist das nicht eindeutig (Terminalserver), einmal unter Mein Konto → „Mein Arbeitsplatz“ wählen (Cookie). Module sehen den Arbeitsplatz als `KIENZLE_ARBEITSPLATZ` (`$k->arbeitsplatz()`).
- Seite „Arbeitsplätze“: Liste der eingerichteten PCs (verbunden/zuletzt, Adresse, Version, Rückmeldung zu Druckern und Aktualisierung), umbenennen, entfernen (der Agent verliert den Zugang).
- Der Starter prüft das Serverzertifikat jetzt auch über die **Zertifizierungsstelle der Suite** (`ca.crt` im Paket), nicht nur über den Fingerabdruck des einzelnen Zertifikats: Nach neuem Servernamen oder Zertifikat bleibt die Verbindung bestehen.
- Datenbank Version 5 (Tabellen `arbeitsplaetze`, `arbeitsplatz_auftraege`).
- Sicherheit: Der Agent führt nur feste Aufgaben aus (Datei mit fester Endung in den festen GDT-Ordner, Drucker mit geprüften Namen und Adressen auf dem Server, Programm nur mit passender Prüfsumme); keine Befehle aus der Suite. Fehlversuche an `/agent/…` werden gebremst.
- Geprüft mit Tests (PHP: Anmelden, Abholen, Sollzustand, GDT, Zuordnung, Seiten; Go: Anmelden, Angleichen, GDT, Selbstaktualisierung, Zertifizierungsstelle; Windows-Teile nur übersetzt und mit `go vet` geprüft). **Nicht geprüft:** auf einem echten Windows-PC (geplante Aufgabe, Registrierung, Zertifikat, Drucker, Austausch des laufenden Programms), mit T2med und auf der VM. macOS und Linux bekommen vorerst nur den Starter ohne Dienst.

## 0.22.0-beta.9

- **Fehler behoben, Lauschordner auf dem NAS:** Der Ablage-Dienst legte die geholten Dateien mit Rechten 0660 (Gruppe ks-ablage) ab; das Modul ist Besitzer des Ordners, aber nicht in dieser Gruppe, und konnte sie nicht lesen („Permission denied“). Jetzt 0664 (der Ordner mit 2770 schließt andere Benutzer weiterhin aus); schon liegende Dateien werden beim nächsten Lauf (alle 5 Minuten) lesbar gemacht. Test ergänzt.

## 0.22.0-beta.8

- **Nachrichten-Ausgang für Module:** Module, die die Verbindung `nachrichten` anmelden, können SMS und E-Mail über die Zugänge der Basis verschicken, **ohne die Zugangsdaten zu kennen** (bisher bekam ein Modul für E-Mail das SMTP-Passwort; SMS gab es für Module gar nicht). Das Modul legt einen Auftrag in `/var/spool/kienzle/nachrichten/<modul>/` ab (PHP: `$k->smsSenden()`, `$k->mailSenden()`, Stand mit `$k->nachricht()`), der neue Dienst `kienzle-nachrichten` (eigener Benutzer `ks-nachrichten`, alle 20 Sekunden) verschickt ihn und meldet das Ergebnis zurück. Fehlschläge werden 30 Minuten lang wiederholt; je Modul höchstens 60 SMS und 120 E-Mails pro Stunde; ins Protokoll kommen nie Empfänger oder Inhalte. Die Zugänge liest nur `ks-nachrichten` (`/etc/kienzle/verbindungen/nachrichten.json`, 0640).
- Verwaltung → Verbindungen → „Wer nutzt was“ zeigt die Verbindungen mit Namen statt Kennung.
- Geprüft mit Tests (Verteilung, Dienst mit Ersatz-Versand, Wiederholung, Grenze, PHP-Hilfe). **Nicht geprüft:** echter Versand über seven.io und SMTP/Gmail aus dem Dienst, Anlegen des Benutzers und des Zeitgebers durch `install.sh` auf Ubuntu 26.04.

## 0.22.0-beta.7

- **Windows-Druckerskript** (`install-drucker.ps1` im Starter-Paket und Anleitung unter „Arbeitsplätze“): Bei „Drucker … nicht angelegt“ nennt die Meldung jetzt getrennt den Fehler beim Anlegen des Anschlusses und beim Anlegen des Druckers (vorher verdeckte der zweite den ersten) und prüft, ob die Druckeradresse vom Arbeitsplatz aus antwortet. Häufigste Ursache: Der Drucker fehlt auf dem Server (Verwaltung → Drucker → „Virtuelle Drucker der Module abgleichen“) oder Port 631 ist blockiert.
- **Umlaute in der PowerShell-Ausgabe** („unverändert“ statt „unverÃ¤ndert“): Die Skripte werden mit UTF-8-Kennung (BOM) ausgeliefert.
- Nicht auf Windows geprüft.

## 0.22.0-beta.6

- **Fehler behoben: Modul-Update scheiterte mit „Vorgang fehlgeschlagen (Error)“**, wenn das Modul im Datenordner Sockets oder Pipes hat. Betroffen war das Telefonmodul (laufendes Asterisk legt `asterisk.ctl` an): Der Schnappschuss vor dem Update brach beim Kopieren ab. Sockets, Pipes und Gerätedateien werden jetzt übersprungen (sie gehören nicht in einen Schnappschuss). Das Protokoll (`/var/lib/kienzle/module/module.log`) nennt bei unbekannten Fehlern jetzt die Ursache. Mit Test nachgestellt (Socket und Pipe im Datenordner). Nach dem Update der Basis das Telefonmodul erneut aktualisieren; es wurde beim Abbruch nichts verändert.

## 0.22.0-beta.5

- **Klicksperre bei Update-Abfragen von 30 auf 10 Sekunden** („Jetzt nach Updates suchen“, Basis und Module). Bei vielen Klicks hintereinander gilt weiter der Schutz vor dem GitHub-Limit (60 Abfragen pro Stunde und IP-Adresse ohne Anmeldung).

## 0.22.0-beta.4

- **Veränderbare Druckerliste für Module** (`[printer_list]` in `module.toml`, siehe `docs/MODULE-TOML.md`): Ein Modul pflegt die Namen virtueller Drucker selbst (Datei im Datenordner), die Basis legt sie beim Abgleichen an (Verwaltung → Drucker → „Virtuelle Drucker der Module abgleichen“, auch bei Installieren und Aktualisieren). Namen müssen mit einem festen Vorsatz beginnen (schützt Namen anderer Module); fehlt die Datei oder ist sie kaputt, gilt die Vorgabe. Der Druckername kommt als `KIENZLE_DRUCKER` im Umfeld des Druckprogramms an. Die Einheiten der IPP-Drucker bekommen diese Zeile, deshalb startet der erste Abgleich nach dem Update alle virtuellen Drucker einmal neu.
- **Lauschordner auf dem NAS** (Verbindung „ablage“): `$k->lauschen($ziel, $ordner, $endungen)` meldet einen Ordner auf dem NAS an; `ks-ablage` holt neue Dateien (höchstens 20 je Durchlauf) nach `$k->lauschenEingang()`, prüft sie durch zweites Lesen und löscht sie erst danach auf dem NAS. Gleichnamige Dateien werden nicht überschrieben. Geprüft mit dem nachgebauten NAS (SMB und SFTP); nicht an einem echten NAS.

## 0.22.0-beta.3

- **Abhängigkeiten zwischen Modulen** (`[module] requires` in `module.toml`, ab `core_api = "2"`): Ein Modul lässt sich erst installieren, wenn die genannten Module da sind; unter Verwaltung → Module steht dann „Braucht zuerst: …“ und „Installieren“ ist ausgegraut. Ein Modul, das noch gebraucht wird, lässt sich nicht entfernen („Wird noch gebraucht von: …“). Erster Fall: Fax braucht Telefon.
- **Symbole** für die Module Fax (Faxgerät mit Hörer) und Telefon (Hörer) in der Seitenleiste und unter Module.
- Nicht auf der VM geprüft.

## 0.22.0-beta.2

- **Passwortregeln einstellbar** (Verwaltung → Sicherheit → „Passwörter“): Mindestlänge (6–64), Zahl der Zeichenarten (1–4), Mindeststärke (0–4), Sperre häufiger Muster und Sperre des Anmeldenamens im Passwort. Standard wie bisher (10 Zeichen, Muster- und Namensprüfung); die Regeln gelten für neue und geänderte Passwörter, bestehende bleiben gültig.
- **Warnung bei zu lockeren Regeln** (rot: Länge unter 8 oder Mustersperre aus; gelb: Länge unter 10, Namensprüfung aus oder weder Stärke noch Zeichenarten bei unter 12 Zeichen). Der Abschnitt öffnet sich dann von selbst und trägt das Schild „Warnung“.
- **Stärkebalken** beim Festlegen und Ändern eines Passworts (Einrichtungslink, erster Admin, Konto → Passwort ändern): zeigt Stärke von „sehr schwach“ bis „sehr gut“ und was noch fehlt. Die Rechnung steht in `Passwords::score` und `passwort.js` (gleiche Ergebnisse). Nicht auf der VM geprüft.

## 0.22.0-beta.1

- **Schnittstelle für Module, Version 2 (`core_api = "2"`)**, für die neuen Module Fax und Telefon (`docs/PLAN-FAX-TELEFON.md`). Version 1 bleibt gültig; die bisherigen Module ändern sich nicht.
  - `[[exchange]]`: gemeinsamer Austauschordner zweier Module. Melden beide einander an, legt die Basis `/var/spool/kienzle/austausch/<a>_<b>` an (root, 0770, ACL nur für die beiden Modulbenutzer), trägt ihn in die Weboberfläche beider Module ein (`open_basedir`, `ReadWritePaths`) und startet deren Dienste neu. Kein anderes Modul kommt hinein.
  - `[system] disables`: Dienste aus Ubuntu-Paketen, die ein Modul selbst betreibt, werden gestoppt und maskiert (zugelassen nur `asterisk.service`); beim Entfernen wird die Maskierung aufgehoben.
- Der Installer installiert zusätzlich `acl` (für die Rechte des Austauschordners).
- Beschreibung in `docs/MODULE-TOML.md`. Geprüft mit Tests; **nicht auf der VM geprüft** (ACL und Maskierung brauchen root und systemd).

## 0.21.4-beta.67

- **Starter unter Windows ohne schwarzes Fenster:** Das Starterprogramm wird für Windows ohne Konsole gebaut (`-H=windowsgui`), beim Aufruf aus T2med öffnet sich nur noch der Browser. Fehler stehen weiter in `starter.log` neben dem Programm. Das Paket auf den Arbeitsplätzen einmal neu ausführen (aktualisiert nur das Programm). Nicht unter Windows geprüft.

## 0.21.4-beta.66

- **Paket-Download „Serverzertifikat der Suite nicht lesbar“ behoben:** Die PHP-Einstellung `open_basedir` erlaubte nur `ca.crt`, nicht `server.crt`; der Starter braucht das Serverzertifikat für Fingerabdruck und Paket. Jetzt ist `server.crt` lesend freigegeben (`installer/vorlagen/php-fpm-core.conf`). **Nach dem Update einmal `sudo bash installer/install.sh`**, das schreibt die PHP-Einstellung neu. Nicht auf dem Server geprüft.

## 0.21.4-beta.65

- **Seite „Arbeitsplätze“ neu geordnet:** Oben steht das **Paket** (Starter, Zertifikat, Drucker in einem Durchgang), danach der **Name der Suite** (immer nötig). Zertifikat und virtuelle Drucker stehen darunter nur noch zum Aufklappen als Einrichtung **von Hand**, falls das Paket nicht klappt (Anker `#zertifikat`, `#drucker`, `#name`, `#paket` bleiben). Nicht auf der VM geprüft.

## 0.21.4-beta.64

- **Starter gehört zu „Arbeitsplätze“:** Die eigene Seite „Starter“ im Menü entfällt. Auf der Seite „Arbeitsplätze“ steht unten der Abschnitt „Paket für einen Arbeitsplatz“ (Startkontexte, Download je System, Schlüssel erneuern, nur für angemeldete Admins). Die alte Adresse leitet dorthin weiter.
- **Ein Paket richtet den ganzen Arbeitsplatz ein:** Starterprogramm, Zertifikat, Aufruf aus T2med und die virtuellen Drucker der Module (vKasse, vTermine, vBriefversand) in einem Durchgang. Windows fragt für die Drucker einmal nach Administratorrechten (`install-drucker.ps1`), macOS und Linux nutzen `lpadmin` über sudo. Die Texte zur Druckereinrichtung bleiben als Rückfall auf der Seite.
- **Erneutes Ausführen aktualisiert nur Änderungen:** Jede Zeile meldet „neu“, „aktualisiert“ oder „unverändert“ (Starterprogramm, Einstellungen, Zertifikat, Aufruf je Kontext, Drucker). Nicht auf der VM geprüft.
- Das Erklärvideo liegt nicht mehr im Repository (zu groß, README-Link entfernt).
- Die Umbenennung „sumup“ → „kasse“ ist abgeschlossen; der einmalige Umzug (`replaces`, Daten und Rechte, Datenbank-Schritt) ist wieder aus dem Code entfernt. Die Datenbank-Versionsnummer bleibt bei 4.
- Prüfung von `module.toml`: der alte Schlüssel `web.starter` wird wieder angenommen (und ignoriert), damit ein installiertes altes Kasse-Modul die Basis-Aktualisierung nicht mehr blockiert.

## 0.21.4-beta.63

- **Starter nur noch an einer Stelle:** Die Hilfeseite „Arbeitsplätze“ und der Einrichtungs-Überblick verweisen auf die Seite „Starter“ (Verwaltung → Starter) statt auf das Paket der Kasse.
- **Starter-Schlüssel erneuern mit Rückfrage:** Der Knopf steckt in einem eingeklappten Bereich „nur im Fehlerfall“, verlangt ein Häkchen und wird sonst vom Server abgelehnt.
- **T2med-Aufruf der Kasse heißt jetzt `kienzle-kasse://`** (statt `kienzle-sumup://`). Die Adresse im T2med-Drittanbieter-Zugriff ändern und das Starter-Paket auf den Arbeitsplätzen neu ausführen. Nicht auf der VM geprüft.

## 0.21.4-beta.62

- **Erklärvideo im Repository:** `docs/video/Kienzle-Suite-Ueberblick.mp4` (2:38 Minuten, ohne Ton, erfundene Daten), in der README verlinkt. Keine Änderung am Programm.

## 0.21.4-beta.61

- **Neue Bildschirmfotos und Erklärvideo-Werkzeug:** Alle Bilder in `docs/screenshots` neu mit erfundenen Daten, dazu Starter, Kasse (Vorgänge, Ablage, IGeL, GOÄ-Rechner), Terminzettel, Signatur und ein Schaubild „Weg der Rechnung“. Neue Skripte: `scripts/bildschirmfotos-seiten.py`, `scripts/modul-vorschau/`, `scripts/video-bauen.py`. README und `AGENTS.md` angepasst. Keine Änderung am Programm.

## 0.21.4-beta.60

- **Virtuelle Drucker dürfen Aufträge an die Ablage abgeben:** Die Einheit eines Druckers (z. B. „vKasse“) lief mit `ProtectSystem=strict` und durfte nur in den Datenordner des Moduls schreiben. Der Auftragsordner der Ablage (`/var/spool/kienzle/ablage/<modul>`) war schreibgeschützt. Die Rechnung kam an, die PDF wurde aber nie zum Netzwerkspeicher übergeben. Jetzt steht der Ordner in `ReadWritePaths` (mit `-`, falls er fehlt). Die Drucker-Einheit wird beim nächsten Abgleich (Verwaltung → Drucker oder automatisch) neu geschrieben und der Drucker neu gestartet. Nicht auf der VM geprüft.

## 0.21.4-beta.59

- **Klicksperre bei Update-Abfragen:** „Jetzt nach Updates suchen“ (Basis und Module) geht höchstens alle 30 Sekunden (ab 0.22.0-beta.5: 10 Sekunden). Davor erscheint „Bitte kurz warten …“ mit der Restzeit. Grund: GitHub erlaubt ohne Anmeldung nur 60 Abfragen pro Stunde und IP-Adresse (Fehler „HTTP 403 rate limit exceeded“). Nicht auf der VM geprüft.

## 0.21.4-beta.58

- **Rückmeldung nach Knopfdruck überall:** Viele Aktionen leiteten nach dem Absenden ohne Meldung weiter, besonders **Updates** („Jetzt nach Updates suchen“, „Jetzt installieren“, Kanal speichern, Zurück auf frühere Version), aber auch Server, Dienste, Gruppen, Firewall, Module, Verbindungen, Drucker, Einrichtung und Sicherung. Jetzt merkt sich der Server einen kurzen Text zur Sitzung und zeigt ihn einmalig auf der nächsten Seite; `core.js` (v7) setzt ihn direkt hinter den angeklickten Knopf. Das gilt auch für die Meldungen der Signatur-Seiten. Nicht im Browser geprüft.

## 0.21.4-beta.57

- **Logo im Browser-Tab:** Kleines Herz mit Puls-Linie (eigene Zeichnung, `assets/favicon.svg`) als Tab-Symbol aller Seiten der Basis und der Module im Rahmen der Suite. Falls der Tab noch kein Herz zeigt: Strg+F5. Nicht im Browser geprüft.

## 0.21.4-beta.56

- **Zentraler Arbeitsplatz-Starter:** Neue Seite **Einrichten → Starter** (`/verwaltung/arbeitsplaetze`). Ein Starterprogramm je PC (Windows, macOS, Linux) und ein Starter-Schlüssel für alle Module. Die Seite listet die Startkontexte der installierten Module (`[[starters]]` in `module.toml`, z. B. `kienzle-sumup://…`) mit der Adresse für T2med und bietet das Paket mit Starterprogramm, Zertifikat, Schlüssel und Einrichtungsskript an. Neue Module erscheinen von selbst; danach das Paket am PC einmal erneut einrichten. Der Schlüssel liegt verschlüsselt in der Datenbank und lässt sich erneuern.
- nginx: je Modul mit Startkontexten `POST /starter/<modul>`, vorher prüft die Basis den Schlüssel (`/_starter-pruefen`, mit Bremse nach Fehlversuchen). Das Modul bekommt nur geprüfte Aufrufe. Der alte Weg `/api.php` der Kasse bleibt unverändert.
- `core.js` (v6) leitet `#launch=…&m=<modul>` nach der Anmeldung an das richtige Modul weiter.
- Das Starterprogramm (Go, `core/client/starter`) wird von `scripts/starter-bauen` gebaut und liegt im Basis-Archiv (`client-dist`); CI baut es vor dem Release.
- Nicht auf der VM, nicht mit T2med und nicht im Browser geprüft; Go-Tests und Cross-Kompilierung sind grün.

## 0.21.4-beta.55

- **Festplatten-Balken wird wieder angezeigt:** Die Sicherheitsrichtlinie der Seite erlaubt keine `style`-Angaben im HTML, deshalb blieb der Belegungsbalken leer. Die Breite wird jetzt per Skript gesetzt (`core.js?v=5`).
- Falls die Start-Kacheln (Server, Betrieb, Dienste, je drei pro Zeile) nach dem Update noch alt aussehen: einmal Strg+F5, damit `core.css?v=36` neu geladen wird. Nicht im Browser auf der VM geprüft.

## 0.21.4-beta.54

- **Meldungen bleiben bei der Aktion:** Die Rückmeldung nach einem Klick („Gespeichert.“, Fehlertext) erscheint nicht mehr ganz oben auf der Seite, sondern direkt hinter dem Block mit dem angeklickten Knopf und wird ins Bild gerollt (`core.js?v=4`, `core.css?v=36`). Fehlt der Block oder wechselt die Seite, bleibt sie oben.
- **Modulverwaltung:** Bei Installation und Aktualisierung eines Moduls zeigt ein Hinweisfeld mit **drehendem Kreis** „… läuft“, bleibt beim Scrollen sichtbar, und die Seite lädt sich selbst neu. Die Dienste des Moduls werden bei der Aktualisierung neu gestartet; die Meldung am Ende sagt jetzt „aktualisiert, Dienste neu gestartet“.
- Nicht im Browser auf der VM geprüft.

## 0.21.4-beta.53

- **Alte Erfolgsmeldungen** („Gespeichert. Die Suite ist jetzt erreichbar unter …“, „Virtuelle Drucker abgeglichen“, „Letterxpress gespeichert“, „Empfänger gespeichert“, Modul-Meldungen) erscheinen nur noch **10 Minuten** nach der Aktion, nicht mehr bei jedem späteren Aufruf der Seite.
- Startseite, **Server-Zustand**: Kacheln in **drei Spalten**, gegliedert in **Server** (Prozessor, Arbeitsspeicher, Festplatte, Temperatur), **Betrieb** (Läuft seit, Letzte Sicherung) und **Dienste** (Guthaben Letterxpress, seven.io).
- Mein Konto: Der Pfeil bei „Passwort ändern“ sitzt wie bei den anderen Zeilen rechts; „Authenticator-App“ hat ein eigenes Symbol (Schild mit Haken).
- Benutzer und Gruppen: Avatar und Anmeldename in einer Zeile, Name ohne Umbruch, „Zugang“ darf umbrechen.
- Nicht im Browser auf der VM geprüft (`core.css?v=35`).

## 0.21.4-beta.52

- Seitenleiste: Der Anmeldename steht jetzt unter **Mein Konto** (oben), unten entfällt „Abmelden (Name)“. **Abmelden** gibt es als Knopf auf der Seite „Mein Konto“ (neben dem Profil). Nicht im Browser geprüft.
- Mein Konto: Der Anzeigename steht als Text mit kleinem **Stift**; erst ein Klick darauf zeigt das Feld zum Ändern (mit „Speichern“).

## 0.21.4-beta.51

- Seitenleiste: Die Reihenfolge der Anwendungen ändert man jetzt **per Ziehen** (Ziehen und Ablegen) statt mit Pfeilen und „Reihenfolge ändern“. Per Tastatur: Alt + Pfeil nach oben/unten auf dem Eintrag. Die Reihenfolge merkt sich weiter der Browser. Auf Touch-Geräten (Handy, Tablet) funktioniert Ziehen nicht.
- Seitenleiste in Kasse und Terminzettel zeigte nach einem Basis-Update noch die alte Leiste (ohne Einklappfeile bei Praxis und Betrieb): Die Modul-Dienste (php-fpm) hielten die alte Datei im Zwischenspeicher, weil `current` ein Symlink ist. Jetzt lösen die Modul-Dienste den Symlink bei jedem Aufruf neu auf (`opcache.revalidate_path`), und der Installer startet sie neu. **Einmal `sudo bash installer/install.sh`** (startet die Modul-Dienste neu), danach erscheinen die Pfeile in allen Modulen. Nicht im Browser und nicht auf der VM geprüft.

- `module.toml`: neuer Schlüssel `[web] uploads = true` erlaubt Formular-Uploads (`$_FILES`, bis 3 MB je Datei) im Modul-Dienst; ohne ihn bleibt `file_uploads` aus. Beschreibung in `docs/MODULE-TOML.md`.  Bisher von keinem Modul genutzt (der Terminzettel sendet sein Logo roh per fetch).

## 0.21.4-beta.50

- Verwaltung → Module: Das alte, in „Signatur“ umbenannte Modul „unterschrift“ wird nicht mehr als verfügbar angeboten (stand wegen eines alten GitHub-Releases weiter in der Liste). Nicht auf der VM geprüft.

## 0.21.4-beta.49

- Seitenleiste: Die Gruppen **Praxis** und **Betrieb** lassen sich wie **Anwendungen** einklappen (Pfeil an der Überschrift). Die Gruppe mit der aktuellen Seite bleibt immer offen.
- Seitenleiste: Die Reihenfolge der **Anwendungen** (Module) ist frei wählbar: unter den Modulen auf „Reihenfolge ändern“ klicken, mit ▲ ▼ verschieben, „Fertig“. Zugeklappte Gruppen und Reihenfolge merkt sich der Browser (je Browser, nicht je Benutzerkonto); neue Module erscheinen hinten. Gilt in der Basis und in allen Modulseiten. Auf schmalen Bildschirmen (Handy) bleiben alle Gruppen offen. Nicht im Browser geprüft.

## 0.21.4-beta.48

- Uploads in Modulen über 1 MB scheiterten mit „client intended to send too large body“: Die Anmeldeprüfung (`/_auth/…`) fiel auf das Limit des Servers von 1 MB zurück, obwohl die Module bis 25 MB zulassen. Betrifft das Hochladen der Pad-Software im Modul Signatur (6,8 MB). Das Limit gilt jetzt auch dort mit 25 MB. Nach dem Update erzeugt die Basis die nginx-Einstellungen neu (`sudo bash installer/install.sh` oder Verwaltung → Module). Nicht auf dem Server geprüft.

## 0.21.4-beta.47

- PowerShell-Skript für die virtuellen Drucker (Arbeitsplatz einrichten): Fehler werden nicht mehr verschluckt. Das Skript meldet, wenn Windows nach dem Einschalten der IPP-Funktion neu starten muss, wenn der Server unter dem Namen oder auf Port 631 nicht erreichbar ist, und nennt am Ende den Grund, falls der Drucker nicht angelegt wurde. Nicht an einem Windows-PC geprüft.

## 0.21.4-beta.46

- Startseite → Server-Zustand: Prozessor, Arbeitsspeicher, Festplatte (mit Hochrechnung), Temperatur und Laufzeit fehlten auf dem echten Server ganz. Ursache: Die PHP-Einstellung `open_basedir` der Basis erlaubte weder `/proc` noch `/sys` noch den Plattenordner; die Tests liefen nur mit Testordnern. Jetzt sind genau diese Dateien lesend freigegeben (`installer/vorlagen/php-fpm-core.conf`), die Platte wird in `/var/lib/kienzle/core` gemessen, und ein Test prüft die Vorlage. **Nach dem Update einmal `sudo bash installer/install.sh`**, das schreibt die PHP-Einstellung neu; danach erscheinen die Kacheln. Nicht auf dem Server geprüft.

## 0.21.4-beta.45

- Das neue Modul heißt **Signatur** (vorher „Unterschrift“, Kennung `signatur`, Adresse `/signatur`). Seitenleisten-Symbol angepasst. Wer „Unterschrift“ 0.1.0-beta.1 schon installiert hat, entfernt es und installiert „Signatur“ neu; es gab noch keine Daten, die erhalten bleiben müssten. Nicht auf der VM geprüft.

## 0.21.4-beta.44

- Neues Modul **Unterschrift** (0.1.0-beta.1) liegt bei: Vorlagen und Vorgänge für Dokumente am Unterschriftenpad. Symbol in der Seitenleiste ergänzt. Pad-Anbindung und Rückschreiben nach T2med folgen; nicht auf der echten VM geprüft.

## 0.21.4-beta.43

- Virtuelle Drucker umbenannt: „SumUp-Rechnung“ → **vKasse**, „Letterxpress“ → **vBriefversand**, „Terminzettel“ → **vTermine**. Alle Beschreibungstexte, Anleitungen, PPD-Dateien (`vKasse-PDF.ppd`, `vBriefversand-PDF.ppd`, `vTermine-PDF.ppd`) und Tests sind angepasst. **Achtung:** Die alten Warteschlangen werden beim Abgleich entfernt, die neuen angelegt. An jedem Arbeitsplatz muss der Drucker neu eingerichtet und in T2med neu gewählt werden (Arbeitsplatz einrichten → Virtuelle Drucker). Die Zuordnung „Rechnungsdrucker“ in der Kasse bleibt auf dem Praxisdrucker.
- Verwaltung → Drucker: Jede Karte eines virtuellen Druckers nennt in einem Satz, was er macht.
- Nicht auf der echten VM geprüft (Abgleich der Warteschlangen nach der Umbenennung).

## 0.21.4-beta.42

- Tooltips (Info-Zeichen „i“): Sie ragen nicht mehr über den Fensterrand. Reicht der Platz nicht, wird der ganze Tooltip verschoben (im Zweifel in die Mitte), nicht schmaler gemacht. Gilt für die Basis und die Module; nicht auf der echten VM geprüft.

## 0.21.4-beta.41

- Startseite, Server-Zustand: Prozessor und Arbeitsspeicher als Kacheln mit Balken (Prozent belegt). Die Festplatte ist eine breite Kachel: Der Balken zeigt den heutigen Stand und schraffiert die Hochrechnung für in 12 Monaten, dazu den Zuwachs pro Monat und wie lange der Platz reicht.
- Die Hochrechnung braucht einen Verlauf: Beim Aufruf der Startseite wird einmal je Tag der belegte Platz gemerkt (bis zu 400 Tage). Gerechnet wird aus den letzten 90 Tagen; bis mindestens 3 Tage vorliegen, steht dort „Hochrechnung folgt“. Der Verlauf wächst nur an Tagen, an denen ein Admin die Startseite öffnet.
- Die Seite „Server“ verweist auf den Server-Zustand der Startseite (er bleibt dort, wo alle Admins zuerst hinsehen).
- Fällt eine Kachel (Sicherung, Guthaben) aus, verschwindet nicht mehr die ganze Karte „Server-Zustand“.
- Nicht auf der echten VM geprüft.

## 0.21.4-beta.40

- Arbeitsplatz einrichten: Der Hinweis zum Namen richtet sich nach dem eingestellten Servernamen (FRITZ!Box wird nur genannt, wenn der Name auf „.fritz.box“ endet).
- Neuer Schritt „Virtuelle Drucker einrichten“ (nach Zertifikat und Name, vor dem Kassen-Starter; nur wenn Module virtuelle Drucker anbieten), mit der vollständigen Anleitung je Drucker (Windows, PowerShell-Skript, Mac, T2med). Die Anleitung bei Verwaltung → Drucker bleibt unverändert.
- Verweise darauf in der Einrichtungsübersicht der Basis.
- Nicht getestet und nicht auf der echten VM geprüft.
- Einrichtung: Neuer Punkt „Virtuelle Drucker der Module auf den Arbeitsplätzen eingerichtet“ (nur wenn ein Modul virtuelle Drucker anbietet; wird nach einer Namensänderung wieder offen). Der Punkt „Arbeitsplatz-Starter“ führt jetzt in die Einstellungen des Moduls.
- Blauer Punkt: Er stand bei „Module“, obwohl der offene Punkt (Starter) beim Modul zu erledigen war. Er erscheint jetzt beim Modul selbst; die Seite „Module“ nennt außerdem, welche Einrichtungspunkte offen sind (bei der Basis und je Modul).
- SMS (seven.io): Die Guthabenabfrage liest den Betrag jetzt auch, wenn er als Text („20,50“) oder als Objekt (amount/balance) kommt; bisher stand dann „unbekannt“. Nicht mit dem echten Konto geprüft.

## 0.21.4-beta.39

- **Virtuelle Drucker: IPP immer an, Samba optional.** Verwaltung → Drucker zeigt jeden virtuellen Drucker als Karte: IPP-Adresse mit Knopf „Kopieren“, „Freigabe per IPP: immer an“, ein Schalter „Windows-Freigabe (Samba, alt)“ (speichert sofort, Erläuterung im Hinweis „i“: standardmäßig aus, von neuem Windows nicht mehr unterstützt, IPP ist der Standardweg für alle Betriebssysteme) und „Testseite“. Das Häkchen für IPP und „Freigabe speichern“ entfallen.
- Neue virtuelle Drucker starten nur mit IPP (`share = ["ipp"]` in allen Modulen). Der Druckerdienst gibt virtuelle Drucker immer per IPP frei, auch wenn ein Modul nichts nennt; die Samba-Wahl des Admins bleibt bei einem Abgleich erhalten (bisher setzte der Abgleich sie auf die Vorgabe des Moduls zurück). Bereits mit Samba freigegebene Drucker behalten es.
- Anleitung für die Arbeitsplätze: Voraussetzung neu formuliert (nur Firewall IPP 631), PPD-Datei je Drucker (auch „Letterxpress“), Hinweis zum Sammelversand.
- Nicht auf der echten VM geprüft (Seite, Kopieren, Sofort-Speichern mit Skript).

## 0.21.4-beta.38

- Verwaltung → Server: Der Verweis „Name, Anschrift, Konto und Steuer der Praxis“ (zeigte nur auf „Praxis“) entfällt.
- Arbeitsplätze einrichten: Ist die Kasse installiert, steht als dritter Schritt der **Starter** für T2med mit einem Knopf zu Kasse → Einstellungen → „Arbeitsplätze einrichten“, wo das Paket geladen wird.

## 0.21.4-beta.37

- „Mein Konto“ aufgeräumt: Oben steht das Profil, dort lässt sich jetzt der **Anzeigename ändern** (leer lassen zeigt den Benutzernamen; der Benutzername zum Anmelden bleibt). Darunter sind E-Mail-Adresse, Passwort, Handynummer (SMS-Ersatz, nur Admins), Authenticator-App, Passkeys und gemerkte Geräte Ausklappzeilen mit Statusmarke („bestätigt“, „nicht eingerichtet“ …) statt sieben Blöcken mit langem Text. Wartet eine E-Mail-Adresse auf Bestätigung, ist diese Zeile geöffnet.

## 0.21.4-beta.36

- Die Seitenleiste in den Modulen (Kasse, Terminzettel) sieht jetzt aus wie in der Verwaltung: Für Admins zeigt sie die roten Zähler (Updates, Module, Sicherung), den Punkt am Logo bei offener Einrichtung und den orangen Punkt an Modulen mit offener Einstellung. Mitarbeiter sehen davon nichts, wie bisher.
- Die Zähler werden 30 Sekunden zwischengespeichert, damit die Prüfung jeder Modul-Anfrage schnell bleibt. Die Modul-Hilfsklasse (`KienzleModul::menue()`) liefert sie mit; vorhandene Module brauchen keine Änderung.

## 0.21.4-beta.35

- Modulübersicht neu gestaltet: Das **Logo** der Kachel öffnet das Modul (kleines ↗ am Logo), der Knopf „Öffnen“ entfällt. Ein **rundes Pfeil-Symbol** unter dem Namen dient für beides: mit neuer Version steht dort „Auf 2.x aktualisieren“, sonst „aktuell“ mit einem Rundpfeil zum Suchen nach Updates („Nach Update suchen“ im Menü entfällt).
- Starten, Neu starten und Entfernen stehen weiter hinter „⋯“, jetzt mit einheitlichen Symbolen. **Entfernen ist doppelt abgesichert**: Nach „Entfernen …“ muss der Name des Moduls eingetippt werden (der Server prüft das ebenfalls), dazu optional „Auch alle Daten löschen“.

## 0.21.4-beta.34

- Dokumentation: README und Bildschirmfotos auf den aktuellen Stand gebracht (neu: Übersicht, Mein Konto, Gruppen, Einrichtung, Verbindungen, Kasse-Startseite und -Einstellungen); die Bilder entstehen jetzt reproduzierbar mit `scripts/bildschirmfotos.sh` (erfundene Daten, Vorschau der Kasse in `scripts/kasse-vorschau/`). AGENTS.md enthält den vollständigen Stand für neue Sitzungen. Keine Funktionsänderung.

## 0.21.4-beta.33

- Mein Konto: Die E-Mail-Adresse wird jetzt mit einem Zahlencode bestätigt (16 Ziffern in Vierergruppen, z. B. 1234-5678-9012-3456) statt mit einem Link, denn ein Link ist im lokalen Netz oft nicht erreichbar. Der Code wird in Mein Konto eingegeben, gilt eine Stunde und ist nach fünf falschen Eingaben verbraucht. Der Link zum Zurücksetzen des Passworts („Passwort vergessen?“) bleibt unverändert.

## 0.21.4-beta.32

- Module können ihre Seiten im Rahmen der Suite zeigen (Seitenleiste, Kopf, Praxisname): neue Kopfzeile `X-Kienzle-Menue` der Anmelde-Prüfung (schlank, ohne zusätzliche Anfrage und ohne Sitzungs-Marke) und die Helfer `menue()` und `rahmen()` im PHP-Modul-Helfer; Beschreibung in docs/MODUL-SCHNITTSTELLE.md.

## 0.21.4-beta.31

- Mein Konto: Profilkopf mit Avatar (Initialen, unten rechts das Symbol der wichtigsten Rolle: Admin, Arzt, MFA) und den Gruppen des Kontos als Rollen-Zeile; derselbe Avatar steht in der Benutzerliste.
- Neu: E-Mail-Adresse im Konto. Sie gilt erst, nachdem der Link in der Bestätigungs-Mail geöffnet wurde (24 Stunden gültig, nur angemeldet, eine Adresse nur für ein Konto). Der Versand läuft über die E-Mail-Verbindung der Suite (neue Aktion „mail-senden“ im root-Dienst); ohne sie weist die Seite darauf hin.
- Neu: „Passwort vergessen?“ auf der Anmeldeseite (nur sichtbar, wenn der E-Mail-Versand eingerichtet ist). Mit bestätigter Adresse kommt ein Link (3 Tage, einmal), unabhängig davon, ob die Adresse existiert, dieselbe Antwort; höchstens fünf Anfragen, dann Pause. Beim Verwenden werden – wie beim Admin-Link – Passwort, App, Passkeys und alle Anmeldungen zurückgesetzt. Admin-Konten mit Authenticator-App sind ausgenommen (nur Konsole).
- Datenbank: Änderungen innerhalb einer Transaktion dürfen jetzt verschachtelt sein.

## 0.21.4-beta.30

- Einrichtung: Die Liste ist in Gruppen gegliedert (Zugang und Konten, Servername und Arbeitsplätze, Datensicherung, Schutz und Updates), mit Zähler je Gruppe, Legende und klareren Zeilen.
- Ändert man den Servernamen oder eine Adresse (Verwaltung → Server), werden alle davon abhängigen Punkte zurückgesetzt und rot angezeigt („Erneut nötig“): Servername auf den Arbeitsplätzen (Router/hosts), Neuanmeldung unter dem neuen Namen, Passkeys, Arbeitsplatz-Starter der Module (Zertifikat) und – falls vorhanden – Dienste mit Anmeldung über die Suite; bei „neue Zertifizierungsstelle“ zusätzlich die Zertifizierungsstelle auf den Arbeitsplätzen. Oben erscheint ein Hinweis; jeder Punkt wird einzeln wieder bestätigt. Das Ändern des Praxisnamens setzt nichts zurück.
- Neue Einrichtungspunkte: „Arbeitsplatz-Starter … neu installiert“ und „Dienste mit Anmeldung … umgestellt“ (nur wenn Module mit Starter bzw. OIDC-Anbindung vorhanden sind).

## 0.21.4-beta.29

- Benutzer und Gruppen: Gruppen sind jetzt feste Rollen. Wer einer Gruppe angehört, behält sie dauerhaft; ändert man die Gruppe (Rechte, Modul-Admin), gilt das sofort für alle Mitglieder. Mehrere Rollen pro Benutzer sind möglich.
- Beim Benutzer gibt es eine Rechte-Matrix je Modul: Spalten = Rollen (✓ = die Rolle gewährt das Recht), letzte Spalte = Ergebnis für diesen Benutzer. Blau = von der Rolle, gelb mit Stift = einzeln geändert. Ein Klick ändert ein Recht einzeln, „zurück auf Rolle“ nimmt alle Einzeländerungen zurück.
- Standardsymbole: Admin = Schild mit Haken, Arzt = Stethoskop, MFA = Person mit Herz (auch in der Benutzerliste und bei den Gruppen, dort mit der Mitgliederliste).
- Update: bisherige Rechte werden als Einzelrechte ohne Rolle übernommen; für niemanden ändert sich der Zugang. Datenbank-Version 3 (neue Tabellen `user_groups`, `user_overrides`).

## 0.21.4-beta.28

- Der Name der Praxis steht jetzt unter Verwaltung → Praxis (ganz oben, mit Status „✓ eingetragen“); die Seite „Server“ enthält nur noch den Namen des Servers. Die Seitentitel und das Menü heißen kurz „Server“ und „Praxis“ (vorher „Server und Name“ und „Praxis und Daten“). Beim Speichern des Praxisnamens bleiben die Servernamen unverändert; der Root-Dienst übernimmt die Änderung wie bisher.
- Kasse 2.3.4-beta.9: Hinweistexte in den Kasse-Einstellungen nennen „Verwaltung → Praxis“.

## 0.21.4-beta.27

- Module: Die Seite zeigt Kacheln statt langer Kästen. Installierte Module mit Symbol, Status („✓ läuft“, „gestoppt“), Version und „Öffnen“; bei einem Update steht „Update x.y.z“ mit Knopf „Aktualisieren“ in der Kachel. Noch nicht installierte Module stehen gestrichelt im selben Raster mit „verfügbar“ und „Installieren“. Starten, Neu starten, Nach Update suchen und das rote „Modul entfernen“ (mit Wahl, ob die Daten gelöscht werden) sind im Drei-Punkte-Menü der Kachel. Die Funktionen sind unverändert.

## 0.21.4-beta.26

- Seitenleiste vereinfacht: Die Gruppe „Einstellungen“ entfällt (sie führte zu denselben Modulen wie „Anwendungen“). „Anwendungen“ ist einklappbar und zeigt je Modul das Symbol der Startseite; der rote Punkt neben einem Modul zeigt, dass dort noch etwas eingerichtet werden muss. Die Einstellungen eines Moduls öffnen sich im Modul selbst.
- Mein Konto: Alle Abschnitte (Passwort ändern, Handynummer, Authenticator-App, Passkeys, Gemerkte Geräte) sind einklappbar und beim Öffnen der Seite eingeklappt; der Status (z. B. „✓ eingerichtet“) steht in der Kopfzeile und bleibt eingeklappt sichtbar. Abschnitte mit Fehlermeldung bleiben offen.

## 0.21.4-beta.25

- Startseite (Admins): neue Karte „Server-Zustand“ mit Kacheln für freien Speicherplatz, Arbeitsspeicher, Prozessorlast (Minutenmittel je Kern), Temperatur (nur wenn der Server einen Sensor meldet, in einer VM meist nicht), Laufzeit seit dem Neustart, Datum der letzten Sicherung (gelb bei „nicht eingerichtet“ oder älter als drei Tage, rot bei Fehler; Klick öffnet die Sicherung) sowie Guthaben bei Letterxpress und seven.io (nur wenn eingerichtet; gelb unter 5 €). Warnfarben: Speicher und Arbeitsspeicher ab 85 % (rot ab 95 %) belegt, Prozessor ab 80 % (rot ab 95 %), Temperatur ab 75 °C (rot ab 85 °C). Mitarbeiter ohne Admin-Recht sehen die Karte nicht.
- Guthaben: Aktualisieren-Pfeil an der Kachel fragt den Betrag beim Anbieter ab und lädt die Startseite danach selbst neu. Neu: seven.io-Guthaben wird abgefragt (Schlüssel prüfen merkt den Betrag ebenfalls). Es wird nichts verschickt. Nur mit nachgebautem Aufruf getestet, nicht gegen echtes seven.io/Letterxpress; der root-Dienst muss neu starten (normales Update genügt).
- Symbole: Einrichtung, Server-Zustand, Ihre Module sowie je Modul (Kasse, Terminzettel; andere das Standardsymbol) erhalten ein Symbol.
- Technik: Neue Klasse `SystemStatus` liest nur /proc und /sys (keine Befehle, keine Rechte). Die Prüfen-Aktion der Verbindungen kennt `zurueck=start`.

## 0.21.4-beta.24

- Verbindungen → E-Mail: Ist Gmail (oder SMTP) verbunden, zeigt ein grüner Balken „Mit Google verbunden: …“ und die Überschrift „✓ eingerichtet“; die Anmeldung (Anleitung, Schritt 1) ist dann unter „Erneut einrichten oder anderes Konto verbinden“ eingeklappt. Knopfreihe (Anmeldung prüfen, Testnachricht mit Empfängerfeld, Entfernen) steht in einer Zeile; das Feld bleibt mit seinem Knopf zusammen (auch bei SMS und Letterxpress).

## 0.21.4-beta.23

- Gmail-Anleitung: Schritt „Branding“ ergänzt (App-Name, Support-E-Mail, Adressen für Startseite und Datenschutzerklärung, autorisierte Domain), den Google vor „In Produktion“ verlangt. Nur Text.

## 0.21.4-beta.22

- Testbetrieb überall gelb gekennzeichnet (wie die offenen Punkte): neues Schild `tag test`/„Testbetrieb“ bei Letterxpress in den Verbindungen; „Live“ grün.
- Verbindungen → Letterxpress: eigener Knopf „Guthaben abfragen“ mit Symbol; darüber steht der zuletzt abgefragte Wert mit Uhrzeit (auch nach „Zugang prüfen“). Nur mit nachgebautem Aufruf getestet, nicht gegen das echte Letterxpress. Der root-Dienst muss neu starten (normales Update genügt).

## 0.21.4-beta.21

- Einheitliches Aussehen der Kartenüberschriften im ganzen Projekt: ein passendes Symbol davor und, wo es sinnvoll ist, ein Status-Schild dahinter („✓ eingerichtet“ / „nicht eingerichtet“, „✓ ausgefüllt“, „✓ aktiv“, „✓ gedruckt“ …): Verbindungen, Praxis und Daten, Mein Konto (Authenticator-App, Passkeys, Handynummer), Sicherheit (Firewall), Sicherung (Schutz vor Erpressungstrojanern, Schlüsselblatt, Benachrichtigung). Neue Seiten setzen dafür nur `View::$status`.
- Gmail-Anleitung an die „Google Auth Platform“ von Google angepasst (Erste Schritte, Datenzugriff, Zielgruppe, Clients). Nur nach Googles Dokumentation geschrieben, nicht mit einem Konto durchgespielt.

## 0.21.4-beta.20

- Verbindungen → E-Mail-Versand: neben dem SMTP-Server jetzt auch ein Gmail-Konto per OAuth (Gmail-Schnittstelle, nur Berechtigung „E-Mails senden“). Jede Praxis legt dazu einmal einen eigenen Google-Zugang an (Anleitung auf der Seite); die Anmeldung geschieht über Weiterleitung auf localhost und Einfügen der Adresse. Module bekommen `art = gmail` mit Client und Refresh-Token. Nur mit nachgebautem Google getestet, nicht mit einem echten Konto.

## 0.21.4-beta.19

- Verbindungen: Hilfe-Tooltips (i) bei den Feldern von SMS (seven.io: welche Einstellungen und Berechtigungen der API-Key braucht – nur „sms“ und „balance“, Umgebung Live, unbegrenzt gültig) und Letterxpress. Nur Text, nicht im Browser geprüft.

## 0.21.4-beta.18

- Verbindungen: neuer Abschnitt „Letterxpress (Briefversand)“ mit Benutzername, API-Key und Betrieb Test/Live, Prüfung (Guthaben) und Entfernen. Module melden die Verbindung `letterxpress` in ihrer module.toml an und bekommen die Zugangsdaten wie bei E-Mail. Erfordert einmal den Installer bzw. ein normales Update. Nur mit nachgebautem Aufruf getestet, nicht gegen das echte Letterxpress.

## 0.21.4-beta.17

- Verbindungen: Alle Abschnitte (T2med, E-Mail, SMS, Netzwerkspeicher, Wer nutzt was) lassen sich einklappen. Eingerichtete sind zugeklappt und tragen ein grünes „✓ eingerichtet“; noch nicht eingerichtete oder fehlerhafte bleiben aufgeklappt. Nur mit Testdaten geprüft, nicht im Browser.

## 0.21.4-beta.16

- Einrichtung: Module erscheinen als eine einzige Karte mit Zähler („3 offen“, rot markiert, solange nötige Schritte offen sind); ein Klick führt in die Einstellungen des Moduls, wo die Schritte stehen. Nur mit Testdaten geprüft, nicht im Browser.

## 0.21.4-beta.15

- Updates: Zustand, installierte und neue Version stehen in einer einzigen Karte mit Statussymbol (✓ aktuell, ↑ neue Version, ! Fehler); die Karte „Verfügbar“ entfällt.
- Roter Punkt am K-Logo, solange die Einrichtung nicht abgeschlossen ist.
- Einrichtung: Jeder nötige Punkt lässt sich „Ignorieren“ (und wieder beachten); ignorierte Punkte zählen nicht als offen. Nur mit Testdaten geprüft, nicht im Browser.

## 0.21.4-beta.14

- Verwaltung → Module zeigt pro Modul den Zustand der Dienste („läuft“ / „gestoppt“) mit den Knöpfen „Starten“ bzw. „Neu starten“ und „Status der Dienste aktualisieren“. Der Status wird bei der täglichen Modulprüfung und auf Knopfdruck erneuert. Nur mit nachgebautem systemctl getestet, nicht auf dem echten Server (Ubuntu 26.04).

## 0.21.4-beta.13

- Modul-Dienste, die nach einem unterbrochenen Update gestoppt blieben (z. B. Terminzettel: Seite zeigte „Einen Moment bitte“), werden bei der täglichen Modulprüfung und bei „Neu prüfen“ wieder gestartet, wenn sie eingeschaltet sind und gerade kein Update läuft. Einmalige Dienste (Type=oneshot) bleiben unberührt. Nur mit nachgebautem systemctl getestet.

## 0.21.4-beta.12

- Fehler behoben: Auf der Updates-Seite (bei verfügbarer Version) rutschte der Inhalt unter die Seitenleiste. Ursache: Die Aufteilung in Karten schnitt an jeder Überschrift, auch an der im Update-Kasten, und ließ ein div offen. Karten beginnen jetzt nur noch an Überschriften auf oberster Ebene. Das betrifft ebenso andere Seiten mit Überschriften in Kästen (z. B. Gruppen). Im Test mit Beispiel-HTML geprüft, nicht im Browser.

## 0.21.4-beta.11

- Menü: Ein dezenter blauer Punkt hinter einem Menüeintrag zeigt, dass auf dieser Seite noch ein nötiger Schritt aus der Einrichtung offen ist (optionale Punkte zählen nicht); auch bei Modul-Einstellungen, wenn das Modul offene Schritte meldet.
- Lange Seiten: Selten gebrauchte Abschnitte sind eingeklappt (Überschrift mit Pfeil bleibt sichtbar): Drucker suchen, Drucker von Hand eintragen, Wer nutzt was, Danach (Server), Zeitplan/Benachrichtigung/Aufbewahrung (Sicherung), Kanal und frühere Version (Updates), Protokolle, Dienst hinzufügen, Neue Gruppe. Abschnitte mit Fehlermeldung bleiben offen. Nicht im Browser angesehen.

## 0.21.4-beta.10

- Fehler behoben: Ein Rückschritt des Updaters (automatisch nach fehlgeschlagenem Update oder von Hand) stellte die Datenbank als root wieder her; danach konnte der Dienst sie nicht mehr öffnen („Interner Fehler“, „Die Basis antwortet nicht“). Der Updater merkt sich jetzt beim Schnappschuss Besitzer und Gruppe aller Dateien und stellt sie beim Rückschritt wieder her (Schnappschüsse aus älteren Versionen: Besitzer des Zielordners).
- Gehört die Datenbank nicht dem Dienst, nennt das Systemprotokoll jetzt die Ursache samt Reparaturbefehl statt einer allgemeinen PDO-Meldung.
- Gleichzeitig Web-Update und `install.sh` zu starten bleibt zu vermeiden. Nur im Test mit Probeverzeichnissen geprüft, nicht mit einem echten Rückschritt auf dem Server.

## 0.21.4-beta.9

- Interne Fehler: Das Systemprotokoll (`journalctl -t kienzle-core-php`) enthält jetzt zusätzlich die gekürzte Meldung der Ausnahme sowie Methode und Pfad der Anfrage (ohne Eingaben, Query-Teil abgeschnitten).
- Der Neustart-Dienst (kienzle-neustart.path) steht in der open_basedir-Liste von PHP; sonst hätte die Seite ihn trotz Installation als „nicht eingerichtet“ gemeldet. Nach dem Update einmal `sudo bash installer/install.sh` (schreibt die PHP-Einstellung neu). Nicht auf dem Server getestet.

## 0.21.4-beta.8

- Einrichtung: „Mindestens zwei Admins“, „Netzwerkspeicher eingetragen“ und „Probe-Wiederherstellung erfolgreich“ sind jetzt als optional gekennzeichnet und zählen nicht für den Fortschritt.

## 0.21.4-beta.7

- Zugang (Abmeldezeiten): Zahlenfeld und Einheit stehen jetzt nebeneinander in sinnvoller Breite (vorher war das Zahlenfeld zusammengedrückt); der doppelte Rahmen im aufklappbaren Abschnitt ist weg. Nicht im Browser angesehen.

## 0.21.4-beta.6

- Updates: Während ein Update läuft, steht oben ein gelb hinterlegter, beim Scrollen mitlaufender Hinweis mit drehendem Symbol und dem aktuellen Schritt; die Knöpfe sind gesperrt, bis „fertig“ dasteht. Neu: Hinweis, dass Module beim Update automatisch mit aktualisiert werden, wenn unter Module die automatische Aktualisierung an ist. Nicht im Browser angesehen.

## 0.21.4-beta.5

- Menü links: Symbole vor allen Einträgen; „Start“ ist jetzt das Logo oben; „Neu starten“ (Dienste/Server) steht aufklappbar unten über „Abmelden“. Die Seitenleiste bleibt beim Scrollen stehen und läuft über die volle Höhe (vorher brach die Anzeige auf langen Seiten wie Updates um).
- Einrichtung: nicht mehr im Menü, sondern als Karte mit Fortschritt auf der Startseite (nur Admins). Die Schritte stehen in der nötigen Reihenfolge, jeder mit Ort (Webfenster, Arbeitsplätze, NAS/Router) und Kennzeichnung „optional“; optionale Punkte zählen nicht für den Fortschritt. Als optional gelten: Passkeys, Schnappschüsse am NAS, Signaturprüfung der Updates. Nicht im Browser/auf dem Server angesehen.

## 0.21.4-beta.4

- Neu im Menü links (nur Admins): aufklappbarer Punkt „Neu starten“ mit „Dienste neu starten“ (Webinterface, Module, nginx) und „Server neu starten“. Beides fragt erst auf einer eigenen Seite nach, wird protokolliert und läuft über einen neuen root-Dienst (kienzle-neustart.path); er wird vom Installer eingerichtet, also nach dem Update einmal `sudo bash installer/install.sh` ausführen. Nicht auf dem Server getestet, der Neustart selbst nur im Probelauf.

## 0.21.4-beta.3

- Neue zentrale Verbindung „SMS (seven.io)“ unter Verwaltung → Verbindungen: API-Schlüssel und optionaler Absendername, Schlüssel prüfen, Test-SMS. Der Versand läuft über den root-Dienst für Verbindungen; der Schlüssel wird nie wieder angezeigt. Gedacht auch für die spätere kienzlefon-App.
- Ersatz bei der Admin-Anmeldung: Admins hinterlegen unter „Mein Konto“ ihre Handynummer (nur nach Bestätigung mit Passwort und Code). Auf der Code-Seite gibt es dann „Code per SMS senden“: 6 Ziffern, 10 Minuten gültig, einmal verwendbar, höchstens 3 je Anmeldung, frühestens nach einer Minute erneut; protokolliert. Hinweis: Das schwächt die Zwei-Faktor-Anmeldung auf „Besitz der SIM-Karte“; wer das nicht will, richtet SMS nicht ein.
- Nicht auf dem Server und nicht gegen seven.io getestet (nur mit nachgebauter Schnittstelle).

## 0.21.4-beta.2

- Neue Seite „Praxis und Daten“ (Verwaltung → Praxis): Anschrift, Kontodaten für Überweisungen (Kontoinhaber, IBAN mit Prüfziffer, BIC, Bank) und Steuerangaben (Steuernummer/USt-IdNr., Kleinunternehmerregelung) an einer Stelle; die Module übernehmen sie. Die Anschrift ist von „Server und Name“ hierher gezogen. Nicht auf dem Server getestet.

## 0.21.4-beta.1

- Verwaltung entschlackt: „Sicherheit“ fasst Firewall, Zugang (Abmeldezeiten) und Protokoll in einer Karte mit aufklappbaren Abschnitten zusammen; „Benutzer und Gruppen“ stehen auf einer Seite. Die alten Adressen (/verwaltung/firewall, /zugang, /protokoll, /gruppen) funktionieren weiter.
- Zugang: Zeiten mit Einheit (Minuten, Stunden, Tage), bis höchstens 1 Jahr (vorher 183 Tage).
- Hinweis (Tooltip und Text) zum T2med-Demo-Schlüssel: Dabei darf kein eigener API-Schlüssel eingetragen sein.
- Noch offen: Stammdaten (Kontodaten, Steuernummer, USt) unter „Praxis und Daten“ zusammenführen. Nicht auf dem Server getestet.

## 0.21.3-beta.6

- Durchreich-PPD der virtuellen Drucker enthält nur noch die drei Filterzeilen des früheren Rechnungseingangs (PDF, PostScript, CUPS-PostScript). Die zusätzlichen Zeilen über „application/vnd.cups-pdf“ ließen CUPS weiter JPEG, PNG, TIFF, PWG-Raster und URF anbieten; Windows schickte JPEG-Seiten (Bild-PDF ohne Textebene, Texterkennung nötig). Die Warteschlangen werden beim nächsten Abgleich neu angelegt. Folge: `lp` einer reinen Textdatei an einen virtuellen Drucker funktioniert nicht mehr (Windows druckt PDF). Nicht mit Windows getestet.

## 0.21.3-beta.5

- Windows-Skript: Anschluss und Drucker werden bis zu viermal angelegt (Pause nach dem Spooler-Neustart, sonst Fehler 0x800703eb), am Ende zeigt es den angelegten Drucker. Nicht getestet (auf dem Windows-PC des Anwenders klappte der Befehl `Add-Printer` beim zweiten Versuch).

## 0.21.3-beta.4

- AGENTS.md: Hinweise für neue Sitzungen (Stand, Arbeitsweise, offene Punkte). Nur Dokumentation.

## 0.21.3-beta.3

- README: aktueller Stand und Abschnitt „So sieht es aus“ mit Bildschirmfotos (Anmeldung, Benutzer, Gruppen, Sicherung auf das NAS per SFTP, Updater; nur erfundene Beispieldaten). Nur Dokumentation, kein Programmcode geändert.

## 0.21.3-beta.2

- Virtuelle Drucker bieten nur noch das Papierformat des Moduls an (neu in module.toml: `paper`, Vorgabe A4; Kasse A4, Terminzettel A6) statt A6/A5/A4/Letter. Nicht mit Windows getestet.

## 0.21.3-beta.1

- Virtuelle Drucker der Module (Kasse „SumUp-Rechnung“, „Terminzettel“): Die CUPS-Warteschlange wird nicht mehr mit „everywhere“, sondern mit einer mitgelieferten Durchreich-PPD angelegt (PDF und PostScript unverändert, wie im früheren Rechnungseingang). Vorher bot CUPS zusätzlich Raster- und Bildformate (pwg-raster, urf, pclm, jpeg, …) an; der Windows-IPP-Treiber wählte so ein Format, das PDF hatte keine Textebene und die Rechnung musste per Texterkennung gelesen werden (Fehler „Rechnungsnummer nicht eindeutig (Texterkennung)“). Bestehende Warteschlangen werden beim nächsten Abgleich (Installer bzw. „Virtuelle Drucker der Module abgleichen“) automatisch umgestellt. Echte Drucker bleiben unverändert.
- Tests: tests/core/test_drucker.py. Nicht mit Windows/echtem T2med getestet.

## 0.21.2-beta.3

- Windows-Skript: „Set-PrintConfiguration -PaperSize“ entfernt (der IPP-Class-Treiber lehnt es ab; die Papiergröße kommt vom Drucker). Nicht getestet.

## 0.21.2-beta.2

- Windows-Skript korrigiert: Der Druckspooler wird nach dem Aktivieren der Windows-Funktion neu gestartet und es wird auf den laufenden Dienst gewartet (vorher „Spoolerdienst ist nicht erreichbar“). Nicht getestet.

## 0.21.2-beta.1

- Windows-Anleitung robuster: Das PowerShell-Skript startet den Druckspooler neu und entfernt zuerst den alten Drucker (behebt „Das Handle ist ungültig“ in den Druckereigenschaften); neuer Hinweis zur Fehlerbehebung. Nicht getestet (vom Anwender am Windows-PC bestätigt: Neueinrichtung per IPP-Adresse mit IPP-Class-Treiber funktioniert).

## 0.21.1-beta.1

- Verwaltung → Drucker: Die Anleitung für die virtuellen Drucker folgt dem Format der Kasse-Anleitung (SumUp-Rechnung): je Drucker Windows-Adresse mit „Microsoft IPP Class Driver“ (auch für den Terminzettel statt „Generic / Text Only“), Adresse per IP, PowerShell-Befehle, Mac mit PPD-Download. Nicht getestet (Tests auf Wunsch nicht ausgeführt).

## 0.21.0-beta.1

- Verwaltung → Drucker → „Wofür welcher Drucker?“: neu die **Papierbreite des Bondruckers** (58 mm Epson TM-m10 oder 80 mm TM-m30III). Sie gilt zentral für alle Module; die Module bekommen sie mit jeder Anfrage (`KienzleModul::bonBreite()`, ergänzende Schnittstelle, `core_api` bleibt 1).
- Nicht auf der echten VM getestet.

## 0.20.9-beta.1

- Verwaltung → Drucker: Freigaben (Samba/IPP) gibt es nur noch für die virtuellen Drucker der Module; echte Drucker und der Bondrucker brauchen keine (Auswahl beim Anlegen entfällt, Hinweis im Text).
- Neu: aufklappbare Anleitung „virtuelle Drucker an den Arbeitsplätzen einrichten“ unter den virtuellen Druckern (Windows per IPP mit Adresse und Treiber je Drucker, PowerShell-Befehle, Mac, T2med-Einstellung). Die Adresse enthält den Servernamen, unter dem die Seite aufgerufen wird.
- Nicht auf der echten VM getestet.

## 0.20.8-beta.1

- T2med: Das Feld heißt „API-Schlüssel der FHIR-Schnittstelle“ (nicht mehr „Client Secret“); der Hinweis erklärt, dass T2med bei einem unpassenden Wert „FHIR-API-Key fehlt oder ist ungültig“ (HTTP 403) meldet. Neu: „Gespeicherten Schlüssel entfernen“, dann nimmt die Kasse wie das alte Tool den hinterlegten T2med-Demo-Schlüssel. Vermutung, nicht an echtem T2med bestätigt: Der Wert, der im alten Tool funktionierte, war der Demo-Schlüssel, nicht das Client Secret des Drittanbieters.

## 0.20.7-beta.1

- T2med: Leerzeichen und Tabulatoren am Rand des API-Schlüssels (Client Secret) werden beim Speichern entfernt; beim Kopieren aus T2med werden sie leicht mitgenommen und führen zu „FHIR-API-Key fehlt oder ist ungültig“.

## 0.20.6-beta.1

- Verwaltung → Module: Bei jedem installierten Modul ohne bekanntes Update gibt es den Knopf „Jetzt nach einem Update suchen“. Gibt es eine neue Version, erscheint danach „Aktualisieren“ (mit Schnappschuss der Moduldaten vorher, bei Fehlern springt das Modul zurück). An der Konsole: `sudo kienzle-module installieren <modul>` aktualisiert ein installiertes Modul, `sudo kienzle-module pruefen --sofort` sucht sofort nach Angeboten.

## 0.20.5-beta.1

- Behoben: Im Einrichtungs-Überblick stand bei Modulen „Überblick nicht lesbar: [Errno 2] No such file or directory: 'runuser'“, weil der Dienst `runuser` (liegt in /usr/sbin) nicht im Suchpfad fand. Überblick, Update-Haken und Sicherungs-Haken der Module verwenden jetzt den vollen Pfad. Nicht auf der VM geprüft.

## 0.20.4-beta.1

- Verbindungen → T2med: Die Adresse für den Eintrag in T2med (`kienzle-sumup://?kontextId=…`) steht jetzt direkt unter dem Formular. Das Feld heißt „Erlaubte fhirBasisUrl aus T2med – weitere Adressen“ und erklärt, dass hier die von T2med mitgeschickte FHIR-Adresse gemeint ist, nicht die Abrufadresse.

## 0.20.3-beta.1

- Einrichtung: „Servername festgelegt“ gilt erst als erledigt, wenn die Schritte danach von Hand bestätigt sind: Namen im Router bzw. in der hosts-Datei eintragen, Suite unter dem neuen Namen öffnen und neu anmelden, Passkeys neu anlegen (oder keine vorhanden). Die zwei neuen Punkte stehen im Einrichtungs-Überblick neben dem bestehenden Punkt zum Servernamen.

## 0.20.2-beta.1

- T2med: Das Feld heißt „API-Schlüssel (Client Secret aus T2med)“ mit Hinweis, dass T2med es nur einmal anzeigt (verloren = Drittanbieter-Zugriff in T2med löschen und neu erstellen).
- Verbindungsprüfung: Antwortet T2med mit 401/403, meldet die Prüfung jetzt „erreichbar, Zertifikat stimmt, Secret nicht allein prüfbar“ statt „Zugriff abgelehnt“. Vermutung, nicht an echtem T2med bestätigt: T2med verlangt für die FHIR-Abfragen zusätzlich das OAuth-Token, das es nur beim Aufruf aus der Patientenakte mitgibt.

## 0.20.1-beta.1

- Verbindungen → Netzwerkspeicher: Das Formular „Ziel anlegen“ erklärt jede Angabe per Tooltip (Kennung, Bezeichnung, Server, Freigabe, Port, Basisordner, Benutzer, Passwort, Domäne), zeigt je nach Art nur die passenden Felder (SMB oder SFTP), empfiehlt SFTP und beschreibt kurz die Einrichtung an einem Synology-NAS (Menünamen nicht gegen ein echtes Gerät geprüft).
- SFTP: „Verbindung prüfen“ ohne bestätigten Serverschlüssel ruft den Schlüssel jetzt gleich ab, sodass die Schaltfläche „Übernehmen“ erscheint; vorher stand nur „noch nicht bestätigt“ ohne Weg weiter.

## 0.20.0-beta.1

- Basis-Admins dürfen in jedem installierten Modul alles: Sie gelten dort als Modul-Admin mit allen Rechten, ohne dass Haken gesetzt sein müssen (Menü, Einstellungen und Zugriff der Module; auch die Haken in der Benutzerverwaltung erscheinen gesetzt).
- Neu: Verwaltung → Gruppen. Vorgaben „Admin“ (Admin in allen Modulen, alle Rechte), „Arzt“ (alle Rechte) und „MFA“ (Kasse im Alltag: kassieren, bezahlt abhaken, Rechnungseingang). Gruppen sind änderbar, zurücksetzbar und um eigene ergänzbar. Beim Bearbeiten eines Benutzers übernimmt „Gruppe anwenden“ beim Speichern die Rechte der Gruppe; danach lassen sich die Haken einzeln ändern. Spätere Änderungen an einer Gruppe ändern bestehende Benutzer nicht. Ungeänderte Vorgaben schließen neue Module von selbst ein; die Gruppe „Admin“ macht in der Benutzerverwaltung zum Modul-Admin, was die Authenticator-App voraussetzt.
- Nicht in einem echten Browser angesehen.

## 0.19.9-beta.1

- Installer: `libnss-mdns` wird mitinstalliert (trägt mDNS in die Namensauflösung ein) und der Avahi-Dienst eingeschaltet. Damit löst der Server Namen wie `Drucker.local` selbst auf; bisher scheiterte das Einrichten und Drucken mit „Temporary failure in name resolution“. Nach dem Update den Installer erneut ausführen. Nicht auf echter Hardware geprüft: Funktioniert mDNS im Praxisnetz nicht (z. B. gesperrt im Router), bleibt nur die IP-Adresse des Druckers.

## 0.19.8-beta.1

- Drucker: Bereits angelegte Drucker der Suite, deren Adresse einen „.local“-Namen enthält, den der Server nicht auflösen kann, werden beim nächsten Lauf des Druckdienstes (z. B. beim Öffnen der Druckerseite oder bei der nächsten Anfrage) automatisch auf die IP-Adresse umgestellt. Das behebt den Fehler beim Testdruck „Der Name des Druckers lässt sich nicht auflösen“. Nicht an echter Hardware geprüft; die Auflösung braucht `avahi-utils` (Installer erneut ausführen).

## 0.19.7-beta.1

- Anmeldeseite: Hinweis mit Link „Arbeitsplatz einrichten“ (Zertifikat und Servername je PC), für den Fall, dass der Browser „Nicht sicher“ meldet oder die Adresse nicht öffnet.

## 0.19.6-beta.1

- T2med-Prüfung: Statt „antwortet nicht wie ein FHIR-Server“ nennt die Meldung jetzt den HTTP-Status und die wahrscheinliche Ursache (Schlüssel abgelehnt, Pfad falsch, Adresse zeigt nicht auf die FHIR-Schnittstelle). Nicht an einer echten T2med-Installation geprüft.

## 0.19.5-beta.1

- Updates: Beim Anhalten und Neustarten der Basis zeigte nginx kurz „502 Bad Gateway“. Stattdessen erscheint jetzt eine Seite „Einen Moment bitte“, die sich alle 3 Sekunden selbst neu lädt, bis die Suite wieder antwortet (gilt auch für die Modulpfade). Nicht auf einer echten VM geprüft; nginx-Syntax läuft erst im CI-Integrationstest gegen echtes nginx.

## 0.19.4-beta.1

- Drucker: Die Seite trennt jetzt „Drucker“ (echte Drucker und Bondrucker) von „Virtuelle Drucker“ (von Modulen angelegt, mit dem Knopf zum Abgleichen).
- Drucker einrichten: Namen wie `Drucker.local` (mDNS) löst der Server nun selbst in eine IP-Adresse auf (avahi-utils wird vom Installer mitinstalliert); gelingt das nicht, nennt die Meldung klar den Weg über die IP-Adresse statt „Temporary failure in name resolution“. Nicht an echten Druckern geprüft.

## 0.19.3-beta.1

- Verbindungen → T2med: Selbstsignierte Zertifikate lassen sich jetzt im Webinterface übernehmen. „Zertifikat des Servers abrufen“ holt das Zertifikat von T2med und zeigt den SHA-256-Fingerabdruck; nach dem Vergleich (z. B. im Browser unter „Zertifikat anzeigen“) hinterlegt „Übernehmen“ es fest (Pinning). Die Verbindung prüft dann nur noch diesen Fingerabdruck, auch wenn das Zertifikat selbstsigniert ist oder nicht auf den Servernamen lautet. Bei einem Zertifikatsfehler weist die Meldung auf diesen Weg hin.
- Nicht an einer echten T2med-Installation geprüft, nur gegen einen lokalen Testserver mit eigenem Zertifikat.

## 0.19.2-beta.1

- **Beta-Kanal automatisch:** Der Zweig `beta` veröffentlicht Vorabversionen selbst (`.github/workflows/beta.yml`), `main` nur noch mit Tag.
  Die Basis prüft stündlich nach Updates (bisher täglich); Module werden stündlich geprüft und nachts (02–05 Uhr) selbst installiert,
  nach einem Basis-Update sofort.
- Nur die Abläufe in den Workflows sind nicht auf GitHub erprobt, bis der erste Lauf auf `beta` grün war.

## 0.19.1

- `web.einstellungen = "/"` ist erlaubt (Einstellungsseite auf der Startseite des Moduls). Das Modul Terminzettel 1.0.0 liegt mit im Release.

## 0.19.0

- **Drucker zentral zuordnen:** Verwaltung → Drucker hat den Abschnitt „Wofür welcher Drucker?“ (Praxisdrucker A4, Bondrucker).
  Module bekommen die Zuordnung mit jeder Anfrage (`KienzleModul::druckerRollen()`, `X-Kienzle-Drucker`) und fragen nicht mehr selbst.
- **Einstellungen in der Seitenleiste:** Module können mit `web.einstellungen` (module.toml) eine Einstellungsseite angeben;
  Modul-Admins sehen sie unter „Einstellungen“ in der Seitenleiste.
- Wer ein Modul über die Weboberfläche installiert, wird beim nächsten Aufruf dessen Admin (einmalig).
- Nur mit nachgestelltem nginx/PHP geprüft, nicht auf einer echten VM.

## 0.18.0

- **Module aktualisieren sich selbst:** Die tägliche Prüfung (04:20, `kienzle-module pruefen`) installiert neuere Modulversionen
  von GitHub und aus der Basis automatisch. Ein Modul, das gerade beschäftigt ist (z. B. laufende Zahlung), wird übersprungen
  und am nächsten Tag erneut versucht; scheitert ein Update, springt die Basis wie bisher zurück. Das Ergebnis steht unter
  Verwaltung → Module. Abschaltbar ebendort („Automatische Updates ausschalten“).
- Nur mit nachgestellten Modulen geprüft, nicht auf einer echten VM.

## 0.17.0

- **Praxisdaten zentral:** Unter Verwaltung → Server und Name stehen jetzt neben dem Praxisnamen auch Anschrift und Kontakt
  (für Rechnungen und Belege). Die Module bekommen sie mit jeder Anfrage (`KienzleModul::praxis()`, Kopfzeile `X-Kienzle-Praxis`).
- **Seitenleiste:** Jedes installierte Modul, für das der Benutzer Zugang hat, erscheint unter „Anwendungen“ (z. B. „Kasse“).
- Nur mit nachgestelltem nginx/PHP geprüft, nicht auf einer echten VM.

## 0.16.1

- **Drucker anlegen:** Die Meldung „Der Druckdienst (CUPS) läuft nicht“ kam auch, wenn der *Drucker* die automatische
  Einrichtung ablehnte („Unable to query printer: Connection refused“). Jetzt steht dort, dass IPP Everywhere/AirPrint am
  Drucker fehlt. Vorher startet die Basis CUPS, falls er im Leerlauf beendet war. Für A4-Drucker probiert sie danach
  `ipps://…/ipp/print` und `ipp://…/ipp` und zuletzt, wenn Port 9100 offen ist, einen allgemeinen PCL- bzw. PostScript-Treiber.
- Nicht mit echten Druckern geprüft (Brother, HP …); nur mit nachgestelltem CUPS.

## 0.16.0

- **Module über das Webinterface installieren** (Verwaltung → Module): installieren, aktualisieren und entfernen, ohne Konsole.
  Quellen: mit der Basis mitgelieferte Module (im Release unter `modules/`, ohne Beispielmodul) und GitHub-Releases
  `<modul>-v<version>` (Prüfung wie bei der Basis: Manifest, SHA-256 jeder Datei, Signatur je nach Einstellung).
  Neuer root-Dienst `kienzle-module` (Anfragedatei, `.path`-Einheit, täglicher Timer für das Angebot):
  Pakete aus `[system] packages`, Systembenutzer `ks-<id>`, Datenordner 0700, Socket-Ordner, Versionsordner
  `/opt/kienzle/<id>/releases/<version>/<id>` mit Verweis `current`, eigene php-fpm-Instanz je FastCGI-Modul
  (`kienzle-<id>-php.service`, gehärtet, darf ins Netz, schreibt nur in eigene Ordner), Haken als `ks-<id>`,
  Gesundheitsprüfung. Beim Aktualisieren: Sperre bei „beschäftigt“, Daten-Schnappschuss, Rücksprung bei Fehler.
  Entfernen behält die Daten, außer der Admin wählt „Auch alle Daten löschen“.
  An der Konsole: `sudo kienzle-module liste|installieren <id>|entfernen <id>`.
- `kienzle-web module`: Modulverzeichnis aus den installierten Modulen; der Installer nutzt es bei jedem Lauf.
- **Arbeitsplatz-Starter** (`[web] starter = true`, höchstens ein Modul): `POST https://<server>/api.php` geht ohne
  Anmeldung an dieses Modul (nur POST, 64 KiB); der Starter weist sich mit seinem Schlüssel aus. Den einmaligen
  Startlink `https://<server>/#launch=…` gibt die Basis nach der Anmeldung an das Modul weiter (core.js).
- Release-Workflow und `scripts/release-bauen` bauen auch Modul-Releases (Tag `<modul>-v<version>`).
- Mitgeliefert: Modul **Kasse** (`sumup` 2.0.1, siehe `modules/sumup/RELEASE.md`).

Nicht auf echtem Ubuntu 26.04 geprüft: Installation eines Moduls über die Seite (apt, useradd, systemd, php-fpm
des Moduls, AppArmor), Starter 1.4.1 gegen `/api.php`, Weitergabe des Startlinks nach der Anmeldung im Browser.

## 0.15.0

- **Signaturprüfung der Updates vorerst optional** (Entscheidung vom 22.11.2026): Vorgabe ist „ohne Signaturprüfung“;
  „nur im Beta-Kanal ohne Signatur“ und „immer prüfen“ bleiben wählbar. Manifest und SHA-256 jeder Datei werden weiter
  geprüft, eine vorhandene, aber falsche Signatur wird weiter abgelehnt. Im Überblick ist die Signaturprüfung ein
  optionaler Hinweis statt eines offenen Punkts. Wer „immer prüfen“ gewählt hat, behält das.

## 0.14.1

- **Releases über GitHub Actions:** Ein Tag `core-v<version>` prüft die Version, führt alle Tests aus, baut Archiv und
  Manifest und veröffentlicht das Release. Signieren bleibt offline beim Herausgeber; die `.sig` wird danach hochgeladen
  (`docs/RELEASE-ABLAUF.md`). Bis dahin nur mit abgeschalteter Signaturprüfung installierbar.
- Erstes Release für das Update-Panel; davor gab es nur Commits, deshalb fand die Test-VM keine neue Version.

## 0.14.0

Neue Gestaltung nach Entwurf B (Rückmeldung von der Test-VM): Farben, Schrift, Karten und Einrichtungsliste wie im
Sumup-Zweig `rechnungseingang`.

- **Seitenleiste** mit gegliedertem Menü: Einrichten (Überblick, Server und Name, Arbeitsplätze), Praxis (Benutzer,
  Drucker, Verbindungen, Dienste), Betrieb (Sicherung, Updates, Firewall, Zugang, Protokoll); Zähler für offene Punkte,
  Sicherungshinweise und neue Versionen. Auf dem Telefon wird sie zur Leiste oben.
- **Karten:** Jeder Abschnitt einer Seite steht in einer Karte; Knöpfe stehen links in Reihen statt verstreut.
- **Überblick:** Fortschritt („x von y Schritten“), Punkte nach Ort gruppiert (In diesem Webfenster · Auf den
  Arbeitsplätzen · Am NAS und im Router) mit ✓/○-Zeichen; Module darunter je in einer Karte.
- **Server und Name einfacher:** Praxisname, ein Feld „Name des Servers“ (Vorgabe „kienzle“) und eine Endung zur Auswahl
  (.praxis.internal, .fritz.box, .local, .lan); die IP-Adresse bleibt automatisch erhalten. Weitere Namen und die neue
  Zertifizierungsstelle stehen aufgeklappt unter „Fachleute“. Danach-Schritte mit Anleitung.
- **Neue Seite „Arbeitsplätze einrichten“** (`/hilfe/arbeitsplatz`, auch ohne Anmeldung): Zertifikat herunterladen,
  Fingerabdruck, Anleitung für Windows (auch PowerShell), Mac, iPhone/iPad, Android, Firefox; Servername über den Router
  oder die hosts-Datei. Neuer Punkt im Überblick „Servername auf den Arbeitsplätzen bekannt“.

## 0.13.0

- **Uhrzeit des Servers** (Wunsch nach dem Test auf der VM): Der Installer schaltet die Zeitsynchronisation ein, stellt
  die Zeitzone auf Europe/Berlin (wenn noch UTC) und meldet, ob die Uhr synchronisiert ist.
- **Falsche Server-Uhr erkennen und stellen:** Passt ein Code der Authenticator-App (Einrichtung oder Anmeldung) zu einer
  anderen Uhrzeit innerhalb von 24 Stunden, nennt die Suite die Abweichung und bietet „Uhrzeit des Servers korrigieren“
  an. Die Abweichung kommt nur aus dem Code (nicht aus einer Eingabe), gilt einmal und 5 Minuten; gestellt wird über den
  root-Dienst `kienzle-servername` (`timedatectl`), protokolliert.
- Nach einem falschen Code bei der Einrichtung bleibt der Schlüssel derselbe – nicht neu scannen.
- php-fpm mit Zeitzone Europe/Berlin (Uhrzeiten auf den Seiten).

## 0.12.3

- **Formulare im echten Browser abgelehnt** („Anfrage von einer fremden Seite“, gefunden auf der Test-VM): Mit
  `Referrer-Policy: no-referrer` schicken Browser bei Formularen `Origin: null`. Jetzt `same-origin` (keine Adressen an
  fremde Seiten, eigene Herkunft bleibt), und `Origin: null` gilt nur zusammen mit `Sec-Fetch-Site: same-origin`.
  Die Tests liefen bisher nur mit gesetzter Herkunft; jetzt auch mit `null`.

## 0.12.2

- **Aufruf über die IP-Adresse ging nicht** (gefunden bei der ersten Installation auf der Test-VM): Browser schicken bei
  IP-Adressen kein SNI, und `ssl_reject_handshake` im Standard-Server brach die Verbindung ab. Der Standard-Server
  antwortet jetzt mit 421 auf unbekannte Namen; nginx wählt den Server über die Kopfzeile Host.
- Prüfung nach Installation und Update mit `curl --connect-to` statt `--resolve` (ging bei einer IP als erstem Namen nicht
  über 127.0.0.1, `/_health` lehnte dann ab).
- Ende-zu-Ende-Prüfung: Aufruf über eine IP ohne SNI, fremder Name mit 421.

## 0.12.1

- **Zertifizierungsstelle erlaubt interne Namensräume:** Neben den Namen bei der Installation auch `*.internal`, `*.local`,
  `*.lan`, `*.home.arpa`, `*.intranet`, `*.localdomain`, `*.fritz.box`, `*.corp`, `*.home`, `*.praxis` (und private IPs).
  So lässt sich der endgültige Name nach der einfachen Installation unter Verwaltung → Server festlegen, ohne die
  Zertifizierungsstelle zu tauschen. Gefunden von der Ende-zu-Ende-Prüfung (die Namensbeschränkung lehnte neue Namen ab).
- Namen außerhalb davon werden abgelehnt, außer ein Admin kreuzt „neue Zertifizierungsstelle“ an
  (`kienzle-zertifikat neue-ca`, alte bleibt als Kopie; alle Arbeitsplätze müssen sie neu einspielen).
- Gilt für neu angelegte Zertifizierungsstellen. Bestehende Test-VMs: bei Bedarf neu installieren.

## 0.12.0

- **Einfache Erstinstallation:** `sudo bash installer/install.sh` ohne Angaben. Der Installer nimmt die IPv4-Adressen und
  den Rechnernamen der VM (IP zuerst, damit der Einrichtungslink sofort funktioniert).
- **Verwaltung → Server:** Servernamen und Praxisname im Webinterface festlegen. Der root-Dienst `kienzle-servername`
  schreibt `core.json`, stellt das Server-Zertifikat für die neuen Namen aus (Zertifizierungsstelle bleibt) und schreibt
  nginx neu; lehnt nginx ab, kommt der vorige Stand zurück. An der Konsole: `sudo kienzle-servername setzen NAME …`.
  Hinweise zu Passkeys (an den ersten Namen gebunden) und zur Adresse der Anmeldung für Dienste. Punkt „Servername
  festgelegt“ im Einrichtungs-Überblick.
- `kienzle-web neu`: nginx neu schreiben und das Modulverzeichnis behalten. Der Installer nutzt das; ein zweiter Lauf
  leert das Modulverzeichnis nicht mehr.
- Virtuelle IPP-Drucker: `avahi-daemon` wird mitinstalliert (`ippeveprinter` braucht DNS-SD), Start ohne laufendes
  systemd erkannt wie `sd_booted`.

Nicht geprüft: Namensänderung auf einer echten VM mit systemd; automatische Namen bei mehreren Netzwerkkarten.

## 0.11.2

- **Release-Schlüssel des Herausgebers eingetragen** (`installer/release-keys/`): Hauptschlüssel `kienzle-release-2026`
  (`06dcb35f70c31d6f…`) und Reserve `kienzle-reserve-2026` (`ddacb5541655b3aa…`); volle Fingerabdrücke in `README.md`. Ab dieser Version
  nehmen neu installierte Server signierte Releases an.

## 0.11.1

- **Domänenbeitritt (Active Directory) wieder entfernt** (Entscheidung vom 22.11.2026). Unter Verwaltung → Drucker →
  Windows-Netz bleibt die wählbare Arbeitsgruppe; Paket `winbind` wird nicht mehr installiert.
- Privater SSH-Schlüssel auf dem Schlüsselblatt bestätigt (Frage 22).

## 0.11.0

Meilenstein 9 (`docs/PLAN.md`): Basis als OpenID-Connect-Anbieter für Dienste mit eigener Anmeldung.

- **Verwaltung → Dienste:** Dienst eintragen (Name, Weiterleitungsadressen, Adressen nach dem Abmelden, wer darf sich
  anmelden: alle, nur Admins oder nur mit Zugang zu einem Modul; vertraulich mit Geheimnis oder öffentlich mit PKCE).
  Client-ID und Geheimnis werden einmal angezeigt; Geheimnis erneuern, Dienst entfernen; alles protokolliert.
- **Ablauf:** Authorization Code mit PKCE (S256), Anmeldung mit Passwort+OTP oder Passkey wie bei den Modulen, Rückkehr
  zum Dienst nach der Anmeldung; ID-Token ES256 (Signaturschlüssel P-256, mit dem Schlüssel der Basis verschlüsselt in
  der Datenbank), Userinfo mit Zugangsmarke (1 Stunde), Abmelden mit Rücksprung nur an eingetragene Adressen.
- Gespeichert werden nur SHA-256 von Codes, Zugangsmarken und Geheimnissen; Codes einmalig und 60 Sekunden gültig, auch bei
  Fehlversuchen verbraucht. Gesperrte Benutzer verlieren sofort den Zugang (auch über Userinfo).
- Datenbank Version 2 (neue Tabellen `oidc_*`, Übernahme automatisch). Pfade `/oidc`, `/bestaetigen`, `/ca.crt` für die Basis reserviert.
- Tests: Ablauf über den nachgebauten Browser mit Signaturprüfung, Fehlerfälle (fremde Weiterleitung, Implicit Flow,
  PKCE plain/falsch, Code doppelt/abgelaufen, falsches Geheimnis), Zugang je Dienst, Abmelden, Verwaltung; Ende-zu-Ende
  Discovery, JWKS und Token über nginx.

Nicht geprüft: Anmeldung echter Dienste (z. B. Telefonanlage, Synology DSM), die dem Zertifikat der Suite vertrauen müssen.

## 0.10.0

Umsetzung der Antworten vom 22.11.2026, Teil 2: Drucker.

- **Druckaufträge** auf der Seite Drucker: offene Aufträge aller Warteschlangen (Nummer, Drucker, Benutzer, Größe, Zeit; ohne
  Titel), einzeln oder je Drucker abbrechen (protokolliert).
- **Windows-Netz wählbar:** Arbeitsgruppe frei, oder **Domänenbeitritt** (Active Directory, `net ads join` mit einem
  Domänen-Admin; das Passwort geht nur über die Umgebung an `net` und wird nicht gespeichert), Verlassen der Domäne.
  `smb.conf` bindet dafür `/etc/samba/kienzle-netz.conf` ein; `winbind` läuft nur im Domänenbetrieb.
- **Virtuelle Drucker als IPP Everywhere** (`[[printers]] command`, empfohlen): Die Basis startet je Drucker
  `ippeveprinter` als systemd-Dienst **als Modul-Benutzer** und nur auf localhost (Ports 47100–47199); CUPS-Warteschlange
  „everywhere“ davor, Freigabe wie bisher. Das Programm des Moduls bekommt jeden Auftrag als Datei. Keine PPD, kein
  Backend mit Rechten des Benutzers `lp`.
- **Virtuelle Drucker ohne Backend** (`ppd` ohne `backend`): Warteschlange mit dem Backend `kienzle-leer` der Basis
  (die Filter des Moduls erledigen die Arbeit, die Ausgabe wird verworfen).
- `module.toml`: `command`, `formats`; `ppd` nur noch für die ältere Art. Beispielmodul 0.2.0 mit einem IPP-Everywhere-Drucker.
- Installer: Pakete `cups-ipp-utils`, `winbind`; Backend `kienzle-leer`.

Nicht geprüft: Domänenbeitritt an einem echten Active Directory, Drucken von Windows-Domänenrechnern, ippeveprinter unter
systemd (im Container direkt gestartet).

## 0.9.0

Umsetzung der Antworten vom 22.11.2026 (`docs/OFFENE-FRAGEN.md`), Teil 1: Sicherung, E-Mail, Export.

- **Testnachricht** (Verwaltung → Verbindungen → E-Mail): echte Nachricht an eine eingegebene Adresse.
- **E-Mail bei Fehlern der Sicherung:** bis zu 5 Empfänger; verschickt über die zentrale E-Mail-Verbindung, wenn
  Sicherung, Prüfung oder Probe-Wiederherstellung fehlschlagen. Ergebnis des Versands auf der Seite Sicherung.
- **Zeitplan der Sicherung wählbar:** täglich, wöchentlich (Wochentag) oder monatlich (Tag 1–28), mit Uhrzeit
  (systemd-Timer mit Ergänzungsdatei). Aufräumen und Prüfen höchstens wöchentlich, Lesen und Probe-Wiederherstellung
  höchstens monatlich, jeweils nach Alter statt nach Wochentag. Die Warnung „zu alt“ richtet sich nach dem Zeitplan.
- **Schlüsselblatt für den Admin** (bisher Wiederherstellungsblatt): zusätzlich der private SSH-Schlüssel für das NAS
  (Text und QR-Code), dessen öffentlicher Teil, Fingerabdruck des NAS und der Zertifizierungsstelle. Jederzeit neu
  erzeugbar; nach „gedruckt“ wird es gelöscht. `install.sh --wiederherstellen` fragt nach dem SSH-Schlüssel vom Blatt
  (Datei), dann muss am NAS nichts geändert werden.
- **Export der Einstellungen mit Benutzerkonten** (Vorgabe an): stimmiger Stand der Datenbank der Basis (Benutzer, Rechte,
  Authenticator-Apps, Passkeys); beim Einspielen geprüft (`PRAGMA integrity_check`) und eingesetzt.
- Google-Sprachausgabe nur für Ansagen festgehalten (`docs/ARCHITEKTUR.md`).

Nicht geprüft: Versand über einen echten SMTP-Server, geänderter Timer unter systemd, QR-Code des SSH-Schlüssels mit einem
echten Lesegerät, Wiederherstellung mit dem Schlüssel vom Blatt auf einer frischen VM.

## 0.8.1

- `cupsd.conf`: Die Vorlage aus 0.7.0 beschränkte alle IPP-Abfragen auf @SYSTEM. Damit sahen Module (und Arbeitsplätze)
  keine Drucker und konnten nicht drucken. Jetzt wie in der Vorgabe von CUPS: Einrichten und Steuern der Drucker nur
  @SYSTEM und nur am Server, Abfragen und Drucken für alle. Gefunden von der Ende-zu-Ende-Prüfung mit echtem cupsd.
- `installer/autoinstall.yaml`: normales Ubuntu Server statt „minimized“ (Werkzeuge für die Fehlersuche bleiben).

## 0.8.0

Meilenstein 8 aus `docs/PLAN.md`: Druckerverwaltung in der Basis. Damit ist Schritt 1 (Basis) vollständig.

- **Verwaltung → Drucker:** Netzwerk und USB durchsuchen (`lpinfo`), Bondrucker (TM-m10, TM-m30III, allgemein ESC/POS) als RAW
  über `socket://…:9100`, A4-Drucker über IPP Everywhere, von Hand oder aus der Suche; Testseite (Bon bzw. PDF);
  Freigabe je Drucker für Windows (Samba) und/oder macOS (IPP); Entfernen nur von Druckern der Suite.
- **root-Dienst `kienzle-drucker`** (`.path`, Timer alle 5 Minuten für die Liste): einziger Aufrufer von `lpadmin`; weder
  ks-core noch ein Modul ist in der Gruppe `lpadmin`.
- **Virtuelle Drucker der Module** aus `[[printers]]`: Backend (root, 0755) und PPD (0644) aus dem Programmordner,
  Warteschlange mit Kennung der Suite, Freigaben laut `share`; `kienzle-web schreiben` gleicht nach jeder Änderung der
  Module ab und entfernt Warteschlangen, Backends und PPDs entfernter Module. Symbolische Verweise werden abgelehnt.
- **Samba gibt nur markierte Drucker frei:** `smb.conf` lädt keine Drucker mehr selbst (`load printers = no`), sondern
  bindet die erzeugte `/etc/samba/kienzle-drucker.conf` ein.
- **Für Module:** `KienzleModul::drucker()` (Auswahlliste) und `drucken()` (lp als Modul-Benutzer, geprüfte Optionen,
  Auftragsnummer) in `core/php/modul/Kienzle.php`; `docs/MODUL-SCHNITTSTELLE.md`, Abschnitt Drucken.
- Übernommen aus der Beta: `src/PrinterAdmin.php` (Druckerarten, Suche, URI-Prüfung, nur eigene entfernen, Fehlertexte),
  `src/Cups.php`/`src/OfficePrinter.php` (lp mit Auswertung der Auftragsnummer), `tests/printer-admin.php` als Muster.
- Tests: 7 Python-Tests mit nachgebautem CUPS, 3 PHP-Tests, Ende-zu-Ende im Container mit echtem cupsd: Bondrucker
  anlegen und entfernen, Samba-Freigabe per `testparm`, virtueller Drucker des Beispielmoduls anlegen, als Modul drucken,
  nach Entfernen des Moduls wieder weg.

Nicht geprüft: echte Bondrucker und A4-Drucker, Suche im echten Netz, Drucken von Windows über Samba und von macOS über IPP
(nur Test-VM), CUPS unter systemd mit AppArmor.

## 0.7.0

Meilenstein 7 aus `docs/PLAN.md`: Installer für Ubuntu Server 26.04.

- **CUPS und Samba als Druckserver** aus festen Vorlagen (`installer/vorlagen/cupsd.conf`, `smb.conf`): vor dem Übernehmen
  geprüft (`cupsd -t`, `testparm`), Original einmalig nach `/etc/kienzle/original/`, startet der Dienst nicht, kommt die
  vorige Datei zurück (Vorgehen aus `terminzettel/install.py`). CUPS ohne Weboberfläche und Verwaltung nur am Server,
  Samba nur mit `[printers]`, SMB 3, ohne NetBIOS. Erreichbar erst nach Freigabe in der Firewall.
- **Vorabprüfungen** (Kerne, Arbeitsspeicher, Plattenplatz als Hinweis), **automatische Sicherheitsupdates ohne Neustart**
  (aus der Beta, `scripts/install-server.sh`).
- **AppArmor:** `kienzle-web schreiben` ergänzt `/etc/apparmor.d/local/gs` bzw. `qpdf` in einem markierten Abschnitt um die
  Datenordner der Module, die das in `hooks.apparmor` anmelden; andere Zeilen bleiben (aus der Beta, Zeilen 41–51).
- **Einrichtungs-Überblick** (Verwaltung → Einrichtung): Punkte der Basis (Admins mit OTP, Vertretung, Wiederherstellungscodes,
  Zertifizierungsstelle auf den Arbeitsplätzen, Firewall, Signaturprüfung, Updates, Netzwerkspeicher, Sicherung, Blatt,
  NAS-Schnappschüsse, Probe), nicht prüfbare Punkte von Hand bestätigen; darunter die Überblicke der Module. Die Haken
  `[setup] overview` sammelt der root-Dienst `kienzle-ueberblick` als Modul-Benutzer ein (Timer alle 15 Minuten, „neu prüfen“),
  geprüft nach Format und Links.
- Installer-Optionen `--admin-reset NAME`, `--kanal stabil|beta`; `installer/autoinstall.yaml` für die Standard-ISO;
  Anleitung `docs/INSTALLATION.md`.
- Tests: Vorlagen (nur Drucker in Samba, CUPS-Verwaltung nur lokal, `open_basedir`, autoinstall), AppArmor-Abschnitt,
  Einsammeln der Überblicke, Seite Einrichtung; Ende-zu-Ende im Container mit echtem CUPS/Samba-Paket und `testparm`.

Nicht geprüft: Start von CUPS und smbd unter systemd (nur Test-VM), Drucken von Windows und macOS, autoinstall mit der
echten ISO, AppArmor auf echtem Ubuntu 26.04 (im Container gibt es keine Profile).

## 0.6.1

- **Einstellungen exportieren** (Verwaltung → Sicherung): alle Einstellungen der Basis (`/etc/kienzle`: Zertifizierungsstelle,
  Schlüssel, Verbindungen, Firewall, Updates) und der Module (neu: `[backup] settings` in `module.toml`) in einer Datei,
  verschlüsselt mit einem frei gewählten Passwort (mindestens 12 Zeichen): Schlüssel per Argon2id (4 Durchläufe, 256 MiB),
  Daten mit XChaCha20-Poly1305 (libsodium secretstream, jeder Block authentisiert). Das Passwort der Datensicherung nur auf Wunsch.
  Die Datei liegt 24 Stunden zum Herunterladen bereit (Download protokolliert), dann wird sie gelöscht.
  Einspielen an der Konsole: `sudo kienzle-sicherung einstellungen-pruefen DATEI ORDNER` (zur Ansicht) bzw.
  `einstellungen-einspielen DATEI`; nur Pfade unter `/etc/kienzle` und die angemeldeten Einstellungen der Module, Eigentümer nach Namen.
- **Signaturprüfung der Updates wählbar** (Verwaltung → Updates): „Immer prüfen“ (Vorgabe), „im Beta-Kanal auch unsignierte
  Versionen“ oder „ohne Signaturprüfung – alle Versionen“. Auch ohne Prüfung bleiben Manifest und SHA-256 jeder Datei Pflicht,
  und eine vorhandene, aber falsche Signatur wird abgelehnt. Die Wahl steht im Protokoll.
- php-fpm: `open_basedir` mit einzelnen Einheiten statt einer Vorsilbe (PHP kennt keine Vorsilben; CI-Fehler von 0.6.0).
- Neues Hilfsprogramm `core/bin/kienzle-tresor` (Ver- und Entschlüsseln).

Nicht geprüft: Einspielen eines Exports auf einer frischen VM; Export mit vielen Modulen.

## 0.6.0

Meilenstein 6 aus `docs/PLAN.md`: verschlüsselte Datensicherung mit restic.

- **Verwaltung → Sicherung:** Ziel wählen (Netzwerkspeicher aus „Verbindungen“ per SFTP oder SMB, restic-REST-Server,
  Ordner auf dem Server), dann einmal das **Wiederherstellungsblatt** drucken. Es enthält das zufällige Passwort
  (40 Zeichen in 8 Fünfergruppen), die Ablage und die Repository-ID. Nach „Blatt ist gedruckt“ wird es vom Server gelöscht;
  das Webinterface kann das Passwort danach nicht mehr lesen (`/etc/kienzle/sicherung/passwort`, root, 0600).
- **Ablauf (Timer 02:30):** täglich sichern; sonntags `forget --prune` (Vorgabe 7/4/12/3, einstellbar) und `check`;
  am ersten Sonntag im Monat `check --read-data-subset 5%` und Probe-Wiederherstellung der Datenbanken mit `PRAGMA integrity_check`.
  „Jetzt sichern“, „Prüfen“ und „Probe-Wiederherstellung“ auch von Hand.
- **Inhalt:** Stand der Datenbank der Basis per `VACUUM INTO`, `/etc/kienzle` (Zertifizierungsstelle, Schlüssel, Verbindungen),
  `/var/lib/kienzle/core`, der SSH-Schlüssel des Netzwerkspeichers und die `[backup] paths` der Module (vorher deren
  Schnappschuss-Haken als Modulbenutzer). Nicht gesichert: Programme, das Sicherungspasswort, das Blatt.
- **SMB nur während der Sicherung eingehängt** (`mount.cifs`, SMB 3.1.1 mit Verschlüsselung, Zugangsdatei 0600 im
  privaten Arbeitsordner), SFTP mit eigenem Schlüssel und festem Serverschlüssel.
- **Wiederherstellen:** Basis oder ein Modul auf den Stand einer Sicherung (Dienste anhalten, Daten ersetzen, Eigentümer
  setzen, starten), protokolliert. Nach Totalausfall: `sudo bash installer/install.sh --wiederherstellen` auf einer neuen VM
  fragt Ablage und Passwort ab und holt alles zurück (gleiche Zertifizierungsstelle, gleiche Benutzer).
- **Warnungen** in der Navigation: keine Sicherung seit 36 Stunden, Prüfung oder Probe fehlgeschlagen, Blatt nicht
  gedruckt, schreibgeschützte NAS-Schnappschüsse nicht bestätigt.
- Vor jedem Update eine Sicherung, falls eingerichtet (Fehler halten das Update nicht auf, stehen aber im Protokoll).
- root-Dienst `kienzle-sicherung` (`.path` für Anfragen, `.timer` für den täglichen Lauf); Installer bringt `restic` und `cifs-utils` mit.
- Übernommen aus Kienzlezeit (`kienzlezeit-backup-und-restore.sh`): geprüfte Wiederherstellung, zeitgesteuerter Ablauf,
  SFTP/SMB-Anbindung (über M5).
- Tests: 13 Python-Tests (in GitHub Actions mit echtem restic, lokal mit nachgebautem), 3 PHP-Tests, Ende-zu-Ende im
  Container mit echtem restic: einrichten, Blatt, sichern, Probe, Prüfung mit Lesen, Wiederherstellen der Basis.

**Fehlerbehebung aus M3–M5:** php-fpm durfte wegen `open_basedir` die Statusdateien der root-Dienste, deren Einheiten
und `qrencode`/`ip`/`ss` nicht lesen. Auf einem echten Server zeigten Firewall, Updates und Verbindungen deshalb „Dienst nicht
eingerichtet“, und der QR-Code für die OTP-Einrichtung fehlte. Jetzt sind genau diese Pfade lesbar; die Ende-zu-Ende-Prüfung deckt das ab.

Abweichend vom Plan: ein gemeinsamer Schnappschuss mit Tag `kienzle` statt Tags je Teil (Wiederherstellung je Teil über
die Pfade); SFTP- und REST-Server im CI-Container noch nicht.
Nicht geprüft: echtes NAS über SFTP und SMB (`mount.cifs` braucht Kernel-Rechte), REST-Server im Nur-Anfügen-Modus,
`--wiederherstellen` auf einer frischen Ubuntu-26.04-VM, Schnappschuss-Haken echter Module.

## 0.5.0

Meilenstein 5 aus `docs/PLAN.md`: Netzwerkspeicher und zentrale Verbindungen.

- **Verwaltung → Verbindungen:** T2med (FHIR-Adresse, erlaubte Start-Adressen, API-Schlüssel, Zertifikat, Pinning), E-Mail
  (SMTP), Ziele auf dem Netzwerkspeicher (SMB oder SFTP), je mit „Verbindung prüfen“, dazu „Wer nutzt was“.
- **Geheimnisse bleiben root-eigen** (`/etc/kienzle/verbindungen/konfiguration.json`, 0600). Das Webinterface sieht sie nach dem
  Speichern nie wieder; ein leeres Passwortfeld heißt „unverändert“.
- **Verteilung nur an anmeldende Module** (`[[connections]]`): `/etc/kienzle/verbindungen/module/<id>.json`, nur für `ks-<id>`
  lesbar. Jedes Modul bekommt einen eigenen Schlüssel für seine eigenen Geheimnisse.
- **Netzwerkspeicher ohne Mount:** Dienst `ks-ablage` überträgt Aufträge der Module per `smbclient` bzw. `sftp`, als `.part`,
  liest zurück, vergleicht SHA-256, benennt dann um; Wiederholung alle 5 Minuten, Aufgabe nach zwei Tagen.
  SFTP nur mit eigenem SSH-Schlüssel und bestätigtem Serverschlüssel (Fingerabdruck), nie mit Passwort.
- **Prüfungen:** T2med über `/metadata` (CapabilityStatement), mit eigenem Zertifikat bzw. Pinning; E-Mail nur Anmeldung, ohne
  Versand; NAS mit Testdatei (schreiben, zurücklesen, löschen).
- **Hilfsdatei für PHP-Module** `core/php/modul/Kienzle.php`: Benutzer und Rechte, CSRF, Bestätigung, Verbindungen, Ablage,
  eigene Geheimnisse (`docs/MODUL-SCHNITTSTELLE.md`).
- root-Dienst `kienzle-verbindungen` (`.path`), Dienst `kienzle-ablage` mit Timer; Installer legt `ks-ablage` mit SSH-Schlüssel an.
- Übernommen: Pfad- und Zeichenprüfung, SFTP-/SMB-Aufrufe und geprüftes Hochladen aus Kienzlezeit
  (`kienzlezeit-backup-und-restore.sh`), Zugangsdatei und NT_STATUS-Auswertung aus der Beta (`src/InvoiceExport.php`),
  Prüfung der FHIR-Adressen und Pinning aus der Beta (`src/Config.php`, `src/HttpClient.php`).
- Tests: 17 Python-Tests mit nachgebautem NAS und echtem HTTPS-Server, 4 PHP-Tests, Ende-zu-Ende-Prüfung der Dateirechte.

Nicht geprüft: echtes NAS (Synology/QNAP) über SMB und SFTP, echter T2med-Server, echter SMTP-Server.

## 0.4.1

- `scripts/release-bauen` bricht ab, wenn der git-Status nicht lesbar ist (bisher galt das als „alles eingecheckt“).
- GitHub Actions: git im Container für den Arbeitsordner freigegeben.

## 0.4.0

Meilenstein 4 aus `docs/PLAN.md`: Updater mit signierten Releases, Versionsordnern und Rücksprung.

- **Verwaltung → Updates:** installierte Version, verfügbare Versionen mit Notizen, Kanal stabil/beta, „Jetzt nach Updates
  sehen“, Installieren, Rücksprung von Hand auf eine aufbewahrte Version. Hinweis im Menü bei neuer Version.
- **Signierte Releases:** eigener Ed25519-Schlüssel (OpenSSL), keine externe Stelle. Geprüft werden Signatur, Manifest,
  Archiv und jede Datei; nur neuere Versionen; Archiv nur mit normalen Dateien.
- **Ablauf:** Schnappschuss der Daten und Einstellungen, Installer des neuen Releases (Versionsordner, Verweis `current`),
  Prüfung nach dem Start über `/_health` mit der neuen Version, sonst Rücksprung samt Daten. Unterbrochene Aktualisierung
  (z. B. Stromausfall) wird beim nächsten Start zurückgenommen. Die letzten 3 Versionen und 5 Schnappschüsse bleiben.
- **Unsignierte Beta-Versionen** nur nach ausdrücklicher Freigabe eines Admins im Beta-Kanal; falsche Signaturen werden
  immer abgelehnt, stabile Versionen brauchen immer eine Signatur.
- **Herausgeber:** `scripts/schluessel-erzeugen`, `scripts/release-bauen` (reproduzierbar, nur eingecheckte Dateien),
  `scripts/release-signieren`; Ablauf in `docs/RELEASE-ABLAUF.md`.
- root-Dienst `kienzle-update` mit `kienzle-update.path`, täglichem `kienzle-update-pruefen.timer` und
  `kienzle-update-start.service`; der Installer legt sich selbst im Versionsordner ab (für den Rücksprung) und kennt
  `--aktualisieren`.
- Übernommen aus der Beta: Anfragedatei, Status und bereinigtes Protokoll, nur HTTPS, Größenbegrenzung, Rücksicherung,
  Sperre während einer Zahlung (jetzt `hooks.busy`) aus `src/Updater.php` und `scripts/self-update.sh`.
- Tests: 26 Python-Tests mit echten Testschlüsseln, nachgebautem Installer und Ordner-Quelle, 4 PHP-Tests.

Nicht geprüft: Update über GitHub auf einer echten VM (es gibt noch keinen Release-Schlüssel und kein veröffentlichtes
Release), Rücksprung mit echtem systemd. Ohne Schlüssel sind Updates auf echten Servern gesperrt, außer unsignierten
Beta-Versionen nach Freigabe.

## 0.3.0

Meilenstein 3 aus `docs/PLAN.md`: Firewall.

- **Verwaltung → Firewall:** freigegebene Netze (Vorschlag: das Netz dieses Geräts), SSH, Drucken über IPP (631) und
  Samba (445), Ports der Module einzeln freischaltbar, eigene Ports.
- **Vorgabe „alles zu“** (policy drop); wahlweise wie in der Beta nur die verwalteten Ports auf die Netze beschränken.
  HTTPS (443) ist immer aus den freigegebenen Netzen offen.
- **Ports der Module** kommen aus `[[firewall]]` der `module.toml`. Der root-Dienst schlägt sie im root-eigenen
  Modulverzeichnis nach; die Anfrage des Webinterfaces nennt nur Kennungen wie `beispiel/1`.
- **Schutz vor Aussperren** (aus der Beta): Das anfragende Gerät muss in einem freigegebenen Netz liegen; ohne Bestätigung
  binnen 2 Minuten stellt der Dienst die vorherigen Regeln wieder her. Bestätigte Regeln werden beim Start vor dem Netz geladen.
- Root-Dienst `kienzle-firewall` (aus `scripts/firewall-apply.py` der Beta): eigene Tabelle `inet kienzle`, Regeln nur aus
  festen Vorlagen, Anfragen nur als echte, kleine Datei von `ks-core`, `nft -c` vor dem Laden.
- systemd: `kienzle-firewall.path`, `kienzle-firewall-anwenden.service`, `kienzle-firewall.service`; Installer richtet sie ein.
- Übernommen aus der Beta: `src/Network.php`, `src/Firewall.php`, `scripts/firewall-apply.py`, `tests/firewall.php`, `tests/firewall-apply.sh`.
- Tests: 12 Python-Tests für den Dienst (nachgebautes nft), Syntaxprüfung mit echtem nft (lokal und in GitHub Actions mit sudo),
  7 PHP-Tests für Seite und Prüfung.

Nicht geprüft: Laden der Regeln im echten Kernel auf Ubuntu 26.04 mit systemd, Aussperrschutz über das Netz (Prüfliste
`docs/VM-TEST.md`, Meilenstein 3).

## 0.2.4

- Stamm-CA 30 Jahre gültig statt 10 (Wunsch der Praxis: Arbeitsplätze nur einmal einrichten). Das Server-Zertifikat
  bleibt bei höchstens 825 Tagen (Vorgabe von macOS/iOS) und wird automatisch erneuert.
- Ende-zu-Ende-Prüfung: Kopfzeilen ohne `\r` vergleichen.

## 0.2.3

- php-fpm der Basis protokolliert ins Systemprotokoll (`journalctl -t kienzle-core-php`) statt nach `/proc/self/fd/2`, das sich als `ks-core` nicht öffnen lässt (gefunden in der Ende-zu-Ende-Prüfung).

## 0.2.2

- nginx: `server_tokens off` nur noch in den `server`-Blöcken. Ubuntu 26.04 setzt es schon in `nginx.conf`; doppelt lehnt nginx die Konfiguration ab (gefunden in der Ende-zu-Ende-Prüfung).

## 0.2.1

- Test der Zertifizierungsstelle unabhängig von der Schreibweise des Zertifikatsnamens (OpenSSL 3.0 und 3.5).

## 0.2.0

Meilenstein 2 aus `docs/PLAN.md`: Anmeldung, Benutzer und Rechte, Admin-OTP, Passkeys, lokale Zertifizierungsstelle.

- **Eine Anmeldung für alle Module:** nginx prüft jede Anfrage an ein Modul bei der Basis (`/_auth/<modul>`) und
  reicht Benutzer, Rolle, Rechte, CSRF-Marke und Zeit der letzten Bestätigung weiter (`docs/MODUL-SCHNITTSTELLE.md`).
  Kopfzeilen vom Browser werden ersetzt, bei FastCGI heißen die Werte `KIENZLE_*`.
- **Konten und Rollen:** beliebig viele Konten; Basis-Admin, Admin je Modul, Mitarbeiter mit Rechten je Modul.
  Ein Basis-Admin sieht Module nicht automatisch. Passwort legt jede Person selbst über einen einmaligen Link fest.
- **Admins nur mit Passwort und OTP** (TOTP nach RFC 6238, geprüft mit den Testwerten der RFC), Code nur einmal gültig,
  10 Wiederherstellungscodes zum Drucken. Admin-Konten werden nur an der Konsole zurückgesetzt (`kienzle-admin`).
- **Passkeys** (WebAuthn, ES256/Ed25519/RS256, nur mit Nutzerprüfung am Gerät): ersetzen Passwort und Code bei der
  Anmeldung und bei Bestätigungen; Admins behalten OTP als Rückfall. Nur über den Servernamen, nicht über IP-Adressen.
- **Sitzungen:** einstellbare Abmeldezeiten, „Gerät merken“ nur aus dem Netz der Anmeldung, heikle Aktionen und die
  Verwaltung verlangen alle 24 Stunden eine erneute Bestätigung. Neuer Sitzungsschlüssel nach jedem Anmeldeschritt.
- **Schutz:** Sperre je Absender mit wachsender Dauer und Gesamtbremse (aus der Beta), CSRF-Marken, Prüfung von Origin und
  Servername, CSP und weitere Schutz-Kopfzeilen, Cookies `Secure; HttpOnly; SameSite=Strict`. Änderungsprotokoll ohne
  Passwörter, Codes oder Patientendaten.
- **Lokale Zertifizierungsstelle** (`kienzle-zertifikat`): ECDSA P-256, Namensbeschränkung auf die eigenen Namen und private
  Netze, Server-Zertifikat 825 Tage, tägliche Prüfung und Erneuerung 60 Tage vor Ablauf; CA unter `/ca.crt`.
- **Dienste:** eigene php-fpm-Instanz als `ks-core` mit systemd-Härtung, Socket-Ordner je Teil (nur Teil und nginx),
  nginx-Konfiguration aus festen Vorlagen (`kienzle-web`), nur Port 443, fremde Servernamen bekommen keine Seite.
- **Installer** (`installer/install.sh`, Grundstock): Pakete, Benutzer, Ordner, Schlüssel, CA, Dienste, Einrichtungslink;
  wiederholbar ohne Verlust von Schlüssel, CA und Daten.
- **Format `module.toml`:** Socket jetzt `unix:/run/kienzle/<id>/web.sock`; neu `web.protocol` (`http`/`fastcgi`) und `web.entry`.
- Aus der Beta übernommen: Sitzungen, Gerät merken, Bestätigung, Passwortregeln, Sperren (`src/Admin.php`), `Throttle`,
  `AuditLog`, `Database`, `Command`, `Problem`, Warten auf die Paketsperre.
- Tests: 110 Python- und 57 PHP-Testfälle, darunter ein nachgebauter Passkey-Authenticator mit echten Schlüsseln;
  Ende-zu-Ende-Prüfung mit echtem nginx und php-fpm im Container `ubuntu:26.04` (`tests/integration/nginx.sh`).

Nicht geprüft: echte Ubuntu-26.04-VM mit systemd (Prüfliste `docs/VM-TEST.md`), echte Passkeys auf Windows, macOS,
iOS und Android, Namensbeschränkung der CA in allen Browsern, QR-Code mit echter Authenticator-App. Die
Ende-zu-Ende-Prüfung läuft erst in GitHub Actions; lokal gab es kein nginx und kein php-fpm.

## 0.1.1

- Neu im Format `module.toml`: `[[connections]]`. Ein Modul meldet an, welche zentrale Verbindung der Basis es nutzt
  (`t2med`, `mail`, `ablage`), wofür (`purpose`) und ob es ohne sie arbeitet (`required`). Nur anmeldende Module
  bekommen die Zugangsdaten. Zugänge, die nur ein Modul nutzt (Letterxpress, SumUp-API, Google), bleiben im Modul.
- Tests für das neue Feld.

Nicht geprüft: Lauf auf echtem Ubuntu 26.04 (lokal Python 3.13).

## 0.1.0

Meilenstein 1 aus `docs/PLAN.md`: Gerüst der Basis und Format der Modul-Beschreibung.

- Ordnerstruktur `core/`, `modules/`, `installer/`, `tests/`; Basis-Version 0.1.0, Modul-Schnittstelle `core_api` 1.
- Format `module.toml` festgelegt (`docs/MODULE-TOML.md`), mit neuen Feldern gegenüber dem ersten Entwurf:
  `hooks.busy`, `hooks.health`, `[setup] overview`, `[[printers]]`, `firewall.scope`.
- Prüfprogramm `core/bin/kienzle-modul-pruefen`: prüft eine `module.toml` streng (unbekannte Schlüssel, Typen,
  Namen, Pfade, Ports, Rechte, Sicherungspfade, Haken), die Dateien im Modulordner (vorhanden, keine Symlinks,
  ausführbar, nicht fremd beschreibbar) und Konflikte zwischen Modulen (Webpfad, Druckername, Ports).
  Ausgabe auf Deutsch, auf Wunsch als JSON.
- Beispielmodul `modules/beispiel` ohne Funktion, nur für Tests.
- Tests: `tests/run-all.sh` (85 Testfälle für das Prüfprogramm), GitHub Actions auch im Container `ubuntu:26.04`.

Nicht geprüft: Lauf auf echtem Ubuntu 26.04. Lokal getestet mit Python 3.13; der Container-Job in GitHub Actions
prüft mit Python aus Ubuntu 26.04.
