# Schnittstelle zwischen Basis und Modulen

Gilt für `core_api = "1"` (Basis ab 0.2.0). Das Format von `module.toml` steht in `docs/MODULE-TOML.md`.

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
