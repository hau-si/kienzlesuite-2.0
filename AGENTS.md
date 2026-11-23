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
- **Zweige:** Gepusht wird (nach Zustimmung) auf den Zweig `beta`, nie auf `main`. Auf `beta` tragen die geänderten Teile eine Vorabversion
  (`0.20.0-beta.1`, `2.3.0-beta.2`; je Änderung hochzählen). `.github/workflows/beta.yml` veröffentlicht daraus nach grünen Tests
  automatisch eine Vorabversion, die Server im Beta-Kanal selbst erkennen. `main` bekommt Änderungen nur als Stand mit Endversion
  (ohne `-beta`) und einem Tag `<teil>-v<version>` durch den Nutzer (`release.yml`, stabiler Kanal).
