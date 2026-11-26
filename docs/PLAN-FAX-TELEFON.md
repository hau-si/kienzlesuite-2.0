# Fax und Telefon als Module (Übernahme von Kienzlefax und Kienzlefon)

Stand: 26.11.2026. Quellen: [thomaskien/kienzlefax-fuer-linux](https://github.com/thomaskien/kienzlefax-fuer-linux) 3.5.0
(Faxserver und Telefonanlage) und [thomaskien/kienzlefon](https://github.com/thomaskien/kienzlefon) 2.7 (Sprachmenü,
Transkription, Ansagen, KI). Beide MIT-Lizenz, von Dr. Thomas Kienzle.

## Entscheidungen des Nutzers (26.11.2026)

- Zwei Module: **`fax`** (Pfad `/fax`) und **`telefon`** (Pfad `/telefon`).
- Die **Telefonanlage (Asterisk) gehört zum Modul Telefon**. Fax braucht Telefon.
- Der Code wird **übernommen**; danach kann Thomas die Module hier weiterentwickeln.
- **Alles** wird übernommen (Fax, Warteschlange, Sprachmenü, Whisper, Ansagen, KI, Middleware, App-Schnittstellen).
- **SMS an Anrufer** soll möglich sein (über die SMS-Verbindung der Basis).
- Die Telefonanlage bleibt im Modul Telefon (Prüfung am 26.11.2026: in der Basis wäre Asterisk weniger abgeschottet, mehr root-Code
  und jedes Basis-Update träfe die Anlage). Eine Praxis nur mit Fax installiert Telefon ohne Telefone.
- **Sprachausgabe:** Piper (Standard); Google (nur feste Praxistexte) und Kokoro wahlweise zuschaltbar; Python-Pakete aus PyPI mit festen Versionen.
- Benutzer, Rechte und Schnittstellen (Mail, SMS, T2med) kommen aus der Basis. Die eigenen Passwörter der alten
  Weboberflächen entfallen.

## Aufbau

```
Modul telefon (ks-telefon)                         Modul fax (ks-fax)
  Asterisk 22 aus Ubuntu 26.04 (eigene Instanz,      Weboberfläche: Senden, Telefonbuch, Aufträge,
  Konfiguration im Datenordner, kein root)           Sendeprotokoll, Fehlerberichte, Faxeingang
  Leitungen, Nebenstellen, Warteschlange,            virtuelle Drucker vFax1 … vFax5
  Kanalgrenzen (aus Kienzlefax)                      Eingangsdienst (empfangene Faxe übernehmen)
  Faxkanal: Worker, AGI-Status, SendFAX/ReceiveFAX
            \                                        /
             `--- /var/spool/kienzle/austausch/fax_telefon ---'
                  (Austauschordner der Basis, nur diese beiden Module)
```

- **Asterisk** kommt als Paket (`asterisk`, `asterisk-modules` mit `res_fax_spandsp`, `asterisk-prompt-de`). Der Bau aus
  dem Quellcode wie bei Kienzlefax entfällt. Der Paketdienst `asterisk.service` wird von der Basis abgeschaltet
  (`[system] disables`), das Modul startet eine eigene Instanz als `ks-telefon` (`kienzle-telefon-asterisk.service`).
- **Austauschordner** (`[[exchange]]`, Basis 0.22.0): Melden zwei Module einander an, legt die Basis
  `/var/spool/kienzle/austausch/<a>_<b>` an (root, 0770, ACL nur für `ks-<a>` und `ks-<b>`). Kein anderes Modul kommt hinein.
- **Vertrag Fax ↔ Telefon** im Austauschordner: das Auftragsformat von Kienzlefax (`queue/<job>/job.json` + `doc.pdf`,
  `processing/`, `sendeberichte/`, `sendefehler/eingang|berichte/`) und `empfang/` für eingegangene Faxe (PDF + JSON).
  Einzelheiten: `modules/telefon/docs/AUSTAUSCH-FAX.md`.

## Stufen

| Stufe | Inhalt | Stand |
|---|---|---|
| 1 | Basis: `[[exchange]]`, `[system] disables`, `core_api` 2. Telefon: Anlage (Leitungen, Nebenstellen, Warteschlange, Kanalgrenzen, Faxkanal). Fax: Drucker, Senden, Telefonbuch, Aufträge, Berichte, Eingang | fertig (Basis 0.22.0-beta.1, Telefon/Fax 0.1.0-beta.1) |
| 2 | Kienzlefon: Sprachmenü (AGI), Zeiten, Ansagen (Piper; Google und Kokoro wahlweise), Anrufsbaum, Sonderqueue, rotes Telefon, Ansagen-IVR 777 | Code übernommen (beta.2), Sprachmenü angeschlossen (beta.3), Piper optional nachladen und Ansagen erzeugen (beta.5), Google und Kokoro wahlweise (beta.6), Seite „Sprachmenü“ für Zeiten, Texte und Tasten (beta.7), Wartemusik von Johannes Plachetka oder eigene Datei (beta.9) |
| 3 | Kienzlefon: Whisper-Worker, Vorgänge (Rezept, Überweisung …) mit Ablage im Modul und kurzer Löschfrist; Basis: Nachrichten-Ausgang für Module (SMS und Mail über die Verbindungen der Basis, ohne Zugangsdaten im Modul); SMS an Anrufer; Mail bei Faxeingang | Whisper optional nachladen, Seite „Vorgänge“ verschlüsselt mit Löschfrist (Telefon beta.10); Nachrichten-Ausgang der Basis (0.22.0-beta.8), SMS an Anrufer (Telefon beta.11), E-Mail bei Faxeingang (Fax beta.4) – fertig |
| 4 | Kienzlefon: Rufgruppen-Steuerung (Port 8767), AMI für die T2med-Middleware, KI-Übergabe (WireGuard), App-Schnittstelle; Firewall-Einträge | Rufgruppen nur lokal mit Seite (Telefon beta.12/13); Middleware und App als eigene Schritte, siehe „Vorschlag: Middleware und App“ |
| 5 | Kienzlefax: Scan-/Fax-Texterkennung (OCR), Samba-Freigaben über die Basis; Umzug bestehender Installationen (`kienzlefax-installer.env`, `kienzlefon.toml`) | Texterkennung empfangener Faxe mit Suche im Eingang (Fax beta.10); Samba entfällt (Entscheidung des Nutzers: NAS-Ordner der Basis reicht); Umzug zurückgestellt (Entscheidung des Nutzers) |

## Vorschlag: Middleware und App (Stand 26.11.2026, Entscheidung des Nutzers ausstehend)

Entscheidung des Nutzers: Middleware, App und KI kommen später als **Module**; Zugriff von außen auf die Anlage vorerst nicht.

- **kienzlefon-app (Praxis-App, ca. 17 000 Zeilen PHP):** kein zweites Modul, sonst gäbe es zwei Posteingänge für dieselben Vorgänge.
  Ihre fehlenden Funktionen wandern in die Seite „Vorgänge“ des Moduls telefon (erledigt in Telefon beta.14; SMS-Empfang und Quellserver vorerst nicht, Entscheidung des Nutzers): „In Bearbeitung“ mit Bearbeiter (angemeldeter
  Benutzer der Suite) und Übernahme, Teilanliegen abhaken, Kommentare, „Dringend“, Papierkorb, Neu-Laden mit Ton, wahlweise
  Tonaufnahme anhören (kurze Frist). Status und Kommentare verschlüsselt neben dem Vorgang. Entfällt: eigene Anmeldung,
  Quellserver/SCP-Abholer, eigener SMS-Worker (Basis), Installer. Offen: SMS-**Empfang** über die FRITZ!Box (bräuchte einen
  Nachrichten-Eingang der Basis), Kontaktformular und IONOS-Sprachbot (brauchen einen Server im Internet).
- **kienzlefon-t2med (Middleware, PHP/ReactPHP + Go-Importer):** Entscheidung des Nutzers (26.11.2026): Live-Übersicht und Wählen **im Modul telefon** (Seite „Anrufe“, Telefon beta.16); die T2med-Funktionen werden später entschieden (in telefon oder eigenes Modul).
  Funktionen in dieser Reihenfolge: 1. Live-Übersicht (Warteschlange, Gespräche, Nebenstellen) und Wählen per Klick;
  2. Anrufername aus T2med auf dem Telefon und Patientensuche (Telefonverzeichnis über die T2med-Verbindung der Basis statt
  Demo-Schlüssel); 3. Akte öffnen per GDT (Freigabe über die Basis); 4. Anrufstatistik. Umbau: Dienst nur auf 127.0.0.1 hinter
  nginx mit Anmeldung der Suite, Pfade im Datenordner, eigener AMI-Benutzer mit wenigen Rechten, den das Modul telefon über den
  Austauschordner bereitstellt, Rufgruppen über den Steuerungsdienst (schon da). `tel:`-Links am Arbeitsplatz über den zentralen
  Starter der Basis statt eines eigenen Python-Clients.
- **KI-Übergabe:** erst, wenn es den KI-Dienst gibt (Schnittstelle beschrieben in `kienzlefon/docs/SPEZIFIKATION-…-v1.1.md`).

## Was bei der Übernahme anders ist

- Kein Bash-Installer: Einstellungen in der Weboberfläche, Prüfung in PHP und Python, Asterisk-Dateien erzeugt das Modul.
- Keine Weboberfläche ohne Anmeldung (Kienzlefax) und kein eigenes Passwort (Kienzlefon): Rechte der Suite.
- Keine Dateien unter `/etc/asterisk`, `/srv/kienzlefax`, `/srv/scan`: alles im Datenordner bzw. Austauschordner.
- Asterisk-Protokolle nur Warnungen und Fehler, sieben Tage (Rufnummern sind personenbezogen).
- Firewall: Ports kommen als Einträge des Moduls und sind zunächst geschlossen.

## Nicht auf echter Hardware geprüft

Alles in Stufe 1: keine echte SIP-Leitung, kein Telefon, kein Faxgerät, keine VM. Geprüft sind die erzeugten
Asterisk-Dateien, der Auftragsablauf mit nachgestelltem Asterisk und die Weboberflächen mit Tests.
