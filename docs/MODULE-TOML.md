# Modul-Beschreibung `module.toml`

Gilt für `core_api = "1"` (Basis ab 0.2.0) und `core_api = "2"` (Basis ab 0.22.0: zusätzlich `[system] disables`, `[[exchange]]` und `[[fileshare]]`). Jedes Modul liegt in `modules/<id>/` und bringt dort eine `module.toml` mit.
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

Module installiert, aktualisiert und entfernt die Basis (Betrieb → Module bzw. `sudo kienzle-module …`). Quellen: die mit
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
| `requires` | nein | nur `core_api = "2"`: Module, die vorher installiert sein müssen (IDs, höchstens 5), z. B. `["telefon"]`. Installieren geht erst danach, Entfernen der gebrauchten Module erst ohne dieses Modul |

### `[system]` – Pflicht

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `user` | ja | genau `ks-<id>`; der Systembenutzer, unter dem alle Dienste und Haken des Moduls laufen |
| `data` | ja | genau `/var/lib/kienzle/<id>`; eigener Datenordner (0700) |
| `packages` | nein | Ubuntu-Pakete, höchstens 50, Muster `[a-z0-9][a-z0-9+.-]+`. Die Basis installiert sie. |
| `disables` | nein | nur `core_api = "2"`: Dienste aus Ubuntu-Paketen, die das Modul selbst betreibt; die Basis stoppt und maskiert sie bei der Installation und hebt die Maskierung beim Entfernen auf. Erlaubt: `asterisk.service` (Modul Telefon betreibt eine eigene Asterisk-Instanz) |
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
| `uploads` | nein | `true`: Formular-Uploads (`multipart/form-data`, `$_FILES`) sind erlaubt, bis 3 MB je Datei. Sonst ist `file_uploads` im Modul-Dienst aus. Nur mit `fastcgi`. Vorgabe `false` |
| `personen` | nein | `true`: Das Modul bekommt mit jeder Anfrage die Personen mit Zugang zu diesem Modul (Anmeldename, Name, Gruppen, Rechte im Modul; `X-Kienzle-Personen`, PHP `$k->personen()`), z. B. um an eine Gruppe vorzulegen. Erst mit `core_api = "2"` (Basis ab 0.22.0-beta.46). Vorgabe `false` |

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
| `settings` | nein | 0–20 Dateien oder Ordner mit den Einstellungen des Moduls (innerhalb von `/var/lib/kienzle/<id>`); kommen in den verschlüsselten Export der Einstellungen (Betrieb → Sicherung) |

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
| `share` | nein | Liste aus `"samba"` und `"ipp"`: wie die Arbeitsplätze den Drucker erreichen. IPP ist bei virtuellen Druckern immer an (Standardweg für alle Betriebssysteme), auch wenn es hier fehlt; Samba (alte Windows-Freigabe) ist nur eine Vorgabe für neue Drucker, der Admin schaltet es unter Praxis → Drucker ein oder aus |

### `[printer_list]` – optional

Veränderbare Druckerliste (z. B. Faxdrucker, die der Admin in den Einstellungen des Moduls erweitert). Das Modul schreibt die Namen
nach `/var/lib/kienzle/<id>/<file>` (`{"drucker": [{"name": "vFax"}, …]}`); die Basis legt beim Abgleichen (Praxis → Drucker →
„Virtuelle Drucker der Module abgleichen“, auch bei Installieren und Aktualisieren des Moduls) für jeden Namen einen virtuellen Drucker an.
Alle Drucker der Liste teilen sich Programm, Beschreibung und Papier. Das Programm bekommt den Druckernamen in der Umgebungsvariablen
`KIENZLE_DRUCKER`. Zusammen mit `[[printers]]` höchstens 10 Drucker.

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `prefix` | ja | Jeder Name muss so beginnen (z. B. `vFax`); schützt Namen anderer Module |
| `label` | ja | Beschreibung |
| `command` | ja | Programm im Modulordner wie bei `[[printers]]` |
| `file` | ja | Dateiname im Datenordner, `*.json`, höchstens 64 KB |
| `default` | nein | Namen, solange die Datei fehlt oder kaputt ist (Vorgabe: nur `prefix`) |
| `max` | nein | höchstens so viele Drucker aus der Liste (Vorgabe 10) |
| `paper`, `formats`, `share` | nein | wie bei `[[printers]]` |

### `[[starters]]` – optional

Startkontexte für den zentralen Arbeitsplatz-Starter der Basis (Praxis → Arbeitsplätze). Nur mit `[web] protocol = "fastcgi"`. Höchstens 5 Einträge je Modul.

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `schema` | ja | URL-Schema, das T2med aufruft, `praxisherz-<name>` (Kleinbuchstaben, Ziffern, Bindestrich); über alle Module eindeutig |
| `label` | ja | Name des Kontexts für die Anzeige der T2med-Einträge (Praxis → T2med) |

Das Starterprogramm sendet `POST https://<server>/starter/<modul-id>` (JSON mit `context_id`, `fhir_base_url`, `oauth_token`, `action = "launch"`).
Die Basis prüft vorher den Starter-Schlüssel; das Modul sieht `KIENZLE_OEFFENTLICH=starter` und `KIENZLE_STARTER_GEPRUEFT=1` und antwortet mit
`{"url": "https://<server>/#launch=<64 Hex-Zeichen>&m=<modul-id>"}`. Die Basis leitet nach der Anmeldung an `<web.path>/#launch=…` weiter; das Modul
löst den Code ein.

