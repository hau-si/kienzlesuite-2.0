# Installation der Kienzle-Suite (Basis)

Für die Praxis bzw. die betreuende IT. Was dabei technisch passiert: `docs/ARCHITEKTUR.md`.
Prüfliste für die Test-VM: `docs/VM-TEST.md`.

## Voraussetzungen

- VM mit **Ubuntu Server 26.04 LTS** (aktuelles Abbild, derzeit 26.04.1), frisch installiert.
- Mindestens 2 CPU-Kerne, 4 GB Arbeitsspeicher, 100 GB Speicher (der Installer warnt, wenn weniger da ist).
- Feste IP-Adresse im Praxisnetz und ein Name im DNS der Praxis bzw. im Router, z. B. `kienzle.praxis.internal`.
  Passkeys funktionieren nur über einen Namen, nicht über eine IP-Adresse.
- Internetzugang für Pakete und Updates.

## Installation von Hand

1. Ubuntu Server 26.04 installieren – die normale Variante „Ubuntu Server“, nicht „minimized“ (Sprache Deutsch, Tastatur Deutsch, Zeitzone Europe/Berlin, OpenSSH-Server an).
2. Anmelden und die Suite holen:

   ```sh
   sudo apt-get install -y git
   git clone https://github.com/hau-si/kienzlesuite-2.0.git
   cd kienzlesuite-2.0
   sudo bash installer/install.sh --name kienzle.praxis.internal --name 192.0.2.10 --praxis "Praxis Dr. Beispiel"
   ```

   `--name` ist der Name bzw. die Adresse, unter der die Arbeitsplätze den Server erreichen (mehrfach möglich).
3. Am Ende zeigt der Installer
   - den **Fingerabdruck der Zertifizierungsstelle** (zum Vergleich auf den Arbeitsplätzen),
   - den **einmaligen Einrichtungslink** für den ersten Admin (gilt 24 Stunden).
4. Auf einem Arbeitsplatz `https://kienzle.praxis.internal/ca.crt` laden und als **vertrauenswürdige
   Stammzertifizierungsstelle** eintragen (Windows: Doppelklick → Zertifikat installieren → Lokaler Computer →
   „Vertrauenswürdige Stammzertifizierungsstellen“). Fingerabdruck vergleichen.
5. Einrichtungslink öffnen, Passwort setzen, Authenticator-App einrichten, Wiederherstellungscodes ausdrucken.
6. **Verwaltung → Einrichtung** zeigt, was noch fehlt: zweiter Admin, Firewall, Netzwerkspeicher, Sicherung,
   Schlüsselblatt, Schnappschüsse am NAS, Probe-Wiederherstellung – und darunter die Punkte der Module.

## Unbeaufsichtigt (autoinstall)

`installer/autoinstall.yaml` ist eine Vorlage für die **Standard-ISO** von Ubuntu Server 26.04 (keine eigene ISO):

1. Platzhalter in `<SPITZEN KLAMMERN>` ersetzen; Passwort-Hash mit `mkpasswd -m yescrypt` erzeugen.
2. Datei als `user-data` zusammen mit einer leeren `meta-data` auf ein kleines zweites Laufwerk mit der Bezeichnung
   `CIDATA` legen (z. B. `cloud-localds seed.iso user-data meta-data`) und beides an die VM hängen.
3. Von der Standard-ISO starten. **Achtung:** das größte Laufwerk der VM wird ohne Rückfrage gelöscht.
4. Beim ersten Start läuft der Installer der Suite von selbst. Den Einrichtungslink zeigt danach
   `sudo journalctl -u kienzle-erstinstallation` bzw. `sudo kienzle-admin einrichtungslink`.

## Was der Installer einrichtet

- nginx und php-fpm (nur die Suite; die Beispielseite der Distribution wird abgeschaltet), eigene Zertifizierungsstelle.
- Dienste der Basis: Firewall, Updates, Verbindungen, Netzwerkspeicher, Sicherung, Einrichtungs-Überblick.
- **CUPS und Samba als Druckserver** aus festen Vorlagen. Vorher werden die Vorlagen geprüft (`cupsd -t`, `testparm`),
  die ursprünglichen Dateien liegen unter `/etc/kienzle/original/`. Samba hat keine Dateifreigaben, nur SMB 3, kein NetBIOS.
  Aus dem Praxisnetz erreichbar erst nach Freigabe in **Verwaltung → Firewall** (IPP 631, Windows-Freigabe 445).
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

## Nach einem Totalausfall

1. Neue VM wie oben, Suite herunterladen.
2. `sudo bash installer/install.sh --wiederherstellen` und die Angaben vom Schlüsselblatt eingeben
   (Ablage, Passwort). Bei SFTP zeigt der Installer den neuen SSH-Schlüssel des Servers; ihn kurz am NAS erlauben.
3. Servername und Zertifizierungsstelle sind die alten: Die Arbeitsplätze müssen nichts neu einrichten.
