# Release-Notizen Telefon

## 0.1.0-beta.22

- **Schutz des T2med-Demo-Schlüssels:** Ist in der Basis kein eigener T2med-API-Schlüssel hinterlegt (öffentlicher Demo-Schlüssel), lädt das Telefonverzeichnis je Aufruf höchstens 3 Seiten, also höchstens 3 Anfragen an T2med. So wird der Demo-Schlüssel nicht wegen zu vieler Anfragen gesperrt. Die Einstellungen und die Meldung nach dem Laden weisen darauf hin; mit eigenem Schlüssel wird wie bisher alles geladen.
- Geprüft mit Tests (nachgestelltes T2med mit 6 Seiten: Demo 3 Anfragen, eigener Schlüssel alle). **Nicht mit echtem T2med geprüft.**

## 0.1.0-beta.21

- **Fehler behoben: Anrufe auf die Telefonnummer landeten beim Fax (Faxton).** Melden sich Telefon- und Faxleitung beim selben Anbieter-Server an (z. B. zwei voip2gsm-Leitungen), kann Asterisk einen eingehenden Anruf nicht sicher der richtigen Leitung zuordnen und nahm die Faxleitung. Jetzt kennt jede Leitung alle eingetragenen Rufnummern; die gewählte Nummer entscheidet, ob der Anruf zum Telefon, zum Fax oder in den Überlauf geht.
- **Fehler behoben: Mit Sprachmenü (Kienzlefon) ging kein Anruf nach draußen über voip2gsm** („Everyone is busy/congested“). Kienzlefon wählte die Nummer als „49…“; voip2gsm nimmt nur „0…“ bzw. „00…“ an. Die Anlage setzt dafür jetzt `sip_zielformate` (Format `national`) für jede voip2gsm-Leitung in `kienzlefon.toml`.
- **Fehler behoben: Faxversand mit „Permission denied“** (Ghostscript/qpdf unter AppArmor): Das Modul meldet `gs` und `qpdf` jetzt bei der Basis an (`apparmor = ["gs", "qpdf"]`); zusammen mit Basis 0.22.0-beta.14 dürfen beide im Austauschordner arbeiten.
- Nach dem Update werden die Dateien der Anlage automatisch neu erzeugt; nichts neu einzustellen.
- Geprüft mit Tests (Dialplan, Zielformat über die Wahlprüfung von Kienzlefon). **Nicht geprüft:** echte Anrufe über voip2gsm auf der VM.

## 0.1.0-beta.20

- **Telefonverzeichnis aus T2med** (Einstellungen → „T2med-Telefonverzeichnis“): In T2med einen Drittanbieter-Eintrag `kienzle-telefonverzeichnis://?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}` anlegen und aufrufen. Die Suite lädt dann alle Patienten (Name, Geburtsdatum, Telefonnummern, Patientennummer) Schritt für Schritt mit Fortschritt oben auf der Seite; die alte Fassung bleibt bis zum Ende gültig. Nur die in der Basis freigegebene FHIR-Adresse wird angefragt; das Token liegt nur verschlüsselt und höchstens 4 Stunden auf dem Server. Datei `<daten>/t2med/patienten.sqlite` (0600). Einstellbar: Ortsvorwahl (für Nummern ohne Vorwahl), Anrufername an/aus, Verzeichnis löschen.
- **Anrufername am Telefon:** Bei eingehenden Anrufen (Hauptanschluss und Überlauf) zeigt das Telefon „Nachname, Vorname“, wenn die Nummer genau zu einem Patienten passt (`lib/anrufername.agi`, liest nur; Nummernabgleich wie kienzlefon-t2med: 030…, +49…, 0049…, „(0)“).
- **Suchfeld oben auf jeder Telefon-Seite** (Entwurf C, Recht „patienten“): Name oder Teile davon (Reihenfolge egal, ä = ae), Telefonnummer oder Patientennummer; Treffer mit Geburtsdatum, Nummern zum Anrufen (eigenes Telefon klingelt zuerst) und Knopf **„Akte“**; Pfeiltasten und Enter öffnen die Akte.
- **„Akte“ öffnet den Patienten in T2med am eigenen PC** über die Basis und den Kienzle-Agent (Basis 0.22.0-beta.10 oder neuer, GDT-Datei `C:\Kienzle\GDT\AUFRUFi.gdt`).
- **Seite „Anrufe“ (Entwurf B):** Wartende und Gesprächspartner mit Namen aus dem Verzeichnis und Knopf „Akte“, unbekannte Nummern mit „Im Verzeichnis suchen“; oben eine Leiste mit den Zahlen von heute.
- **Seite „Statistik“** (Reiter oben, Recht „statistik“): Anrufe heute und im Zeitraum (7 Tage, 30 Tage, 3 Monate), Anteil angenommen, Ø Wartezeit, Anrufe je Tag, Anliegen aus dem Sprachmenü, Wochenmuster (Wochentag × Stunde). Erfasst vom neuen Dienst `kienzle-telefon-ereignisse` (AMI-Zugang nur lesen: Statistik-Haken von Kienzlefon und Warteschlange). **Gespeichert werden nur Zeitpunkte, Anliegen und Wartezeiten, keine Nummern oder Namen**; nach zwei Jahren gelöscht.
- Asterisk-Module `app_userevent`, `func_sha1`, `func_rand` ergänzt; `UUID()` der Statistik-Haken (im Ubuntu-Paket fehlt `func_uuid`) wird durch eine gleich aufgebaute Kennung ersetzt. Neue Pakete: `php-curl`, `php-sqlite3`.
- Geprüft mit Tests (nachgestelltes T2med mit zwei Seiten, Suche, Nummern gleich in PHP und Python, Statistik, Seiten) und mit echtem Asterisk (Anrufername am Kanal gesetzt, Statistik erfasst, keine Nummer gespeichert); Seiten im Browser gegen den PHP-Testserver. **Nicht geprüft:** mit echtem T2med (Paging, Größe, Demo-Schlüssel), echten Telefonen, auf der VM.

