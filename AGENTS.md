# Arbeitsregeln

Konzept und Aufbau: `docs/ARCHITEKTUR.md`. Vor jeder größeren Änderung lesen.

## Sprache

Oberfläche, Hilfetexte, Dokumentation und Commit-Nachrichten auf Deutsch.

## Versionierung

- Jedes Modul (`modules/<id>/module.toml`) und die Basis (`core/VERSION`) haben eine eigene Version.
- Bei jeder Änderung die Version des betroffenen Teils erhöhen, auch bei Fehlerbehebungen und Dokumentation.
  Zusammengehörige Änderungen eines Auftrags erhalten gemeinsam eine neue Version.
- Ändert sich die Schnittstelle der Basis für Module, die `core_api`-Version erhöhen und alle Module anpassen.
- Release-Notizen je Teil in `docs/RELEASE.md` bzw. `modules/<id>/RELEASE.md`.

## Sicherheit und Daten

- Nie echte Patientendaten, Namen, IP-Adressen, Zugangsdaten oder Schlüssel in Code, Tests, Screenshots oder Videos.
  Nur erfundene Beispieldaten und Platzhalter-Adressen (192.0.2.0/24, example.org).
- Neue Funktionen prüfen gegen das Sicherheitskonzept in `docs/ARCHITEKTUR.md`: Rechte, CSRF, Eingaben, Pfade,
  Datensparsamkeit, Protokolle ohne Patientendaten.
- Module schreiben nur in ihren eigenen Datenordner; root-Aktionen nur über die Dienste der Basis.
- Geheimnisse nie ausgeben, nie protokollieren.

## Tests

- Jede Änderung mit Tests; alle Tests müssen vor einem Commit grün sein.
- Was nicht auf echter Hardware oder einem echten Ubuntu 26.04 getestet wurde, in Commit bzw. Release-Notiz so benennen.

## Git

- Nicht pushen und keine Tags setzen ohne ausdrückliche Zustimmung des Nutzers.
