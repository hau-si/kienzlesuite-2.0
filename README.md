# Praxisherz – freie Software für die Arztpraxis

Praxis-Appliance für Hausarztpraxen mit T2med: **eine Basis**, einmal auf einer VM installiert, und **Module**, die
einzeln dazukommen. Verwaltung komplett im Webinterface; Anmeldung, Benutzer und Rechte, Firewall, Admin-Zugang mit OTP,
Updates, verschlüsselte Sicherung und die Verbindungen zu T2med, E-Mail, SMS und Netzwerkspeicher gibt es nur einmal, in der Basis.

Stand (28.11.2026, Zweig `beta`): **Basis 0.22.0-beta.64** mit den Modulen

| Modul | Version | Was es kann |
|---|---|---|
| **Kasse** | 2.3.4-beta.67 | Kartenzahlung am SumUp-Terminal, bar oder per Überweisung mit QR-Code. Rechnungen aus T2med kommen über den Drucker „vKasse“ an und werden gelesen (auch per Texterkennung, mit Nachbearbeitung nicht erkannter Rechnungen). IGeL und GOÄ ohne T2med-Rechnung, Storno, Vorgänge mit Excel-Export, Post per Letterxpress, PDF-Ablage im Netzwerkordner. |
| **Terminzettel** | 1.3.0-beta.25 | Termine aus T2med groß und lesbar auf dem Bondrucker, mit Praxislogo, Kalender-QR je Termin und Absage-QR (vorbereitete E-Mail). |
| **Signatur** | 0.1.0-beta.51 | IGeL-Verträge, Aufklärungen und Datenschutzerklärungen am signotec-Pad unterschreiben lassen, mit Live-Ansicht des Pads. Eigene Dokumente im Baustein-Editor (Text, Patientendaten, Ankreuzfelder, Auswahl, Bilder). Patient aus T2med, Übergabe an die Kasse, unterschriebenes PDF zurück in die Akte. |
| **Qualitätsmanagement** | 0.1.0-beta.1 | QM-Handbuch mit Ordnern, Tags und Suche; Dokumente schreiben oder aus Word übernehmen (z. B. QEP-Muster der KBV), vidieren, zur Kenntnis vorlegen, Lesebestätigung und Wiedervorlage. |
| **Telefon** | 0.1.0-beta.31 | Eigene Telefonanlage (Asterisk) mit Leitungen, Telefonen und Warteschlange. Sprachmenü mit Ansagen und Wartemusik; aufgesprochene Anliegen (Rezept, Termin, Überweisung, Rückruf) werden als Text erkannt und als Vorgänge abgearbeitet, mit Rückruf und SMS. Rufgruppen, Anrufübersicht, Anrufstatistik ohne Nummern und Namen. |
| **Fax** | 0.1.0-beta.16 | Papierloses Fax über die Leitung des Moduls Telefon: auf einen Faxdrucker drucken oder Dateien hineinziehen, mehrere Dokumente zu einem Fax, Telefonbuch mit Favoriten, Sendeprotokoll und Fehlerberichte, Faxeingang mit Texterkennung und Benachrichtigung per E-Mail. |
| **Homepage** | 0.1.0-beta.4 | Mitteilungen auf die WordPress-Homepage: Vertretung (mehrere Praxen aus einer Liste), Impfstoff lieferbar, Sonderöffnungszeiten oder freier Text; je Mitteilung ein- und ausschaltbar, mit Zeitraum und Ort (Leiste, Kasten, Fenster, Kurzcode). Dazu eine Öffnungszeiten-Karte (geöffnet/Mittagspause/geschlossen, Bereitschaftsdienst). Plugin „Praxisherz-Hinweise“ für WordPress liegt bei. |

Installierbar nur auf einer Test-VM ([docs/VM-TEST.md](docs/VM-TEST.md)); was noch geplant ist, steht in [docs/PLAN.md](docs/PLAN.md).

![Basis und Module](docs/architektur-whiteboard.png)

## So sieht es aus

Alle Bilder zeigen erfundene Beispieldaten (Praxis Beispiel, example.org); neu erzeugen mit `bash scripts/bildschirmfotos.sh` ([Hinweise](docs/screenshots/README.md)).

**Eine Anmeldung für alle Module** – Passwort oder Passkey. Basis-Admins brauchen zusätzlich einen Code aus der Authenticator-App (OTP).

![Anmeldung](docs/screenshots/anmelden.png)

