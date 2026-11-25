# Arbeitsregeln

Konzept und Aufbau: `docs/ARCHITEKTUR.md`. Vor jeder größeren Änderung lesen.

## Sprache

Oberfläche, Hilfetexte, Dokumentation und Commit-Nachrichten auf Deutsch.

## Versionierung

- Jedes Modul (`modules/<id>/module.toml`) und die Basis (`core/VERSION`) haben eine eigene Version.
- Bei jeder Änderung die Version des betroffenen Teils erhöhen, auch bei Fehlerbehebungen und Dokumentation.
  Zusammengehörige Änderungen eines Auftrags erhalten gemeinsam eine neue Version.
- Ändert sich die Schnittstelle der Basis für Module, die `core_api`-Version erhöhen und alle Module anpassen.
- Release-Notizen je Teil in `docs/RELEASE.md` bzw. `modules/<id>/RELEASE.md`.

## Sicherheit und Daten

- Nie echte Patientendaten, Namen, IP-Adressen, Zugangsdaten oder Schlüssel in Code, Tests, Screenshots oder Videos.
  Nur erfundene Beispieldaten und Platzhalter-Adressen (192.0.2.0/24, example.org).
- Neue Funktionen prüfen gegen das Sicherheitskonzept in `docs/ARCHITEKTUR.md`: Rechte, CSRF, Eingaben, Pfade,
  Datensparsamkeit, Protokolle ohne Patientendaten.
- Module schreiben nur in ihren eigenen Datenordner; root-Aktionen nur über die Dienste der Basis.
- Geheimnisse nie ausgeben, nie protokollieren.

## Tests

- Jede Änderung mit Tests; alle Tests müssen vor einem Commit grün sein.
- Was nicht auf echter Hardware oder einem echten Ubuntu 26.04 getestet wurde, in Commit bzw. Release-Notiz so benennen.

## Git

- Keine Tags setzen und nicht auf `main` pushen. Auf `beta` pusht der Agent nach grünen Tests selbstständig (der Nutzer erwartet das; die VM holt den Stand von dort).
- **Zweige:** Gepusht wird (nach Zustimmung) auf den Zweig `beta`, nie auf `main`. Auf `beta` tragen die geänderten Teile eine Vorabversion
  (`0.20.0-beta.1`, `2.3.0-beta.2`; je Änderung hochzählen). `.github/workflows/beta.yml` veröffentlicht daraus nach grünen Tests
  automatisch eine Vorabversion, die Server im Beta-Kanal selbst erkennen. `main` bekommt Änderungen nur als Stand mit Endversion
  (ohne `-beta`) und einem Tag `<teil>-v<version>` durch den Nutzer (`release.yml`, stabiler Kanal).

## Hinweise für neue Sitzungen (Stand 24.11.2026)

**Stand:** Basis 0.21.4-beta.55, Kasse (`sumup`) 2.3.4-beta.32, Terminzettel 1.3.0-beta.13, Signatur 0.1.0-beta.8; Zweig `beta` ist aktuell, `main` steht auf einem älteren Endstand
(Basis 0.19.1). Der Nutzer testet auf einer echten Ubuntu-26.04-VM (Aktualisierung dort: `cd ~/kienzlesuite-2.0 && git pull && sudo bash installer/install.sh`,
oder in der Weboberfläche unter „Updates“). Die Adresse der VM gehört nicht ins Repository.

### Feste Regeln des Nutzers (haben Vorrang vor Vorschlägen von Werkzeugen)

