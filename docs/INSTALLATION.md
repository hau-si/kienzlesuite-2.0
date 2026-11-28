# Installation Praxisherz (Basis)

Für die Praxis bzw. die betreuende IT. Was dabei technisch passiert: `docs/ARCHITEKTUR.md`.
Prüfliste für die Test-VM: `docs/VM-TEST.md`.

## Voraussetzungen

- VM mit **Ubuntu Server 26.04 LTS** (aktuelles Abbild, derzeit 26.04.1), frisch installiert.
- Mindestens 2 CPU-Kerne, 4 GB Arbeitsspeicher, 100 GB Speicher (der Installer warnt, wenn weniger da ist).
- Feste IP-Adresse im Praxisnetz und ein Name im DNS der Praxis bzw. im Router, z. B. `praxisherz`.
  Passkeys funktionieren nur über einen Namen, nicht über eine IP-Adresse.
- Internetzugang für Pakete und Updates.

## Installation von Hand

1. Ubuntu Server 26.04 installieren – die normale Variante „Ubuntu Server“, nicht „minimized“ (Sprache Deutsch, Tastatur Deutsch, Zeitzone Europe/Berlin, OpenSSH-Server an).
2. Anmelden und Praxisherz holen und installieren – ohne weitere Angaben:

   ```sh
   sudo apt-get install -y git
   git clone https://github.com/hau-si/praxisherz.git
   cd praxisherz
   sudo bash installer/install.sh
   ```

   Der Installer nimmt die IP-Adresse(n) und den Rechnernamen der VM. Den endgültigen Namen und den Praxisnamen legt
   ein Admin danach unter **Praxis → Server** fest. Wer es gleich richtig haben will, kann beides auch mitgeben:
   `--name praxisherz --name 192.0.2.10 --praxis "Praxis Dr. Beispiel"`.
3. Am Ende zeigt der Installer
   - die Adresse(n), unter denen Praxisherz erreichbar ist (zunächst über die IP),
   - den **Fingerabdruck der Zertifizierungsstelle** (zum Vergleich auf den Arbeitsplätzen),
   - den **einmaligen Einrichtungslink** für den ersten Admin (gilt 24 Stunden).
4. Auf dem ersten Arbeitsplatz `https://<IP-der-VM>/ca.crt` laden und als **vertrauenswürdige
   Stammzertifizierungsstelle** eintragen (Windows: Doppelklick → Zertifikat installieren → Lokaler Computer →
   „Vertrauenswürdige Stammzertifizierungsstellen“). Fingerabdruck vergleichen. Das ist nur für diesen ersten Zugriff
   nötig: Weitere PCs bekommen das Zertifikat mit dem Paket unter **Praxis → Arbeitsplätze** (siehe Schritt 8).
5. Einrichtungslink öffnen, Passwort setzen, Authenticator-App einrichten, Wiederherstellungscodes ausdrucken.
6. **Praxis → Server:** Namen festlegen, unter dem die Arbeitsplätze Praxisherz erreichen (z. B. `praxisherz`,
   Eintrag im DNS bzw. Router nötig), dazu den Praxisnamen. Das Server-Zertifikat wird dabei neu ausgestellt; die
   Zertifizierungsstelle bleibt. Passkeys funktionieren nur über diesen Namen.
7. **Startseite → Einrichtung** zeigt, was noch fehlt: zweiter Admin, Firewall, Netzwerkspeicher, Sicherung,
   Schlüsselblatt, Schnappschüsse am NAS, Probe-Wiederherstellung – und darunter die Punkte der Module.
8. **Praxis → Arbeitsplätze:** Auf jedem Windows-PC einmal `Praxisherz-Setup.exe` ausführen. Der Praxisherz-Agent
   richtet Zertifikat, Starter für T2med und die virtuellen Drucker der Module ein und hält sie aktuell. Für Mac, Linux
   oder wenn etwas schiefgeht, steht dort jeder Schritt eingeklappt unter „Von Hand einrichten“.

## Unbeaufsichtigt (autoinstall)

`installer/autoinstall.yaml` ist eine Vorlage für die **Standard-ISO** von Ubuntu Server 26.04 (keine eigene ISO):

1. Platzhalter in `<SPITZEN KLAMMERN>` ersetzen; Passwort-Hash mit `mkpasswd -m yescrypt` erzeugen.
2. Datei als `user-data` zusammen mit einer leeren `meta-data` auf ein kleines zweites Laufwerk mit der Bezeichnung
   `CIDATA` legen (z. B. `cloud-localds seed.iso user-data meta-data`) und beides an die VM hängen.
3. Von der Standard-ISO starten. **Achtung:** das größte Laufwerk der VM wird ohne Rückfrage gelöscht.
4. Beim ersten Start läuft der Installer von Praxisherz von selbst. Den Einrichtungslink zeigt danach
   `sudo journalctl -u kienzle-erstinstallation` bzw. `sudo kienzle-admin einrichtungslink`.

## Start-ISO (iso/): einfachster Weg

Die kleine Start-ISO (rund 100–150 MB) liegt als Download im GitHub-Release **„start-iso“** (GitHub → Releases → „Start-ISO (neueste)“); sie wird
von `.github/workflows/iso.yml` gebaut. Auf Windows reicht: Datei laden, mit Rufus oder balenaEtcher auf einen USB-Stick schreiben (oder an die VM hängen),
davon starten.

