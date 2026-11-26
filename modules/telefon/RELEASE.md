# Release-Notizen Telefon

## 0.1.0-beta.4

- Beschreibung nennt, dass Telefon die Faxleitung für das Modul Fax bereitstellt und Fax erst danach installiert werden kann.
- Eigenes Symbol (Hörer).
- Nicht auf der VM geprüft.

## 0.1.0-beta.3

- **Sprachmenü von Kienzlefon angeschlossen** (Einstellungen → Sprachmenü, Vorgabe aus): Die Anlage erzeugt ihre Asterisk-Dateien jetzt in der Aufteilung von Kienzlefax (`extensions-kfx-telefonie.conf`, `pjsip-kfx-telefonie.conf`, `queues-kfx.conf`, `queuerules-kfx.conf`). Ist das Sprachmenü an, setzt Kienzlefon 2.7 unverändert darauf auf (`install_asterisk_config`), und das Modul ersetzt die festen Pfade von Kienzlefon durch die eigenen Ordner. Der Praxiseingang geht dann an `kienzlefon-entry` statt direkt in die Warteschlange.
- `kienzlefon.toml` liegt im Datenordner (`kienzlefon/`, 0600) und bekommt die Ordner des Moduls, Leitung und Absendernummer der Anlage und ein eigenes Passwort für das rote Telefon. Startskripte für alle Programme von Kienzlefon unter `lib/kzf-bin/`.
- Sprachausgabe wählbar: Piper (Standard), Google und Kokoro wahlweise zuschaltbar. **Noch nicht gebaut:** Erzeugen der Ansagen, Seiten für Zeiten und Texte, Wartemusik, Aufnahme von Anliegen (Whisper). Ohne Ansagen spielt das Menü nichts ab; deshalb vorerst ausgeschaltet lassen.
- Neues Paket `python3-cryptography` (braucht Kienzlefon für den Faxkanal-unabhängigen Teil des Sprachmenüs).
- Geprüft mit Tests und Asterisk 20.6 in der Testumgebung: Ein Anruf in den Praxiseingang erreicht das Sprachmenü, das AGI läuft mit der Konfiguration des Moduls und fordert die Ansagen aus dem Modulordner an. Nicht auf der VM und nicht mit echtem Telefon geprüft.

## 0.1.0-beta.2

- **Kienzlefon 2.7 übernommen** (Stufe 2 beginnt): Python-Paket, Tests, Dokumentation und Vorlagen unverändert unter `kienzlefon/` (Herkunft und Einbauweg: `kienzlefon/HERKUNFT.md`). Noch nicht eingebunden: Die Anlage arbeitet wie in beta.1; Sprachmenü, Zeiten und Ansagen folgen in den nächsten Versionen.
- Sprachausgabe entschieden: Piper (Standard) und Google (nur feste Praxistexte), Kokoro wahlweise; Python-Pakete aus PyPI mit festen Versionen.
- Die 597 Tests von Kienzlefon laufen in `tests/run-all.sh` und in der CI mit (ohne die nicht übernommenen Bash-Installer).

## 0.1.0-beta.1

- **Neues Modul „Telefon“** auf Grundlage von Kienzlefax 3.5.0 und Kienzlefon 2.7 (Dr. Thomas Kienzle, MIT-Lizenz). Stufe 1 von 5 (`docs/PLAN-FAX-TELEFON.md`): die Telefonanlage aus Kienzlefax.
- **Eigene Asterisk-Instanz** aus dem Ubuntu-Paket (kein Bau aus dem Quellcode), als `ks-telefon` mit Konfiguration im Datenordner; der Paketdienst `asterisk.service` wird von der Basis abgeschaltet. Nur die benötigten Asterisk-Module werden geladen; Protokoll nur Warnungen und Fehler, sieben Tage; keine Anrufliste (CDR) mit Rufnummern.
- **Leitungen** der Anbieter wie in Kienzlefax: 1&1 (UDP und TLS mit SRTP), Telekom, sipgate, voip2gsm, Fonial/Plusnet, sipgate trunking (Überlauf), andere. Passwörter verschlüsselt mit dem Modulschlüssel der Basis; in der Seite nie sichtbar.
- **Rufnummern:** eingehende Telefonleitung, ausgehende Leitung und Absendernummer, Faxleitung mit Faxnummer und Kennung, Sipgate-Überlauf. Mehrere Rufnummern auf einer Leitung werden nach Nummer verteilt.
- **Gemeinsame Kanalgrenze** für Telefon und Fax (wie Kienzlefax: Hauptanschluss insgesamt, Telefon, Fax, Überlauf), Warteschlange „praxis“ mit wachsender Rufgruppe (alle 20 Sekunden ein Telefon mehr), deutsche Positionsansage, höchstens N Wartende, Überlast mit „besetzt“ vor der Annahme.
- **Telefone (Nebenstellen)** ab 201 mit eigenem Passwort; Anmeldung nur aus dem eingetragenen Netz; Zugangsdaten nur nach frischer Bestätigung (Passwort+OTP oder Passkey). Interne Anrufe klingeln nur bei freiem Telefon. Notrufe 110/112 immer; Auslandsgespräche und Sonderrufnummern (0900, 0137, 118…) sind gesperrt, bis sie in den Einstellungen erlaubt werden (neu gegenüber Kienzlefax).
- **Faxkanal** für das Modul Fax: Fax-Worker und AGI-Status aus Kienzlefax unverändert (Wiederholungen bei Besetzt/keine Antwort, Sendebericht mit Dokument, Kopfzeile mit Praxisnamen aus der Basis), Empfang als PDF in den Austauschordner (`docs/AUSTAUSCH-FAX.md`).
- Seiten: Übersicht (Anlage, Leitungen angemeldet, Telefone angemeldet, Gespräche, Wartende) und Einstellungen; Rechte `status` und `nebenstellen`; Firewall-Einträge für Telefone (5060/UDP), Anbieter (5070/UDP) und Sprache (12000–12999/UDP).
- Geprüft mit Tests und mit Asterisk 20.6 aus Ubuntu 24.04 in der Testumgebung: Die erzeugte Konfiguration lädt ohne Fehler, ein Fax läuft über eine lokale Schleife vom Auftrag bis zum empfangenen PDF. **Nicht geprüft:** echtes Ubuntu 26.04 (Asterisk 22.5), echte Leitung eines Anbieters, echte Telefone, echtes Faxgerät, Firewall, systemd-Härtung der Dienste.