## 0.1.0-beta.19

- **Sprachmenü, Zeitschemata:** Bei „Telefonzeiten“, „Apotheken und Pflege“ und „Krankenhaus, Notarzt und Kollegen“ gibt es den Knopf „Zeiten der Praxis übernehmen“: Er kopiert die Zeiten aus „Öffnungszeiten“ mit einem Klick in alle sieben Tage des Schemas. Gespeichert wird erst mit „Speichern“, davor lassen sich einzelne Tage noch ändern. Das Kopieren läuft im Browser (nicht am echten Gerät geprüft).

## 0.1.0-beta.18

- **Mehrere Netze:** „Netz der Telefone“ und „Anmelden erlaubt aus“ nehmen jetzt mehrere Netze, getrennt durch Komma (bis zu 8, nur IPv4). Nötig z. B. für Telefone über VPN: Das Telefon meldet sich aus dem VPN-Netz (z. B. 192.168.113.0/24), nennt aber als Kontakt die Adresse seines Heimnetzes (192.168.1.x); beide Netze gehören dann unter „Anmelden erlaubt aus“.
- **Erklärungen als Tooltips** (Maus über Feld oder Beschriftung) für die Felder im Abschnitt „Netz“, die MAC-Adresse und den Schalter der automatischen Einrichtung.
- Firewall: Das Netz, aus dem sich die Telefone melden (Port 5060/UDP), muss zusätzlich unter Verwaltung → Firewall zu den Praxisnetzen zählen. Nicht am echten Telefon über VPN geprüft.

## 0.1.0-beta.17

- **Automatische Einrichtung der Snom-Telefone (D785 u. a.) über die MAC-Adresse:** Admins tragen bei jedem Telefon die MAC-Adresse ein und schalten unter „Einrichtung der Telefone (Snom)“ den Dienst ein. Die Seite zeigt die Adresse `http://<Server>:8780/<Schlüssel>/{mac}.xml`; sie wird einmal im Telefon (Einstellungs-URL) oder im DHCP-Server (Option 66) eingetragen. Das Telefon holt sich Benutzer, Passwort, Server und Anzeigename selbst.
- **Sicherheit:** Eigener Dienst `kienzle-telefon-prov` (Port 8780/TCP, nur Praxisnetze in der Firewall, nie Internet). Ohne den geheimen 128-Bit-Schlüssel in der Adresse und die richtige MAC-Adresse gibt es 404; mehr als 20 Fehlversuche pro Minute und Adresse werden gebremst. Dateien 0600, Schlüssel jederzeit mit „Neuen Schlüssel vergeben“ ersetzbar. MAC-Adressen und der Schalter sind nur für Admins (sonst könnten Mitarbeiter mit dem Recht „Telefone“ fremde Zugangsdaten abrufen). Das Telefon-Passwort wird im Klartext in die Datei geschrieben (Snom lädt sie unverschlüsselt per HTTP); das Netz muss daher vertrauenswürdig sein.
- Geprüft mit Tests (MAC-Prüfung, Dateien, Schlüsselwechsel, Dienst-Abruf, Seiten und Rechte). **Nicht geprüft:** echtes Snom D785. Namen der Einstellungen in der XML-Datei und der Platzhalter `{mac}` stammen aus der Snom-Dokumentation von Hand und müssen am Gerät bestätigt werden; ggf. in `SNOM_VORLAGE` (`lib/anlage.py`) anpassen.

