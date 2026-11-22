# Umsetzungsplan Schritt 1: Basis aus kienzle-suite-beta herauslösen

Stand: Entwurf zur Freigabe, noch kein Code. Grundlage: `docs/ARCHITEKTUR.md`, `AGENTS.md`.

Quelle für übernommenen Code: `hau-si/kienzle-suite-beta`, **Branch `rechnungseingang`**, Commit `50daac0`
(Version 1.4.1-rechnung.57). Der Branch `main` (1.4.1) enthält Admin, Firewall, Updater und Netzwerkordner
noch nicht. Die Tests `tests/firewall.php`, `tests/updater.php`, `tests/security.php` und
`tests/firewall-apply.sh` dieses Stands laufen grün (PHP 8.3, geprüft am 22.11.2026).
Dateipfade unten ohne Präfix beziehen sich auf diesen Stand.

Der Code steht unter MIT (© Dr. Thomas Kienzle und Mitwirkende). Übernommene Dateien behalten den Hinweis;
die Lizenz der Suite 2.0 ist noch offen (siehe `ARCHITEKTUR.md`, Offene Fragen).

## Grundentscheidungen (vor Meilenstein 1 bestätigen)

| Nr. | Entscheidung | Vorschlag | Begründung |
|---|---|---|---|
| E1 | Sprachen | Webteil der Basis in **PHP** (Paketversion von Ubuntu 26.04), root-Dienste und Prüfprogramm in **Python 3** nur mit Standardbibliothek, Shell nur für dünne Starter | Admin, Firewall-Oberfläche und Updater liegen in PHP vor; die root-Seite der Firewall ist schon Python. Python bringt `tomllib` mit, also kein eigener TOML-Leser. Keine Paketverwaltung (Composer, pip) für die Basis. |
| E2 | Zugang über Port 443 | **nginx** als Reverse-Proxy mit `auth_request`: jede Anfrage an `/<modulpfad>` wird erst von der Basis geprüft. Die Basis setzt dann Benutzer und Rechte als Kopfzeilen, das Modul läuft über einen Unix-Socket. | Apache (wie in der Beta) hat kein vergleichbares `auth_request`. Von außen kommende Kopfzeilen `X-Kienzle-*` werden immer verworfen. |
| E3 | Ablage auf dem Server | Programme: `/opt/kienzle/<teil>/releases/<version>/`, aktiv über den Verweis `/opt/kienzle/<teil>/current`. Konfiguration: `/etc/kienzle/`. Daten: `/var/lib/kienzle/<teil>/` (0700). Sockets: `/run/kienzle/`. | Aufbau für Versionsordner und Rücksprung (M4) gleich von Anfang an. `<teil>` ist `core` oder die Modul-ID. |
| E4 | Datenbank | SQLite je Teil (`/var/lib/kienzle/core/core.sqlite`), Migrationen mit Versionsnummer wie in der Beta (`src/Database.php`) | bewährt, sicherbar per `VACUUM INTO` |
| E5 | Ein Leser für `module.toml` | Nur das Python-Prüfprogramm liest `module.toml`. Es schreibt ein geprüftes Verzeichnis `/var/lib/kienzle/core/modules.json`, und der PHP-Teil liest nur diese Datei. | keine zwei TOML-Leser, die sich auseinanderentwickeln |
| E6 | Tests | PHP-Testskripte im Stil der Beta (`php tests/…php`, ohne PHPUnit), Python mit `unittest`, ein gemeinsamer Aufruf `tests/run-all.sh`. Dazu GitHub Actions mit einem Job im Container `ubuntu:26.04`. | gleiche Arbeitsweise wie bisher; der Container prüft Paketnamen und Programmversionen von 26.04 |
| E7 | root-Aktionen | Wie in der Beta: Das Webinterface legt eine kleine Anfragedatei ab, eine systemd-`.path`-Einheit startet den root-Dienst, und der prüft Eigentümer, Größe und Inhalt streng. **Kein sudo.** | bewährt; umgeht die sudoers-Eigenheiten von sudo-rs ganz |
| E8 | Versionen | `core/VERSION` beginnt bei `0.1.0`, `core_api = "1"`. Jeder Meilenstein erhöht die Basis-Version (0.1 → 0.7). `1.0.0` erst, wenn Basis und SumUp-Modul auf der Test-VM laufen. | `AGENTS.md`: jede Änderung erhöht die Version |

## Prüfung je Meilenstein

