# Umsetzungsplan Schritt 1: Basis aus kienzle-suite-beta herauslösen

Stand: abgestimmt am 22.11.2026 (Entscheidungen siehe unten), noch kein Code. Grundlage: `docs/ARCHITEKTUR.md`, `AGENTS.md`.

Quelle für übernommenen Code: `hau-si/kienzle-suite-beta`, **Branch `rechnungseingang`**, Commit `50daac0`
(Version 1.4.1-rechnung.57). Der Branch `main` (1.4.1) enthält Admin, Firewall, Updater und Netzwerkordner
noch nicht. Die Tests `tests/firewall.php`, `tests/updater.php`, `tests/security.php` und
`tests/firewall-apply.sh` dieses Stands laufen grün (PHP 8.3, geprüft am 22.11.2026).
Dateipfade unten ohne Präfix beziehen sich auf diesen Stand.

Der Code steht unter MIT (© Dr. Thomas Kienzle und Mitwirkende). Die Suite 2.0 übernimmt diese Lizenz (`LICENSE`),
übernommene Dateien bleiben damit unter demselben Hinweis.

## Grundentscheidungen

Bestätigt am 22.11.2026: nginx (E2), Updater-Konzept (M4), CUPS und Samba in der Basis (E10).
Arbeitsplatz-Starter und Einrichtungs-Überblicke kommen aus den Modulen (E11).
Restic auf SMB: die robusteste und sicherste Lösung wählen; Ergebnis siehe M6.

| Nr. | Entscheidung | Vorschlag | Begründung |
|---|---|---|---|
| E1 | Sprachen | Webteil der Basis in **PHP** (Paketversion von Ubuntu 26.04), root-Dienste und Prüfprogramm in **Python 3** nur mit Standardbibliothek, Shell nur für dünne Starter | Admin, Firewall-Oberfläche und Updater liegen in PHP vor; die root-Seite der Firewall ist schon Python. Python bringt `tomllib` mit, also kein eigener TOML-Leser. Keine Paketverwaltung (Composer, pip) für die Basis. |
| E2 | Zugang über Port 443 | **nginx** als Reverse-Proxy mit `auth_request`: jede Anfrage an `/<modulpfad>` wird erst von der Basis geprüft. Die Basis setzt dann Benutzer und Rechte als Kopfzeilen, das Modul läuft über einen Unix-Socket. | Apache (wie in der Beta) hat kein vergleichbares `auth_request`. Von außen kommende Kopfzeilen `X-Kienzle-*` werden immer verworfen. |
| E3 | Ablage auf dem Server | Programme: `/opt/kienzle/<teil>/releases/<version>/`, aktiv über den Verweis `/opt/kienzle/<teil>/current`. Konfiguration: `/etc/kienzle/`. Daten: `/var/lib/kienzle/<teil>/` (0700). Sockets: `/run/kienzle/`. | Aufbau für Versionsordner und Rücksprung (M4) gleich von Anfang an. `<teil>` ist `core` oder die Modul-ID. |
| E4 | Datenbank | SQLite je Teil (`/var/lib/kienzle/core/core.sqlite`), Migrationen mit Versionsnummer wie in der Beta (`src/Database.php`) | bewährt, sicherbar per `VACUUM INTO` |
| E5 | Ein Leser für `module.toml` | Nur das Python-Prüfprogramm liest `module.toml`. Es schreibt ein geprüftes Verzeichnis `/var/lib/kienzle/core/modules.json`, und der PHP-Teil liest nur diese Datei. | keine zwei TOML-Leser, die sich auseinanderentwickeln |
| E6 | Tests | PHP-Testskripte im Stil der Beta (`php tests/…php`, ohne PHPUnit), Python mit `unittest`, ein gemeinsamer Aufruf `tests/run-all.sh`. Dazu GitHub Actions mit einem Job im Container `ubuntu:26.04`. | gleiche Arbeitsweise wie bisher; der Container prüft Paketnamen und Programmversionen von 26.04 |
| E7 | root-Aktionen | Wie in der Beta: Das Webinterface legt eine kleine Anfragedatei ab, eine systemd-`.path`-Einheit startet den root-Dienst, und der prüft Eigentümer, Größe und Inhalt streng. **Kein sudo.** | bewährt; umgeht die sudoers-Eigenheiten von sudo-rs ganz |
| E8 | Versionen | `core/VERSION` beginnt bei `0.1.0`, `core_api = "1"`. Jeder Meilenstein erhöht die Basis-Version (0.1 → 0.8). `1.0.0` erst, wenn Basis und SumUp-Modul auf der Test-VM laufen. | `AGENTS.md`: jede Änderung erhöht die Version |
| E9 | TLS im Praxisnetz | **Lokale Zertifizierungsstelle auf dem Server**, ohne externe Stelle (Details in M2) | Browser ohne Warnung auf allen Arbeitsplätzen; nichts muss aus dem Internet erreichbar sein |
| E10 | Drucken | CUPS und Samba installiert und konfiguriert die Basis. Module drucken über die lokalen Warteschlangen und melden eigene virtuelle Drucker in `module.toml` an (`[[printers]]`). Anlegen und Entfernen übernimmt ein root-Dienst der Basis (M8). | ein Druckdienst für alle Module; kein Modul braucht die Gruppe `lpadmin` (in der Beta war der PHP-Dienst Mitglied) |
| E11 | Starter und Überblick | Arbeitsplatz-Starter gehören zum SumUp-Modul (Schritt 2). Jedes Modul liefert seinen eigenen Einrichtungs-Überblick über einen Haken; die Basis zeigt ihren eigenen und die der Module auf einer Seite. | Starter und Überblick stammen aus der SumUp-Suite und sind modulspezifisch |
| E12 | Zugänge zu anderen Diensten | Zentral, was mehrere Module brauchen oder zur Praxis gehört (T2med/FHIR, E-Mail, Netzwerkspeicher); im Modul, was nur ein Modul nutzt (Letterxpress, SumUp-API, Google). Module melden zentrale Verbindungen in `[[connections]]` an. | eine Stelle für Praxis-Zugänge; „ein Modul bekommt nur seine eigenen Geheimnisse“ bleibt gewahrt |

