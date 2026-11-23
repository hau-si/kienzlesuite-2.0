# Modul-Beschreibung `module.toml`

Gilt für `core_api = "1"` (Basis ab 0.2.0). Jedes Modul liegt in `modules/<id>/` und bringt dort eine `module.toml` mit.
Geprüft wird sie mit

```
core/bin/kienzle-modul-pruefen modules/<id>          # ein Modul
core/bin/kienzle-modul-pruefen --alle modules        # alle Module und Konflikte zwischen ihnen
core/bin/kienzle-modul-pruefen --json modules/<id>   # geprüfte Fassung als JSON
```

Rückgabe 0 = gültig, 1 = ungültig (alle Fehler werden aufgelistet), 2 = falscher Aufruf.

Nur dieses Prüfprogramm liest `module.toml`. Installer, Updater, Firewall, Druckerverwaltung und Webinterface lesen
seine Ausgabe (`--alle --json`), die die Basis als `/var/lib/kienzle/core/modules.json` ablegt.

## Installation

Module installiert, aktualisiert und entfernt die Basis (Verwaltung → Module bzw. `sudo kienzle-module …`). Quellen: die mit
der Basis gelieferten Module (`modules/<id>/` im Release der Basis, ohne `beispiel`) und GitHub-Releases `<id>-v<version>`
mit dem Archiv `modules/<id>/…` (`scripts/release-bauen <id>`). Das Programm landet in `/opt/kienzle/<id>/releases/<version>/<id>/`
(nur root beschreibbar), aktiv über `/opt/kienzle/<id>/current`. FastCGI-Module bekommen eine eigene php-fpm-Instanz
(`kienzle-<id>-php.service`, Konfiguration `/etc/kienzle/module/<id>/php-fpm.conf`, `open_basedir` auf die eigenen Ordner).

## Grundregeln

- **Streng:** unbekannte Abschnitte und Schlüssel sowie falsche Typen sind Fehler.
- Datei höchstens 64 KiB, UTF-8.
- Texte (`name`, `label` …): ohne Steuerzeichen, nicht leer, höchstens 80 Zeichen (`description` 300).
- **Pfade im Modulordner** (Haken, PPD, Backend): relativ, nur `A–Z a–z 0–9 _ . -` und `/`, kein `..`.
  Bei der Prüfung eines Ordners müssen die Dateien existieren und echte Dateien sein; kein Teil des Pfads darf ein Verweis (Symlink) sein.
  Die Dateien dürfen für Gruppe und andere nicht beschreibbar sein. Haken und Backends müssen ausführbar sein.
- Der Modulordner muss so heißen wie `module.id`.

## Abschnitte

### `[module]` – Pflicht

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `id` | ja | Kleinbuchstaben, Ziffern, einzelne Bindestriche, beginnt mit Buchstaben, 2 bis 29 Zeichen. Reserviert: `core`, `kienzle`, `admin`, `ablage`, `sicherung`, `drucker`, `zertifikat`, `firewall`, `update`, `updates`, `beta`, `stabil`, `requests`, `run` |
| `name` | ja | Anzeigename im Menü |
| `version` | ja | `X.Y.Z`, optional mit Vorabkennung: `2.1.0-beta.3` |
| `core_api` | ja | Text, z. B. `"1"`; muss in `core/API` stehen |
| `description` | nein | kurze Beschreibung (höchstens 300 Zeichen) |

### `[system]` – Pflicht

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `user` | ja | genau `ks-<id>`; der Systembenutzer, unter dem alle Dienste und Haken des Moduls laufen |
| `data` | ja | genau `/var/lib/kienzle/<id>`; eigener Datenordner (0700) |
| `packages` | nein | Ubuntu-Pakete, höchstens 50, Muster `[a-z0-9][a-z0-9+.-]+`. Die Basis installiert sie. |
| `services` | nein | systemd-Einheiten des Moduls, höchstens 20, nur `kienzle-<id>[-…].service/.timer/.path/.socket`. Die Dateien liegen im Modul unter `systemd/<name>`; jeder Dienst muss genau `User=ks-<id>` enthalten (keine Gruppen, keine Befehle mit Vorrechten) |

### `[web]` – optional

`einstellungen` (optional, z. B. `"/settings.php"`): Seite unter dem Webpfad, die Modul-Admins unter „Einstellungen“ in der Seitenleiste der Basis sehen.

