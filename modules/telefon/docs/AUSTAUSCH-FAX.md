# Austauschordner Fax ↔ Telefon

Pfad: `/var/spool/kienzle/austausch/fax_telefon` (von der Basis angelegt, sobald beide Module installiert sind; root, 0770,
ACL nur für `ks-fax` und `ks-telefon`). Das Format ist das von Kienzlefax 3.5.0 (`/srv/kienzlefax`), damit der Fax-Worker
weitgehend unverändert bleibt. Einzige Abweichung vom Original (seit Worker 1.3.20): `sendeberichte/eingang/`, siehe unten.

| Ordner | Schreibt | Liest | Inhalt |
|---|---|---|---|
| `staging/<job>/` | fax | – | Auftrag im Aufbau (wird danach atomar nach `queue/` verschoben) |
| `queue/<job>/` | fax, Worker | Worker, fax | `job.json`, `doc.pdf`, `source.pdf`; Abbruch: `cancel.requested = true` in `job.json` |
| `processing/<job>/` | Worker, AGI | fax (Anzeige) | laufender Versand; `job.json` mit `status`, `attempt`, `retry`, `live` |
| `sendeberichte/` | Worker | fax (holt ab) | `<name>__<job>.json` und `<name>__<job>__OK.pdf` (Bericht + Dokument) |
| `sendeberichte/eingang/` | Worker (seit 1.3.20, Abweichung vom Original) | fax (holt ab) | Original des erfolgreich gesendeten Faxes zum erneuten Senden; das Modul fax hebt es nur 7 Tage auf, danach bleibt nur noch der Bericht |
| `sendefehler/berichte/` | Worker | fax (holt ab) | `<name>__<job>.json` und `<name>__<job>__FAILED.pdf` |
| `sendefehler/eingang/` | Worker | fax (holt ab) | Original des fehlgeschlagenen Faxes zum erneuten Senden |
| `empfang/` | `fax-empfangen` (Asterisk) | fax (holt ab) | `<JJJJMMTT-hhmmss>_<absender>_<id>.pdf` und `.json` (`empfangen`, `absender`, `seiten`, `status`); die JSON-Datei kommt zuletzt |

`job.json` beim Anlegen (wie `kienzlefax.php`):

```json
{"job_id": "JOB-20260101-120000-abc123", "created_at": "2026-01-01T12:00:00+01:00", "status": "queued",
 "source": {"src": "vFax1", "filename_original": "Befund.pdf"},
 "recipient": {"name": "Labor Beispiel", "number": "49309876543"},
 "options": {"ecm": true, "resolution": "fine"}, "kienzle": {"benutzer": "mfa1"}}
```

Nummern ohne `+`/`00`, national mit `49` (`030 9876543` → `49309876543`); der Dialplan `fax-out` wählt `0…` bzw. `00…`.

Regeln:
- Dateien und Ordner mit Gruppenrechten anlegen (0660/0770), sonst greift die Vorgabe-ACL nicht.
- Fertige Berichte und empfangene Faxe holt das Modul fax sofort in seinen Datenordner; im Austauschordner bleiben nur
  laufende Aufträge (Datensparsamkeit, Löschfristen im Modul fax).
- Der Fax-Worker läuft im Modul telefon (`kienzle-telefon-fax`), weil er die Telefonanlage (AMI, `asterisk -rx`) braucht.