Jeder Meilenstein endet mit:

1. `tests/run-all.sh` grün (lokal und in GitHub Actions),
2. Eintrag in `docs/RELEASE.md` mit der Angabe, was **nicht** auf echtem Ubuntu 26.04 bzw. echter Hardware geprüft wurde,
3. einem Commit, danach deiner Freigabe zum Pushen,
4. bei Meilensteinen mit dem Zeichen 🖥 einer kurzen Prüfliste für die Test-VM (Ubuntu 26.04.1) in `docs/VM-TEST.md`.
   Die führst du aus, oder wir gehen sie gemeinsam durch.

Der Installer (M7) wächst ab M2 mit, damit jeder Stand auf der VM installierbar ist. M7 macht ihn vollständig.

---

## M1 – Gerüst `core/`, `module.toml`-Format und Prüfprogramm

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
- Prüfung über alle Module hinweg (`--alle modules/`): keine doppelten IDs, Pfade, Benutzer oder Ports.
- `modules/beispiel/`: ein minimales Testmodul ohne Funktion, nur für Tests.
- `tests/run-all.sh` und `.github/workflows/pruefung.yml`.

**Übernahme aus der Beta:** keine Programmzeilen. Beispiel und Felder kommen aus `ARCHITEKTUR.md`.
Die Musterprüfungen (Portbereiche, Namen) folgen `scripts/firewall-apply.py` (`clean_custom`, `clean_services`).

**Tests:** `tests/core/test_manifest.py` mit einer gültigen Beispieldatei und je Regel mindestens einer
ungültigen Datei (`tests/fixtures/manifest/`), dazu Konflikte zwischen zwei Modulen.

---

## M2 – Anmeldung, Benutzer und Rechte inkl. Admin-OTP 🖥

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

**VM:** Installation, Ersteinrichtung mit OTP-App, Anmeldung, Mitarbeiterkonto, Zurücksetzen an der Konsole.

---

## M3 – Firewall 🖥

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

---

## M4 – Updater: signierte Releases, Versionsordner, Rücksprung 🖥

> **Hinweis:** Die Einzelheiten dieses Konzepts stehen bisher nicht in `ARCHITEKTUR.md`. Der folgende Ablauf ist
> mein Vorschlag aus den Stichworten „signierte Releases, Versionsordner, Prüfung nach dem Start, Rücksprung“.
> Bitte gegen das im Chat Besprochene prüfen. Nach der Freigabe kommt er als Abschnitt „Updates“ in `ARCHITEKTUR.md`.

**Ziel:** Updates je Teil (Basis oder Modul), die nur aus signierten Releases kommen, in einem eigenen Versionsordner
landen, nach dem Start geprüft werden und bei Fehlern automatisch auf den vorherigen Stand zurückspringen.

**Ablauf:**

1. **Release:** je Teil ein Archiv `<teil>-<version>.tar.gz` und ein `manifest.json`. Das Manifest enthält Teil, Version,
   Kanal, `core_api` bzw. die nötige Mindestversion der Basis und SHA-256 jeder Datei. Dazu eine Signatur
   `manifest.json.sig`. Veröffentlicht als GitHub-Release in `hau-si/kienzlesuite-2.0`; Tags `core-v0.4.0`,
   `sumup-v2.0.0`; Beta = Vorabversion.
2. **Signatur:** Ed25519 mit `ssh-keygen -Y sign/verify` (Namensraum `kienzle-release`). openssh ist auf jedem
   Ubuntu Server vorhanden. Der öffentliche Schlüssel kommt mit dem Installer nach `/etc/kienzle/release-keys/`.
   Der private Schlüssel bleibt **offline beim Herausgeber**, nicht in GitHub Actions.
   Ein Schlüsselwechsel geht nur über ein Release, das mit dem alten Schlüssel signiert ist.
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
10. **Sperren:** kein Update, solange ein Modul „beschäftigt“ meldet (Beispiel Beta: laufende Kartenzahlung).
    Dafür bekommt `module.toml` einen optionalen Haken `hooks.busy`; das ändert das Format aus M1, deshalb wird er schon dort aufgenommen.

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

---

## M5 – Netzwerkspeicher 🖥

**Ziel:** Ein gemeinsamer Dienst für Ziele auf dem NAS. Module legen Dateien ab, ohne die Zugangsdaten zu kennen.

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

**Übernahme:**