Ohne `[web]` erscheint das Modul nicht im Menü (z. B. ein reines Druckmodul).

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `path` | ja | `/` und 1–31 Zeichen aus `a–z 0–9 -`. Reserviert: `/anmelden`, `/abmelden`, `/verwaltung`, `/einrichtung`, `/konto`, `/hilfe`, `/assets`, `/api`, `/_auth`, `/_health`, `/core`, `/kienzle` |
| `upstream` | ja | genau `unix:/run/kienzle/<id>/web.sock`. Der Ordner gehört `ks-<id>`, Gruppe `www-data`, Rechte 0750: nur das Modul und nginx kommen hinein, andere Module nicht |
| `protocol` | nein | `"http"` (Vorgabe: das Modul spricht HTTP über den Socket) oder `"fastcgi"` (PHP über php-fpm) |
| `entry` | bei `fastcgi` | Einstiegsdatei im Modulordner, z. B. `public/index.php`; alle Anfragen unter `path` gehen an sie |
| `starter` | nein | `true`: das Modul bedient den Arbeitsplatz-Starter (`POST https://<server>/api.php` ohne Anmeldung, siehe `docs/MODUL-SCHNITTSTELLE.md`). Nur mit `fastcgi`, höchstens ein Modul. Vorgabe `false` |

### `[[firewall]]` – optional, höchstens 20

Ports, die das Modul im Praxisnetz anbietet. Sie sind zunächst geschlossen; der Admin schaltet sie in der Firewall frei.

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `name` | ja | Bezeichnung in der Firewall-Seite |
| `port` | ja | Zahl (`8766`) oder Bereich als Text (`"10000-20000"`), 1–65535 |
| `proto` | ja | `"tcp"` oder `"udp"` |
| `scope` | nein | `"nets"` (Vorgabe: nur aus den freigegebenen Praxisnetzen) oder `"any"` (von überall, z. B. VPN-Gegenstellen) |

Der Basis vorbehalten und daher abgelehnt: tcp 22 (SSH), tcp 443 (HTTPS), tcp 445 (Samba), tcp/udp 631 (CUPS).
Drucker, die ein Modul per Samba oder IPP freigibt, brauchen deshalb keinen eigenen Firewall-Eintrag.
Bereiche eines Moduls dürfen sich nicht überschneiden, auch nicht mit denen anderer Module.

### `[[rights]]` – optional, höchstens 30

Rechte, die der Admin Mitarbeitern als Kästchen zuweist. Admin-Rechte je Modul vergibt die Basis selbst.

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `id` | ja | `[a-z][a-z0-9_]*`, höchstens 31 Zeichen, eindeutig im Modul; `admin` ist reserviert |
| `label` | ja | Text des Kästchens |

### `[backup]` – optional

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `paths` | ja | 1–20 absolute Pfade innerhalb von `/var/lib/kienzle/<id>` |
| `snapshot` | nein | Haken, der vor der Sicherung eine stimmige Kopie erzeugt (z. B. SQLite per `VACUUM INTO`) |
| `settings` | nein | 0–20 Dateien oder Ordner mit den Einstellungen des Moduls (innerhalb von `/var/lib/kienzle/<id>`); kommen in den verschlüsselten Export der Einstellungen (Verwaltung → Sicherung) |

### `[[printers]]` – optional, höchstens 10

Virtuelle Drucker des Moduls. Die Druckerverwaltung der Basis legt sie an und entfernt sie wieder.

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `name` | ja | Warteschlangenname, 1–40 Zeichen aus `A–Z a–z 0–9 _ -`, über alle Module eindeutig (ohne Groß-/Kleinschreibung) |
| `label` | ja | Beschreibung |
| `command` | eins von beiden | **Empfohlen.** Programm im Modulordner; die Basis startet dafür einen IPP-Everywhere-Drucker (`ippeveprinter`) als `ks-<id>`, nur auf localhost. Das Programm bekommt jeden Auftrag als Datei (Pfad als Argument) und läuft als Modul-Benutzer |
| `paper` | nein | nur mit `command`: Papierformat der Warteschlange für Windows/Mac: `A4` (Vorgabe), `A5` oder `A6` |
| `formats` | nein | nur mit `command`: angenommene Dateitypen, Vorgabe `["application/pdf"]`, höchstens 5 |
| `ppd` | eins von beiden | Ältere Art: CUPS-Warteschlange mit PPD-Datei aus dem Modulordner (CUPS behandelt PPDs als veraltet) |
| `backend` | nein | nur mit `ppd`: CUPS-Backend im Modulordner (ausführbar, läuft als Benutzer `lp`). Ohne Backend verwirft die Warteschlange die Ausgabe der Filter (Backend `kienzle-leer` der Basis) |
| `share` | nein | Liste aus `"samba"` und `"ipp"`: wie die Arbeitsplätze den Drucker erreichen |

### `[[connections]]` – optional

