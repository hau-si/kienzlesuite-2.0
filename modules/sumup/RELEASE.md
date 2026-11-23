# Modul „Kasse“ (sumup) – Release-Notizen

## 2.3.4-beta.10

- Einrichtungs-Überblick: „Starter auf den Kassen-PCs installieren“ steht rot („Erneut nötig“), wenn sich das Serverzertifikat geändert hat (z. B. nach einer Änderung des Servernamens), statt nur als offen.

## 2.3.4-beta.9

- Hinweise in den Einstellungen verweisen auf „Verwaltung → Praxis“ (Praxisname, Anschrift, Konto, Steuer stehen dort gemeinsam; die Seite „Praxis und Daten“ heißt jetzt „Praxis“). Nur Text.

## 2.3.4-beta.8

- Testbetrieb gelb: Die Anzeige „TESTMODUS“/„T2MED TESTMODUS“ oben in der Kasse und den Einstellungen ist gelb; im Rechnungseingang steht bei Letterxpress im Testbetrieb ein gelbes Schild „TESTBETRIEB Post“. Nicht im Browser geprüft.

## 2.3.4-beta.7

- E-Mail-Beleg: Wird in der Basis ein Gmail-Konto verbunden, versendet die Kasse Belege über die Gmail-Schnittstelle statt per SMTP. Voraussetzung: Basis 0.21.4-beta.20. Nicht mit einem echten Gmail-Konto getestet.

## 2.3.4-beta.6

- Letterxpress-Zugang (Benutzername, API-Key, Test/Live) kommt jetzt zentral aus der Basis (Verwaltung → Verbindungen) und steht nicht mehr in den Einstellungen der Kasse. Bisher hier eingetragene Werte werden nicht mehr verwendet: bitte einmal in der Basis eintragen. Druck, Beidseitig, Versandart und Einschreiben bleiben in der Kasse. Voraussetzung: Basis 0.21.4-beta.18. Nicht gegen das echte Letterxpress getestet.

## 2.3.4-beta.5

- Rechnungseingang: In der Liste stand in der Spalte „Eingang“ „[object Observable]“ statt Datum und Uhrzeit, weil die Datumsfunktion in der Einstellungsseite fehlte; behoben. Auch das Datum im Protokoll der abgelehnten Drucke ist davon betroffen gewesen. Nicht im Browser getestet.

## 2.3.4-beta.4

- Einrichtung der Kasse im Aufbau der Basis: eine nummerierte Liste in der nötigen Reihenfolge (Suite, dann Kassen-PCs, dann T2med) mit Fortschrittsbalken und Schildern „In diesem Webfenster“, „Auf den Kassen-PCs“, „In T2med“ sowie „optional“.
- Rechnungsnummern: Das eingetragene Kürzel wird auch bei Text-PDFs zugeordnet (z. B. wird „1IG2026-309“ zu „IG2026-309“), nicht nur nach Texterkennung. Voraussetzung: das Kürzel ist unter Rechnungseingang eingetragen. Nicht mit echten T2med-Rechnungen getestet.

## 2.3.4-beta.3

- Kontodaten (Kontoinhaber, IBAN, BIC, Bank), Steuernummer und Kleinunternehmerregelung kommen jetzt zentral aus der Basis (Verwaltung → Praxis und Daten) und stehen nicht mehr in den Einstellungen der Kasse. Bisher hier eingetragene Werte werden nicht mehr verwendet: bitte einmal in der Basis eintragen. Voraussetzung: Basis 0.21.4-beta.2. Nicht auf dem Server getestet.

## 2.3.4-beta.2

- Hinweis zum T2med-Demo-Schlüssel (kein eigener API-Schlüssel eintragen, sonst 403) in Einstellungshilfe.

## 2.3.4-beta.1

- Neue Einstellung „Kürzel der Rechnungsnummern“ (T2med-Rechnungen übernehmen, ein Kürzel pro Zeile, mehrere Rechnungsgruppen möglich): Die Texterkennung liest das Kürzel oft verschieden („1IG“, „16G“, „162“); die Kasse ordnet es dem eingetragenen Kürzel zu (Verwechslungen I/1, G/6, O/0 …), sodass dieselbe Rechnung immer dieselbe Nummer erhält und beim erneuten Druck „Ersetzt“ wird. Nur mit erfundenen Nummern getestet, nicht mit echten T2med-Rechnungen.