**Übersicht und Menü:** Die Seitenleiste von Praxisherz führt zu den Anwendungen und (für Admins) zu Einrichten, Praxis und Betrieb; oben steht „Mein Konto“ mit dem Anmeldenamen. Die Anwendungen lassen sich per Ziehen sortieren, die Gruppen einklappen (gemerkt im Browser). Auch die Seiten der Module erscheinen im selben Rahmen.

![Start](docs/screenshots/start.png)

**Mein Konto:** Profil mit Avatar, Gruppen und Abmelden-Knopf (der Name lässt sich über den kleinen Stift ändern), E-Mail-Adresse (Bestätigung per Zahlencode), Passwort, Handynummer, Authenticator-App und Passkeys.

![Mein Konto](docs/screenshots/konto.png)

**Benutzer und Gruppen:** Jeder Benutzer hat ein Konto für ganz Praxisherz. Gruppen (voreingestellt: Admin, Arzt, MFA = Medizinische Fachangestellte) sind dauerhafte Rollen mit Rechten je Modul; beim Benutzer lassen sich einzelne Rechte abweichend erlauben oder entziehen. Die Module melden ihre Rechte selbst an.

![Benutzer](docs/screenshots/benutzer.png)

![Gruppen](docs/screenshots/gruppen.png)

**Einrichtung:** Der Überblick zeigt, was noch zu tun ist; nach einer Änderung des Servernamens stehen abhängige Punkte wieder rot („Erneut nötig“).

![Einrichtung](docs/screenshots/einrichtung.png)

**Arbeitsplätze:** Ein Paket je PC richtet den Praxisherz-Agent ein (Windows-Setup mit einem Klick): Starter für T2med, Zertifikat, virtuelle Drucker und „Akte öffnen“. Ein Schlüssel gilt für alle Module; T2med startet damit Kasse, Signatur und Telefon mit dem aktuellen Patienten. Ohne Agent (Mac, Linux) steht jeder Schritt eingeklappt auch zum Einrichten von Hand bereit.

![Starter](docs/screenshots/starter.png)

**Öffnungszeiten:** Unter Praxis → Öffnungszeiten pflegt die Praxis (auch die MFA mit dem Recht „Öffnungszeiten pflegen“) Sprech- und Telefonzeiten, Urlaub, Fortbildung und Sonderöffnungen mit Vertretung je Zeitraum sowie die Feiertage des Bundeslands, einzeln an- und abwählbar. Homepage und Telefon übernehmen alles von selbst: Mitteilung auf der Homepage, Ansage mit Vertretung am Telefon.

![Öffnungszeiten](docs/screenshots/zeiten.png)

![Feiertage](docs/screenshots/feiertage.png)

**Verbindungen:** Zugänge, die mehrere Module brauchen (T2med, E-Mail, SMS, Letterxpress, Ablage per SFTP oder SMB), werden einmal eingetragen; ein Modul bekommt nur die, die es anmeldet.

![Verbindungen](docs/screenshots/verbindungen.png)

**Kasse – Startseite:** Links die Rechnungen aus T2med mit Filter (offen, zurückgestellt, überwiesen), Namenssuche und Marken; die neueste ist vorgewählt, Karte ist vorbelegt, ein Klick kassiert. „Zurückstellen“ blendet eine Rechnung aus der Liste aus, ohne sie zu drucken. Rechts die Kasse für freie Eingabe, IGeL und GOÄ (Suche nach Ziffer oder Namen, auch ohne T2med-Rechnung) in zwei Spalten ohne Scrollen. Aus T2med öffnet sich dieselbe Startseite mit dem Patienten in der Patientenkarte.

![Kasse](docs/screenshots/kasse-start.png)

**Kasse – Einstellungen:** Die Einrichtung steht als Ausklappzeilen im Stil von Praxisherz, Rotes zuerst; am Zahnrad der Startseite zeigt eine rote Zahl, wie viel noch offen ist.

![Kasse-Einstellungen](docs/screenshots/kasse-einstellungen.png)

**Kasse – Vorgänge:** Tag, Woche, Monat und Jahr mit Filtern, Abhaken in T2med und Excel-Liste für die Steuerberatung.

![Kasse-Vorgänge](docs/screenshots/kasse-vorgaenge.png)

**Der Weg einer Rechnung:** Verbindungsklick und Rechnung drucken aus T2med oder das IGeL-Modul der Kasse enden im Kassieren und, wenn gewünscht, als PDF im Netzwerkordner.

