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

**Stand:** Basis 0.21.4-beta.35, Kasse (`sumup`) 2.3.4-beta.20, Terminzettel 1.3.0-beta.3; Zweig `beta` ist aktuell, `main` steht auf einem älteren Endstand
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
- **Mein Konto:** Profilkopf mit Avatar und Gruppen; E-Mail-Adresse wird per **Zahlencode** bestätigt (16 Ziffern, 1 Stunde, 5 Versuche, beta.33), „Passwort vergessen?“ schickt dagegen einen Link.
- **Module im Rahmen der Suite (beta.32):** nginx `auth_request` → `/_auth/<modul>` liefert `X-Kienzle-*`-Kopfzeilen (Benutzer, Name, Rolle, Rechte, Csrf, Bestaetigt, Drucker, Praxis, Menue) → FastCGI `KIENZLE_*`. PHP-Helfer `core/php/modul/Kienzle.php` (`KienzleModul`: `benutzer`, `rechte`, `recht`, `praxis`, `csrf`, `menue()`, `rahmen()`); Beschreibung in `docs/MODUL-SCHNITTSTELLE.md`.
- **Kasse (`modules/sumup`)**: `public/index.php` ist der Router (Tabelle `$pages`; Dateien aus `public/assets`), Seiten in `seiten/`, Logik in `src/` (`App.php` Besuche, Startlinks, Zahlungen, Rechnungseingang; `SettingsPage.php`; `Suite.php`; `InboxOverview.php`; `Module.php` Einrichtungs-Überblick).
  - `/kasse/` = **Startseite** (`seiten/start.php`, im Rahmen der Suite, `assets/start.js`): links die Rechnungen aus dem Eingang (Filter Offen/Bezahlt/Zurückgestellt/Alle inkl. verarbeiteter Zahlungen der letzten 7 Tage (API `processed`, Detailansicht mit PDF und Storno über `storno`, `beleg.php`), Namenssuche, Marken `parked`/`transfer` über API `inbox_tag`, Neueste vorgewählt, „neu“ = letzte 10 Minuten), rechts die Kassenansicht als eingebettete Seite: `kassieren?neu=1` (freie Eingabe/IGeL/GOÄ ohne Patientenakte, zwei Spalten Leistungen | Zahlung, Suche nach Ziffer/Name, Zahnrad zu den Einstellungen) oder `kassieren?rechnung=<id>&aktion=…#launch=<ticket>` (Rechnung mit Karte vorgewählt). Die eingebettete Ansicht erkennt der PHP-Code an `body.embed` (`assets/embed.css`) und meldet per `postMessage('ks-refresh')` Änderungen an die Startseite. Oben rechts: Tageswerte und Zahnrad mit rotem Zähler (`setup_count`).
  - `/kasse/kassieren` = schlanke Ansicht ohne Menü (`seiten/index.php`, `assets/app.js`), so startet T2med über den Starter (`#launch=<ticket>`; die Startseite leitet solche Links dorthin weiter). X-Frame-Options ist `SAMEORIGIN`, damit die Startseite sie einbetten darf.
  - API: `seiten/api.php` (Aktionen u. a. `manual_open`, `inbox_visit`, `open_inbox`, `day_stats`, `setup_count`; verlangt Suite-CSRF `X-Kienzle-Csrf`, Vorgangsaktionen zusätzlich `X-CSRF-Token` des Browser-Cookies `ks_browser`), `seiten/settings-api.php` für die Einstellungen.
  - Einstellungen (`seiten/settings.php`, `assets/settings.js`): im Rahmen; Einrichtung als Ausklappzeilen im Stil der Basis, Rotes zuerst.
  - Rechnungen aus T2med kommen als PDF über den virtuellen Drucker „SumUp-Rechnung“; `T2medInvoice::fromPdf` liest Nummer, Datum, Betrag, Patient (Textebene oder Texterkennung). **Beispiel:** `modules/sumup/tests/fixtures/t2med-beispielrechnung.pdf` (vollständig erfunden, Aufbau der T2med-Liquidation, erzeugt von `make-t2med-beispielrechnung.py`); Hinweise in `modules/sumup/tests/fixtures/README.md`.