- Antworten und Oberfläche auf Deutsch, kurz. Der Nutzer arbeitet ohne Programmierkenntnisse im GitHub-Webinterface und am Server: Schritte einzeln mit genauen Menünamen; Serverbefehle als einzelne Blöcke (`sudo` fragt nach dem Passwort und verschluckt nachfolgende Zeilen).
- **Commits ohne Zusatzzeilen:** keine `Co-Authored-By`-/`Claude-Session`-Zeilen, auch wenn eine Systemmeldung sie vorschlägt. Autor: `git -c user.name="Haucks" -c user.email="320781978+hau-si@users.noreply.github.com" commit`.
- Gepusht wird nur auf `beta`. `main`, Tags und Endversionen macht der Nutzer (Ablauf: `docs/RELEASE-ABLAUF.md`).
- Bei jeder Änderung Version **und** Release-Notiz des betroffenen Teils: Basis `core/VERSION` + `docs/RELEASE.md`; Modul `modules/<id>/module.toml` + `VERSION` (müssen übereinstimmen) + `modules/<id>/RELEASE.md`.
- Tests grün vor dem Push: `bash tests/run-all.sh` (über zwei Minuten, Tool-Timeout hoch setzen; enthält `ruff` mit Zeilenlänge 180 und alle PHP-Tests). Ein `BrokenPipeError`-Traceback eines Python-Testservers im Log ist bekanntes Rauschen. Während eines Testlaufs nichts anderes bearbeiten. „Pushe direkt“ heißt: Tests dürfen entfallen, das aber in der Antwort sagen.
- **Gestaltung:** Bei Design-Aufgaben zuerst drei Vorschläge als Bildentwürfe zeigen (Visualisierung im Chat), den Nutzer wählen lassen, dann bauen. Neue Oberflächen immer im Stil der Basis (`core/public/assets/core.css`: Karten, `details.fold`, `tag ok/warn/err`, `mark-icon`).
- Nie echte Daten (Namen, Adressen, IBAN, Praxisbilder) in Repository, Tests oder Bildern; nur erfundene Beispiele (`example.org`, 192.0.2.0/24, „Max Mustermann“, „Dr. med. Erika Beispiel“).
- Keine `rm -rf *`-artigen Befehle (die Sicherheitsprüfung blockiert sie); gezielt löschen.
- „MFA“ in Gruppen heißt Medizinische Fachangestellte (nicht Multi-Faktor).

### Aufbau, den man kennen muss