![Weg der Rechnung](docs/screenshots/weg-der-rechnung.png)

**Kasse – IGeL und GOÄ:** Leistungen ohne T2med-Rechnung kassieren; beim Anlegen einer Leistung genügt der Endpreis, der Faktor wird nach § 5 GOÄ errechnet.

![IGeL](docs/screenshots/kasse-igel.png)

![GOÄ-Rechner](docs/screenshots/kasse-goae.png)

**Kasse – Ablage im Netzwerkordner:** Rechnungen als PDF (bezahlte oder jede gedruckte) in einen Ordner auf dem Netzwerkspeicher, Unterordner wie `Rechnungen/{jahr}/{monat}` werden bei Bedarf angelegt.

![Kasse-Ablage](docs/screenshots/kasse-ablage.png)

**Terminzettel:** Großer, lesbarer Zettel für den Bondrucker mit Kalender-QR-Code je Termin.

![Terminzettel](docs/screenshots/terminzettel.png)

**Signatur:** Patient oben rechts (aus T2med oder per Namen), Dokument wählen, ans Pad am Arbeitsplatz senden. Links die offenen Vorgänge; liegt ein Dokument am Pad, zeigt die Live-Ansicht, was der Patient gerade sieht. Dokumente lassen sich vor und nach der Unterschrift drucken.

![Signatur-Vorgänge](docs/screenshots/signatur-vorgaenge.png)

**Signatur – Editor:** Dokumente aus Bausteinen (Text mit Platzhaltern wie `{{patient}}`, Patientendaten, Ankreuzfeld, Einzel- und Mehrfachauswahl, Aufzählung, Bild, Trennlinie, Seitenumbruch); rechts die Vorschau, wie das Pad das Dokument zeigt.

![Signatur-Editor](docs/screenshots/signatur-editor.png)

**Signatur – Einstellungen:** Pads je Arbeitsplatz (Suche im Netzwerk, Anleitung für das Servicemenü) und die Pad-Software von signotec (wird nicht mitgeliefert, Upload als ZIP).

![Signatur-Pads](docs/screenshots/signatur-pads.png)

![Signatur-Software](docs/screenshots/signatur-software.png)

**Qualitätsmanagement:** QM-Handbuch mit Ordnern, Tags und Suche nach Namensschnipseln. Dokumente entstehen im einfachen Editor (Überschriften, Listen, Checklisten, Tabellen, Bilder) oder aus Word-Dateien (z. B. den QEP-Mustern der KBV), werden vidiert, den MFA zur Kenntnis vorgelegt und von ihnen als gelesen bestätigt; Wiedervorlage nach Prüffrist.

![QM-Lesemappe](docs/screenshots/qm-lesemappe.png)

![QM-Handbuch](docs/screenshots/qm-handbuch.png)

![QM-Dokument](docs/screenshots/qm-dokument.png)

![QM-Editor](docs/screenshots/qm-editor.png)

![QM-Import](docs/screenshots/qm-import.png)

**Telefon – Vorgänge:** Anrufer sprechen im Sprachmenü ihr Anliegen auf; die Spracherkennung (Whisper, auf dem Server) macht daraus eine Karte mit Name, Geburtsdatum, Rückrufnummer und Anliegen. Reiter Neu, In Bearbeitung, Erledigt und Papierkorb; „Übernehmen“, „Dringend“, Kommentare, Zurückrufen am eigenen Telefon und SMS an den Anrufer. Die Vorgänge liegen verschlüsselt auf dem Server, die Tonaufnahme lässt sich 14 Tage anhören.

![Telefon-Vorgänge](docs/screenshots/telefon-vorgaenge.png)

**Telefon – Statistik:** Anrufe je Tag, gewählte Anliegen, angenommene Anrufe und Wartezeit, dazu die Zeiten, in denen am meisten angerufen wird. Gespeichert werden nur Zeitpunkte und Anliegen, keine Nummern oder Namen.

![Telefon-Statistik](docs/screenshots/telefon-statistik.png)

**Telefon – Anlage:** Leitungen der Anbieter und Telefone der Praxis (mit automatischer Einrichtung für Snom-Telefone), Warteschlange, Rufgruppen, Sprachmenü mit Ansagen (Sprachausgabe auf dem Server) und Wartemusik. Mit T2med lassen sich Patienten suchen und ihre Akte am Arbeitsplatz öffnen (die Namensanzeige beim Anruf wird gerade an die Vorgaben von T2med angepasst).