| Quelle | Verwendung |
|---|---|
| Beta `src/InvoiceExport.php` | `smbclient`-Aufruf mit Zugangsdatei (0600, `-A`), schrittweises `mkdir`, Auswertung der `NT_STATUS_*`-Codes, Unterordner mit `{jahr}`/`{monat}`. Der Teil für Rechnungsnamen bleibt im SumUp-Modul. |
| Beta `scripts/export-invoices.php` + Timer im Installer | stündliche Nachholung |
| kienzlezeit `kienzlezeit-backup-und-restore.sh` | Prüfung der Verbindungsangaben (`transport` ssh/smb, Gastzugang), SSH-Aufrufe, Hochladen über `.part` mit anschließendem Umbenennen, Rücklesen und Vergleich der SHA-256-Prüfsumme |

**Tests:** Prüfung der Angaben, Pfad-Angriffe in Aufträgen, Aufbau der `smbclient`- und `sftp`-Befehle, Wiederholung, Geheimnisse nicht im Protokoll.
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
- Nicht Teil von M6, nur beschrieben: NAS-Schnappschüsse und restic-REST-Server im Nur-Anfügen-Modus (Einstellung am NAS), zweite Kopie außer Haus.

**Zu klären in M6:** restic kann SFTP direkt, SMB aber nicht. Vorschlag: SFTP als Empfehlung.
Für SMB das restic-Ziel `rclone:` mit dem SMB-Treiber von rclone (Ubuntu-Paket `rclone`, ab 1.62) statt eines
`mount.cifs` als root. Ob die rclone-Version in 26.04 passt, prüfe ich zu Beginn von M6.

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

**VM:** Einrichtung auf das Test-NAS, Blatt drucken, eine Datei und ein Modul wiederherstellen.

---

## M7 – Installer für Ubuntu 26.04 🖥

**Ziel:** Eine Standard-Installation von Ubuntu Server 26.04.1 wird mit einem Befehl zur Kienzle-Suite (nur Basis). Erneutes Ausführen ändert nichts Unerwünschtes.

**Ergebnis (prüfbar):**

- `installer/install.sh` prüft vorab: Ubuntu 26.04, root, systemd, mindestens 2 Kerne, 4 GB RAM, 100 GB Speicher (nur Warnung), Internet.
- Wartet bis zu 10 Minuten auf die Paketsperre, installiert Pakete und richtet ein:
  - Benutzer `ks-core`, `ks-ablage`,
  - Ordner mit Rechten nach E3,
  - nginx, php-fpm-Pool, Zertifikat,
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

Entfällt: der eigene Apache auf Port 7868 und die Parallelbetrieb-Sonderwege.

**Tests:**

- `bash -n`, und `shellcheck`, falls vorhanden.
- Durchlauf im Container `ubuntu:26.04` in GitHub Actions. Ohne systemd prüft er nur Pakete, Dateien und Rechte; das Starten der Dienste prüft er nicht.
- Zweiter Durchlauf ohne Änderungen.
- Vollständig nur auf der Test-VM: frische 26.04.1, mit und ohne autoinstall, Wiederherstellung auf einer zweiten, leeren VM.

---

## Nicht in M1–M7, Einordnung offen

Aus `ARCHITEKTUR.md` (Abschnitte „Die Basis“ und „Vorgehen“ 1) fehlen in der Reihenfolge noch:

1. **Zertifikat und Zugang:** eigene Zertifizierungsstelle statt selbstsigniertem Zertifikat. M2 braucht HTTPS;
   Vorschlag: in M2 zunächst ein selbstsigniertes Zertifikat wie in der Beta, die eigene CA als M8.
2. **Arbeitsplätze:** Starter-Paket, Zertifikat, Drucker je Arbeitsplatz (Beta: `src/ClientPackage.php`, `client/`). Vorschlag: zusammen mit M8.
3. **Einrichtungs-Überblick** (Beta: Teil von `src/SettingsPage.php`). Vorschlag: als Abschluss von M7, sobald alle Bereiche da sind.
4. **CUPS und Samba als gemeinsame Dienste.** Vorschlag: mit dem SumUp-Modul (Schritt 2), weil sie dort zuerst gebraucht werden.

## Offene Punkte zur Freigabe

1. Grundentscheidungen E1–E8, besonders **nginx statt Apache** (E2).
2. Updater-Konzept in M4, und wo der private Signaturschlüssel liegen soll.
3. SMB für restic über rclone oder nur SFTP (M6).
4. Einordnung der vier Punkte oben.