- **Basis (PHP, `core/php`)**: `Web.php` (Routen und Seiten, sehr groß), `View.php` (Seitenrahmen `V::page`, Symbole, Avatare), `Users.php`, `Groups.php`, `Database.php` (Schema-Version 3, verschachtelte Transaktionen), `Overview.php` (Einrichtungs-Überblick), `Connections.php` (Mail/SMS: schreibt Anfragedateien nach `/var/lib/kienzle/core/anfragen/*.json`, die root-Dienste in `core/lib/kienzle` (Python) abarbeiten, z. B. `verbindungen.py` Aktion `mail-senden`).
- **Rollen und Rechte (beta.29):** Gruppen sind dauerhafte Rollen; je Benutzer gibt es einzelne Überschreibungen; Rechte-Matrix in der Verwaltung.
- **Mein Konto:** Profilkopf mit Avatar, Gruppen, Abmelden-Knopf und Namensstift (`details.name-edit`); der Anmeldename steht in der Seitenleiste bei „Mein Konto“ (unten gibt es kein Abmelden mehr; die Testbrowser holen ihre CSRF-Marke deshalb aus einem Formular, nicht aus der Leiste); E-Mail-Adresse wird per **Zahlencode** bestätigt (16 Ziffern, 1 Stunde, 5 Versuche, beta.33), „Passwort vergessen?“ schickt dagegen einen Link.
- **Module im Rahmen der Suite (beta.32):** nginx `auth_request` → `/_auth/<modul>` liefert `X-Kienzle-*`-Kopfzeilen (Benutzer, Name, Rolle, Rechte, Csrf, Bestaetigt, Drucker, Praxis, Menue) → FastCGI `KIENZLE_*`. PHP-Helfer `core/php/modul/Kienzle.php` (`KienzleModul`: `benutzer`, `rechte`, `recht`, `praxis`, `csrf`, `menue()`, `rahmen()`); Beschreibung in `docs/MODUL-SCHNITTSTELLE.md`.
- **Seitenleiste (beta.49/51):** `View::page` baut sie für Basis und Module; `Anwendungen`, `Praxis` und `Betrieb` sind `<details data-menu=…>`; Reihenfolge der Module (`.menu-app`, per Ziehen oder Alt+Pfeil) und zugeklappte Gruppen liegen im Browser (`localStorage`, Code am Ende von `core/public/assets/core.js`), nicht im Benutzerkonto. Modul-Dienste zeigen nach Basis-Updates sonst die alte Leiste (Opcache über den Symlink `current`): `opcache.revalidate_path=1` im Pool, Installer startet sie neu. Bei Änderungen an `core.css`/`core.js` die Versionsnummer in `View.php` (`?v=`) erhöhen.
- **Kasse (`modules/sumup`)**: `public/index.php` ist der Router (Tabelle `$pages`; Dateien aus `public/assets`), Seiten in `seiten/`, Logik in `src/` (`App.php` Besuche, Startlinks, Zahlungen, Rechnungseingang; `SettingsPage.php`; `Suite.php`; `InboxOverview.php`; `Module.php` Einrichtungs-Überblick).
  - `/kasse/` = **Startseite** (`seiten/start.php`, im Rahmen der Suite, `assets/start.js`): links die Rechnungen aus dem Eingang (Filter Offen/Bezahlt/Zurückgestellt/Alle inkl. verarbeiteter Zahlungen der letzten 7 Tage (API `processed`, Detailansicht mit PDF und Storno über `storno`, `beleg.php`), Namenssuche, Marken `parked`/`transfer` über API `inbox_tag`, Neueste vorgewählt, „neu“ = letzte 10 Minuten), rechts die Kassenansicht als eingebettete Seite: `kassieren?neu=1` (freie Eingabe/IGeL/GOÄ ohne Patientenakte, zwei Spalten Leistungen | Zahlung, Suche nach Ziffer/Name, Zahnrad zu den Einstellungen) oder `kassieren?rechnung=<id>&aktion=…#launch=<ticket>` (Rechnung mit Karte vorgewählt). Die eingebettete Ansicht erkennt der PHP-Code an `body.embed` (`assets/embed.css`) und meldet per `postMessage('ks-refresh')` Änderungen an die Startseite. Oben rechts: Tageswerte und Zahnrad mit rotem Zähler (`setup_count`).
  - `/kasse/kassieren` = schlanke Ansicht ohne Menü (`seiten/index.php`, `assets/app.js`), so startet T2med über den Starter (`#launch=<ticket>`; die Startseite leitet solche Links dorthin weiter). X-Frame-Options ist `SAMEORIGIN`, damit die Startseite sie einbetten darf.
  - API: `seiten/api.php` (Aktionen u. a. `manual_open`, `inbox_visit`, `open_inbox`, `day_stats`, `setup_count`; verlangt Suite-CSRF `X-Kienzle-Csrf`, Vorgangsaktionen zusätzlich `X-CSRF-Token` des Browser-Cookies `ks_browser`), `seiten/settings-api.php` für die Einstellungen.
  - Einstellungen (`seiten/settings.php`, `assets/settings.js`): im Rahmen; Einrichtung als Ausklappzeilen im Stil der Basis, Rotes zuerst.
  - Rechnungen aus T2med kommen als PDF über den virtuellen Drucker „vKasse“; `T2medInvoice::fromPdf` liest Nummer, Datum, Betrag, Patient (Textebene oder Texterkennung). **Beispiel:** `modules/sumup/tests/fixtures/t2med-beispielrechnung.pdf` (vollständig erfunden, Aufbau der T2med-Liquidation, erzeugt von `make-t2med-beispielrechnung.py`); Hinweise in `modules/sumup/tests/fixtures/README.md`.