## Prüfung je Meilenstein

Jeder Meilenstein endet mit:

1. `tests/run-all.sh` grün (lokal und in GitHub Actions),
2. Eintrag in `docs/RELEASE.md` mit der Angabe, was **nicht** auf echtem Ubuntu 26.04 bzw. echter Hardware geprüft wurde,
3. einem Commit, danach deiner Freigabe zum Pushen,
4. bei Meilensteinen mit dem Zeichen 🖥 einer kurzen Prüfliste für die Test-VM (Ubuntu 26.04.1) in `docs/VM-TEST.md`.
   Die führst du aus, oder wir gehen sie gemeinsam durch.

Der Installer (M7) wächst ab M2 mit, damit jeder Stand auf der VM installierbar ist. M7 macht ihn vollständig.

---

## M1 – Gerüst `core/`, `module.toml`-Format und Prüfprogramm ✅

**Ziel:** Das Format der Modul-Beschreibung ist festgelegt und wird maschinell geprüft. Alle weiteren Meilensteine bauen darauf auf.

**Ergebnis (prüfbar):**

- Ordner nach `ARCHITEKTUR.md`: `core/`, `modules/`, `installer/`, `tests/`, dazu `core/VERSION` (0.1.0) und `core/API` (1).
- `docs/MODULE-TOML.md`: Referenz aller Felder mit Pflicht/optional, erlaubten Werten und Beispiel.
- `core/lib/kienzle/manifest.py` und Befehl `core/bin/kienzle-modul-pruefen <ordner>`. Ausgabe auf Deutsch,
  Rückgabewert 0 oder 1, auf Wunsch `--json` mit der geprüften, vereinheitlichten Beschreibung.
- Prüfregeln (Auszug):
  - Unbekannte Schlüssel sind ein Fehler (Tippfehler fallen auf).
  - `id` nur `[a-z][a-z0-9-]{1,30}`, `version` nach SemVer, `core_api` von dieser Basis unterstützt.
  - `user` muss `ks-<id>` sein, `data` genau `/var/lib/kienzle/<id>`, `upstream` nur `unix:/run/kienzle/<id>.sock`.
  - `web.path` nach dem Muster `/[a-z0-9-]+`, nicht reserviert (`/anmelden`, `/verwaltung`, `/_auth`, `/_health`, `/api` …).
  - Firewall-Ports 1–65535, `tcp`/`udp`, Bereiche erlaubt; 443 und SSH bleiben der Basis vorbehalten.
  - `rights`: IDs eindeutig, Bezeichnung vorhanden.
  - `backup.paths` nur innerhalb des Datenordners.
  - Haken (`hooks.*`, `backup.snapshot`): relative Pfade im Modulordner, ohne `..` und Verweise, ausführbar.
  - `hooks.apparmor` nur aus einer Freigabeliste (vorerst `gs`, `qpdf`).
  - Paket- und Dienstnamen per Muster geprüft, Dienste nur `kienzle-<id>-*.service`.
- Zusätzliche Felder gegenüber dem Beispiel in `ARCHITEKTUR.md` (Ergebnis der Abstimmung):
  - `hooks.busy`: meldet „gerade beschäftigt“, zum Beispiel bei einer laufenden Kartenzahlung; sperrt Updates (M4).
  - `hooks.health`: Gesundheitsprüfung nach dem Start (M4).
  - `[setup] overview`: Haken, der den Einrichtungs-Überblick des Moduls als JSON liefert
    (Punkte mit Titel, Zustand erledigt/offen/Fehler, Hinweis, Link). Die Basis zeigt ihn an (E11).
  - `[[printers]]`: virtuelle Drucker des Moduls mit Name, Beschreibung, PPD, CUPS-Backend und Freigabe per Samba
    bzw. IPP. Name nach dem Muster `[A-Za-z0-9_-]{1,40}`, Dateien im Modulordner (E10, M8).
- Prüfung über alle Module hinweg (`--alle modules/`): keine doppelten IDs, Pfade, Benutzer oder Ports.
- `modules/beispiel/`: ein minimales Testmodul ohne Funktion, nur für Tests.
- `tests/run-all.sh` und `.github/workflows/pruefung.yml`.

**Übernahme aus der Beta:** keine Programmzeilen. Beispiel und Felder kommen aus `ARCHITEKTUR.md`.
Die Musterprüfungen (Portbereiche, Namen) folgen `scripts/firewall-apply.py` (`clean_custom`, `clean_services`).

**Tests:** `tests/core/test_manifest.py` geht vom gültigen Beispielmodul aus und verändert je Regel genau eine Stelle
(statt vieler einzelner Testdateien), dazu Dateiprüfungen im Modulordner und Konflikte zwischen zwei Modulen.

**Stand:** erledigt mit Basis 0.1.0 (22.11.2026); 0.1.1 ergänzt `[[connections]]` (E12).

---

## M2 – Anmeldung, Benutzer und Rechte inkl. Admin-OTP und Passkeys 🖥 ✅

**Ziel:** Eine Anmeldung für alle Module, mehrere Benutzerkonten, Rechte je Modul. Admins melden sich nur mit Passwort **und** TOTP an.

**Ergebnis (prüfbar):**

- Web-Gerüst der Basis: Anmeldeseite, Startseite mit Menü aus `modules.json`, Verwaltung „Benutzer“, Änderungsprotokoll.
- **Benutzer und Rollen** (Datenbankversion 1 der Basis):
  - Konten mit eigenem Passwort (Argon2id).
  - Rolle *Basis-Admin* (Firewall, Updates, Sicherung, Benutzer).
  - *Modul-Admin* je Modul: wer die Kasse verwaltet, sieht nicht automatisch Kienzlezeit.
  - *Mitarbeiter* mit den angekreuzten Rechten aus `[[rights]]` der Module.