- **Tests:** `tests/run-all.sh`; PHP-Tests der Basis in `tests/php` (`lib.php` mit `Env`, `browser.php` mit `Browser` und `admin_browser`, `verbindungen_hilfe.php`); Kasse in `modules/sumup/tests` (je Datei `php modules/sumup/tests/<name>.php`).
- **Bildschirmfotos und Vorschau:** `scripts/bildschirmfotos.php` erzeugt aus der Testumgebung (erfundene Daten) die Seiten der Basis als HTML; `scripts/kasse-vorschau/` startet die Kasse lokal mit erfundenen `KIENZLE_*`-Werten (`php -S 127.0.0.1:8099 -t … router.php`), Aufnahme mit `google-chrome --headless=new --no-sandbox --screenshot`. Anleitung in `docs/screenshots/README.md`.

### Nicht auf echter Hardware geprüft (der Nutzer testet auf der VM)

- Kasse: Seite `/kasse/vorgaenge` (Klasse `Vorgaenge`, Tag/Woche/Monat/Jahr, Abhaken, Excel `vorgaenge-export.php`; ersetzt Rechnungseingangsliste, Steuer-Excel und Abhakliste der Einstellungen; Rechte `kassieren` = Tag/Woche, `periods` = Monat/Jahr, `export`, `paid`; `TaxExport` ist nur noch Datenschicht), Storno auch für offene Überweisungen, Filter T2med/IGeL - noch nicht auf der VM geprüft.
- Kasse: Storno (neue Stornonummer je Storno, Original bleibt; keine automatische Rückzahlung über SumUp), Nummernformat der IGeL-Rechnungen (`DocNumbers`), Reiter Bezahlt/Zurückgestellt - alles noch nicht auf der echten VM geprüft.
- Kasse-Startseite (eingebettete Kassenansicht, Direktknöpfe an Rechnungen, Per Post, Tageswerte), Einrichtung als Ausklappzeilen, roter Zähler am Zahnrad.
- Rahmen der Suite für Modulseiten, Menü-Kopfzeile, Mein Konto mit Avatar, Zahlencode per E-Mail (echter Mailversand, z. B. Gmail), Rechte-Matrix.
- Wirkt die Durchreich-PPD des virtuellen Druckers (Basis 0.21.3)? Prüfen: `ipptool -tv ipp://localhost:631/printers/SumUp-Rechnung get-printer-attributes.test | grep document-format-supported` (kein `pwg-raster`/`urf`/`jpeg`).
- Kasse: Überweisung mit EPC-QR, manuelle Kartenzahlung, „Patient zahlt später“, Letterxpress (echt), seven.io-SMS, Logo auf dem Bondrucker.
- T2med: Aufruf-URL `kienzle-sumup://…`; HTTP 403 nach Wechsel des Demo-Schlüssels; Namensauflösung `kienzle.praxis.internal` am Windows-PC.

### Gelöst (zur Erinnerung)

- Terminzettel druckt über den virtuellen Drucker „Terminzettel“; Ausfälle kamen von beschädigter Windows-Druckereinrichtung („Das Handle ist ungültig“): Spooler neu starten, Drucker entfernen, per `http://<Server-IP>:631/printers/<Name>` mit „Microsoft IPP Class Driver“ neu einrichten.
- Virtuelle Drucker nutzen eine Durchreich-PPD (`ppd_text` in `core/lib/kienzle/drucker.py`), damit Windows Text-PDFs statt Bild-PDFs schickt; die Kasse toleriert bei Texterkennung ein um höchstens ein Zeichen abweichend gelesenes Zeichen der Rechnungsnummer.
- Servername geändert → abhängige Einrichtungspunkte stehen wieder rot („Erneut nötig“).

### Offene Ideen (nichts davon ist beauftragt)

- SMS für Passwort-/Einrichtungslink; SMS-Verbindung der Basis an Module durchreichen; E-Mail per Gmail-OAuth.
- Einstellungen der Kasse weiter vereinfachen (Überblicksliste einklappen; „Als bezahlt abhaken“ auch in der rechten Liste der Startseite); Karte „Arbeitsplätze“ in die Basis verschieben; Terminzettel im Rahmen der Suite anzeigen; „Reader suchen“ für SumUp.
- Stammdaten (Konto/IBAN, Steuernummer, USt) in der Verwaltung unter „Praxis“ zusammenführen; weitere Verwaltungsseiten zusammenlegen.
- Updater: SSH-Signaturen unterstützen; Repository später an Thomas übertragen (GitHub: Settings → Transfer ownership; danach Workflows und Release-Schlüssel prüfen); `beta` nach `main` mit Endversionen durch den Nutzer.
