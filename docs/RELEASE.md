# Basis – Release-Notizen

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