## 2.3.3-beta.4

- Windows-Skript: Anschluss und Drucker werden bis zu viermal angelegt (Pause nach dem Spooler-Neustart, sonst Fehler 0x800703eb), am Ende zeigt es den angelegten Drucker. Nicht getestet (auf dem Windows-PC des Anwenders klappte der Befehl `Add-Printer` beim zweiten Versuch).

## 2.3.3-beta.3

- Nach einer nicht erfolgten oder abgebrochenen Zahlung heißt die Überweisung überall „Patient zahlt später (Überweisung)“. Bei internen Rechnungen (Leistungsauswahl) wechselt sie in die offene Überweisung mit Kontodaten und QR-Code (Bon druckbar); bei T2med-Rechnungen, deren Überweisungs-QR-Code schon auf der Rechnung steht, kehrt sie zur Rechnungsliste zurück (Rechnung wird gedruckt, wenn der Rechnungsdruck eingeschaltet ist). Dieselbe Beschriftung gilt in der Rechnungsliste.
- Nicht auf echter Hardware getestet.

## 2.3.3-beta.2

- Virtuelle Drucker bieten nur noch das Papierformat des Moduls an (neu in module.toml: `paper`, Vorgabe A4; Kasse A4, Terminzettel A6) statt A6/A5/A4/Letter. Nicht mit Windows getestet.

## 2.3.3-beta.1

- Rechnungseingang mit Texterkennung: Liest die Texterkennung dieselbe Rechnungsnummer an mehreren Stellen leicht verschieden (z. B. O statt 0, Abweichung um höchstens ein Zeichen), gilt die häufigste bzw. zuerst gelesene Nummer, statt die Rechnung mit „nicht eindeutig“ abzulehnen. Betrag, Zahlbetrag, Patient und Geburtsdatum werden weiterhin streng gegengeprüft; ohne Texterkennung (Textebene) bleibt die Prüfung unverändert streng.
- Hinweis: Die Meldung „[object Observable]“ vor dem Fehlertext ist der Dokumentname, den T2med beim Drucken mitschickt (kommt nicht aus der Kasse).
- Nicht mit echten T2med-Rechnungen getestet.

## 2.3.2-beta.3

- Windows-Skript: „Set-PrintConfiguration -PaperSize“ entfernt (der IPP-Class-Treiber lehnt es ab; die Papiergröße kommt vom Drucker). Nicht getestet.

## 2.3.2-beta.2

- Windows-Skript korrigiert: Der Druckspooler wird nach dem Aktivieren der Windows-Funktion neu gestartet und es wird auf den laufenden Dienst gewartet (vorher „Spoolerdienst ist nicht erreichbar“). Nicht getestet.

## 2.3.2-beta.1

- Windows-Anleitung robuster: Das PowerShell-Skript startet den Druckspooler neu und entfernt zuerst den alten Drucker (behebt „Das Handle ist ungültig“ in den Druckereigenschaften); neuer Hinweis zur Fehlerbehebung. Nicht getestet (vom Anwender am Windows-PC bestätigt: Neueinrichtung per IPP-Adresse mit IPP-Class-Treiber funktioniert).

## 2.3.1-beta.1

- Die Papierbreite des Bondruckers (58/80 mm) wird nicht mehr in den Kasse-Einstellungen gewählt, sondern zentral in der Basis (Verwaltung → Drucker → Wofür welcher Drucker?, ab Basis 0.21.0-beta.1). Die Felder „Modell“ und „Druckbreite in Punkten“ entfallen; bei einer älteren Basis gilt 58 mm.
- Nicht auf echtem Bondrucker getestet.

## 2.3.0-beta.3

- Neu: **Manuelle Kartenzahlung** auf der Startseite der Kasse (ohne Patientenakte): Betreff und Betrag eingeben, die Zahlung startet am Kartenterminal; der Betreff wird als Beschreibung an SumUp übergeben (keine Patientendaten eintragen). „Vorgang abschließen“ beendet sie, es wird nichts in T2med geschrieben. Beleg und Rechnung wie bei anderen Zahlungen.
- Auf der Startseite gibt es jetzt einen großen Knopf „Einstellungen der Kasse“.
- Nicht mit echtem SumUp-Terminal getestet (nur Testmodus).

## 2.3.0-beta.2