- **OTP für alle Admin-Rollen:**
  - TOTP nach RFC 6238 (SHA-1, 6 Stellen, 30 s, ±1 Zeitfenster), ein Code wird nur einmal angenommen.
  - Das Geheimnis wird mit libsodium verschlüsselt gespeichert, der Schlüssel liegt nur für root und `ks-core` lesbar unter `/etc/kienzle/`.
  - Einrichtung per QR-Code (Paket `qrencode`, SVG) oder Code zum Abtippen.
- **Wiederherstellungscodes:** 10 Codes, nur als Prüfsumme gespeichert, je einmal gültig. Sie werden einmal angezeigt, mit Druckseite.
- **Zurücksetzen nur an der Konsole:** `sudo kienzle-admin zuruecksetzen <benutzer>` setzt OTP und Passwort zurück und gibt einen einmaligen Einrichtungslink aus.
- **Ersteinrichtung:** Der Installer gibt einen einmaligen Link aus; über ihn wird der erste Admin angelegt, mit Pflicht-OTP.
- **Sitzungen:**
  - Leerlauf- und Höchstdauer einstellbar.
  - „Gerät merken“ gilt nur aus dem Netz der Anmeldung, ist einsehbar und widerrufbar.
  - Heikle Aktionen verlangen einmal je 24 Stunden erneut Passwort und OTP.
- **Schutz:** Sperre je Absender mit wachsender Dauer plus eine Gesamtbremse, CSRF-Token, Origin-Prüfung,
  Cookies mit `Secure; HttpOnly; SameSite=Strict`, Kopfzeilen für CSP und `frame-ancestors`.
- **Weitergabe an Module:** `docs/MODUL-SCHNITTSTELLE.md` beschreibt `/_auth` und die Kopfzeilen
  `X-Kienzle-Benutzer`, `X-Kienzle-Rollen`, `X-Kienzle-Rechte`. nginx entfernt diese Kopfzeilen aus jeder Anfrage von außen.
  Module prüfen die Rechte noch einmal; dafür gibt es eine kleine PHP-Hilfsdatei.
- nginx-Vorlage und php-fpm-Pool `ks-core`, systemd-Einheiten gehärtet (`NoNewPrivileges`, `ProtectSystem=strict`, …).
- **Lokale Zertifizierungsstelle (E9).** Sie gehört schon in M2, damit HTTPS von Anfang an mit dem endgültigen Zertifikat läuft.
  - Der Installer erzeugt auf dem Server eine Stamm-CA „Kienzle-Suite <Praxisname>“ (ECDSA P-256, 30 Jahre).
    Ihr privater Schlüssel liegt nur für root lesbar unter `/etc/kienzle/ca/` und wird nur zum Ausstellen benutzt.
  - Die CA trägt eine **Namensbeschränkung** (`nameConstraints`) auf die Namen und Adressen der Praxis
    (Servername, `*.praxis.internal` o. Ä., die privaten Netze aus der Firewall). Selbst mit gestohlenem CA-Schlüssel
    lassen sich damit keine gültigen Zertifikate für fremde Seiten wie Banken ausstellen.
  - Daraus ein Server-Zertifikat für nginx (Servername, IP-Adressen), höchstens 825 Tage gültig, wie macOS und iOS
    es verlangen. Ein Timer erneuert es 60 Tage vor Ablauf automatisch.
  - Die Arbeitsplätze vertrauen der CA einmal. Das CA-Zertifikat steht unter `/ca.crt` zum Herunterladen, mit
    SHA-256-Fingerabdruck zum Vergleich auf der Server-Konsole. Später verteilt es der Starter des SumUp-Moduls mit.
  - Die Beta pinnt heute den Fingerabdruck des Server-Zertifikats (`client/main.go`, `tests/tls-pin.py`).
    Mit der CA pinnt der Starter stattdessen die CA, sodass ein erneuertes Server-Zertifikat den Starter nicht bricht.
  - Die CA ist Teil der Sicherung (M6), damit nach einem Totalausfall die Arbeitsplätze nicht neu eingerichtet werden müssen.
  - Werkzeug: `openssl` aus Ubuntu, Aufruf nur über den root-Dienst `kienzle-zertifikat`.

**Übernahme aus der Beta:**

| Datei | Verwendung |
|---|---|
| `src/Admin.php` | Sitzungen (`open`, `session`, gleitende Verlängerung, Höchstdauer), „Gerät merken“ mit Netzbindung (`net`, `devices`, `revoke`, `describeAgent`), Bestätigung je 24 h (`confirmedRecently`), Einrichtungslink (`createSetupToken`, `setup`), Passwortregeln (`checkPassword`, `weak`), Gesamtbremse (`failed`). **Neu gebaut** wird das Kontenmodell: Die Beta kennt nur ein Admin- und ein Mitarbeiter-Passwort. |
| `src/Throttle.php` | unverändert bis auf den Namensraum |
| `src/AuditLog.php` | als Grundlage des Änderungsprotokolls, erweitert um Benutzername und geänderte Einstellung (ohne Werte) |
| `src/Database.php`, `src/Problem.php`, `src/Command.php`, `src/bootstrap.php` | Grundgerüst |
| `src/SettingsPage.php`, `public/settings*.php`, `public/assets/settings.js`, `style.css` | Teile der Oberfläche (Zugang, gemerkte Geräte, Bestätigungsdialog). Aufgeteilt, da `settings.js` mit 96 KB SumUp-Teile enthält. |
| `tests/security.php`, `tests/settings.php` | Muster für Sicherheitstests (CSRF, Sperre, Sitzungen) |

**Tests:**

- TOTP gegen die Testwerte aus RFC 6238 Anhang B, Zeitfenster und Wiederholungsschutz.
- Wiederherstellungscodes: einmal gültig, danach ungültig.
- Sperren nach Fehlversuchen, Ablauf und Netzbindung der Sitzungen, CSRF und Origin.
- Rechte-Trennung: Ein Kassen-Admin bekommt bei `/_auth` keine Kienzlezeit-Rechte; Mitarbeiter erreichen keine Verwaltung.
- Gefälschte `X-Kienzle-*`-Kopfzeilen werden verworfen: Test mit echtem nginx im CI-Container.
- Geheimnisse erscheinen nie in Antworten oder Protokollen.