## 0.1.0-beta.16

- **Neue Seite „Anrufe“** (`/telefon/anrufe`, Entwurf B, Knopf in der Übersicht; Entscheidung des Nutzers: im Modul Telefon statt eigenem Modul): links die Warteschlange mit Nummer und Wartezeit (ROT markiert), Anzahl im Sprachmenü; rechts alle Telefone mit Zustand (frei, klingelt, im Gespräch mit Nummer und Dauer, nicht angemeldet). Aktualisiert sich alle 3 Sekunden, frisch aus Asterisk; gespeichert wird dabei nichts.
- **Wählen per Klick:** „Mein Telefon“ einmal wählen; dann klingelt beim Wählen zuerst das eigene Telefon, nach dem Abheben wählt die Anlage die Nummer (wie am Telefon gewählt, mit denselben Sperren und Kanalgrenzen). Zurückrufen per Klick auf eine wartende Nummer und mit „Zurückrufen“ an jeder Vorgangskarte.
- Neue Rechte `anrufe` („Live-Übersicht sehen“) und `waehlen` („per Klick wählen und zurückrufen“). Eigener AMI-Zugang `kienzle-web` nur von 127.0.0.1, ohne Systembefehle (lesen; wählen).
- Geprüft mit Tests, davon einer mit echtem Asterisk (Anrufer in der Warteschlange erkannt, Wählauftrag angenommen). **Nicht geprüft:** VM, echte Telefone (Klingeln und Verbinden), Browser.

## 0.1.0-beta.15

- **Tonaufnahmen anhören** (Seite „Vorgänge“, an jeder Karte „Aufnahmen anhören“): je aufgesprochenem Feld (Vorname, Nachname, Geburtsdatum, Rückrufnummer, Medikament 1, 2 …, Grund) ein Abspielfeld. Gilt für alle Vorgänge aus der Spracherkennung ab diesem Stand; ältere Aufnahmen wurden bisher sofort gelöscht.
- **Löschfrist der Aufnahmen 14 Tage** nach dem Anruf (Pflege, `TON_TAGE`). Die Klartext-Abschrift im Arbeitsordner von Kienzlefon wird weiterhin nach 2 Tagen gelöscht (sie steht verschlüsselt im Vorgang). „Endgültig löschen“ im Papierkorb löscht die Aufnahmen sofort mit.
- Technik: `audio_beilegen` wird aus `kienzlefon.toml` entfernt; dann behält Kienzlefon die Aufnahmen in `spool/ready/<id>/audio`. Abspielen nur mit dem Recht `vorgaenge`, ohne Zwischenspeichern im Browser.
- Geprüft mit Tests (Worker behält die Aufnahmen, Fristen der Pflege, Abspielen, Rechte, Löschen). **Nicht geprüft:** VM, echte Anrufe, Wiedergabe im Browser.

## 0.1.0-beta.14

- **Seite „Vorgänge“ ausgebaut** (Funktionen aus der Praxis-App kienzlefon-app, ohne eigenes Modul):
  - Reiter **Neu / In Bearbeitung / Erledigt / Papierkorb** mit Zählern.
  - **Übernehmen**: der angemeldete Benutzer bearbeitet den Vorgang („In Bearbeitung von … seit …“); andere können ihn übernehmen, „zurück zu Neu“ gibt ihn frei.
  - **Dringend** (rote Marke, steht oben).
  - **Teilanliegen abhaken** bei mehreren Anliegen eines Anrufs (wer, wann).
  - **Kommentare** je Vorgang (verschlüsselt mit dem Modulschlüssel; Bearbeitungsstand ohne Patientendaten daneben).
  - **Papierkorb**: „Löschen“ legt den Vorgang 7 Tage in den Papierkorb (wiederherstellbar), dort „Endgültig löschen“.
  - **Hinweis auf neue Vorgänge**: Die Seite fragt alle 20 Sekunden nur die Anzahl ab und zeigt dann einen Hinweis mit kurzem Ton (kein Neuladen, damit angefangene Texte bleiben).
