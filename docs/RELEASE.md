# Basis – Release-Notizen

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