Zusätzliche Tests für die CA:

- erzeugtes Server-Zertifikat wird gegen die CA geprüft (`openssl verify`),
- ein Zertifikat für einen Namen außerhalb der Namensbeschränkung wird abgelehnt,
- Erneuerung vor Ablauf, Rechte auf den Schlüsseldateien.

**Passkeys** (Wunsch vom 22.11.2026): WebAuthn ohne Fremdbibliothek, nur mit Nutzerprüfung am Gerät; ersetzt Passwort und
OTP bei Anmeldung und Bestätigung; Admins behalten OTP als Rückfall; nur über den Servernamen.

**Stand:** umgesetzt mit Basis 0.2.0 (22.11.2026). Abweichungen vom Plan: eigene php-fpm-Instanz je Teil statt des
php-fpm-Dienstes der Distribution (Härtung je Dienst); Socket-Ordner je Teil; Konsole `kienzle-admin`.

**VM:** Installation, CA auf einem Windows- und einem macOS-Arbeitsplatz einspielen (Browser ohne Warnung, auch
Prüfung der Namensbeschränkung), Ersteinrichtung mit OTP-App, Anmeldung, Mitarbeiterkonto, Zurücksetzen an der Konsole.

---

## M3 – Firewall 🖥 ✅

**Ziel:** Die Firewall der Beta, verallgemeinert: Ports kommen aus den installierten Modulen statt aus einer festen Liste. Standardmäßig ist alles zu.

**Ergebnis (prüfbar):**

- Eigene nftables-Tabelle `inet kienzle`, andere Regeln des Servers bleiben unberührt.
- Immer freigegeben aus den Praxisnetzen: 443 (Basis). SSH ist wählbar. Modul-Ports aus `modules.json` schaltet der Admin einzeln frei.
- **Neu gegenüber der Beta:** Richtlinie `drop` als Vorgabe (Beta: `accept`, nur verwaltete Ports gesperrt). Der bisherige Modus bleibt als Wahl „nur verwaltete Ports sperren“.
- Schutz vor Aussperren bleibt:
  - Das anfragende Gerät muss in einem freigegebenen Netz liegen.
  - Ohne Bestätigung binnen 120 s werden die alten Regeln wiederhergestellt.
  - Beim Start lädt `kienzle-firewall.service` die bestätigten Regeln.
- Kienzlefon-Einträge aus der festen Liste der Beta entfallen. Sie kommen später über die `module.toml` der Module Fax und Telefon.
- Die ausgehende Freigabeliste (`ARCHITEKTUR.md`: optional) ist **nicht** Teil von M3. Vorschlag: nach Schritt 2.

**Übernahme aus der Beta:**

| Datei | Verwendung |
|---|---|
| `scripts/firewall-apply.py` | fast vollständig: `read_small` (kein Symlink, Eigentümer, Größe), `clean_networks`, `clean_custom`, `build_rules` aus festen Vorlagen, `nft -c` vor dem Laden, Rücksprung, `boot()`. Neu: Ports aus `modules.json` statt `SERVICES`, Webport fest 443. |
| `src/Firewall.php` | Prüfung und Aussperrschutz (`validate`, `overview`, `apply`, `confirm`, `status`) |
| `src/Network.php` | unverändert (Schnittstellen, Netze, lauschende Ports) |
| `tests/firewall.php`, `tests/firewall-apply.sh` | übernommen und um die Modul-Ports erweitert |

**Tests:** wie in der Beta (mit nachgebildetem `nft`), dazu Ports aus Modulen, Konflikt mit reservierten Ports,
Richtlinie `drop`, und `nft -c` gegen die erzeugten Regeln im CI-Container (mit sudo).

**VM:** Firewall einschalten, Bestätigung verstreichen lassen (Rücksprung), aussperrendes Netz wird abgelehnt, Neustart lädt die Regeln.

**Stand:** umgesetzt mit Basis 0.3.0 (22.11.2026). Zusätzlich zum Plan: Samba (445) als eigene Option für die Druckfreigabe der Basis.

---

## M4 – Updater: signierte Releases, Versionsordner, Rücksprung 🖥 ✅

Freigegeben am 22.11.2026; steht auch in `ARCHITEKTUR.md`, Abschnitt „Updates“.

**Ziel:** Updates je Teil (Basis oder Modul), die nur aus signierten Releases kommen, in einem eigenen Versionsordner
landen, nach dem Start geprüft werden und bei Fehlern automatisch auf den vorherigen Stand zurückspringen.

**Ablauf:**

1. **Release:** je Teil ein Archiv `<teil>-<version>.tar.gz` und ein `manifest.json`. Das Manifest enthält Teil, Version,
   Kanal, `core_api` bzw. die nötige Mindestversion der Basis und SHA-256 jeder Datei. Dazu eine Signatur
   `manifest.json.sig`. Veröffentlicht als GitHub-Release in `hau-si/kienzlesuite-2.0`; Tags `core-v0.4.0`,
   `sumup-v2.0.0`; Beta = Vorabversion.