Zentrale Verbindungen, die die Basis verwaltet (Seite „Verbindungen“). Nur Module, die eine Verbindung hier anmelden,
bekommen deren Zugangsdaten; sie sind dann nur für `ks-<id>` lesbar. Zugänge, die nur ein Modul braucht
(z. B. Letterxpress, SumUp-API, Google), verwaltet das Modul selbst.

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `id` | ja | `"t2med"` (FHIR-Zugang zu T2med), `"mail"` (E-Mail-Versand per SMTP) oder `"ablage"` (Netzwerkspeicher); je einmal |
| `purpose` | ja | wofür das Modul die Verbindung nutzt, höchstens 120 Zeichen; wird dem Admin angezeigt |
| `required` | nein | `true` (Vorgabe): ohne die Verbindung arbeitet das Modul nicht; `false`: nur einzelne Funktionen fehlen |

### `[setup]` – optional

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `overview` | nein | Haken, der den Einrichtungs-Überblick des Moduls als JSON ausgibt (Format unten) |

### `[hooks]` – optional

| Schlüssel | Bedeutung |
|---|---|
| `install`, `update`, `remove` | beim Installieren, nach dem Aktualisieren, vor dem Entfernen |
| `busy` | Rückgabe 0 = frei, 75 = gerade beschäftigt (z. B. laufende Kartenzahlung); dann kein Update |
| `health` | Prüfung nach dem Start; Rückgabe 0 = gesund. Der Updater springt sonst zurück |
| `apparmor` | Programme, die Zugriff auf den Datenordner brauchen; erlaubt: `gs`, `qpdf` |

Alle Haken laufen als Modul-Benutzer `ks-<id>`, nie als root. Was root braucht (Pakete, Drucker, Firewall, AppArmor),
erledigt die Basis anhand der Angaben in dieser Datei.

## Einrichtungs-Überblick (`setup.overview`)

Der Haken gibt auf der Standardausgabe aus:

```json
{"items": [
  {"title": "SumUp-Schlüssel eingetragen", "state": "done", "hint": "", "link": "/kasse/einstellungen"},
  {"title": "Bondrucker gewählt", "state": "open", "hint": "Unter Einstellungen → Drucken", "link": "/kasse/einstellungen#drucken"}
]}
```

`state`: `done`, `open` oder `error`. `link` muss mit dem eigenen `web.path` beginnen. Höchstens 30 Punkte.
Die Prüfung dieser Ausgabe gehört zum Einrichtungs-Überblick der Basis (Meilenstein 7).

## Vollständiges Beispiel

Entspricht `modules/beispiel/module.toml`; die Tests prüfen, dass dieses Beispiel gültig ist.

```toml
[module]
id = "beispiel"
name = "Beispielmodul"
version = "0.2.0"
core_api = "1"
description = "Zeigt alle Felder der Modul-Beschreibung; nur für Tests."

[system]
packages = ["sqlite3"]
services = ["kienzle-beispiel-worker.service"]
user = "ks-beispiel"
data = "/var/lib/kienzle/beispiel"

[web]
path = "/beispiel"
upstream = "unix:/run/kienzle/beispiel/web.sock"

[[firewall]]
name = "Beispiel-Schnittstelle für Geräte im Praxisnetz"
port = 8766
proto = "tcp"

[[firewall]]
name = "Beispiel-Bereich für Sprachdaten"
port = "10000-10010"
proto = "udp"

[[rights]]
id = "ansehen"
label = "Beispiel-Liste ansehen"

[[rights]]
id = "bearbeiten"
label = "Beispiel-Einträge bearbeiten"

[backup]
paths = ["/var/lib/kienzle/beispiel"]
snapshot = "bin/backup-snapshot"
settings = ["/var/lib/kienzle/beispiel/einstellungen.json"]

[[printers]]
name = "Beispiel-Drucker"
label = "Virtueller Drucker des Beispielmoduls"
ppd = "printer/beispiel.ppd"
backend = "printer/kienzle-beispiel-backend"
share = ["samba", "ipp"]

[[printers]]
name = "Beispiel-PDF"
label = "Virtueller PDF-Drucker des Beispielmoduls (IPP Everywhere)"
command = "printer/kienzle-beispiel-druck"
formats = ["application/pdf"]
share = ["ipp"]

[[connections]]
id = "t2med"
purpose = "Beispiel: Patient zum Vorgang lesen"

[[connections]]
id = "mail"
purpose = "Beispiel: Nachricht an die Praxis senden"
required = false

[setup]
overview = "bin/setup-overview"

[hooks]
install = "bin/install"
update = "bin/update"
remove = "bin/remove"
busy = "bin/busy"
health = "bin/health"
apparmor = ["gs"]
```

## Ausgabe (`--json`)

Die geprüfte Fassung enthält alle Abschnitte, fehlende mit Vorgaben (`web`/`backup`: `null`, Listen leer, Haken `null`).
Firewall-Ports stehen vereinheitlicht als `from`/`to` (Zahlen). `--alle --json` liefert
`{"core_api": ["1"], "modules": [...]}`, sortiert nach Ordnername.
