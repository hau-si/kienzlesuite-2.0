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

- Nicht pushen und keine Tags setzen ohne ausdrückliche Zustimmung des Nutzers.
- **Zweige:** Gepusht wird (nach Zustimmung) auf den Zweig `beta`, nie auf `main`. Auf `beta` tragen die geänderten Teile eine Vorabversion
  (`0.20.0-beta.1`, `2.3.0-beta.2`; je Änderung hochzählen). `.github/workflows/beta.yml` veröffentlicht daraus nach grünen Tests
  automatisch eine Vorabversion, die Server im Beta-Kanal selbst erkennen. `main` bekommt Änderungen nur als Stand mit Endversion
  (ohne `-beta`) und einem Tag `<teil>-v<version>` durch den Nutzer (`release.yml`, stabiler Kanal).

## Hinweise für neue Sitzungen (Stand 23.11.2026)

**Stand:** Basis 0.21.3-beta.x, Kasse (`sumup`) 2.3.3-beta.x, Terminzettel 1.2.4-beta.x; Zweig `beta` ist aktuell, `main` steht auf einem älteren Endstand
(Basis 0.19.1). Der Nutzer testet auf einer echten Ubuntu-26.04-VM (Aktualisierung dort: `cd ~/kienzlesuite-2.0 && git pull && sudo bash installer/install.sh`,
Module über den Beta-Kanal). Die Adresse der VM gehört nicht ins Repository.

**Arbeitsweise mit dem Nutzer**
- Selbstständig und sparsam arbeiten, Antworten kurz und auf Deutsch. „Pushe direkt“ heißt: Tests dürfen entfallen (nur `php -l`/Syntaxprüfung), das aber in der Antwort sagen.
- Commits als `git -c user.name="Haucks" -c user.email="320781978+hau-si@users.noreply.github.com" commit` **ohne** `Co-Authored-By`/`Claude-Session`-Zeilen.
- Gepusht wird nur auf `beta`; `main` und Tags macht der Nutzer (Ablauf: `docs/RELEASE-ABLAUF.md`; vorher Endversionen ohne `-beta` auf einem Hilfszweig setzen, per Pull Request nach `main`, dann Tags `core-v…`, `<modul>-v…`, Manifest signieren).
- Der Nutzer arbeitet im GitHub-Webinterface ohne Programmierkenntnisse: Schritte einzeln und mit genauen Menünamen beschreiben. Befehle für den Server als einzelne Blöcke geben; `sudo` fragt nach dem Passwort und verschluckt sonst nachfolgende Zeilen.
- „MFA“ in Gruppen heißt Medizinische Fachangestellte (nicht Multi-Faktor).
- Testläufe: `tests/run-all.sh` und `modules/sumup/tests/run-all.sh` dauern über zwei Minuten (Tool-Timeout hoch setzen). Nie `tail` am Ende einer Pipeline verwenden, wenn der Exit-Code zählt.

**Gelöst und bestätigt (VM bzw. Windows-PC des Nutzers)**
- Terminzettel von T2med über den virtuellen Drucker „Terminzettel“ druckt auf dem Bondrucker. Ursache des Ausfalls war eine beschädigte Windows-Druckereinrichtung („Das Handle ist ungültig“), nicht der Server. Abhilfe: Spooler neu starten, Drucker entfernen, per `http://<Server-IP>:631/printers/<Name>` mit „Microsoft IPP Class Driver“ neu einrichten (PowerShell-Skript steht in Basis → Drucker, Terminzettel- und Kasse-Einstellungen).
- Basis 0.21.3: Die CUPS-Warteschlangen der virtuellen Drucker nutzen eine mitgelieferte Durchreich-PPD (`ppd_text` in `core/lib/kienzle/drucker.py`, nur PDF/PostScript, Papierformat je Modul über `paper` in `module.toml`) statt `-m everywhere`. Grund: Mit „everywhere“ bot CUPS Rasterformate an, Windows schickte Bild-PDFs ohne Textebene, die Kasse musste per Texterkennung lesen („Rechnungsnummer nicht eindeutig (Texterkennung)“). Echte Drucker bleiben bei „everywhere“.
- Die Kasse toleriert bei Texterkennung eine um höchstens ein Zeichen abweichend gelesene Rechnungsnummer (`T2medInvoice::single`, `ocrKey`).
- „[object Observable]“ vor Fehlermeldungen ist der von T2med gesendete Dokumentname.

**Noch offen / vom Nutzer zu bestätigen (nicht auf echter Hardware geprüft)**
- Wirkt die Durchreich-PPD? Prüfen: `ipptool -tv ipp://localhost:631/printers/SumUp-Rechnung get-printer-attributes.test | grep document-format-supported` (kein `pwg-raster`/`urf`/`jpeg`), danach Rechnung aus T2med drucken. Bleibt die Texterkennung, Druckerfreigabe in Windows neu einrichten und die Auftragsdatei prüfen (`pdffonts`, `pdftotext`).
- „Letzte Druckaufträge“ des Terminzettels blieb leer; `druckprotokoll.txt` gehört `ks-terminzettel` (mit `sudo` lesen). Prüfen, ob das Protokoll geschrieben wird; ippeveprinter protokolliert die Fehlerausgabe des Druckbefehls nur mit `-v`.
- Kasse: Überweisung mit EPC-QR (Bon, PDF), manuelle Kartenzahlung, „Patient zahlt später (Überweisung)“ nach Abbruch, T2med-Wechsel auf Barzahlung. QR einmal mit einer Banking-App scannen.
- T2med: Aufruf-URL durch die Starter-URL `kienzle-sumup://…` ersetzen; HTTP 403 nach Wechsel des Demo-Schlüssels (gespeicherten API-Schlüssel entfernen oder Client-Secret prüfen); Namensauflösung `kienzle.praxis.internal` am Windows-PC (Browser mit „sicherem DNS“, Hosts-Eintrag).
- Ideen ohne Entscheidung: Karte „Arbeitsplätze“ der Kasse in die Basis verschieben; Schaltfläche „Reader suchen“ für SumUp; Updater prüft nur OpenSSL-Release-Schlüssel (SSH-Signaturen wären eine Erweiterung); Repository später an Thomas übertragen (GitHub: Settings → Transfer ownership; danach Workflows und Release-Schlüssel prüfen).

**Bildschirmfotos für die README** (`docs/screenshots/`) entstehen aus den Seiten der Basis mit erfundenen Daten: Testumgebung aus `tests/php/lib.php` und `tests/php/browser.php` (Klasse `Env`, `admin_browser`) mit den echten Modul-Manifesten, Seiten als HTML mit eingebettetem `core/public/assets/core.css` speichern, mit `google-chrome --headless=new --screenshot` aufnehmen, bei Bedarf zuschneiden. Nie Daten der echten VM verwenden.