- Bewusst nicht übernommen: eigene Anmeldung, Quellserver und SMS-Empfang (Entscheidung des Nutzers), Anhören der Tonaufnahmen (werden nach dem Umwandeln gelöscht).
- Geprüft mit Tests. **Nicht geprüft:** VM, Browser (Ton, Hinweis).

## 0.1.0-beta.13

- **Seite „Rufgruppen“** (`/telefon/rufgruppen`, Entwurf C, Knopf in der Übersicht): je Gruppe eine Karte („Normal“ = Taste 1, „ROT“ = Taste 9) mit den Telefonen als Knöpfe; farbig = klingelt, ein Klick meldet sofort an oder ab (bis zum nächsten Neustart der Anlage). Admins stellen darunter die Standards nach einem Neustart und die Rufstufen ein. Die letzte Nummer einer Gruppe lässt sich nicht abmelden.
- Einschalten unter Einstellungen → Sprachmenü → „Rufgruppen“ (braucht das Sprachmenü). Neues Recht `rufgruppen` („Telefone in den Rufgruppen an- und abmelden“), z. B. für MFA.
- Geprüft mit Tests (Seite, Rechte, Ein-/Ausschalten; Schalten gegen echtes Asterisk im Test von beta.12). **Nicht geprüft:** VM, echte Telefone.

## 0.1.0-beta.12

- **Unterbau für die Rufgruppen „Normal“ und „ROT“** (wer bei Taste 1 bzw. 9 klingelt; Telefone live an- und abmelden, Standards nach einem Neustart der Anlage). Steuerungsdienst von Kienzlefon als `kienzle-telefon-steuerung` (nur auf 127.0.0.1:8767, startet über `kienzle-telefon-steuerung.path`, sobald die Rufgruppen eingeschaltet sind), eigener AMI-Zugang nur von diesem Rechner (`manager_kienzlefon_control.conf`), Auftragsjournal im Datenordner, Befehle `anlage.py rufgruppen-lesen|-schalten|-speichern`. Kienzlefon bleibt unverändert; `lib/kzf_anpassung.py` biegt Journalordner und Prozesskennung (eigene Asterisk-Instanz statt `asterisk.service`) um.
- **Noch ausgeschaltet und ohne Seite** (die Seite „Rufgruppen“ folgt nach Wahl des Entwurfs); am Verhalten der Anlage ändert sich nichts.
- Geprüft mit echtem Asterisk (Telefone werden eingetragen, live abgemeldet, Standards gespeichert, nach einem Neustart von Asterisk wieder eingetragen). **Nicht geprüft:** VM, echte Telefone.

## 0.1.0-beta.11

- **SMS an Anrufer** (Seite „Vorgänge“, aufklappbar an jeder Karte „SMS an den Anrufer“): Handynummer aus der Anrufernummer vorbelegt (sonst aus der Akte eintragen), Textvorschlag je Art ohne Gesundheitsangaben (z. B. „Ihr Rezept liegt zur Abholung bereit. Ihre Praxis …“), vor dem Senden änderbar, wahlweise danach als erledigt markieren. Der Stand („wird verschickt“, „verschickt“, „nicht verschickt“ mit Grund) steht an der Karte. Verschickt wird über den Nachrichten-Ausgang der Basis (Verbindung `nachrichten`, braucht Basis 0.22.0-beta.8 und eine SMS-Verbindung unter Verwaltung → Verbindungen); das Modul kennt den SMS-Zugang nicht und merkt sich je Vorgang nur die Auftragskennung.
- Geprüft mit Tests (Nummer, Vorlage, Auftrag, Rechte, Anzeige). **Nicht geprüft:** echter SMS-Versand, VM.

## 0.1.0-beta.10