2. **Signatur ohne externe Zertifizierungsstelle:** ein eigenes Ed25519-Schlüsselpaar, erzeugt mit `ssh-keygen -t ed25519`.
   Signiert und geprüft wird mit `ssh-keygen -Y sign/verify` (Namensraum `kienzle-release`); openssh ist auf jedem Ubuntu Server vorhanden.
   - Das Vertrauen entsteht **durch den Installer**: Er bringt den öffentlichen Schlüssel mit nach
     `/etc/kienzle/release-keys/allowed_signers`. Danach nimmt der Server nur Releases an, die mit dem
     zugehörigen privaten Schlüssel signiert sind. Eine CA oder ein Dienst von außen ist nicht nötig.
   - Den Installer selbst prüft man einmal von Hand: Der Fingerabdruck des Schlüssels steht in `README.md` und
     `docs/INSTALLATION.md`. Der Installer gibt ihn aus, damit man beide vergleichen kann.
   - Der private Schlüssel bleibt **offline beim Herausgeber**, passwortgeschützt, mit einer Sicherungskopie im Tresor;
     er liegt nicht in GitHub Actions. GitHub Actions baut und prüft das Archiv. Der Herausgeber lädt es herunter,
     signiert es mit `scripts/release-signieren.sh` und lädt die Signatur hoch.
   - Ein **zweiter Schlüssel als Reserve** steht von Anfang an in `allowed_signers` (privat nur im Tresor).
     Bei Verlust oder Diebstahl des ersten signiert der Reserveschlüssel ein Release, das den ersten entfernt.
   - Ein Schlüsselwechsel geht nur über ein Release, das mit einem bereits vertrauten Schlüssel signiert ist.
3. **Prüfen vor dem Auspacken:** Signatur, Teil und Kanal, Version neuer als die installierte (kein Zurückstufen
   von außen), Prüfsummen, `module.toml` mit dem Prüfprogramm aus M1, API-Verträglichkeit.
4. **Versionsordner:** Auspacken nach `/opt/kienzle/<teil>/releases/<version>/` (root, nur lesbar für den Dienst).
   Die laufende Version wird dabei nicht angefasst.
5. **Sicherung:** Ist eine Sicherung eingerichtet (M6), läuft vorher eine restic-Sicherung. Immer gibt es zusätzlich
   einen lokalen Schnappschuss der Daten des Teils über seinen `backup.snapshot`-Haken.
6. **Umschalten:** Dienste des Teils stoppen, Migration bzw. `hooks.update` ausführen, den Verweis `current` atomar umsetzen, Dienste starten.
7. **Prüfung nach dem Start:** binnen 60 s `systemctl is-active` aller Dienste des Teils und `/_health` (Basis)
   bzw. der Gesundheitsendpunkt des Moduls über den Socket.
8. **Rücksprung:** Schlägt Schritt 6 oder 7 fehl, wird `current` zurückgesetzt, der Daten-Schnappschuss
   zurückgespielt und gestartet. Die Glocke meldet es, das Protokoll nennt die Ursache.
   Bricht ein Update mitten im Ablauf ab (Stromausfall), erkennt der Dienst den halben Zustand beim nächsten Start und springt zurück.
9. **Aufräumen:** Die letzten 3 Versionen bleiben. Der Admin kann von Hand auf eine davon zurückgehen,
   mit dem Hinweis, dass Daten seit dem Update verloren gehen, wenn die Datenbank zurückgespielt werden muss.
10. **Sperren:** kein Update, solange ein Modul über `hooks.busy` (M1) „beschäftigt“ meldet, zum Beispiel bei einer laufenden Kartenzahlung.

**Übernahme aus der Beta:**

| Datei | Verwendung |
|---|---|
| `src/Updater.php` | Kanal stabil/beta (`channel`, `setChannel`), Abfrage mit Zwischenspeicher (`check`), Änderungsliste aus `RELEASE.md` (`notes`), Statusanzeige mit gesäubertem Protokoll (`status`), Sperre bei laufender Zahlung (`request`) → verallgemeinert zu `hooks.busy`. Neu: GitHub-Releases statt `raw.githubusercontent.com/…/VERSION` und je Teil statt einer Version. |
| `scripts/self-update.sh` | Ablauf und Absicherung: läuft von einer Kopie, verbraucht die Anfrage, schreibt Status und Protokoll, `curl --proto =https --max-filesize`, Rücksicherung bei Fehler. Wird nach Python umgeschrieben (Signatur- und Manifestprüfung). |
| `tests/updater.php`, `tests/self-update.sh` | Muster für die Tests (lokale Quelle per `file://`, ohne systemd) |
| Glocke (`public/assets/settings.js`) | Anzeige grau/roter Punkt/rot |

**Tests:**

- Fehler, die abgewiesen werden müssen: falscher Schlüssel, veränderte Datei, veränderte Signatur, falscher Teil,
  ältere Version, unverträgliche `core_api`, ungültige `module.toml`.
- Erfolgreiches Update, Fehler in der Migration → Rücksprung, Prüfung nach dem Start schlägt fehl → Rücksprung, abgebrochenes Update → Rücksprung beim nächsten Start.
- Aufräumen auf 3 Versionen, Sperre durch `hooks.busy`.
- Alle Tests mit einem Testschlüssel, der nur in `tests/fixtures/` liegt.

**VM:** Update der Basis von 0.4.0 auf eine Test-0.4.1, absichtlich kaputte 0.4.2 → Rücksprung prüfen.

**Stand:** umgesetzt mit Basis 0.4.0 (22.11.2026) für die Basis. Abweichungen:
- Signatur mit OpenSSL (Ed25519) statt `ssh-keygen -Y`: gleiches Verfahren, testbar ohne openssh, OpenSSL ist für die CA ohnehin da.
- Der neue Release bringt seinen Installer mit; der Updater führt ihn aus (dieselben Schritte wie bei der Erstinstallation)
  und für den Rücksprung den Installer der vorherigen Version.
- Unsignierte Beta-Versionen auf ausdrücklichen Wunsch eines Admins (Wunsch vom 22.11.2026).
- Updates der Module nutzen denselben Ablauf, sobald Module installierbar sind (Schritt 2); `hooks.busy` wird schon geprüft.
- Noch offen: öffentlicher Release-Schlüssel des Herausgebers (`docs/RELEASE-ABLAUF.md`).

---

## M5 – Netzwerkspeicher und Verbindungen 🖥

**Ziel:** Ein gemeinsamer Dienst für Ziele auf dem NAS, dazu die zentralen Verbindungen zu T2med und E-Mail (E12).
Module legen Dateien ab, ohne die NAS-Zugangsdaten zu kennen; T2med- und E-Mail-Zugang bekommen nur Module, die sie anmelden.