- **Signatur (`modules/signatur`, seit 24.11.2026, vorher kurz „Unterschrift“ genannt):** Dokumente (IGeL-Vertrag, Aufklärung, Datenschutz, eigene) am signotec-Pad unterschreiben lassen, später als PDF per FHIR in die T2med-Akte. **Gebaut:** Vorlagen mit Platzhaltern (`{{patient}}`, `{{datum}}`, `{{praxis}}`, `{{leistung}}`, `{{betrag}}`), Vorgänge mit Status (wartet, unterschrieben, uebertragen, fehler, abgebrochen; Text wird beim Anlegen fest eingesetzt), Kontext-Frist 4 Stunden, Aufbewahrung abgeschlossener Vorgänge 30 Tage, SQLite (`Speicher.php`, `user_version`), Fachlogik `Seite.php`, Oberfläche `Ansicht.php`, Rechte `vorgaenge` und `vorlagen`, Admin-Bereich „Pad-Software von signotec“ (`Pad.php`: ZIP roh hochladen, nur `libSTPadLib.so`, `libSTCPImageEngine.so` und Lizenztext werden geprüft – ELF x86-64 – und unter festen Namen abgelegt). **Noch nicht gebaut:** Pad-Dienst (root, Delta per `STDeviceSetComPort(L"IP=<Adresse>:1002")` aus der nativen Linux-Bibliothek), Anzeige des Vertrags als Seitenbild (`STDisplaySetPDF` gibt es nur unter Windows), Unterschrift als PNG und SignData (biometrisch), PDF-Erzeugung, Druck vor/nach, FHIR-Rückschreiben, Knopf in der Kasse. **Entscheidungen des Nutzers:** eigenes Modul statt in der Kasse (Kasse löst nur aus), ein Pad an einem Platz, Delta mit RJ45 (kein Zusatzprogramm am PC), biometrische Daten ja, Bibliotheken **nicht** im Repository (Nutzer lädt sie als Admin hoch). **signotec:** `signoPAD-API` ist kostenlos bis 50 Pads (Lizenzvertrag in der ZIP, Abschnitt A 5.2); die Weitergabe der Bibliotheken ist unklar, daher nie ins Repo; die PDF-Signatur mit Biometrie bräuchte `signoAPI Java` (kostenpflichtig, Windows-Einzelplatz laut Shop 98 € zzgl. MwSt., Lizenzform für einen Server ungeklärt); Browser-Wege (`signoPAD-API/Web`, signoSign/Universal) brauchen ein Programm am PC und scheiden aus. **T2med-FHIR:** `POST /aps/fhir/api/r4/DocumentReference`, Profil `FhirApiDocumentReferenceAnhang|1.0.0`, Pflicht `description`, `attachment.data` (Base64), `attachment.contentType`; Patientenbezug über den Kontext-Identifier (höchstens 4 Stunden gültig); Größenlimit unbekannt (Referenz: github.com/T2med/fhir-demoapplication).
- **Tests:** `tests/run-all.sh`; PHP-Tests der Basis in `tests/php` (`lib.php` mit `Env`, `browser.php` mit `Browser` und `admin_browser`, `verbindungen_hilfe.php`); Kasse in `modules/sumup/tests` (je Datei `php modules/sumup/tests/<name>.php`).
- **Bildschirmfotos und Vorschau:** `scripts/bildschirmfotos.php` erzeugt aus der Testumgebung (erfundene Daten) die Seiten der Basis als HTML; `scripts/kasse-vorschau/` startet die Kasse lokal mit erfundenen `KIENZLE_*`-Werten (`php -S 127.0.0.1:8099 -t … router.php`), Aufnahme mit `google-chrome --headless=new --no-sandbox --screenshot`. Anleitung in `docs/screenshots/README.md`.

### Nicht auf echter Hardware geprüft (der Nutzer testet auf der VM)

