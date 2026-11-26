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
| `X-Kienzle-Menue` | `KIENZLE_MENUE` | Menü der Seitenleiste (URL-kodiertes JSON: apps, ids, admin, login), nur Anwendungen mit Zugang, ohne Zähler und ohne Sitzungs-Marke; im PHP-Helfer `menue()` bzw. `rahmen()` |
| `X-Kienzle-Drucker` | `KIENZLE_DRUCKER` | Zuordnung der Drucker aus Verwaltung → Drucker (URL-kodiertes JSON: buero, bon); im PHP-Helfer `druckerRollen()` |
| `X-Kienzle-Praxis` | `KIENZLE_PRAXIS` | Praxisname und Anschrift (URL-kodiertes JSON: name, street, city, contact); im PHP-Helfer `praxis()` |
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

## Arbeitsplatz-Starter

Neu und für alle Module gedacht: Startkontexte (`[[starters]]` in `module.toml`, siehe `docs/MODULE-TOML.md`). nginx legt je Modul `POST /starter/<modul-id>`
an und lässt die Basis den Starter-Schlüssel prüfen (`auth_request`); das Modul bekommt nur geprüfte Aufrufe (`KIENZLE_STARTER_GEPRUEFT=1`).

Der Starter öffnet nach dem Aufruf `https://<server>/#launch=<64 Hexzeichen>&m=<modul-id>`.
Die Basis gibt diesen Teil nach der Anmeldung an `<web.path>/#launch=…` weiter (core.js, über `sessionStorage` im selben Tab,
falls erst angemeldet werden muss). Der Code verlässt den Browser dabei nicht.

## Arbeitsplatz und „Akte öffnen“ (Kienzle-Agent)

Die Basis weiß, an welchem PC der Browser sitzt, wenn dort der Kienzle-Agent läuft (gleiche Adresse oder unter Mein Konto gewählt).
PHP: `$k->arbeitsplatz()` liefert `['id', 'name', 'online']` oder `null`.

Den Patienten in T2med öffnen (GDT-Satz 6301 an den Agent des PCs): aus der Modulseite per JavaScript

```js
fetch('/arbeitsplatz/akte', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json'},
  body: JSON.stringify({modul: 'telefon', csrf: '<CSRF-Marke des Moduls>', patientennummer: '4711'})})
// 200 {ok, auftrag, platz} · 409 {error, waehlen?} (PC nicht erkannt oder Agent nicht verbunden) · 403 (Sitzung/CSRF)
```

Stand: `GET /arbeitsplatz/stand?id=<auftrag>` → `offen`, `abgeholt`, `ok: geschrieben`, `fehler: …`, `verfallen`.

## Gesundheitsprüfung

Die Basis antwortet auf `GET https://<server>/_health` (nur von localhost) mit `{"status":"ok","version":"…"}`.
Module melden ihren Zustand über `hooks.health`; die Modulverwaltung wartet nach dem Start bis zu 60 Sekunden darauf.
Haken bekommen `KIENZLE_MODUL`, `KIENZLE_MODUL_VERSION`, `KIENZLE_DATEN` (Datenordner) und beim Aktualisieren `KIENZLE_VORHER`.

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

## Nachrichten-Ausgang (SMS und E-Mail)

Angemeldet mit `[[connections]] id = "nachrichten"`. Das Modul kennt weder den SMS- noch den E-Mail-Zugang. Es legt einen Auftrag
in seinem Ausgang ab (`/var/spool/kienzle/nachrichten/<id>/`); der Dienst `ks-nachrichten` verschickt ihn innerhalb von etwa
20 Sekunden mit den Verbindungen der Basis (SMS über seven.io, E-Mail über SMTP oder Gmail) und schreibt das Ergebnis zurück.
Fehlschläge werden 30 Minuten lang wiederholt. Je Modul höchstens 60 SMS und 120 E-Mails pro Stunde.

```php
$bereit = $k->nachrichtenBereit();                     // ['sms' => bool, 'mail' => bool]
$job = $k->smsSenden('+491701234567', 'Ihr Rezept liegt zur Abholung bereit.');      // höchstens 320 Zeichen
$job = $k->mailSenden(['empfang@example.org'], 'Neues Fax eingegangen', $text);       // 1–5 Adressen, nur Text
$k->nachricht($job);                                    // ['stand' => 'wartet'|'gesendet'|'fehler'|'unbekannt', 'meldung' => …]
```

Texte so knapp wie möglich und **ohne Befunde, Diagnosen oder Anhänge**; SMS und E-Mail sind nicht Ende-zu-Ende verschlüsselt.
Eigene Dienste des Moduls, die Nachrichten ablegen, tragen `-/var/spool/kienzle/nachrichten/<id>` in `ReadWritePaths` ein.

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


### Lauschordner auf dem Netzwerkspeicher

Mit der Verbindung „ablage“ kann ein Modul auch Dateien vom NAS holen: `$k->lauschen($ziel, 'Ordner', ['pdf'])` schaltet einen Lauschordner
ein (leeres Ziel schaltet aus). `ks-ablage` holt neue Dateien mit den genannten Endungen (höchstens 20 je Durchlauf) nach
`$k->lauschenEingang()`, prüft sie durch zweites Lesen und löscht sie erst danach auf dem NAS. Das Modul liest und löscht die Dateien dort
selbst. Dateien mit gleichem Namen werden nicht überschrieben (Vorsatz `1-`, `2-` …).

## Seiten im Rahmen der Suite

Ein PHP-Modul kann eine Seite in den Rahmen der Suite setzen (Seitenleiste, Kopf, Praxisname): Inhalt mit `ob_start()` sammeln und
`echo $k->rahmen('Titel', $inhalt, ['roh' => true, 'kopf' => '<link …><script …>', 'body' => ' data-x="…"']);` ausgeben.
`roh` lässt den Inhalt unverändert (sonst setzt die Basis Überschrift und Karten), `kopf` hängt Stylesheets und Skripte des Moduls an
(Inhalt der Seite bekommt `<main class="modul">`). Das Menü kommt mit der ohnehin laufenden Anmelde-Prüfung (Kopfzeile `X-Kienzle-Menue`),
es entsteht also keine zusätzliche Anfrage. Die Sitzungs-Marke der Basis erhält das Modul nicht: Abmelden führt deshalb über „Mein Konto“.
Schlanke Seiten (z. B. die Kassieren-Ansicht in T2med) bleiben ohne Rahmen.
