# Praxisherz 2.0 – Architektur

Stand: Konzept; Umsetzung läuft nach `docs/PLAN.md`. Entscheidungen vom 22.11.2026 eingearbeitet (nginx, Updates, Zertifikat, Drucken, Backup-Ziele). Übersicht als Whiteboard:
https://claude.ai/artifact/EGTAPzMGVFBYbxCBUzGjsh (nur mit Freigabe sichtbar)

## Ziel

Eine Praxis-Appliance für Hausarztpraxen mit T2med: **eine Basis**, die einmal installiert wird, und **Module**,
die einzeln dazukommen. Alles wird im Webinterface verwaltet; Firewall, Benutzer, Updates und Sicherung gibt es nur
einmal, in der Basis.

Module (erste Planung): SumUp (Kasse, Rechnungseingang, Post, Ablage), Terminzettel, Fax (Kienzlefax),
Telefon (Kienzlefon), Kienzlezeit (RFID-Zeiterfassung mit Auslagenverwaltung,
[thomaskien/kienzlezeit](https://github.com/thomaskien/kienzlezeit)).

Fax, Telefon und Kienzlezeit sind Projekte von Dr. Thomas Kienzle. Ihre Aufnahme wird mit ihm abgestimmt, bevor
Code dafür entsteht.

## Plattform

- **Ubuntu Server 26.04 LTS** (aktuelles Abbild, derzeit 26.04.1) als einzige unterstützte Basis,
  Sicherheitsupdates bis Mai 2031. Debian 13 wäre technisch gleichwertig; wegen der vorhandenen Installationen bleibt es bei Ubuntu.
- VM auf dem Praxisserver: mindestens 2 Kerne, 4 GB RAM, 100 GB Speicher. Mit Telefon-Transkription mehr RAM einplanen.
- Bekannte Eigenheiten von 26.04, die der Installer bzw. die Modul-Haken einmal lösen:
  - AppArmor sperrt `gs` und `qpdf` außerhalb erwarteter Pfade: lokale Regeln unter `/etc/apparmor.d/local/` je Datenordner.
  - sudo-rs statt sudo: in sudoers keine Platzhalter in Befehlsargumenten verwenden.
  - Python 3.14: jedes Python-Modul bekommt eine eigene virtuelle Umgebung mit festen Versionen (z. B. Obergrenze für `av`).
- Installation: Standard-ISO mit einer `autoinstall`-Datei (Vorlage `installer/autoinstall.yaml`) oder die kleine Start-ISO
  aus `iso/` (verkleinerte Ubuntu-ISO, lädt Ubuntu beim Start; `holen.py` holt danach die signierte Basis aus GitHub).

## Aufbau

```
praxisherz/
  core/                 Basis: Webinterface, Benutzer, Firewall, Updates, Backup, Netzwerkspeicher, Zertifikate
  modules/
    kasse/              Kasse (Kartenzahlung, Rechnungseingang); ab Basis 0.16.0 mitgeliefert, Webpfad /kasse
    terminzettel/
    signatur/
    qm/                 Qualitätsmanagement
    fax/
    telefon/
    kienzlezeit/
  installer/            Erstinstallation, autoinstall-Vorlage
  docs/
```

Ein Repository mit Ordnern (kein Branch je Projekt). Branches nur für stabil/beta. Jedes Modul hat eine eigene Version,
die Basis eine API-Version, gegen die Module geprüft werden.

### Die Basis

| Bereich | Aufgabe |
|---|---|
| Webinterface und Anmeldung | eine Anmeldung für alle Module, Menü aus den installierten Modulen |
| Benutzer, Rollen, Rechte | Admin und Mitarbeiter; Rechte kommen aus den Modulen (Kästchen) |
| Admin-Zugang | Passwort **und** OTP (TOTP, RFC 6238), Wiederherstellungscodes |
| Arbeitsplätze (PCs) | CA-Zertifikat zum Herunterladen; Starter-Paket und Drucker-Einrichtung je Arbeitsplatz kommen aus dem SumUp-Modul |
| Firewall und Netzwerk | nftables, nur Praxisnetz/VPN; Ports kommen aus den Modulen |
| Zertifikat und Zugang | lokale Zertifizierungsstelle auf dem Server (siehe unten), ein Zugang über Port 443 (nginx) mit Pfaden je Modul |
| Einrichtungs-Überblick | eigener Überblick der Basis, darunter die Überblicke der Module |
| Updates | je Modul, Kanal stabil/beta, Sicherung vor dem Update, Rücksprung |
| Module | installieren, aktualisieren, entfernen über Verwaltung → Module (root-Dienst `kienzle-module`, Basis 0.16.0) |
| Netzwerkspeicher | gemeinsamer Dienst für SMB-Ziele (aus der Rechnungsablage der SumUp-Suite) |
| Backup | verschlüsselte Sicherung aller Module auf den Netzwerkspeicher; Export aller Einstellungen als passwortverschlüsselte Datei |
| CUPS, Samba, Protokolle | gemeinsame Dienste; Basis installiert und verwaltet Drucker, Module drucken darauf und melden eigene virtuelle Drucker an |

### Modul-Beschreibung (`module.toml`)

Jedes Modul bringt eine Datei mit, die die Basis liest. Beispiel:

```toml
[module]
id = "kasse"
name = "Kartenzahlung und Rechnungen"
version = "2.0.0"
core_api = "1"

[system]
packages = ["php-fpm", "php-sqlite3", "qpdf", "poppler-utils"]
services = ["kienzle-kasse-worker.service"]
user = "ks-kasse"                 # eigener Systembenutzer
data = "/var/lib/kienzle/kasse"   # eigener Datenordner, 0700

[web]
path = "/kasse"
upstream = "unix:/run/kienzle/kasse/web.sock"
protocol = "fastcgi"                # PHP-Modul über php-fpm
entry = "public/index.php"

# [[firewall]]: Ports, die das Modul selbst anbietet (Beispiel Kienzlefon: port = 8766, proto = "tcp").
# Die Drucker unten erreichen die Arbeitsplätze über Samba/CUPS der Basis, ohne eigenen Eintrag.

[[rights]]
id = "paid"
label = "Bezahlt-Liste abhaken"

[backup]
paths = ["/var/lib/kienzle/kasse"]
snapshot = "bin/backup-snapshot"  # erzeugt eine konsistente Kopie der Datenbank

[[printers]]
name = "vKasse"
label = "T2med-Rechnung an SumUp-Terminal"
ppd = "printer/kasse-rechnung.ppd"
backend = "printer/kienzle-kasse-backend"
share = ["samba", "ipp"]

[[connections]]                   # zentrale Verbindung der Basis, die das Modul nutzt
id = "t2med"
purpose = "Akteneintrag nach erfolgreicher Zahlung"

[setup]
overview = "bin/setup-overview"   # Einrichtungs-Überblick des Moduls als JSON

[hooks]
install = "bin/install"
update = "bin/update"
remove = "bin/remove"
busy = "bin/busy"                 # „gerade beschäftigt“ (z. B. laufende Kartenzahlung): kein Update
health = "bin/health"             # Prüfung nach dem Start
apparmor = ["gs", "qpdf"]         # Programme, die Zugriff auf den Datenordner brauchen
```

Das vollständige Format mit allen Prüfregeln steht in `docs/MODULE-TOML.md` (entsteht in Meilenstein 1, `docs/PLAN.md`).
Gelesen wird `module.toml` nur vom Prüfprogramm der Basis; das Webinterface liest dessen geprüfte Ausgabe.

## Zugänge zu anderen Diensten

Regel (entschieden am 22.11.2026): **Was mehrere Module brauchen oder zur Praxis als Ganzes gehört, verwaltet die
Basis zentral. Ein Zugang, den nur ein Modul nutzt, bleibt im Modul.**

| Zugang | Wer braucht ihn | Wo |
|---|---|---|
| T2med (FHIR: Server-Adresse, erlaubte Start-Adressen, API-Schlüssel, T2med-Zertifikat) | SumUp, voraussichtlich Fax, Telefon, Terminzettel | zentral |
| E-Mail-Versand (SMTP) | SumUp-Belege, Meldungen der Basis, später Fax | zentral |
| Netzwerkspeicher | Ablage, Sicherung | zentral |
| Letterxpress | SumUp | im Modul |
| SumUp-API | SumUp | im Modul |
| Google (Sprachausgabe) | Telefon | im Modul |

- Die Basis hat eine Seite „Verbindungen“: eintragen, Verbindung testen, Passwörter verschlüsselt, nach dem Speichern
  nie mehr angezeigt.
- Ein Modul meldet in `module.toml` an, welche zentrale Verbindung es nutzt (`[[connections]]`, mit Zweck).
  Nur diese Module bekommen die Zugangsdaten, lesbar nur für ihren Systembenutzer.
- Modul-eigene Zugänge speichert das Modul mit demselben Verschlüsselungsverfahren der Basis, aber mit eigenem
  Schlüssel: Die Basis und Admins anderer Module können sie nicht lesen.
- Die Zugangstoken je Patientenaufruf liefert T2med weiter beim Start des Moduls mit.

## Zugang, Zertifikat und Drucken

- **nginx** auf Port 443 vor allen Modulen. Jede Anfrage wird per `auth_request` von der Basis geprüft; die Basis
  reicht Benutzer und Rechte als Kopfzeilen `X-Kienzle-*` an das Modul (Unix-Socket) weiter. Gleichnamige Kopfzeilen
  von außen verwirft nginx immer.
- **Lokale Zertifizierungsstelle** ohne externe Stelle:
  - Der Installer erzeugt auf dem Server eine Stamm-CA (ECDSA P-256, 30 Jahre). Ihr Schlüssel liegt nur für root lesbar.
  - Die CA hat eine **Namensbeschränkung** auf die Namen und privaten Netze der Praxis; selbst mit gestohlenem Schlüssel
    lassen sich keine Zertifikate für fremde Seiten ausstellen.
  - Das Server-Zertifikat gilt höchstens 825 Tage (Vorgabe von macOS/iOS) und wird automatisch erneuert.
  - Arbeitsplätze vertrauen der CA einmal (Download mit Fingerabdruck, später über den Starter). Starter pinnen die
    CA statt des Server-Zertifikats.
  - Die CA ist Teil der Sicherung.
- **Drucken:** CUPS und Samba installiert und konfiguriert die Basis aus festen Vorlagen. Samba nur als Druckserver,
  nur SMB 3, ohne Dateifreigaben. Warteschlangen legt nur ein root-Dienst der Basis an; kein Modul ist in der Gruppe `lpadmin`.
  Module drucken mit `lp` und melden virtuelle Drucker über `[[printers]]` an.

## Updates

Freigegeben am 22.11.2026. Ablauf je Teil (Basis oder Modul):

1. **Signierte Releases:** je Teil ein Archiv mit `manifest.json` (Teil, Version, Kanal, `core_api` bzw. Mindestversion
   der Basis, SHA-256 jeder Datei) und Signatur, veröffentlicht als GitHub-Release (`core-v…`, `<modul>-v…`;
   Beta = Vorabversion).
   - Signiert wird mit einem eigenen Ed25519-Schlüssel (OpenSSL), **ohne externe Zertifizierungsstelle**:
     Der Installer bringt den öffentlichen Schlüssel mit, sein Fingerabdruck steht in der Dokumentation.
   - Der private Schlüssel bleibt offline beim Herausgeber, nicht in GitHub Actions. Ein Reserveschlüssel liegt im Tresor.
   - Ein Schlüsselwechsel geht nur über ein Release, das mit einem vertrauten Schlüssel signiert ist.
2. **Prüfung vor dem Auspacken:** Signatur, Teil, Kanal, nur neuere Versionen, Prüfsummen, `module.toml`,
   API-Verträglichkeit.
3. **Versionsordner:** `/opt/kienzle/<teil>/releases/<version>/`, aktiv über den Verweis `current`.
   Die letzten drei Versionen bleiben erhalten.
4. **Sicherung vorher:** restic-Sicherung, falls eingerichtet, und immer ein lokaler Daten-Schnappschuss des Teils.
   Kein Update, solange ein Modul über `hooks.busy` „beschäftigt“ meldet.
5. **Umschalten:** Dienste stoppen, Migration, Verweis atomar umsetzen, starten.
6. **Prüfung nach dem Start:** Dienste aktiv und Gesundheitsprüfung (`/_health` bzw. `hooks.health`) binnen 60 Sekunden.
7. **Rücksprung** bei Fehler oder abgebrochenem Update: alter Verweis, Daten-Schnappschuss zurück, Start, Meldung in der Glocke.
   Von Hand ist der Rückweg auf eine der aufbewahrten Versionen möglich, mit Hinweis auf möglichen Datenverlust.
8. **Signaturprüfung wählbar** (Wünsche vom 22.11.2026): vorerst Vorgabe „ohne Signaturprüfung“; ein Basis-Admin kann
   „nur Beta ohne Signatur“ oder „immer prüfen“ wählen, sobald die Releases signiert werden. Prüfsummen bleiben, eine vorhandene falsche Signatur wird immer abgelehnt.

Ablauf für den Herausgeber: `docs/RELEASE-ABLAUF.md`.

## Sicherheitskonzept

Praxisherz verarbeitet Gesundheitsdaten (Art. 9 DSGVO): Namen, Geburtsdaten, Rechnungen mit Leistungen, Faxe,
Anrufe und Transkripte, dazu Zugangsdaten zu Zahlungs- und Postdiensten. Kienzlezeit verarbeitet Beschäftigtendaten
(Arbeitszeiten, Urlaub, Krankheit, Auslagen mit Bankverbindung für GiroCodes); diese sind nur für Admins von Kienzlezeit
sichtbar, nicht für Admins anderer Module.
Grundsatz: **so wenig Daten wie möglich, so wenig Rechte wie möglich, nichts aus dem Internet erreichbar.**

### Netz

- Kein Dienst aus dem Internet erreichbar. Zugriff nur aus dem Praxisnetz und optional aus dem VPN.
- Firewall standardmäßig zu; Module melden ihre Ports an, der Admin schaltet sie frei. Schutz vor Aussperren
  (das anfragende Gerät muss freigegeben sein).
- Geräte im Praxisnetz, die Praxisherz ansprechen (RFID-Terminals von Kienzlezeit, Drucker), bekommen nur ihren
  Endpunkt frei; Terminals sprechen HTTPS mit Zertifikatsprüfung, HTTP nur als bewusst gewählte Ausnahme.
- Ausgehend nur, was ein Modul braucht (SumUp-API, Letterxpress, Paketquellen); optional als Freigabeliste.
- TLS für alles, auch intern. Die Starter auf den Arbeitsplätzen prüfen das Zertifikat fest (kein Vertrauen auf Zuruf).

### Anmeldung und Rechte

- Eine Anmeldung in der Basis; die Basis prüft die Sitzung und reicht Benutzer und Rechte an das Modul weiter
  (Reverse-Proxy). Module prüfen Rechte selbst noch einmal.
- **Admin nur mit Passwort und OTP oder mit Passkey.** Ein Passkey (WebAuthn, nur mit Nutzerprüfung am Gerät) ersetzt
  Passwort und OTP; OTP bleibt für Admins als Rückfall Pflicht. Wiederherstellungscodes werden einmal angezeigt und zum Ausdrucken angeboten.
  Zurücksetzen nur lokal an der Server-Konsole (root).
- Mitarbeiter mit eigenen Konten und Rechten je Modul, ohne Zugriff auf Schlüssel und Einstellungen.
- **Eine Anmeldung (SSO) für alle Module:** Module haben keine eigene Anmeldung; Schnittstelle in `docs/MODUL-SCHNITTSTELLE.md`.
  Dienste mit eigener Anmeldung (Telefonanlage, NAS, …) melden über die Basis als **OpenID-Connect-Anbieter** an
  (seit Basis 0.11.0, Verwaltung → Dienste): Code-Ablauf mit PKCE, ID-Token ES256, nur eingetragene Dienste und
  Weiterleitungsadressen, Zugang je Dienst auf Admins oder auf Benutzer eines Moduls beschränkbar.
- Admin-Rechte je Modul: wer die Kasse verwaltet, sieht nicht automatisch Arbeitszeiten und umgekehrt.
- Kienzlezeit kennt den passwortlosen Mitarbeiter-Login per RFID-Karte am Terminal. Dieser bleibt auf
  Kienzlezeit beschränkt und gibt keinen Zugang zu anderen Modulen.
- Sitzungen mit einstellbarer Abmeldezeit, „Gerät merken“ nur aus dem Netz der Anmeldung, widerrufbar.
  Heikle Aktionen fragen einmal je 24 Stunden erneut nach Passwort und OTP.
- Sperre nach Fehlversuchen je Absender, wachsend; CSRF-Schutz, Origin-Prüfung, sichere Cookies.

### Trennung der Module

- Jedes Modul läuft als eigener Systembenutzer, mit eigenem Datenordner (0700) und eigener Datenbank.
- Jedes Modul hat einen eigenen Socket-Ordner `/run/kienzle/<id>/` (0750, Gruppe `www-data`): nur das Modul und nginx kommen
  hinein, kein anderes Modul kann es an der Anmeldung vorbei ansprechen.
- systemd-Härtung je Dienst (`NoNewPrivileges`, `ProtectSystem=strict`, `ReadWritePaths` nur auf den Datenordner,
  `PrivateTmp`, eingeschränkte Adressfamilien).
- Ein Modul bekommt nur seine eigenen Geheimnisse. Root-Aktionen (Firewall, Drucker, Updates) laufen nur über
  geprüfte Anfragedateien an kleine root-Dienste, wie heute bei Firewall und Update.

### Geheimnisse und Daten

- Zugangsdaten verschlüsselt gespeichert, Schlüssel nur für root und den Modul-Benutzer lesbar, nie im Webinterface angezeigt.
- Optional Festplattenverschlüsselung der VM (Ubuntu 26.04 unterstützt TPM-gestützte Verschlüsselung; in der VM über vTPM).
- Datensparsamkeit: an externe Dienste nur, was nötig ist (SumUp: Betrag und Referenz, keine Patientendaten).
  Für Letterxpress und Netzwerkspeicher sind Auftragsverarbeitungsverträge nötig.
- Löschfristen je Modul einstellbar (Rechnungen: steuerliche Aufbewahrung; Faxe, Aufnahmen, Transkripte: kurz).
- Protokolle ohne Patientendaten; ein Änderungsprotokoll hält fest, wer wann welche Einstellung geändert hat.

### Updates und Lieferkette

- Updates nur aus dem eigenen Repository als signierte Releases; Sicherung vor jedem Update, Prüfung nach dem
  Start, Rücksprung bei Fehler (Abschnitt „Updates“).
- Automatische Sicherheitsupdates des Systems (unattended-upgrades), mit Warten auf die Paketsperre.
- Feste Versionen für Python- und PHP-Abhängigkeiten der Module.

### Tests und Screenshots

- Nur erfundene Testdaten. Keine echten Namen, IP-Adressen oder Zugangsdaten in Code, Tests, Screenshots, Videos.
- Sicherheitstests je Modul (Rechte, CSRF, Pfade, Eingaben) laufen vor jeder Version.

## Backup

**Entscheidung: restic** als Grundlage der Datensicherung für alle Module, Ziel Netzwerkspeicher (NAS).

### Warum restic

- Alle Daten im Repository werden mit AES-256 (CTR) verschlüsselt und mit Poly1305-AES gegen Veränderung
  gesichert; der Schlüssel wird aus dem Passwort mit scrypt abgeleitet. Verschlüsselt sind auch Datei- und
  Ordnernamen (wichtig, weil Dateinamen z. B. abgelegter Rechnungen Patientennamen enthalten können). Bei
  AES-verschlüsselten ZIP-Archiven bleiben die Dateinamen lesbar.
- Jeder Block ist über seine SHA-256-Prüfsumme adressiert; `restic check` (mit `--read-data-subset` bzw.
  `--read-data`) erkennt Beschädigung oder Manipulation. Seit 0.16.4 prüft restic Daten zusätzlich vor dem Hochladen.
- Inkrementell mit Deduplizierung und Kompression: viele Versionen bei wenig Platz.
- Seit Jahren verbreitet, quelloffen, reproduzierbare Programmdateien, in Ubuntu enthalten, auch für Windows und
  macOS verfügbar (Notfall-Wiederherstellung ohne Praxisherz).
- Es gibt kein formales Sicherheitsaudit; eine Durchsicht der Kryptografie durch Filippo Valsorda (2017) fand
  das Design gut, mit der Anmerkung, dass die Kombination AES-CTR + Poly1305-AES ungewöhnlich ist.

Von Kienzlezeit (`kienzlezeit-backup-und-restore.sh`) werden übernommen: geprüfte Wiederherstellung,
zeitgesteuerter Ablauf, SSH/SMB-Anbindung. Die verschlüsselte ZIP-Ausgabe bleibt als **Export** (z. B. Monatsstand
für Steuerberatung oder Prüfung), nicht als Datensicherung.

### Ablauf

1. **Konsistenter Schnappschuss:** Jedes Modul liefert über seinen `backup.snapshot`-Haken eine stimmige Kopie
   seiner Daten (SQLite z. B. per `VACUUM INTO`). Gesichert werden Datenordner und Konfiguration, keine Programme.
2. **Verschlüsselung auf dem Server:** Repository-Passwort wird bei der Einrichtung zufällig erzeugt (mindestens
   32 Zeichen), liegt nur für root lesbar auf dem Server und auf dem ausgedruckten **Wiederherstellungsblatt**
   (Praxistresor). Nie auf dem NAS, nie im Webinterface nach der Einrichtung.
3. **Ziel:** NAS mit eigenem Benutzer, der nur den Backup-Ordner sieht. Kein Gastzugriff.
   - **Empfohlen: SFTP** mit eigenem SSH-Schlüssel und fest geprüftem Server-Schlüssel des NAS.
   - Optional: restic-REST-Server im Nur-Anfügen-Modus.
   - Nur wenn das NAS kein SFTP kann: SMB 3.1.1 mit Verschlüsselung über `mount.cifs`, eingehängt nur für die Dauer
     der Sicherung. Den SMB-Treiber von rclone verwenden wir bewusst nicht, weil er weniger erprobt ist.
4. **Schutz vor Erpressungstrojanern:** schreibgeschützte Schnappschüsse des Backup-Ordners **auf dem NAS**
   (z. B. täglich, 30 Tage); wo möglich zusätzlich restic-REST-Server im Nur-Anfügen-Modus. Ein Angreifer auf dem
   Praxisserver kann dann Sicherungen nicht endgültig löschen.
5. **Zweite Kopie außer Haus** (3-2-1-Regel), optional: wechselnde USB-Platte oder Speicher mit Löschsperre in der
   EU. Auch dort nur verschlüsselte Daten.
6. **Zeitplan:** täglich nachts; Aufbewahrung einstellbar, Vorgabe 7 täglich, 4 wöchentlich, 12 monatlich,
   3 jährlich; Aufräumen (`forget --prune`) wöchentlich.
7. **Prüfen:** wöchentlich `restic check`, monatlich zusätzlich Lesetest eines Teils der Daten und eine
   **automatische Probe-Wiederherstellung** der Datenbanken in einen Testordner mit Unversehrtheitsprüfung.
   Fehler oder eine zu alte letzte Sicherung meldet die Glocke, optional per E-Mail.
8. **Wiederherstellung:** einzelne Dateien oder ein Modul im Webinterface; Totalausfall: neue VM, Basis
   installieren, „Aus Sicherung wiederherstellen“ mit dem Wiederherstellungsblatt. Das Blatt beschreibt auch die
   Wiederherstellung von Hand mit restic.

**Abwägung:** Das Passwort muss auf dem Server liegen, damit die Sicherung nachts ohne Eingabe läuft. Wer den
Server übernimmt, kann die Sicherungen lesen, hat die Daten dann aber ohnehin. Deshalb ist Punkt 4 entscheidend:
Löschen der Sicherung durch einen Angreifer wird durch die NAS-Schnappschüsse aufgefangen.

## Vorgehen

1. Basis aus `hau-si/kienzle-suite-beta` (Branch `rechnungseingang`) herauslösen: Admin und Benutzer (mit OTP neu),
   Zertifikat, Firewall, Updater, Netzwerkspeicher aus der Rechnungsablage, Backup neu, Installer mit CUPS und Samba,
   Druckerverwaltung, Einrichtungs-Überblick der Basis. Meilensteine: `docs/PLAN.md`.
2. SumUp und Terminzettel als erste Module umstellen (`module.toml`, eigener Benutzer, eigener Datenordner,
   Einrichtungs-Überblick je Modul, Arbeitsplatz-Starter im SumUp-Modul). Übernahme bestehender Installationen per Migration.
3. Fax, Telefon und Kienzlezeit als Module aufnehmen – nach Abstimmung mit Thomas.

## Hinweise zur Integration von Kienzlezeit

- Kienzlezeit richtet heute Apache mit `mod_php` und einen eigenen HTTPS-VirtualHost auf Port 443 ein. In Praxisherz
  gehört Port 443 der Basis; Kienzlezeit läuft dahinter als Modul (eigener Pfad, PHP-FPM, eigener Systembenutzer).
- Die M5Dial-Terminals brauchen einen festen, erreichbaren Endpunkt (`rfid-scan.php`); der Pfad muss beim Umzug
  gleich bleiben oder die Firmware-Einstellung angepasst werden.
- Eigene Admin-Konten, Auditprotokoll und verschlüsselte Terminal-Geheimnisse von Kienzlezeit werden auf die
  zentrale Benutzerverwaltung abgebildet, ohne das Auditprotokoll zu verlieren.

## Lizenz

MIT (`LICENSE`), übernommen aus der SumUp-Suite, für Basis und Module dieses Repositorys.
Aus der Beta übernommener Code behält damit seinen Lizenzhinweis.

## Offene Fragen

- Wie Thomas seine Projekte künftig pflegen möchte (in diesem Repository oder eigene Repositories, die als Module eingebunden werden).
- Telefonie: welche Dienste Port-Freigaben ins VPN brauchen und ob Telefonie in derselben VM bleiben soll.
- Google-Sprachausgabe (Telefon): **entschieden am 22.11.2026** – nur für die Erzeugung der Ansagen (feste Texte der
  Praxis, z. B. Öffnungszeiten, Menü). Keine Patientennamen, keine Gesprächsinhalte, keine Rufnummern an Google; die fertigen
  Ansagen liegen als Dateien im Modul. Das Telefon-Modul prüft das beim Einbau (Schritt 2).