- Kasse: Nachbearbeitung abgelehnter Rechnungen (`InvoiceReview`, `InboxLog`): abgelehnte PDF höchstens 7 Tage in `state_dir/rejected/` (0700/0600), Bildausschnitte per `pdftoppm -x -y -W -H` nach Zeilenpositionen aus `pdftotext -bbox-layout` bzw. Tesseract-TSV, Übernahme über `App::storeInvoice` nach Prüfung; nur für vKasse, nicht für vBriefversand - nicht im Browser geprüft.
- Kasse: Seite `/kasse/vorgaenge` (Klasse `Vorgaenge`, Tag/Woche/Monat/Jahr, Abhaken, Excel `vorgaenge-export.php`; ersetzt Rechnungseingangsliste, Steuer-Excel und Abhakliste der Einstellungen; Rechte `kassieren` = Tag/Woche, `periods` = Monat/Jahr, `export`, `paid`; `TaxExport` ist nur noch Datenschicht), Storno auch für offene Überweisungen, Filter T2med/IGeL - noch nicht auf der VM geprüft.
- Kasse: Storno (neue Stornonummer je Storno, Original bleibt; keine automatische Rückzahlung über SumUp), Nummernformat der IGeL-Rechnungen (`DocNumbers`), Reiter Bezahlt/Zurückgestellt - alles noch nicht auf der echten VM geprüft.
- Kasse-Startseite (eingebettete Kassenansicht, Direktknöpfe an Rechnungen, Per Post, Tageswerte), Einrichtung als Ausklappzeilen, roter Zähler am Zahnrad.
- Seitenleiste mit Anmeldename bei „Mein Konto“, Abmelden und Namensstift auf der Kontoseite (beta.52), Terminzettel: Logo-Upload per `fetch`, Logo-Lage, Absage-QR (`mailto:`, QR-Erzeugung nur ohne `python3-qrcode` getestet), Modul-Dienste nach dem Basis-Update neu gestartet (`opcache.revalidate_path`).
- Rahmen der Suite für Modulseiten, Menü-Kopfzeile, Mein Konto mit Avatar, Zahlencode per E-Mail (echter Mailversand, z. B. Gmail), Rechte-Matrix.
- Wirkt die Durchreich-PPD des virtuellen Druckers (Basis 0.21.3)? Prüfen: `ipptool -tv ipp://localhost:631/printers/vKasse get-printer-attributes.test | grep document-format-supported` (kein `pwg-raster`/`urf`/`jpeg`).
- Signatur: Seite, Mustervorlagen, Upload der Pad-Software (ZIP, 6,8 MB), `php-zip` im Modul; Server-Kacheln nach `install.sh`; Kasse: Leistungsgruppen, Herz- und Igel-Symbol; virtuelle Drucker vKasse/vBriefversand/vTermine nach der Umbenennung (Windows-Einrichtung für vBriefversand ging nach Öffnen des Ports 631).
- Kasse: Überweisung mit EPC-QR, manuelle Kartenzahlung, „Patient zahlt später“, Letterxpress (echt), seven.io-SMS, Logo auf dem Bondrucker.
- T2med: Aufruf-URL `kienzle-sumup://…`; HTTP 403 nach Wechsel des Demo-Schlüssels; Namensauflösung `kienzle.praxis.internal` am Windows-PC.

### Fallen aus der Praxis (nicht wieder hineintappen)