**Ergebnis (prüfbar):**

- Verwaltung „Netzwerkspeicher“:
  - Ziele mit Name, Art (SMB oder SFTP), Server, Freigabe bzw. Pfad, Benutzer, Passwort bzw. SSH-Schlüssel.
  - Knopf „Verbindung prüfen“: schreibt, liest und löscht eine Testdatei.
  - Passwörter verschlüsselt, nach dem Speichern nie wieder angezeigt.
- **Kein Mount:** Übertragung per `smbclient` bzw. `sftp` durch den Dienstbenutzer `ks-ablage`, ohne root.
- **Schnittstelle für Module:**
  - Ein Modul legt eine Datei und eine kleine Auftragsdatei (Ziel-Name, Unterordner, Dateiname) in seinen Ordner `ablage-ausgang/`.
  - `ks-ablage` liest über eine Gruppe, überträgt, bestätigt und löscht.
  - Wiederholung bei Fehlern, Platzhalter `{jahr}`/`{monat}`.
  - Dateinamen werden geprüft (keine Pfadteile, kein `..`).
- Beschreibung in `docs/MODUL-SCHNITTSTELLE.md`.
- **Seite „Verbindungen“:**
  - T2med (FHIR): Server-Adresse, erlaubte Start-Adressen, API-Schlüssel, T2med-Zertifikat bzw. Fingerabdruck.
  - E-Mail (SMTP): Server, Port, `starttls`/`smtps`, Benutzer, Passwort, Absender.
  - Je Verbindung „Verbindung prüfen“ (T2med: `/metadata`; E-Mail: Anmeldung am Server ohne Versand oder Testmail an eine einzugebende Adresse).
  - Liste, welches Modul welche Verbindung wofür nutzt (`purpose` aus `[[connections]]`).
- **Weitergabe:** Der root-Dienst schreibt je Modul nur die angemeldeten Verbindungen nach `/etc/kienzle/verbindungen/<id>.json`
  (0640, root:ks-<id>). Ändert der Admin eine Verbindung, werden die Dateien neu geschrieben und die Module benachrichtigt.
- **Modul-eigene Geheimnisse:** kleine Hilfsdatei, mit der Module ihre eigenen Zugänge mit libsodium verschlüsseln;
  Schlüssel je Modul unter `/etc/kienzle/schluessel/<id>.key` (0640, root:ks-<id>), für die Basis nicht lesbar.

**Übernahme:**

| Quelle | Verwendung |
|---|---|
| Beta `src/FhirClient.php`, `src/HttpClient.php`, `src/Config.php` (`[fhir]`) | Prüfung der FHIR-Adressen (feste Basis-URL, Liste erlaubter Start-Adressen), T2med-Zertifikat und Pinning, `/metadata`-Abruf für „Verbindung prüfen“ |
| Beta `src/ReceiptMailer.php`, `scripts/configure.py` (`[mail]`), `src/vendor/phpmailer` | SMTP-Einstellungen und Versand; PHPMailer als Rückfall, Systempaket hat Vorrang |
| Beta `src/InvoiceExport.php` | `smbclient`-Aufruf mit Zugangsdatei (0600, `-A`), schrittweises `mkdir`, Auswertung der `NT_STATUS_*`-Codes, Unterordner mit `{jahr}`/`{monat}`. Der Teil für Rechnungsnamen bleibt im SumUp-Modul. |
| Beta `scripts/export-invoices.php` + Timer im Installer | stündliche Nachholung |
| kienzlezeit `kienzlezeit-backup-und-restore.sh` | Prüfung der Verbindungsangaben (`transport` ssh/smb, Gastzugang), SSH-Aufrufe, Hochladen über `.part` mit anschließendem Umbenennen, Rücklesen und Vergleich der SHA-256-Prüfsumme |

**Tests:** Weitergabe nur an anmeldende Module (ein Modul ohne `t2med` bekommt keine Datei), Rechte der Dateien,
keine Geheimnisse in Antworten und Protokollen, Prüfung der FHIR-Adressen. Für den Netzwerkspeicher: Prüfung der Angaben, Pfad-Angriffe in Aufträgen, Aufbau der `smbclient`- und `sftp`-Befehle, Wiederholung, Geheimnisse nicht im Protokoll.
Im CI-Container zusätzlich ein Durchlauf mit einem echten Samba- und SSH-Server auf `127.0.0.1`.

**VM:** Ablage auf ein Test-NAS bzw. eine Samba-Freigabe auf einer zweiten VM.

---

## M6 – Backup mit restic 🖥

**Ziel:** Verschlüsselte, geprüfte Sicherung aller Teile nach `ARCHITEKTUR.md`, Abschnitt Backup.

**Ergebnis (prüfbar):**

- Root-Dienst `kienzle-sicherung` (Python) mit systemd-Timern: täglich Sicherung, wöchentlich `forget --prune` und
  `restic check`, monatlich `check --read-data-subset` und Probe-Wiederherstellung.
  Aufbewahrung einstellbar, Vorgabe 7/4/12/3.
- **Einrichtung:**
  - Ziel aus M5 wählen.
  - Repository-Passwort zufällig erzeugen (40 Zeichen), Ablage `/etc/kienzle/sicherung/passwort` (root, 0600).
  - Einmal das **Wiederherstellungsblatt** zum Drucken: Passwort, Ziel, Repository-ID, Schritte für die Wiederherstellung von Hand mit restic.
  - Danach zeigt das Webinterface das Passwort nie wieder.
- **Ablauf:**
  - Schnappschuss-Haken je Teil als dessen Benutzer; die Basis sichert ihre Datenbank per `VACUUM INTO`.
  - Gesichert werden `/etc/kienzle` und die Datenordner, keine Programme. Das Repository-Passwort selbst wird nicht gesichert.
  - restic-Tags je Teil.
