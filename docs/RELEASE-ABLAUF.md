# Releases bauen, signieren, veröffentlichen

Gilt ab Basis 0.4.0. Konzept: `docs/ARCHITEKTUR.md`, Abschnitt „Updates“.

## Einmalig: Release-Schlüssel erzeugen

Am besten auf einem Rechner ohne Netz, mit Ubuntu, macOS oder Windows (WSL) und OpenSSL 3:

```
scripts/schluessel-erzeugen kienzle-release-2026     # Hauptschlüssel
scripts/schluessel-erzeugen kienzle-reserve-2026     # Reserveschlüssel
```

OpenSSL fragt jeweils nach einem Passwort (lang, nur im Kopf bzw. im Passwortmanager). Ergebnis je Schlüssel:

- `NAME.pem` – **privat**. Offline aufbewahren: USB-Stick im Praxistresor, zweite Kopie an einem anderen Ort.
  Nie ins Repository, nie in GitHub Actions, nie per E-Mail.
- `NAME.pub.pem` – öffentlich. Nach `installer/release-keys/` legen und committen.

Den ausgegebenen Fingerabdruck in `README.md` eintragen. Wer installiert, vergleicht ihn mit der Ausgabe von
`openssl pkey -pubin -in /etc/kienzle/release-keys/NAME.pub.pem -outform DER | sha256sum`.

Solange in `installer/release-keys/` kein Schlüssel liegt, nimmt der Updater keine Releases an. Ausnahme:
Beta-Versionen ohne Signatur, wenn ein Admin das im Beta-Kanal ausdrücklich zugelassen hat (siehe unten).

## Je Release der Basis

1. Version in `core/VERSION` erhöhen, `docs/RELEASE.md` ergänzen, alle Tests grün, committen.
2. Bauen (nur eingecheckte Dateien, reproduzierbar):
   ```
   scripts/release-bauen core                 # stabil
   scripts/release-bauen core --kanal beta    # Vorabversion, Version z. B. 0.5.0-beta.1
   ```
   Ergebnis: `dist/core-<version>.tar.gz` und `dist/core-<version>.manifest.json`.
3. Signieren mit dem privaten Schlüssel (fragt nach dem Passwort, prüft gegen `installer/release-keys/`):
   ```
   scripts/release-signieren dist/core-<version>.manifest.json --schluessel /media/tresor/kienzle-release-2026.pem
   ```
4. Veröffentlichen als GitHub-Release mit dem Tag `core-v<version>`; bei Beta als Vorabversion:
   ```
   gh release create core-v<version> dist/core-<version>.* --title "Basis <version>" --notes-file notizen.md [--prerelease]
   ```
   Die Notizen erscheinen auf der Update-Seite der Praxis (nur Text).

## Was der Server prüft

- Signatur über das Manifest mit einem Schlüssel aus `/etc/kienzle/release-keys/`. Eine vorhandene, aber falsche
  Signatur wird **immer** abgelehnt.
- Manifest: Format, Teil, Version neuer als die installierte, Kanal.
- Archiv: Größe und SHA-256 laut Manifest; beim Auspacken nur normale Dateien, genau die Dateien des Manifests, jede
  mit Größe, SHA-256 und Rechten (0644 oder 0755).
- Danach: Schnappschuss, Installer der neuen Version, Prüfung nach dem Start, bei Fehler Rücksprung.

## Unsignierte Beta-Versionen

Ein Basis-Admin kann unter **Verwaltung → Updates → Kanal** im Beta-Kanal „auch unsignierte Versionen zulassen“.
Dann installiert der Updater Vorabversionen **ohne Signaturdatei**. Manifest und Prüfsummen werden weiter geprüft;
das schützt aber nur vor Beschädigung, nicht vor Fälschung: Wer Releases im Repository anlegen kann, könnte dann
Programme mit root-Rechten auf den Server bringen. Nur für Test-Server. Stabile Versionen brauchen immer eine Signatur.

## Schlüssel wechseln oder Verlust

- Neuen öffentlichen Schlüssel nach `installer/release-keys/` legen und ein Release mit einem **bisher vertrauten**
  Schlüssel signieren; der Installer dieses Releases bringt den neuen Schlüssel mit. Danach den alten entfernen.
- Hauptschlüssel verloren oder gestohlen: mit dem Reserveschlüssel ein Release signieren, das den alten Schlüssel aus
  `installer/release-keys/` entfernt.
