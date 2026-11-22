# Basis – Release-Notizen

## 0.2.0

Meilenstein 2 aus `docs/PLAN.md`: Anmeldung, Benutzer und Rechte, Admin-OTP, Passkeys, lokale Zertifizierungsstelle.

- **Eine Anmeldung für alle Module:** nginx prüft jede Anfrage an ein Modul bei der Basis (`/_auth/<modul>`) und
  reicht Benutzer, Rolle, Rechte, CSRF-Marke und Zeit der letzten Bestätigung weiter (`docs/MODUL-SCHNITTSTELLE.md`).
  Kopfzeilen vom Browser werden ersetzt, bei FastCGI heißen die Werte `KIENZLE_*`.
- **Konten und Rollen:** beliebig viele Konten; Basis-Admin, Admin je Modul, Mitarbeiter mit Rechten je Modul.
  Ein Basis-Admin sieht Module nicht automatisch. Passwort legt jede Person selbst über einen einmaligen Link fest.
- **Admins nur mit Passwort und OTP** (TOTP nach RFC 6238, geprüft mit den Testwerten der RFC), Code nur einmal gültig,
  10 Wiederherstellungscodes zum Drucken. Admin-Konten werden nur an der Konsole zurückgesetzt (`kienzle-admin`).
- **Passkeys** (WebAuthn, ES256/Ed25519/RS256, nur mit Nutzerprüfung am Gerät): ersetzen Passwort und Code bei der
  Anmeldung und bei Bestätigungen; Admins behalten OTP als Rückfall. Nur über den Servernamen, nicht über IP-Adressen.
- **Sitzungen:** einstellbare Abmeldezeiten, „Gerät merken“ nur aus dem Netz der Anmeldung, heikle Aktionen und die
  Verwaltung verlangen alle 24 Stunden eine erneute Bestätigung. Neuer Sitzungsschlüssel nach jedem Anmeldeschritt.
- **Schutz:** Sperre je Absender mit wachsender Dauer und Gesamtbremse (aus der Beta), CSRF-Marken, Prüfung von Origin und
  Servername, CSP und weitere Schutz-Kopfzeilen, Cookies `Secure; HttpOnly; SameSite=Strict`. Änderungsprotokoll ohne
  Passwörter, Codes oder Patientendaten.
- **Lokale Zertifizierungsstelle** (`kienzle-zertifikat`): ECDSA P-256, Namensbeschränkung auf die eigenen Namen und private
  Netze, Server-Zertifikat 825 Tage, tägliche Prüfung und Erneuerung 60 Tage vor Ablauf; CA unter `/ca.crt`.
- **Dienste:** eigene php-fpm-Instanz als `ks-core` mit systemd-Härtung, Socket-Ordner je Teil (nur Teil und nginx),
  nginx-Konfiguration aus festen Vorlagen (`kienzle-web`), nur Port 443, fremde Servernamen bekommen keine Seite.
- **Installer** (`installer/install.sh`, Grundstock): Pakete, Benutzer, Ordner, Schlüssel, CA, Dienste, Einrichtungslink;
  wiederholbar ohne Verlust von Schlüssel, CA und Daten.
- **Format `module.toml`:** Socket jetzt `unix:/run/kienzle/<id>/web.sock`; neu `web.protocol` (`http`/`fastcgi`) und `web.entry`.
- Aus der Beta übernommen: Sitzungen, Gerät merken, Bestätigung, Passwortregeln, Sperren (`src/Admin.php`), `Throttle`,
  `AuditLog`, `Database`, `Command`, `Problem`, Warten auf die Paketsperre.
- Tests: 110 Python- und 57 PHP-Testfälle, darunter ein nachgebauter Passkey-Authenticator mit echten Schlüsseln;
  Ende-zu-Ende-Prüfung mit echtem nginx und php-fpm im Container `ubuntu:26.04` (`tests/integration/nginx.sh`).

Nicht geprüft: echte Ubuntu-26.04-VM mit systemd (Prüfliste `docs/VM-TEST.md`), echte Passkeys auf Windows, macOS,
iOS und Android, Namensbeschränkung der CA in allen Browsern, QR-Code mit echter Authenticator-App. Die
Ende-zu-Ende-Prüfung läuft erst in GitHub Actions; lokal gab es kein nginx und kein php-fpm.

## 0.1.1

- Neu im Format `module.toml`: `[[connections]]`. Ein Modul meldet an, welche zentrale Verbindung der Basis es nutzt
  (`t2med`, `mail`, `ablage`), wofür (`purpose`) und ob es ohne sie arbeitet (`required`). Nur anmeldende Module
  bekommen die Zugangsdaten. Zugänge, die nur ein Modul nutzt (Letterxpress, SumUp-API, Google), bleiben im Modul.
- Tests für das neue Feld.

Nicht geprüft: Lauf auf echtem Ubuntu 26.04 (lokal Python 3.13).

## 0.1.0

Meilenstein 1 aus `docs/PLAN.md`: Gerüst der Basis und Format der Modul-Beschreibung.

- Ordnerstruktur `core/`, `modules/`, `installer/`, `tests/`; Basis-Version 0.1.0, Modul-Schnittstelle `core_api` 1.
- Format `module.toml` festgelegt (`docs/MODULE-TOML.md`), mit neuen Feldern gegenüber dem ersten Entwurf:
  `hooks.busy`, `hooks.health`, `[setup] overview`, `[[printers]]`, `firewall.scope`.
- Prüfprogramm `core/bin/kienzle-modul-pruefen`: prüft eine `module.toml` streng (unbekannte Schlüssel, Typen,
  Namen, Pfade, Ports, Rechte, Sicherungspfade, Haken), die Dateien im Modulordner (vorhanden, keine Symlinks,
  ausführbar, nicht fremd beschreibbar) und Konflikte zwischen Modulen (Webpfad, Druckername, Ports).
  Ausgabe auf Deutsch, auf Wunsch als JSON.
- Beispielmodul `modules/beispiel` ohne Funktion, nur für Tests.
- Tests: `tests/run-all.sh` (85 Testfälle für das Prüfprogramm), GitHub Actions auch im Container `ubuntu:26.04`.

Nicht geprüft: Lauf auf echtem Ubuntu 26.04. Lokal getestet mit Python 3.13; der Container-Job in GitHub Actions
prüft mit Python aus Ubuntu 26.04.