- Bei einer offenen Überweisung gibt es „Überweisungsdaten als PDF“ und „Überweisungsdaten drucken“: ein Bon mit Empfänger, IBAN, BIC, Betrag, Verwendungszweck und dem EPC-QR-Code zum Mitgeben (Bondrucker oder PDF). Der gedruckte QR-Code wurde mit einem OpenCV-Decoder gegengelesen; Druck auf echtem Bondrucker nicht getestet.

## 2.3.0-beta.1

- Neu: Zahlart **Überweisung**. In den Einstellungen (Abschnitt „Überweisung“) werden Kontoinhaber, IBAN (mit Prüfziffernkontrolle), optional BIC und Bank sowie eine Vorlage für den Verwendungszweck fest hinterlegt (`{nummer}`, `{betrag}`).
- Die Kasse zeigt für eine Überweisung die Kontodaten und einen **EPC-QR-Code („GiroCode“)** mit Betrag und Verwendungszweck; die Banking-App füllt die Überweisung damit selbst aus. Der QR-Code wird ohne externe Bibliothek im Modul erzeugt.
- Eine Überweisung bleibt „offen“, bis der Zahlungseingang bestätigt wird; erst dann entstehen Rechnungsnummer (`UEB-JJJJ-NNNN`), Beleg bzw. Quittung und die Dokumentation in der Akte. Offene Überweisungen lassen sich abbrechen.
- Nach fehlgeschlagener oder abgebrochener Zahlung (und bei offener Überweisung) kann direkt auf **Bar** oder **Überweisung** gewechselt werden – bei der Leistungsauswahl („IGeL“) wie bei T2med-Rechnungen; Positionen und Betrag werden übernommen. Überweisung mit QR-Code gibt es nur bei internen Rechnungen der Leistungsauswahl (dritte Zahlart); bei T2med-Rechnungen steht der QR-Code schon auf der Rechnung, dort bleibt es bei Karte und Bar („Später (Überweisung)“ wie bisher).
- Akteneintrag, Beleg und Steuerexport (neue Spalte „davon Überweisung“) kennzeichnen Überweisungen.
- Nicht auf echter Hardware, nicht mit einer echten Banking-App getestet; der QR-Code wurde mit zwei OpenCV-Decodern gegengelesen.

## 2.2.6-beta.1

- Ist in der Suite kein API-Schlüssel für T2med gespeichert, nimmt die Kasse wie das alte Tool den hinterlegten T2med-Demo-Schlüssel (Basis 0.20.8-beta.1 bietet „Schlüssel entfernen“). Nicht an einer echten T2med-Installation geprüft.

## 2.2.5-beta.1

- Meldung „Patientenkontext konnte nicht aus t2med geladen werden“ nennt jetzt den HTTP-Status, die wahrscheinliche Ursache (Secret/Token, Kontext abgelaufen, Adresse) und die Auskunft von T2med, soweit sie keine Patientendaten enthält. Nicht an einer echten T2med-Installation geprüft.

## 2.2.4-beta.1

- Begriffe zur T2med-Anbindung vereinheitlicht: „Erlaubte fhirBasisUrl aus T2med“ in Anleitung, Fehlermeldung und Dokumentation, wie auf der Verbindungsseite der Basis (Basis 0.20.4-beta.1).

## 2.2.3-beta.1

- Einstellungen → T2med-Anbindung: Die Kasse zeigt, ob die T2med-Verbindung der Suite eingerichtet ist, und verlinkt direkt auf Verwaltung → Verbindungen. Dort lässt sich auch ein selbstsigniertes T2med-Zertifikat übernehmen (benötigt Basis 0.19.3-beta.1). Nicht an einer echten T2med-Installation geprüft.

## 2.2.2-beta.1

- Hinweise bei den SumUp-Feldern (API-Key, Händlercode, Reader-ID) erklären, woher die Werte kommen und wie man die Reader-ID des Solo über die SumUp-Cloud-API erhält. Die genauen Menünamen bei SumUp sind nicht gegen das aktuelle Händlerkonto geprüft.

## 2.2.1

- Aufgeräumt: Hilfetexte zum Terminzettel entfernt (der Terminzettel ist ein eigenes Modul).

## 2.2.0

