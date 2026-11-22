# Schnittstelle zwischen Basis und Modulen

Gilt für `core_api = "1"` (Basis ab 0.5.0). Das Format von `module.toml` steht in `docs/MODULE-TOML.md`.

## Eine Anmeldung für alle Module

Module haben **keine eigene Anmeldung**. nginx nimmt jede Anfrage an, fragt vorher die Basis und reicht nur
geprüfte Anfragen an das Modul weiter:

```
Browser ──HTTPS──▶ nginx ──auth_request /_auth/<id>──▶ Basis
                     │◀──────── 204 + Benutzer/Rechte ──┘   (401 → Anmeldeseite, 403 → kein Zugang)
                     └──▶ Modul (Unix-Socket /run/kienzle/<id>/web.sock)
```

Ein Modul wird nur erreicht, wenn das Konto aktiv ist, vollständig angemeldet ist (bei Admin-Rollen mit OTP oder Passkey)
und für dieses Modul Admin ist oder mindestens ein Recht hat. Ein Basis-Admin hat **nicht** automatisch Zugang zu Modulen.

## Was das Modul bekommt

| HTTP-Modul (Kopfzeile) | FastCGI-Modul (`$_SERVER`) | Inhalt |
|---|---|---|
| `X-Kienzle-Benutzer` | `KIENZLE_BENUTZER` | Anmeldename, z. B. `anna` |
| `X-Kienzle-Name` | `KIENZLE_NAME` | Anzeigename, URL-kodiert (`rawurldecode`) |
| `X-Kienzle-Rolle` | `KIENZLE_ROLLE` | `admin` (Admin dieses Moduls) oder `mitarbeiter` |
| `X-Kienzle-Rechte` | `KIENZLE_RECHTE` | Rechte aus `[[rights]]`, mit Komma getrennt; leer möglich |
| `X-Kienzle-Csrf` | `KIENZLE_CSRF` | CSRF-Marke dieser Sitzung für dieses Modul (64 Hexzeichen) |
| `X-Kienzle-Bestaetigt` | `KIENZLE_BESTAETIGT` | Unix-Zeit der letzten Bestätigung mit Passwort+OTP oder Passkey |
| `X-Kienzle-Pfad` | `KIENZLE_PFAD` | Webpfad des Moduls, z. B. `/kasse` |

**Fälschungssicher:** Bei HTTP-Modulen ersetzt nginx gleichnamige Kopfzeilen des Browsers. Bei FastCGI tragen die
Werte keine `HTTP_`-Vorsilbe; Kopfzeilen eines Browsers kommen in PHP immer als `HTTP_…` an und können
`KIENZLE_…` nicht erzeugen. Kopfzeilen mit Unterstrichen verwirft nginx.

**Getrennte Sockets:** Der Ordner `/run/kienzle/<id>/` gehört `ks-<id>`, Gruppe `www-data`, Rechte 0750. Nur das Modul
selbst und nginx kommen hinein; ein anderes Modul kann den Socket nicht an nginx vorbei ansprechen.

## Pflichten des Moduls

1. **Rechte selbst noch einmal prüfen:** jede Aktion gegen `Rolle` und `Rechte`. Die Basis prüft nur „darf ins Modul“.
2. **CSRF:** In jedes Formular bzw. jede schreibende Anfrage die Marke aus `X-Kienzle-Csrf` schreiben und beim Empfang
   mit `hash_equals` vergleichen. Zusätzlich `Origin` bzw. `Sec-Fetch-Site` prüfen wie die Basis.
3. **Heikle Aktionen** (Schlüssel, Einstellungen, Löschen vieler Daten): verlangen, dass `Bestaetigt` höchstens
   24 Stunden alt ist; sonst auf `/bestaetigen?weiter=<eigener Pfad>` weiterleiten.
4. **Keine Patientendaten in Protokollen**, keine Geheimnisse in Antworten (siehe `docs/ARCHITEKTUR.md`).
5. **Abmelden** ist Sache der Basis: Link bzw. Formular auf `/abmelden` (POST mit der CSRF-Marke der Basis) oder einfach zur
   Startseite `/` verlinken.

## Gesundheitsprüfung

Die Basis antwortet auf `GET https://<server>/_health` (nur von localhost) mit `{"status":"ok","version":"…"}`.
Module melden ihren Zustand über `hooks.health` (Meilenstein 4).

## Hilfsdatei für PHP-Module

```php
require '/opt/kienzle/core/current/php/modul/Kienzle.php';
$k = new KienzleModul('kasse');
if (!$k->recht('paid')) $k->verweigern();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$k->csrfGueltig($_POST['csrf'] ?? null)) $k->verweigern();
```