- **Aufnahme von Anliegen mit Whisper** (Einstellungen → Sprachmenü → „Whisper einrichten“, nur Admins): Anrufer sprechen Rezept, Überweisung, Termin, Rückruf oder Sonstiges auf; der Worker von Kienzlefon wandelt die Aufnahmen **auf dem Server** in Text um (faster-whisper, CPU). Whisper wird wie Piper nur auf Knopfdruck geladen: eigene Python-Umgebung aus `lib/whisper/requirements-cp314.lock` (23 Pakete mit Prüfsummen) und das Modell large-v3-turbo (etwa 1,6 GB); wahlweise „Namen genauer erkennen“ mit large-v3 (zusätzlich etwa 3 GB). Neuer Dienst `kienzle-telefon-whisper` (startet, sobald Whisper bereit ist, über `kienzle-telefon-whisper.path`; offline, ohne Telemetrie). Ohne Whisper landen diese Anrufer wie bisher in der Warteschlange.
- **Neue Seite „Vorgänge“** (`/telefon/vorgaenge`, Knopf mit Zähler in der Übersicht, neues Recht `vorgaenge`): je Anliegen eine Karte mit Art, Zeit, Anrufernummer, Name, Geburtsdatum, Rückrufnummer, Medikamenten, Fachrichtung bzw. Grund; mehrere Anliegen eines Anrufs untereinander. Knöpfe „Erledigt“, „Wieder öffnen“, „Löschen“.
- **Datenschutz:** Vorgänge liegen verschlüsselt im Datenordner (Format von Kienzlefon, RSA 3072 + AES-256). Das Schlüsselpaar erzeugt das Modul; der geheime Teil liegt nur verschlüsselt mit dem Modulschlüssel der Basis vor, der Worker kann Vorgänge also schreiben, aber nicht lesen. Tonaufnahmen löscht der Worker nach dem Umwandeln. Die Pflege löscht erledigte Vorgänge nach 7 Tagen und verarbeitete Anrufe im Spool nach 2 Tagen (fehlgeschlagene nach 7). Inhalte stehen nie im Protokoll.
- Geprüft mit Tests (Worker von Kienzlefon mit Ersatz-Whisper vom Spool bis zur Ablage, Entschlüsseln in PHP, Rechte, Pflege, Paketliste). **Nicht geprüft:** echtes Laden von Whisper und den Modellen, Erkennungsgüte und Dauer auf dem Prozessor der VM, echte Anrufe.

## 0.1.0-beta.9

- **Wartemusik** (Telefon → Sprachmenü → „Wartemusik“, unter der Warteschlange): Klingelzeichen, die Wartemelodie von Johannes Plachetka (41 s, MIT-Lizenz mit seiner Freigabe, als MP3 im Modul unter `medien/`, 1,6 MB) oder **eigene Musik zum Hochladen** (MP3, WAV, OGG, FLAC, M4A; höchstens 7 MB und 30 Minuten). Umgewandelt wird mit `hold_music.install_media` aus Kienzlefon (Lautheit angeglichen, alle Asterisk-Formate) in den Datenordner (`kienzlefon/wartemusik`); die hochgeladene Datei wird danach gelöscht. Abspielfeld auf der Seite, am Telefon über Nebenstelle 8805; „Wartemusik löschen“ stellt auf Klingelzeichen zurück.
- Hochgeladen wird roh per `fetch` (Module haben keine Formular-Uploads); Prüfung am Dateikopf und mit `ffprobe`. Hinweis auf der Seite: nur Musik mit Nutzungsrecht (GEMA).
- Bedienbar mit dem Recht `sprachmenue`.
- Geprüft mit Tests (echtes ffmpeg: Umwandlung der Plachetka-Musik und einer erzeugten WAV, Ablehnung fremder Dateien, Warteschlange mit `musicclass`). **Nicht geprüft:** Hochladen im Browser, Wiedergabe in einem echten Gespräch, VM.

## 0.1.0-beta.8

- **Faxkennung (Name im Faxkopf) leer = Praxisname:** Bleibt das Feld unter Einstellungen → Rufnummern → Fax → „Faxkennung“ leer, nimmt die Anlage den Praxisnamen aus Verwaltung → Praxis (ohne Sonderzeichen, Umlaute als ae/oe/ue), sonst „Fax“. **Bis 60 statt 20 Zeichen:** Der volle Name steht als Kopfzeile (`FAXOPT(headerinfo)`) auf jeder Seite; die Stationskennung im Faxprotokoll erlaubt technisch nur 20 Zeichen und bekommt die an Wortgrenze gekürzte Form. Das Feld ist deutlicher beschriftet; das Modul Fax verweist darauf. Nicht an einem echten Faxgerät geprüft (ob der Kopf beim Empfänger vollständig erscheint, hängt auch vom Gerät ab).

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
