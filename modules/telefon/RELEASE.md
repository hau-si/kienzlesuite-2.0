# Release-Notizen Telefon

## 0.1.0-beta.8

- **Wartemusik** (Telefon → Sprachmenü → „Wartemusik“, unter der Warteschlange): Klingelzeichen, die Wartemelodie von Johannes Plachetka (41 s, MIT-Lizenz mit seiner Freigabe, als MP3 im Modul unter `medien/`, 1,6 MB) oder **eigene Musik zum Hochladen** (MP3, WAV, OGG, FLAC, M4A; höchstens 7 MB und 30 Minuten). Umgewandelt wird mit `hold_music.install_media` aus Kienzlefon (Lautheit angeglichen, alle Asterisk-Formate) in den Datenordner (`kienzlefon/wartemusik`); die hochgeladene Datei wird danach gelöscht. Abspielfeld auf der Seite, am Telefon über Nebenstelle 8805; „Wartemusik löschen“ stellt auf Klingelzeichen zurück.
- Hochgeladen wird roh per `fetch` (Module haben keine Formular-Uploads); Prüfung am Dateikopf und mit `ffprobe`. Hinweis auf der Seite: nur Musik mit Nutzungsrecht (GEMA).
- Bedienbar mit dem Recht `sprachmenue`.
- Geprüft mit Tests (echtes ffmpeg: Umwandlung der Plachetka-Musik und einer erzeugten WAV, Ablehnung fremder Dateien, Warteschlange mit `musicclass`). **Nicht geprüft:** Hochladen im Browser, Wiedergabe in einem echten Gespräch, VM.

## 0.1.0-beta.7

- **Neue Seite „Sprachmenü“** (`/telefon/sprachmenue`, Knopf in der Übersicht und unter Einstellungen → Sprachmenü): Ausklappzeilen für Öffnungszeiten, Telefonzeiten, Apotheken und Pflege, Krankenhaus/Notarzt/Kollegen (je Wochentag, mehrere Zeiträume mit Komma), Begrüßung und Notfall, Menü und Tasten (alle vier Auswahlmenüs), Aufnahme von Anliegen, Warteschlange (Zahlwörter eingeklappt), Ansagen-Telefon 777 und weitere Texte. Je Text „Spielen: immer / nur wenn geöffnet / nur wenn geschlossen / nie“, wo Kienzlefon das vorsieht, die Vorgabe als Hinweis und ein Abspielfeld, sobald die Ansage erzeugt ist.
- Gespeichert wird in `kienzlefon.toml`; geprüft wird mit der Prüfung der Weboberfläche von Kienzlefon 2.7 (unverändert übernommen) und danach mit dem Laden der ganzen Konfiguration. Ein zweiter Bearbeiter, der inzwischen gespeichert hat, wird erkannt („bitte neu laden“). Danach wendet das Modul die Anlage neu an (die KI-Taste steht im Wählplan) und erzeugt die geänderten Ansagen, wenn die Sprachausgabe bereit ist. Die Werte bleiben beim Neu-Erzeugen der Anlage erhalten.
- Neues Recht `sprachmenue` („Zeiten, Ansagetexte und Menütasten des Sprachmenüs ändern“), z. B. für die Praxisleitung; die übrigen Einstellungen bleiben bei Admins.
- Geprüft mit Tests (Lesen/Schreiben mit echter Prüfung von Kienzlefon, Rechte, Abspielen). **Nicht geprüft:** VM, echte Anrufe mit den geänderten Zeiten und Tasten.

## 0.1.0-beta.6

- **Google wahlweise zuschaltbar** (Einstellungen → Sprachmenü → „Google (wahlweise)“): API-Schlüssel (verschlüsselt gespeichert, nie wieder angezeigt, leer lassen = behalten, eigener Haken zum Löschen), Stimme und Sprechtempo. Für den Generator von Kienzlefon steht der Schlüssel nur in `kienzlefon/google-tts.conf` (0600), nicht in `kienzlefon.toml`. Es gehen nur die festen Ansagetexte an Google.
- **Kokoro wahlweise nachladen** („Kokoro einrichten“/„Kokoro entfernen“): Der Installer aus Kienzlefon läuft unverändert (feste Modell-Prüfsummen, eigene Python-Laufzeit 3.12, Paketliste), aber mit Ziel im Datenordner und ohne root (`lib/kokoro-einrichten`). Groß (mehrere GB) und langsam; deshalb nur auf ausdrücklichen Knopfdruck.
- „Ansagen erzeugen“ erscheint, sobald die gewählte Sprachausgabe bereit ist (Piper geladen, Kokoro geladen bzw. Google-Schlüssel gespeichert).
- Neue Pakete für Kokoro: `espeak-ng`, `libsndfile1`, `libgomp1`, `git` (der Installer lädt zwei Pakete aus Git-Quellen).
- Geprüft mit Tests (Prüfung der Google-Angaben, Generator-Datei 0600, Aufrufer des Kokoro-Installers mit Ersatz-Installer). **Nicht geprüft:** echter Abruf bei Google, echtes Laden von Kokoro, VM.

## 0.1.0-beta.5

- **Piper wird optional nachgeladen** (Einstellungen → Sprachmenü → „Piper einrichten“), damit das Modul klein bleibt: eigene Python-Umgebung im Datenordner mit festen Versionen und Prüfsummen (`lib/piper/requirements-cp314.lock`, Piper 1.8.0 mit onnxruntime, für Python 3.14) und die Stimme „Thorsten“ (de_DE-thorsten-high) aus der festen Fassung v1.0.0 der Piper-Stimmen. Zusammen etwa 200 MB. „Piper entfernen“ löscht beides; erzeugte Ansagen bleiben.
- **Ansagen erzeugen** („Geänderte Ansagen erzeugen“, „Alle neu erzeugen“): Kienzlefon 2.7 erzeugt die Ansagen mit Piper in den Ansagenordner des Moduls (alle Asterisk-Formate). Die Arbeit macht der Dienst `kienzle-telefon-ansagen` (Auftragsdatei); die Seite zeigt den Stand und lädt sich während eines Auftrags neu.
- Sprachmenü einschalten geht nur, wenn mindestens ein Telefon in der Warteschlange ist und diese Telefone fortlaufend nummeriert sind (Vorgabe von Kienzlefon).
- Neue Pakete: `ffmpeg`, `python3-venv`.
- Geprüft mit Tests (Ansagen mit einem nachgestellten Piper und echtem ffmpeg). **Nicht geprüft:** echtes Laden von Piper und der Stimme (aus der Testumgebung gesperrt; die Prüfsumme der Stimme wird beim ersten Laden ermittelt und gespeichert, nicht vorher festgelegt), Google und Kokoro (folgen), VM.

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