- **Voraussetzungen:** Internet per DHCP beim Start und rund **6 GB Arbeitsspeicher** oder mehr: Ubuntu Server 26.04.1 wird beim Start geladen
  (2,7 GB) und im Arbeitsspeicher gehalten. Eine feste Adresse für den Download: Menüeintrag mit „e“ bearbeiten (Hinweis steht in der Menüdatei).
- **Eingaben:** Nur drei Ubuntu-Bildschirme bleiben: **Netzwerk** (DHCP oder feste Adresse), **Datenträger** (Platte und Größe, Rest bleibt frei)
  und **Benutzer/Passwort** (Servername `praxisherz` und Benutzer `praxisadmin` vorbelegt). Sprache, Tastatur (de), Zeitzone, SSH-Server und Sicherheitsupdates laufen ohne Rückfrage.
- **Startmenü:** *stabil (signiert)*, *Beta (unsignierte Vorabversionen erlaubt)*, *Zweig main* oder *Zweig beta* (nur wenn es kein Release gibt).
  Unsignierte Stände gibt es nur durch ausdrückliche Menüwahl; bei Releases schützt dann noch die Prüfsumme des Manifests, beim Zweig nichts.
- **Danach** holt `kienzle-holen` beim ersten Start das neueste Release des Kanals, prüft die Ed25519-Signatur mit den Schlüsseln aus
  `installer/release-keys` (in der ISO eingebettet), packt es nach `/opt/kienzle-installer` und startet `install.sh`. Schlägt etwas fehl, bleibt das
  System bestehen und der Dienst versucht es nach 2 Minuten erneut (`sudo journalctl -u kienzle-erstinstallation`).
- **Bauen:** `bash iso/bauen.sh` (Linux, braucht `xorriso`, `gpgv`): lädt die Ubuntu-ISO, prüft sie gegen `SHA256SUMS` mit GPG-Signatur (fester
  Fingerabdruck der Ubuntu-CD-Schlüssel), entfernt das große Dateisystem und ergänzt Menü und Autoinstall (Bezeichnung `CIDATA`).
- **Stand:** Download, Signaturprüfung, Seed und Menü sind mit `iso/tests` getestet. **Der Start von der ISO ist noch nicht auf einer VM geprüft**
  (offene Punkte: ob cloud-init den Seed über die Bezeichnung `CIDATA` findet, ob Secure Boot mit dem verkleinerten Abbild startet).

## Was der Installer einrichtet

- nginx und php-fpm (nur Praxisherz; die Beispielseite der Distribution wird abgeschaltet), eigene Zertifizierungsstelle.
- Dienste der Basis: Firewall, Updates, Verbindungen, Netzwerkspeicher, Sicherung, Einrichtungs-Überblick.
- **CUPS und Samba als Druckserver** aus festen Vorlagen. Vorher werden die Vorlagen geprüft (`cupsd -t`, `testparm`),
  die ursprünglichen Dateien liegen unter `/etc/kienzle/original/`. Samba hat keine Dateifreigaben, nur SMB 3, kein NetBIOS.
  Aus dem Praxisnetz erreichbar erst nach Freigabe in **Betrieb → Sicherheit → Firewall** (IPP 631, Windows-Freigabe 445).
  Arbeitsgruppe: **Praxis → Drucker → Windows-Netz**. Windows 11 Pro (ab 24H2) lässt Gastzugriff auf
  Windows-Freigaben nicht mehr zu: dort über IPP drucken.
- Automatische Sicherheitsupdates der Distribution **ohne automatischen Neustart**.
- AppArmor-Ergänzungen für Programme, die Module anmelden (`gs`, `qpdf`).

Mehrfaches Ausführen ist erlaubt; Schlüssel, Zertifizierungsstelle, Daten und eigene Einstellungen bleiben erhalten.

## Weitere Aufrufe

| Aufruf | Zweck |
|---|---|
| `sudo bash installer/install.sh --admin-reset NAME` | Admin-Zugang an der Konsole zurücksetzen (neuer Einrichtungslink) |
| `sudo bash installer/install.sh --kanal beta` | Update-Kanal festlegen (`stabil` oder `beta`) |
| `sudo bash installer/install.sh --wiederherstellen` | Neue VM nach Totalausfall: Angaben vom Schlüsselblatt eingeben, alles kommt zurück |
| `sudo kienzle-sicherung einstellungen-einspielen DATEI` | Export der Einstellungen zurücklegen, danach Installer erneut ausführen |
| `sudo kienzle-module liste` | Installierte und verfügbare Module (sonst Betrieb → Module im Webinterface) |
| `sudo kienzle-module installieren kasse` / `entfernen kasse [--daten-loeschen]` | Modul an der Konsole installieren bzw. entfernen |

## Nach einem Totalausfall

1. Neue VM wie oben, Praxisherz herunterladen.
2. `sudo bash installer/install.sh --wiederherstellen` und die Angaben vom Schlüsselblatt eingeben
   (Ablage, Passwort). Bei SFTP zeigt der Installer den neuen SSH-Schlüssel des Servers; ihn kurz am NAS erlauben.
3. Servername und Zertifizierungsstelle sind die alten: Die Arbeitsplätze müssen nichts neu einrichten.