- **Probe-Wiederherstellung:** Datenbanken in einen Testordner, `PRAGMA integrity_check`, Ergebnis in der Glocke.
  Die Glocke meldet auch eine zu alte letzte Sicherung; E-Mail optional, folgt später.
- **Wiederherstellung im Webinterface:** einzelne Dateien oder ein ganzes Modul (Dienste stoppen, zurückspielen, starten).
- **Totalausfall:** `installer/install.sh --wiederherstellen` (M7) mit den Angaben vom Blatt.
- Nicht Teil von M6, nur beschrieben: Einrichtung der NAS-Schnappschüsse und des REST-Servers am NAS selbst, zweite Kopie außer Haus.

**Ziel-Arten (entschieden: robusteste und sicherste Lösung):**

1. **Empfohlen: SFTP.** restic spricht SFTP selbst, das ist sein ältester und meistgetesteter Weg.
   Die Anmeldung läuft mit einem eigenen SSH-Schlüssel ohne Passwort im Netz. Der Server-Schlüssel des NAS wird bei
   der Einrichtung einmal bestätigt und danach fest geprüft (`known_hosts` je Ziel). Synology, QNAP und andere übliche NAS bieten SFTP an.
2. **Optional: restic-REST-Server im Nur-Anfügen-Modus** (`--append-only`, z. B. als Container auf dem NAS).
   Er ist der stärkste Schutz vor Erpressungstrojanern, weil der Praxisserver selbst keine Sicherungen löschen kann.
3. **Nur wenn das NAS kein SFTP kann: SMB über ein Kernel-Mount nur während der Sicherung.**
   - Der root-Dienst hängt die Freigabe mit `mount.cifs` ein (`vers=3.1.1`, `seal` für Verschlüsselung, `sec=ntlmssp`,
     Zugangsdatei 0600, Einhängepunkt 0700 unter `/run/kienzle/sicherung/`).
   - restic arbeitet dann auf das lokale Verzeichnis; danach wird die Freigabe sofort wieder ausgehängt.
   - Außerhalb der Sicherungszeit ist die Freigabe also nicht erreichbar, auch nicht für einen Angreifer auf dem Server.
   - Verworfen: der SMB-Treiber von rclone. Er ist deutlich jünger als der CIFS-Treiber des Kernels und in Verbindung mit restic wenig erprobt.

Bei SMB und SFTP gilt: Löschschutz nur über die schreibgeschützten Schnappschüsse am NAS (`ARCHITEKTUR.md`, Backup Punkt 4).
Ein **Wartungshinweis** in der Glocke erscheint, solange sie nicht als eingerichtet bestätigt sind.
Der Einrichtungs-Überblick der Basis führt sie als eigenen Punkt.

**Übernahme aus kienzlezeit (`kienzlezeit-backup-und-restore.sh`, `tests/test_backup_restore.py`):**

- geprüfte Wiederherstellung: Manifest mit SHA-256 und Größe je Datei, Vergleich nach dem Auspacken, Wartungsmodus während der Wiederherstellung,
- zeitgesteuerter Ablauf: `/etc/cron.d` → systemd-Timer,
- SSH/SMB-Anbindung über M5,
- Testaufbau mit nachgebildetem Server.

Die verschlüsselte ZIP-Ausgabe bleibt dem späteren Kienzlezeit-Modul als Export vorbehalten.

**Tests:** Mit echtem restic (in GitHub Actions per `apt install restic`, lokales Repository):

- Sicherung, Aufbewahrung, `check`, Probe-Wiederherstellung,
- beschädigtes Repository wird erkannt,
- falsches Passwort,
- Haken eines Moduls schlägt fehl → Meldung, andere Module werden trotzdem gesichert,
- Passwort erscheint nie im Protokoll.

In dieser Arbeitsumgebung ist restic nicht installiert und das Netz für Paketinstallationen gesperrt. Lokal laufen nur die Tests ohne restic; die vollständigen Tests laufen in GitHub Actions.

Im CI-Container zusätzlich: SFTP gegen einen lokalen SSH-Server und restic-REST-Server mit `--append-only`, bei dem Löschen scheitern muss.
SMB per `mount.cifs` braucht Kernel-Rechte; das prüft nur die Test-VM.

**VM:** Einrichtung auf das Test-NAS (SFTP und SMB), Blatt drucken, eine Datei und ein Modul wiederherstellen.

---

## M7 – Installer für Ubuntu 26.04 🖥

**Ziel:** Eine Standard-Installation von Ubuntu Server 26.04.1 wird mit einem Befehl zur Kienzle-Suite (nur Basis). Erneutes Ausführen ändert nichts Unerwünschtes.

**Ergebnis (prüfbar):**

- `installer/install.sh` prüft vorab: Ubuntu 26.04, root, systemd, mindestens 2 Kerne, 4 GB RAM, 100 GB Speicher (nur Warnung), Internet.
- Wartet bis zu 10 Minuten auf die Paketsperre, installiert Pakete und richtet ein:
  - Benutzer `ks-core`, `ks-ablage`,
  - Ordner mit Rechten nach E3,
  - nginx, php-fpm-Pool, lokale CA und Zertifikat (M2),
  - **CUPS und Samba (E10):** CUPS lauscht auf dem Server und ist aus den Praxisnetzen nur über die Firewall-Freigabe
    IPP (631) erreichbar. Samba nur als Druckserver ohne Dateifreigaben und ohne Gastzugriff auf Dateien, nur SMB 3,
    Freigabe 445 ebenfalls über die Firewall. Konfiguration aus festen Vorlagen der Basis; Module ändern `smb.conf` und
    `cupsd.conf` nicht selbst,
  - systemd-Einheiten mit Härtung, root-Dienste mit `.path`-Einheiten, unattended-upgrades,
  - Release-Schlüssel.
- Am Ende: einmaliger Einrichtungslink.
- AppArmor: lokale Regeln unter `/etc/apparmor.d/local/` je Datenordner aus `hooks.apparmor` der Module.
- Optionen:
  - `--admin-reset` (ruft `kienzle-admin` auf),
  - `--wiederherstellen` (Totalausfall aus restic mit den Angaben vom Wiederherstellungsblatt),
  - `--kanal beta`.
