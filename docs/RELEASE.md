# Basis – Release-Notizen

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