![Telefonanlage](docs/screenshots/telefon-anlage.png)

**Fax senden:** Am PC auf einen Faxdrucker (vFax1 … vFax5, auch mit fester Nummer, z. B. „Labor“) drucken oder Dateien in die Seite ziehen; mehrere Dokumente werden in der Reihenfolge des Anhakens ein Fax. Empfänger aus Telefonbuch, Favoriten und „zuletzt“; Vorschau der ersten Seite.

![Fax senden](docs/screenshots/fax-senden.png)

**Faxeingang:** Empfangene Faxe mit Absender aus dem Telefonbuch; die Suche findet auch Wörter im Faxtext (Texterkennung auf dem Server). Auf Wunsch kommt eine E-Mail bei jedem neuen Fax, Ablage im Netzwerkordner möglich.

![Faxeingang](docs/screenshots/fax-eingang.png)

**Homepage:** Mitteilungen auf die WordPress-Homepage der Praxis schicken: Vertretung (mehrere Praxen aus einer Liste, mit Anschrift und Telefon), Impfstoff lieferbar, Sonderöffnungszeiten oder freier Text. Jede Mitteilung lässt sich ein- und ausschalten, hat einen Zeitraum und erscheint wahlweise als Leiste oben, Kasten auf der Startseite, Fenster beim Öffnen oder an der Stelle eines Kurzcodes. Vorschau beim Tippen; in WordPress zeigt das mitgelieferte Plugin „Praxisherz-Hinweise“ die Mitteilungen an.

![Homepage-Mitteilungen](docs/screenshots/homepage-mitteilungen.png)

![Homepage-Vertretungspraxen](docs/screenshots/homepage-praxen.png)

**Homepage – Öffnungszeiten:** Eine Karte für die Homepage (`[praxisherz_oeffnungszeiten]`) zeigt immer die Sprechzeiten aus Praxis → Öffnungszeiten und darüber, ob die Praxis gerade geöffnet hat, Mittagspause macht oder wann sie wieder öffnet. Außerhalb der Sprechzeiten steht der gerade zuständige ärztliche Bereitschaftsdienst (jede Woche gleich, an Feiertagen oder einzelnen Tagen abweichend), bei Urlaub die Vertretung.

![Homepage-Öffnungszeiten](docs/screenshots/homepage-oeffnungszeiten.png)

**Updater:** Updates je Modul und Basis aus signierten Releases (Kanal stabil oder beta), Sicherung vor dem Update, automatischer Rücksprung bei einem Fehler. Kein Update, solange z. B. eine Kartenzahlung läuft.

![Updates](docs/screenshots/updates.png)

- Architektur und Sicherheitskonzept: [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md)
- Installation: [docs/INSTALLATION.md](docs/INSTALLATION.md)
- Umsetzungsplan Schritt 1: [docs/PLAN.md](docs/PLAN.md)
- Schnittstelle für Module: [docs/MODUL-SCHNITTSTELLE.md](docs/MODUL-SCHNITTSTELLE.md), Format [docs/MODULE-TOML.md](docs/MODULE-TOML.md)
- Arbeitsregeln und Stand für Agenten (auch Claude Code): [AGENTS.md](AGENTS.md); Beispielrechnung aus T2med (erfunden): [modules/kasse/tests/fixtures](modules/kasse/tests/fixtures/README.md)
- Grundlage: [hau-si/kienzle-suite-beta](https://github.com/hau-si/kienzle-suite-beta), Fork von
  [thomaskien/t2med-sumup](https://github.com/thomaskien/t2med-sumup)

Plattform: Ubuntu Server 26.04 LTS (aktuelles Abbild, derzeit 26.04.1), VM mit mindestens 2 Kernen und 100 GB Speicher.

Lizenz: [MIT](LICENSE), wie die SumUp-Suite, aus der die Basis hervorgeht.

Release-Schlüssel (Fingerabdruck SHA-256, siehe [docs/RELEASE-ABLAUF.md](docs/RELEASE-ABLAUF.md)):

- Hauptschlüssel `kienzle-release-2026`: `06dcb35f70c31d6fa5cab0bf983e4b929bd4513715650cb5834bd6a550646c0f`
- Reserve `kienzle-reserve-2026`: `ddacb5541655b3aafe816d18ea19612f7ef186d0bb7a704cf8dd7796470361af`