- `installer/autoinstall.yaml`: Vorlage für die Standard-ISO, startet am Ende den Installer. Keine eigene ISO.
- `docs/INSTALLATION.md` für die Praxis, `docs/VM-TEST.md` mit der vollständigen Prüfliste.

**Übernahme aus der Beta (`scripts/install-server.sh`):**

- Warten auf die dpkg-Sperre,
- AppArmor-Ergänzung für `gs`/`qpdf` (Zeilen 41–51),
- unattended-upgrades ohne automatischen Neustart (Zeilen 53–58),
- Zertifikatserzeugung,
- systemd-Einheiten für Update und Firewall (Zeilen 363–433) als Vorlage,
- einmaliger Einrichtungslink am Ende.

Aus `terminzettel/install.py` und `terminzettel/setup_config.py`: Prüfen der Samba- und CUPS-Konfiguration vor dem
Übernehmen (`testparm`, `cupsd -t`), Sicherung und Rücknahme der alten Dateien bei Fehlern, Zwischenspeicher im RAM.

Entfällt: der eigene Apache auf Port 7868 und die Parallelbetrieb-Sonderwege.

**Einrichtungs-Überblick (E11):** Am Ende von M7 bekommt die Basis ihre Überblicksseite. Sie zeigt zuerst die Punkte
der Basis: Admin mit OTP, Wiederherstellungscodes gedruckt, CA auf Arbeitsplätzen, Firewall bestätigt, Updates,
Netzwerkspeicher, Sicherung eingerichtet, NAS-Schnappschüsse, Probe-Wiederherstellung. Darunter folgen die Überblicke
der Module über `[setup] overview`. Das SumUp-Modul überträgt seinen heutigen Überblick (Beta: `src/SettingsPage.php`)
in Schritt 2 in dieses Format.

**Tests:**

- `bash -n`, und `shellcheck`, falls vorhanden.
- Durchlauf im Container `ubuntu:26.04` in GitHub Actions. Ohne systemd prüft er nur Pakete, Dateien und Rechte; das Starten der Dienste prüft er nicht.
- Zweiter Durchlauf ohne Änderungen.
- Vollständig nur auf der Test-VM: frische 26.04.1, mit und ohne autoinstall, Wiederherstellung auf einer zweiten, leeren VM.

---

---

## M8 – Druckerverwaltung in der Basis 🖥

**Ziel:** Drucker werden einmal in der Basis eingerichtet, und alle Module nutzen sie (E10).
Danach ist die Basis bereit für das SumUp- und das Terminzettel-Modul (Schritt 2).

**Ergebnis (prüfbar):**

- Verwaltung „Drucker“: Drucker im Netz und an USB suchen, Warteschlange anlegen und entfernen.
  Bondrucker als RAW, A4-Drucker über IPP Everywhere, Testseite.
- Root-Dienst `kienzle-drucker` über eine Anfragedatei (E7). Nur er ruft `lpadmin` auf;
  kein Modul und auch nicht der PHP-Dienst der Basis ist Mitglied der Gruppe `lpadmin`.
- **Virtuelle Drucker der Module** aus `[[printers]]`:
  - Bei Installation und Update des Moduls legt der Dienst Backend und PPD aus dem Modulordner an (root, 0755 bzw. 0644).
  - Die Warteschlange wird angelegt und je nach Angabe per Samba und/oder IPP freigegeben.
  - Beim Entfernen des Moduls wird beides wieder entfernt.
  - Entfernt werden nur Warteschlangen mit der Kennung der Suite.
- **Für Module:** Auswahlliste der Warteschlangen im Webinterface des Moduls über eine Abfrage an die Basis,
  Drucken mit `lp` als Modul-Benutzer.

**Übernahme aus der Beta:**

| Datei | Verwendung |
|---|---|
| `src/PrinterAdmin.php` | Suche, Druckerarten (TM-m10, TM-m30III, A4 über IPP), URI-Prüfung, nur selbst angelegte Warteschlangen entfernen. Die Aufrufe von `lpadmin` wandern in den root-Dienst. |
| `src/Cups.php`, `src/OfficePrinter.php` | `lp`-Aufruf mit Auswertung der Auftragsnummer, als Hilfsdatei für Module |
| `scripts/install-invoice-printer.sh`, `scripts/invoice-printer/` | Muster für Backend und PPD eines virtuellen Druckers (wird in Schritt 2 zum `[[printers]]`-Eintrag des SumUp-Moduls) |
| `tests/printer-admin.php` | Muster für die Tests |

**Tests:**

- Anfragen mit ungültigen Namen, URIs oder Pfaden werden abgewiesen.
- `lpadmin`-Aufrufe werden mit nachgebildeten Programmen geprüft.
- Anlegen und Entfernen virtueller Drucker beim Installieren und Entfernen von `modules/beispiel/`.
- Im CI-Container mit echtem CUPS: Warteschlange anlegen, Auftrag an einen Datei-Drucker senden.

**VM:** Bondrucker und A4-Drucker einrichten, vom Windows-Arbeitsplatz über Samba und IPP auf einen virtuellen Drucker des Beispielmoduls drucken.

---

## Zuordnung der übrigen Punkte aus `ARCHITEKTUR.md`

| Punkt | Wo |
|---|---|
| Eigene Zertifizierungsstelle | M2 (lokal auf dem Server, E9) |
| Arbeitsplatz-Starter | SumUp-Modul, Schritt 2 (E11) |
| Einrichtungs-Überblick | Basis: Ende M7; je Modul über `[setup] overview` (E11) |
| CUPS und Samba | Installation in M7, Verwaltung in M8 (E10) |
| Ausgehende Freigabeliste der Firewall | nach Schritt 2 |
| SSO für Dienste mit eigener Anmeldung (OpenID Connect, Basis als Anbieter) | Vorschlag M9, noch abzustimmen |