- Der Drucker für Praxisdrucker (A4) und Bondrucker wird nicht mehr in der Kasse gewählt, sondern in der Basis
  (Verwaltung → Drucker → „Wofür welcher Drucker?“). In der Kasse bleiben nur Modell, Papier und Schalter.
  **Nach dem Update dort einmal zuordnen**, sonst druckt die Kasse auf die Namen „TM-m10“ bzw. „Praxisdrucker“, falls es sie gibt.
- Die Kasse-Einstellungen stehen in der Seitenleiste der Basis („Einstellungen → Kasse“).
- Benötigt Basis ab 0.19.0.

## 2.1.0

- Praxisname, Anschrift und Kontakt kommen aus der Basis (Verwaltung → Server und Name) und sind aus den Kasse-Einstellungen
  entfallen. Die Kasse übernimmt sie bei jedem Aufruf durch einen angemeldeten Benutzer; bis dahin (z. B. direkt nach dem
  Update) fehlen sie auf Belegen, bis jemand die Kasse im Browser öffnet. Zuvor in der Kasse eingetragene Werte werden nicht übernommen.
- Benötigt Basis ab 0.17.0.

## 2.0.1

- Test der Barzahlung unabhängig von der Reihenfolge gleichzeitiger Zahlungen (schlug gelegentlich fehl).
- Rechnungseingang: Fehler der Textauswertung werden als solche gemeldet statt als „Bezeichnung fehlt“.
- Rechnungseingang mit PCRE2 10.46 (Ubuntu 26.04, PHP 8.5): Beschriftungen wie „Rechnungsdatum:“ werden einzeln gesucht;
  in einer gemeinsamen Alternation fand 10.46 die Werte hinter der zweiten Beschriftung nicht.
- Letterxpress: Entschlüsseln mit qpdf schreibt in eine Datei statt nach stdout (gleiches Verhalten bei allen qpdf-Versionen).

## 2.0.0

Erste Fassung als Modul der Kienzle-Suite 2.0, übernommen aus kienzle-sumup, Branch `rechnungseingang`,
Version 1.4.1-rechnung.57 (Commit `50daac0`). Funktionen der Kasse unverändert: Kartenzahlung am SumUp Solo,
Barzahlung, T2med-Rechnungseingang, GOÄ-Leistungen und Kombinationen, Belege per PDF, E-Mail und Bondrucker,
Rechnung per Post (Letterxpress), Excel-Export, Bezahlt-Liste.

Umgebaut für die Suite:
- **Anmeldung über die Suite** statt eigenem Admin-Passwort. Rechte: Kassieren, Bezahlt-Liste, Rechnungseingang, Post,
  Leistungen, Excel-Export (Verwaltung → Benutzer). Admins des Moduls sehen alle Einstellungen; heikle Änderungen
  verlangen eine Bestätigung mit Passwort und Code bzw. Passkey (höchstens 24 Stunden alt).
- **Adresse /kasse/**. Der bisherige Starter 1.4.1 auf den Arbeitsplätzen bleibt nutzbar: Er ruft weiter
  `https://<server>/api.php` auf; die Basis leitet den Start nach der Anmeldung an die Kasse weiter. Der Startcode gilt
  jetzt 5 statt 1,5 Minuten (Zeit für die Anmeldung).
- **T2med, E-Mail, Netzwerkspeicher** kommen aus den zentralen Verbindungen der Suite; die Ablage geht über den
  Netzwerkspeicher-Dienst der Basis (Zugangsdaten kennt das Modul nicht mehr, Wiederholung bei Fehlern übernimmt die Basis).
- **Drucker** richtet die Suite ein (Verwaltung → Drucker); Bon- und Praxisdrucker werden aus deren Liste gewählt.
  Der Drucker „SumUp-Rechnung“ ist ein virtueller Drucker des Moduls (IPP Everywhere), den die Basis anlegt.
- Entfallen, weil die Basis sie übernimmt: Firewall, Aktualisierung, Druckerverwaltung, Admin-Passwort und
  Mitarbeiter-Zugang, „Gerät merken“. Der Terminzettel wird ein eigenes Modul.
- Testpatient im Testmodus direkt in der Kasse (ohne T2med).

Nicht auf echtem Ubuntu 26.04 und nicht mit echter Hardware geprüft: Installation über Verwaltung → Module,
php-fpm-Instanz des Moduls, virtueller Drucker „SumUp-Rechnung“ mit T2med, Starter 1.4.1 gegen /api.php der Suite,
SumUp Solo, Letterxpress, Bondrucker.