`benutzer()`, `name()`, `istAdmin()`, `rechte()`, `recht()`, `csrf()`, `csrfGueltig()`, `frischBestaetigt()`, `bestaetigenAdresse()`,
dazu die folgenden Abschnitte.

## Zentrale Verbindungen

Angemeldet in `[[connections]]` (`docs/MODULE-TOML.md`), eingetragen vom Admin unter **Verwaltung → Verbindungen**.
Die Basis schreibt sie nach `/etc/kienzle/verbindungen/module/<id>.json` (root:ks-<id>, 0640), nur mit den angemeldeten
Verbindungen. Ändert der Admin etwas, wird die Datei neu geschrieben; Module lesen sie bei Bedarf neu.

| Verbindung | Inhalt |
|---|---|
| `t2med` | `base_url`, `launch_urls` (Liste), `api_key`, `ca_pem` (leer = Zertifikate des Systems), `pin` (nur genau dieses Zertifikat) |
| `mail` | `host`, `port`, `sicherheit` (`starttls`/`smtps`), `benutzer`, `passwort`, `absender` |
| `ablage` | `ausgang` (Ordner für Aufträge), `ziele` (Liste mit `id` und `name`, **ohne** Zugangsdaten) |

```php
$fhir = $k->verbindung('t2med');      // null, wenn nicht eingerichtet
```

## Netzwerkspeicher

Das Modul kennt die Zugangsdaten zum NAS nicht. Es legt einen Auftrag ab; der Dienst `ks-ablage` überträgt ihn per SMB oder
SFTP, liest die Datei zur Kontrolle zurück und löscht den Auftrag erst danach. Fehlgeschlagene Aufträge werden alle
5 Minuten erneut versucht, nach zwei Tagen in `fehlgeschlagen/` verschoben.

```php
$k->ablegen('steuerbuero', $pdf, '2026-03-15_RE-42_42,50EUR.pdf', 'Rechnungen/{jahr}/{monat}', $rechnungsdatum);
```

Dateinamen: Buchstaben, Ziffern, `_ . , -` und Leerzeichen, mit Endung, **ohne Patientendaten**.
Der Unterordner liegt unter dem Basisordner des Ziels; `{jahr}`/`{monat}` kommen aus dem Datum.

## Eigene Geheimnisse

Zugänge, die nur das Modul nutzt (z. B. Letterxpress, SumUp, Google), verschlüsselt das Modul selbst mit seinem eigenen
Schlüssel (`/etc/kienzle/schluessel/<id>.key`, root:ks-<id>, 0640). Die Basis und andere Module können sie nicht lesen.

```php
$gespeichert = $k->verschluesseln($apiKey, 'letterxpress');
$apiKey = $k->entschluesseln($gespeichert, 'letterxpress');
```

## Drucken

Drucker richtet nur die Basis ein (Verwaltung → Drucker, root-Dienst `kienzle-drucker`); kein Modul ist Mitglied der
Gruppe `lpadmin`. Die eingerichteten Warteschlangen stehen in `/var/lib/kienzle/drucker/status.json` (für alle lesbar).

```php
$k = new KienzleModul('kasse');
$liste = $k->drucker();                                   // [['name' => 'Praxisdrucker', 'description' => …, 'state' => 'bereit'], …]
$auftrag = $k->drucken('Praxisdrucker', $pdf, 'Rechnung RE-42', ['media=A4', 'sides=two-sided-long-edge']);
$k->drucken('TM-m30III', $escpos, 'Bon', ['raw']);
```

`drucken()` ruft `lp` als Modul-Benutzer auf und liefert die Auftragsnummer; der Titel darf keine Patientendaten enthalten.
Virtuelle Drucker des Moduls (`[[printers]]` in `module.toml`) legt die Basis beim Installieren bzw. Aktualisieren an
und entfernt sie mit dem Modul:

- **Empfohlen, `command`:** IPP-Everywhere-Drucker (`ippeveprinter`) als Dienst `kienzle-drucker-<id>-<name>.service`, läuft als
  `ks-<id>` nur auf localhost. Jeder Auftrag landet als Datei unter `/var/lib/kienzle/<id>/druckauftraege/`, das Programm
  bekommt ihren Pfad als erstes Argument und läuft als Modul-Benutzer. Arbeitsplätze sehen eine normale Warteschlange.
- Ältere Art, `ppd` (+ `backend`): Backend 0755 und PPD 0644 aus dem Programmordner, Adresse `kienzle-<id>-<name>:/<name>`;
  ohne `backend` verwirft das Backend `kienzle-leer` der Basis die Ausgabe der Filter.