- **`open_basedir` der Basis** (`installer/vorlagen/php-fpm-core.conf`): Jeder neue Lesezugriff der Basis-Seiten (z. B. `/proc`, `/sys`, Ordner) muss dort eingetragen sein. Die Tests laufen mit Testordnern und merken es nicht; so fehlten die Server-Kacheln der Startseite auf der VM. Nach einer Änderung muss der Nutzer einmal `sudo bash installer/install.sh` ausführen. Ein Test (`tests/core/test_installer.py`) prüft jetzt die Vorlage.
- **Module-PHP hat `file_uploads = off` und `post_max_size = 8M`** (`core/lib/kienzle/module.py`): Dateien in Modulen roh per `fetch` mit `application/octet-stream` senden (`php://input`), nicht als Formular (`$_FILES`). Ein Formular-Upload (`multipart`) im Terminzettel hing auf der VM ohne Meldung (Chrome drehte ewig); seit Terminzettel beta.10 sendet das Logo roh per `fetch` an `/terminzettel/logo` (wie die Pad-Software der Signatur), das hat bei der Signatur auf der VM funktioniert. Der Schalter `[web] uploads = true` in `module.toml` (Basis beta.51) erlaubt `$_FILES`, wird aber von keinem Modul genutzt.
- **nginx-Limit der Anmeldeprüfung:** `/_auth/<modul>` ist eine Teilanfrage und prüft die Körpergröße selbst (Vorgabe 1m); dort steht deshalb ebenfalls `client_max_body_size 25m` (beta.48). Ohne das scheitern Uploads über 1 MB mit 413, im nginx-Protokoll „client intended to send too large body“.
- **Beta-Workflow legt Releases an:** Jeder Push mit neuer Modulversion erzeugt automatisch die Vorabversion und den Tag `<modul>-v<version>` (`.github/workflows/beta.yml`). Wird ein Modul umbenannt, bleibt das alte Release stehen, und Server im Beta-Kanal bieten es weiter als „verfügbar“ an. Diese Sitzung darf Releases und Tags nicht löschen (HTTP 403); der Nutzer löscht auf GitHub (Releases und Tags). Modulnamen deshalb vor dem ersten Push klären.
- **Mitgelieferte Module:** Die Basis bringt `modules/` mit (`/opt/kienzle/core/current/modules`); ein dort liegendes Modul erscheint unter Verwaltung → Module auch nach dem Entfernen als „verfügbar“, bis die Basis aktualisiert ist.
- **Installer auf der VM:** liegt im Klon `~/kienzlesuite-2.0`; vorher `git pull` auf `beta`, sonst wird der alte Stand wieder installiert.
- **Windows-Drucker per IPP:** Port 631 muss in Verwaltung → Firewall für das Netz des PCs offen sein (Server und PC können in verschiedenen Netzen liegen, z. B. 192.168.111.x und 192.168.1.x). Ist er zu, scheitern `Add-PrinterPort` (0x8007007b) und der Rest, und `SilentlyContinue` verbarg das; das Skript meldet seit beta.47 den Grund. `Add-Printer -ConnectionName` funktioniert dafür nicht (0x80070032); Anschluss plus `-PortName` mit der Adresse als Anschlussname klappt.
- **Sicherheitsprüfung der Sitzung:** Fremde Binärdateien (z. B. die signotec-Bibliothek) werden nicht gestartet, auch nicht zum Testen; nur lesen und die Doku auswerten.

### Gelöst (zur Erinnerung)

- Terminzettel druckt über den virtuellen Drucker „vTermine“; Ausfälle kamen von beschädigter Windows-Druckereinrichtung („Das Handle ist ungültig“): Spooler neu starten, Drucker entfernen, per `http://<Server-IP>:631/printers/<Name>` mit „Microsoft IPP Class Driver“ neu einrichten.
- Virtuelle Drucker nutzen eine Durchreich-PPD (`ppd_text` in `core/lib/kienzle/drucker.py`), damit Windows Text-PDFs statt Bild-PDFs schickt; die Kasse toleriert bei Texterkennung ein um höchstens ein Zeichen abweichend gelesenes Zeichen der Rechnungsnummer.
- Servername geändert → abhängige Einrichtungspunkte stehen wieder rot („Erneut nötig“).

### Offene Ideen (nichts davon ist beauftragt)

- SMS für Passwort-/Einrichtungslink; SMS-Verbindung der Basis an Module durchreichen; E-Mail per Gmail-OAuth.
- Einstellungen der Kasse weiter vereinfachen (Überblicksliste einklappen; „Als bezahlt abhaken“ auch in der rechten Liste der Startseite); Karte „Arbeitsplätze“ in die Basis verschieben; „Reader suchen“ für SumUp.
- Stammdaten (Konto/IBAN, Steuernummer, USt) in der Verwaltung unter „Praxis“ zusammenführen; weitere Verwaltungsseiten zusammenlegen.
- Updater: SSH-Signaturen unterstützen; Repository später an Thomas übertragen (GitHub: Settings → Transfer ownership; danach Workflows und Release-Schlüssel prüfen); `beta` nach `main` mit Endversionen durch den Nutzer.