### `[[connections]]` – optional

Zentrale Verbindungen, die die Basis verwaltet (Seite „Verbindungen“). Nur Module, die eine Verbindung hier anmelden,
bekommen deren Zugangsdaten; sie sind dann nur für `ks-<id>` lesbar. Zugänge, die nur ein Modul braucht
(z. B. SumUp-API, Google), verwaltet das Modul selbst.

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `id` | ja | `"t2med"` (FHIR-Zugang zu T2med), `"mail"` (E-Mail-Versand: `art` = `smtp` mit Server und Zugang oder `gmail` mit `client_id`, `client_secret`, `refresh_token` für die Gmail-API, Umfang gmail.send; ein Modul muss beide Arten unterstützen oder `art` prüfen), `"letterxpress"` (Briefversand: `username`, `api_key`, `modus` = `test`/`live`), `"ablage"` (Netzwerkspeicher), `"uebersetzung"` (DeepL: `api_key`, `url` = Gratis- oder Pro-Adresse; nur Vorlagentexte, keine Patientendaten) `"zeiten"` (Öffnungszeiten, Schließungen, Feiertage und Vertretungen aus Praxis → Öffnungszeiten; Aufbau in `docs/MODUL-SCHNITTSTELLE.md`) oder `"nachrichten"` (SMS und E-Mail über den Nachrichten-Ausgang der Basis, ohne Zugangsdaten im Modul; siehe `docs/MODUL-SCHNITTSTELLE.md`); je einmal |
| `purpose` | ja | wofür das Modul die Verbindung nutzt, höchstens 120 Zeichen; wird dem Admin angezeigt |
| `required` | nein | `true` (Vorgabe): ohne die Verbindung arbeitet das Modul nicht; `false`: nur einzelne Funktionen fehlen |

### `[[exchange]]` – optional, höchstens 5, nur `core_api = "2"`

Gemeinsamer Austauschordner mit einem anderen Modul (z. B. Fax ↔ Telefon). Die Basis legt ihn erst an, wenn **beide** Module
installiert sind und einander anmelden: `/var/spool/kienzle/austausch/<a>_<b>` (IDs alphabetisch), root, 0770, ACL nur für
`ks-<a>` und `ks-<b>` (auch als Vorgabe für neue Dateien). Danach starten die Dienste beider Module neu.

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `module` | ja | ID des anderen Moduls |
| `purpose` | ja | wofür der Ordner dient, höchstens 120 Zeichen |

- Die Weboberfläche (php-fpm) des Moduls darf den Ordner lesen und schreiben (`open_basedir`, `ReadWritePaths`).
- Eigene Dienste des Moduls tragen ihn selbst ein: `ReadWritePaths=… -/var/spool/kienzle/austausch/<a>_<b>` (mit `-`, falls er noch fehlt).
- Neue Dateien bekommen die Rechte über die Vorgabe-ACL nur, wenn sie mit Gruppenrechten angelegt werden (Modus 0660 bzw. 0770,
  z. B. `chmod` nach `tempnam`). Inhalt und Format des Ordners legen die beiden Module fest.

### `[[fileshare]]` – optional, höchstens 3, nur `core_api = "2"`

Ordner im Datenverzeichnis des Moduls, den ein Admin unter Praxis → Drucker als **Windows-Freigabe zum Ablegen** einschalten kann
(Vorgabe: aus). Beim Einschalten vergibt der Admin ein Kennwort (8 bis 64 Zeichen); angemeldet wird mit dem Samba-Benutzer `ks-<id>`, Gastzugriff gibt es nicht (Windows 11 24H2 verlangt eine Anmeldung). Dateien gehören `ks-<id>` (Modus 0600, Ordner 0700); beim Ausschalten wird der Samba-Zugang gelöscht. Erreichbar
nur, wenn die Firewall „Drucken über Windows-Freigabe (445)“ für das Netz freigegeben ist. Das Modul überwacht den Ordner selbst
(z. B. mit einer `.path`-Einheit) und muss unvollständig kopierte Dateien abwarten.

| Schlüssel | Pflicht | Regel |
|---|---|---|
| `id` | ja | Kennung, klein geschrieben, innerhalb des Moduls eindeutig |
| `name` | ja | Freigabename (Buchstaben, Ziffern, `_`, `-`; höchstens 31 Zeichen), darf kein Druckername sein |
| `label` | ja | Beschreibung für den Admin, höchstens 80 Zeichen |
| `path` | ja | Ordner relativ zu `[system] data` (z. B. `ablage`); die Basis legt ihn beim Einschalten an |

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
version = "0.2.1-beta.1"
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
label = "Beispiel: Nimmt Druckaufträge für das Beispielmodul an."
ppd = "printer/beispiel.ppd"
backend = "printer/kienzle-beispiel-backend"
share = ["samba", "ipp"]

[[printers]]
name = "Beispiel-PDF"
label = "Beispiel: Nimmt PDF-Aufträge für das Beispielmodul an."
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
