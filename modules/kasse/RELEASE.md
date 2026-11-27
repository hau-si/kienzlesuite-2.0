# Modul „Kasse“ (sumup) – Release-Notizen

## 2.3.4-beta.54

- **Wechsel zu Signatur im selben Tab:** „Zur Unterschrift“ öffnet Signatur jetzt im selben Tab statt in einem neuen. Der Patient bleibt oben erhalten, und Signatur springt nach der Unterschrift hierher zurück zum Bezahlen. Die gewählten Leistungen stehen dann noch da.
- Die Patientenkarte nutzt das neue Bauteil der Basis (ab Basis 0.22.0-beta.22, `docs/MODUL-GESTALTUNG.md`).
- **Nicht auf der VM geprüft.**

## 2.3.4-beta.53

- **Neu: Patient aus T2med bleibt oben (Entwurf B3).** Über der Kasse steht eine Karte mit großem Herz-Etikett „T2med“, dem Namen und dem Geburtsdatum. Sie bleibt stehen, auch wenn man eine Rechnung aus der Liste öffnet, zur freien Eingabe, zu „IGeL“, „Vorgänge“ oder „Einstellungen“ wechselt und zurückkommt; sie gilt für diesen Browser-Tab.
  - **„Zum Patienten“** holt ihn wieder in die Kasse.
  - **„Entfernen“** nimmt ihn heraus.
  - Läuft der T2med-Zugang ab (4 Stunden), verschwindet die Karte von selbst. Die doppelte Namenszeile in der Kasse entfällt.
- **Einfache Symbole auf den Zahlungsknöpfen** (Karte, Bar, Überweisung, Per Post) in der freien Eingabe und bei Rechnungen, als Linien wie die übrigen Symbole der Suite statt Emojis.
- **Alle Seiten der Kasse sind gleich breit** wie die Startseite. „IGeL“ und „Einstellungen“ waren schmaler.
- In der Vorschau mit erfundenen Daten geprüft (Ablauf: Patient kommt, Wechsel zu IGeL und zurück, Rechnung, „Zum Patienten“, „Entfernen“). **Nicht auf der VM geprüft.**

## 2.3.4-beta.52

- **Fehler behoben: Leistungen ließen sich nicht auswählen, die Zahlungsknöpfe lagen falsch,** wenn ein Patient aus T2med kam und für ihn eine offene T2med-Rechnung vorlag. Die Rechnung rutschte dann in die linke Spalte, die Leistungsliste in die schmale rechte und die Zahlung darunter. Jetzt steht die offene Rechnung in einer eigenen Zeile über „Leistungen | Zahlung“.
- In der Vorschau mit erfundenen Daten geprüft. **Nicht auf der VM geprüft.**

## 2.3.4-beta.51

- **Neu: Unterschriebene Dokumente aus Signatur in die T2med-Akte.** Bei „Zur Unterschrift“ merkt sich die Kasse den T2med-Zugang dieses Besuchs. Nach der Unterschrift legt Signatur das PDF in den gemeinsamen Austauschordner (`/var/spool/kienzle/austausch/kasse_signatur`). Der neue Dienst `kienzle-kasse-signatur` (ausgelöst über `.path`) schreibt es als `DocumentReference` (Profil „Anhang“) in die Akte und meldet das Ergebnis an Signatur zurück.
- Der T2med-Zugang bleibt dafür nach dem Kassieren erhalten, solange eine Unterschrift offen ist, höchstens bis zum Ablauf nach 4 Stunden. Danach und nach dem Ablegen wird er gelöscht. Ist er abgelaufen, nimmt die Kasse einen anderen noch gültigen Aufruf desselben Patienten.
- Neu als Voraussetzung: `core_api = "2"` (Basis ab 0.22.0), Datenbank Version 23 (Spalte `visits.signatur_offen`).
- Geprüft mit Tests (`tests/signatur-ablage.php`). **Nicht gegen echtes T2med und nicht auf der VM geprüft: bitte zuerst mit einem Testpatienten.**

## 2.3.4-beta.50

- Nur Tests: Startseite-, vBriefversand- und Letterxpress-Test rechnen das Datum in der Zeitzone der Kasse (Europe/Berlin) (scheiterte sonst zwischen 23 und 24 Uhr UTC). Keine Änderung am Programm.

## 2.3.4-beta.49

- **Hinweis zur Versandart:** „Nur Inland“ ist als empfohlen gekennzeichnet, „Automatisch“ als fehleranfällig (Letterxpress erkennt die Anschrift im PDF nicht immer); die Fehlermeldung „keine lesbare Anschrift“ empfiehlt dasselbe.

## 2.3.4-beta.48

- **Knopf „Per Post senden“ in der Vorgänge-Liste** (Spalte „Post“, nur mit dem Recht „Rechnungen per Post senden“): Er erscheint bei T2med-Rechnungen aus dem Rechnungseingang, die noch nicht an Letterxpress gingen, und fragt vor dem Versand nach. Eine Rechnung lässt sich nur einmal verschicken: Nach dem Versand (auch im Testbetrieb) verschwindet der Knopf, und der Server lehnt einen zweiten Versand ab. Bezahlte oder gerade kassierte Rechnungen werden nicht verschickt. Voraussetzungen wie bei „Per Post senden“ an der Kasse (Letterxpress eingeschaltet, Original-PDF noch vorhanden).
- **Erfolgsmeldungen an der Kasse grün statt rot** (z. B. „Rechnung … wurde an Letterxpress übergeben“, „Rechnung gedruckt“); Fehlermeldungen bleiben rot.

## 2.3.4-beta.47

- **Einheitlicher Seitenkopf:** Alle Seiten der Kasse haben oben dieselben Knöpfe mit denselben Symbolen wie das Modul Signatur: Start, Vorgänge, IGeL, Einstellungen (die aktuelle Seite ist hervorgehoben).
- **Neue Seite „IGeL“** (neben den Einstellungen): Die Leistungs- und IGeL-Liste (GOÄ, Preise, „Unterschrift erforderlich“) ist aus den Einstellungen dorthin umgezogen. Der Zahnrad-Link in der Kassenansicht führt dorthin.
- **Einstellungen ohne Reiter:** Die drei Reiter entfallen. Es bleibt die Einrichtung; der „Einrichtung – Überblick“ lässt sich zuklappen (zeigt „n offen“ oder „fertig“). Der Rechnungseingang steht darunter.
- **Aktualisieren** der Rechnungsliste: kleiner Knopf (↻) oben an der Liste der Startseite.
- **Kürzere Zahlungsknöpfe:** „Karte“, „Bar“ und „Überweisung“, mit Betrag davor (z. B. „15,00 € · Karte“). Nicht auf dem Server geprüft.

## 2.3.4-beta.46

- **Fehler behoben (beta.45):** Auf Servern mit bestehender Datenbank zeigte die Kasse nur „Der Vorgang konnte nicht verarbeitet werden“, weil die neue Spalte „Unterschrift erforderlich“ nicht angelegt wurde (Datenbankversion nicht erhöht). Die Datenbank wird jetzt beim Start auf Version 22 gebracht; ein Test deckt die Migration von Version 21 ab. Bitte beta.45 überspringen.

## 2.3.4-beta.45

- **Haken „Unterschrift erforderlich“ je Leistung** (Einstellungen → Leistungskatalog, in der Liste als Spalte „Unterschrift“). Der Knopf „Zur Unterschrift“ erscheint in der Kasse nur noch, wenn mindestens eine gewählte Leistung diesen Haken hat.
- Einrichtungsleitfaden: erster Punkt „Benötigte Hardware“ (Bondrucker Epson TM-m30III empfohlen). Nicht auf dem Server geprüft.

## 2.3.4-beta.44

- **Aufruf aus T2med öffnet die normale Kasse mit Seitenleiste:** Statt einer eigenen Vollbildseite ohne Menü öffnet sich die Startseite der Kasse (Seitenleiste, Rechnungsliste links) und rechts gleich die Kassenansicht mit dem Patienten aus T2med. Die Rechnungsliste schaltet dabei nicht von selbst auf eine andere Rechnung um. Die direkte Adresse `/kasse/kassieren` zeigt die Kassenansicht ebenfalls im Rahmen der Suite. Nicht auf dem Server geprüft.

## 2.3.4-beta.43

- „Zur Unterschrift“ übergibt auch Vor- und Nachname und das Geschlecht des Patienten (für `{{anrede}}` und `{{briefanrede}}` in Signatur). Nicht auf dem Server geprüft.

## 2.3.4-beta.42

- **„Zur Unterschrift“ übergibt auch Telefon, E-Mail, Geburtsdatum und Anschrift des Patienten** (frisch aus T2med) an Signatur, für die Platzhalter in den Dokumenten. Ist T2med nicht erreichbar, öffnet Signatur trotzdem, die Felder bleiben leer. Nicht auf dem Server geprüft.

## 2.3.4-beta.41

- **Knopf „Zur Unterschrift (Signatur)“ vor dem Kassieren:** Ist das Modul Signatur installiert und für den Benutzer sichtbar, übergibt der Knopf Patient und die gewählten Positionen (mit GOÄ-Ziffer und Preis, einschließlich „Sonstiger Betrag“) an Signatur, in einem neuen Tab. Dort Dokument wählen und den Vorgang anlegen, danach hier wie gewohnt kassieren. Nicht auf der VM geprüft.

## 2.3.4-beta.40

- **Vorgänge: Stornieren direkt in der Liste.** Bei bezahlten und offenen Rechnungen steht rechts der Knopf „Stornieren“ (mit Rückfrage und optionalem Grund; es entsteht wie bisher eine Stornorechnung mit neuer Nummer). Sichtbar und erlaubt nur mit dem Recht „Rechnungen stornieren“, das jetzt auch allein zum Öffnen der Vorgänge genügt.
- **Vorgänge: Sortieren per Klick auf die Spaltenüberschrift** (Datum, Art, Nummer, Patient, Zahlung, Status, Betrag, In T2med); noch einmal klicken kehrt die Reihenfolge um. Nicht auf der VM geprüft.

## 2.3.4-beta.39

- Verweise auf die neue Seite „Arbeitsplätze“ (Paket für den Arbeitsplatz) statt auf „Starter“. Die Migration von „sumup“ ist entfernt. Nicht auf der VM geprüft.

## 2.3.4-beta.38

- **Startkontext heißt `kienzle-kasse`** statt `kienzle-sumup`. In T2med unter „Drittanbieter-Zugriffe verwalten“ die Adresse auf `kienzle-kasse://?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}` ändern, danach auf den Arbeitsplätzen das Paket von Verwaltung → Starter neu ausführen.
- **Kein eigenes Starter-Paket mehr in den Einstellungen:** Unter „Arbeitsplätze“ steht ein Punkt „Starter“ mit Knöpfen zur zentralen Seite der Suite. Der Überblick führt dorthin. Nicht auf der VM geprüft.

## 2.3.4-beta.37

- **Ablage der T2med-Rechnung beim Drucken auf „vKasse“:** Bei „jede gedruckte“ blieb die Ablage im Netzwerkordner leer, obwohl die Rechnung ankam. Ursache war der Drucker selbst (Basis beta.60). Zusätzlich: Schlägt die Ablage beim Drucken fehl, steht im Protokoll des Druckers jetzt eine Warnung statt „übernommen“, und **das Bezahlen der Rechnung holt die Ablage nach**. Bereits Abgelegtes wird nie doppelt abgelegt. Mit Prüfablage getestet, nicht auf der VM.

## 2.3.4-beta.36

- **Drucker „vBriefversand“ einklappbar:** Die Karte hing bisher unter jedem Reiter der Einstellungen (z. B. unten bei „Leistungen“). Sie steht jetzt unter Einrichtung → „Praxis & Rechnungen“ nach Letterxpress, als Ausklappzeile mit Schild: **erledigt** (zugeklappt), sobald der Drucker in der Suite bereit ist, sonst **fehlt** (aufgeklappt). Ob der Drucker am Windows-PC eingerichtet ist, kann der Server nicht erkennen. Der Punkt im Einrichtungs-Überblick führt direkt dorthin. Im Browser (Vorschau mit erfundenen Daten) geprüft, nicht auf der VM.

## 2.3.4-beta.35

- **GOÄ-Verzeichnis eingebaut:** Das Feld „GOÄ-Ziffer“ beim Anlegen einer Leistung (Einstellungen → Leistungen und Kassenansicht → Leistungen verwalten) kennt jetzt alle 2.775 Ziffern des amtlichen Gebührenverzeichnisses der GOÄ. Eine Ziffer (z. B. `250`, `250a`, `K1`) zeigt Leistungstext, Punktzahl, Einfachsatz und Gebührenrahmen. Ein Stichwort (z. B. „Blutentnahme“, „Ultraschall Organ“) zeigt bis zu acht Treffer zum Anklicken. „Übernehmen“ trägt Einfachsatz, Gebührenrahmen (ärztlich, technisch für Abschnitt A/E/O, Labor für M und Nr. 437) und den Schwellenfaktor (2,3 / 1,8 / 1,15; Zuschläge 1,0) ein, eine leere Bezeichnung wird mit dem Leistungstext gefüllt, und der Preis wird berechnet. Ein Stichwort ohne Ziffer wird nicht als GOÄ-Ziffer gespeichert.
- **Gebühr genau nach § 5 GOÄ:** Passt der Einfachsatz zur Ziffer im Verzeichnis, rechnen Rechner und Prüfung beim Speichern mit Punktzahl × 5,82873 Cent × Faktor und runden erst am Ende (Nr. 3 × 2,3 = 20,11 € statt 20,10 € über den gerundeten Einfachsatz). Bei eigenen Einfachsätzen (Analogziffern) bleibt es bei Einfachsatz × Faktor. Gespeicherte Leistungen ändern sich nicht.
- Daten: `public/assets/goae-verzeichnis.json`, erzeugt von `scripts/goae-verzeichnis.py` aus dem amtlichen Text (Spiegel von gesetze-im-internet.de im Repository `bundestag/gesetze`, Stand der GOÄ: zuletzt geändert 21.10.2019). Gegenprobe mit der DM-Spalte der Quelle. Korrigiert: Lithium (Quelle 4114, richtig 4214). Nicht enthalten sind Nr. 441 und 5298, weil sie als Prozentsatz berechnet werden, sowie Analogziffern und Abrechnungsempfehlungen. Neuer Test `tests/goae-verzeichnis.php`.
- Im lokalen Browser mit erfundenen Daten geprüft (Suche, Übernehmen, Speichern), nicht auf der VM.

## 2.3.4-beta.34

- **Zahlungsknöpfe passen wieder:** In der eingebetteten Kassenansicht der Startseite (Freie Eingabe und Rechnung aus dem Eingang) ragte die Schrift der Knöpfe Karte/Bar/Überweisung über den Rand, weil vier Spalten in die schmale rechte Spalte gepresst wurden. Dort stehen die Zahlungsarten jetzt in zwei festen Spalten (Karte | Bar, darunter Überweisung | Per Post), die Schrift darf umbrechen. In kleinen Fenstern stehen Leistungen und Zahlung untereinander. Im Browser (Chromium, Vorschau mit erfundenen Daten) geprüft, nicht auf der VM. Test in `tests/suite.php`.

## 2.3.4-beta.33

- **Zentraler Starter:** Die Kasse meldet ihren Startkontext `kienzle-sumup` bei der Basis an (Verwaltung → Starter). Dort gibt es ein Paket für alle Module; der bisherige Weg über `/api.php` und das Kassen-Paket bleibt unverändert. Der zentrale Aufruf (`/starter/sumup`) wird von der Basis geprüft, die Kasse nimmt ihn nur mit dieser Prüfung an. Neuer Test in `tests/suite.php`. Nicht mit T2med und nicht im Browser geprüft.

## 2.3.4-beta.32

- **Zahlungsversuch verwerfen (IGeL):** Nach „Zahlung nicht erfolgt“ oder „Zahlung abgebrochen“ gibt es zusätzlich zu „Erneut versuchen“ den Knopf **Verwerfen**, solange noch keine Rechnungsnummer vergeben wurde. Er setzt Auswahl und Betrag zurück; die erfolglosen Versuche verschwinden aus der Ansicht, bleiben aber mit Status „discarded“ in der Datenbank. Nicht möglich bei bezahlten oder überwiesenen Zahlungen (Nummer vergeben) und bei T2med-Rechnungen. Neuer Test `tests/discard.php`. Nicht im Browser geprüft.

## 2.3.4-beta.31

- **Zahlart springt nicht mehr zurück:** Die Rechnungsliste in der Kassenansicht wird nur noch neu gezeichnet, wenn sich die Rechnungen geändert haben (vorher alle 10 Sekunden). Eine gewählte Zahlart (z. B. „Per Post“) und der Fokus bleiben stehen. Gilt für Start aus T2med (FHIR) wie für die Ansicht mit Seitenleiste.
- **Zahlungsknöpfe an festem Platz:** Karte, Bar, Überweisung und Per Post stehen in einem festen Raster aus vier Spalten; beim Wechsel zwischen IGeL- und T2med-Rechnung ändern sich Lage und Breite nicht mehr.
- Einstellungen: „Wird geladen …“ statt leerer Seite, Breite wie die Seiten der Basis (940 px; Startseite und Vorgänge bleiben breit).
- Nicht im Browser geprüft.

## 2.3.4-beta.30

- Kassenansicht: Eine von Hand gewählte Zahlart (Bar, Überweisung, Per Post) **springt nicht mehr nach einigen Sekunden auf „Karte“ zurück**. Ursache war die automatische Aktualisierung alle 10 Sekunden, die die Auswahl neu aufbaute. „Karte“ ist nur noch die Vorauswahl; die Wahl wird je Rechnung gemerkt, solange die Seite offen ist. Nicht im Browser geprüft.

## 2.3.4-beta.29

- **Nicht erkannte T2med-Rechnungen von Hand nachbearbeiten** (Kasse → Einstellungen → Rechnungseingang → „Abgelehnte Drucke“ → „Von Hand nachbearbeiten“): Die Rechnung wird in der Seite gesucht, und zu Rechnungsnummer, Datum, Gesamtbetrag (mit Zahlbetrag) sowie Patient und Geburtsdatum erscheint je ein **Bildausschnitt** der Rechnung neben einem Eingabefeld mit Vorschlag (gelesen, unsicher oder nicht gefunden; bei mehreren Werten zum Anklicken). Bei Bild-PDFs per Texterkennung, sonst aus der Textebene. Darunter lässt sich die ganze Rechnung ansehen. „Übernehmen und zuordnen“ prüft die Eingaben (Datum, Betrag im erlaubten Bereich, Nummer, Name), legt die Rechnung in den Rechnungseingang und löscht die PDF; „Verwerfen“ löscht sie ebenfalls.
- Dafür bleibt die abgelehnte PDF (mit Patientendaten) **höchstens 7 Tage** in einem privaten Ordner des Moduls (`rejected/`, Rechte 0700/0600) und wird danach, beim Leeren der Liste und nach der Übernahme gelöscht. Die Liste selbst zeigt weiter nur maskierte Zeilen.
- Recht „Rechnungseingang“ (Mitarbeiter) umfasst die Nachbearbeitung; Aktionen stehen im Änderungsprotokoll.
- Gilt für den Rechnungseingang (Drucker vKasse). Rechnungen, die der Drucker vBriefversand ablehnt, werden nicht gemerkt (kein Postversand ohne geprüfte Rechnung). Nicht im Browser und nicht mit echten T2med-Rechnungen geprüft, nur mit erfundenen Beispiel-PDFs (Text und Bild).

## 2.3.4-beta.28

- Drucker umbenannt: „SumUp-Rechnung“ → vKasse, „Letterxpress“ → vBriefversand; Texte, Anleitungen und PPD-Dateien angepasst. Drucker an den Arbeitsplätzen neu einrichten und in T2med neu wählen.
- Fußzeile „Kasse v… auf Grundlage von …“ entfernt.
- Die Schalter „T2med-Rechnung“ (mit Herz) und „IGeL-Leistungen“ (mit Igel) tragen Symbole.
- Leistungen lassen sich einer Gruppe zuordnen (z. B. Blutwerte, Sonographie, Bescheinigungen, Beratung, Untersuchungen); die Auswahlliste zeigt sie mit Überschriften, die Suche blendet leere Gruppen aus. Neues Feld „Gruppe“ beim Anlegen und Bearbeiten (Datenbankversion 21, bestehende Leistungen bleiben ohne Gruppe).
- Nicht auf der echten VM geprüft.

## 2.3.4-beta.27

- Behoben: Die Einstellungen der Kasse zeigten nur „CLIENT_STEPS is not defined“. Die Anleitung für die Arbeitsplatz-Pakete (Windows, Mac, Linux) fehlte im Skript und ist wieder da; ein Test prüft das jetzt.

## 2.3.4-beta.26

- Tooltips der Kasse (Einstellungen und eingebettete Kassenansicht) bleiben im sichtbaren Bereich; Themenblöcke schneiden sie nicht mehr am Rand ab.

## 2.3.4-beta.25

- Kassenansicht in der Startseite: kein waagerechter Scrollbalken mehr unter dem rechten Anzeigebereich (der eingebettete Rahmen schneidet Überstand ab). Ursache nicht am Bildschirm nachgestellt; nicht auf der echten VM geprüft.

## 2.3.4-beta.24

- Seitenleiste der Suite in der Kasse: Stile der Kasse (Logo-Schrift, Abstand unter dem Namen) machten sie höher als in den anderen Modulen. Dadurch gab es einen Scrollbalken und beim Seitenwechsel einen Sprung. Beides ist behoben, die Leiste sieht wie im Terminzettel aus. Nicht auf der echten VM geprüft.

## 2.3.4-beta.23

- Einstellungen → Arbeitsplätze verweist auf den neuen Schritt „Virtuelle Drucker“ unter „Arbeitsplatz einrichten“ der Basis.
- Einrichtungs-Überblick der Suite enthält jetzt auch die optionalen Punkte „Rechnung per Post über Letterxpress“ und „Rechnungen im Netzwerkspeicher ablegen“ (in den Einstellungen kommen sie von dort).

## 2.3.4-beta.22

- Die virtuellen Drucker „SumUp-Rechnung“ und „Letterxpress“ werden nur noch per IPP freigegeben (`share = ["ipp"]`); Samba schaltet der Admin bei Bedarf unter Verwaltung → Drucker ein.
- Einstellungen → Arbeitsplätze: Neben der Hilfe für „SumUp-Rechnung“ gibt es jetzt eine Karte „Drucker „Letterxpress““ (IPP-Adresse, Windows-Skript, Mac-Datei `Letterxpress-PDF.ppd`, Hinweis zum Sammelversand) und einen Punkt in der Einrichtungsliste. Der Starter selbst richtet keine Drucker ein; er öffnet nur die Kasse aus T2med.

## 2.3.4-beta.21

- **Neuer virtueller Drucker „Letterxpress“** (wird wie „SumUp-Rechnung“ von der Basis angelegt und freigegeben, Papier A4, PDF/PostScript, eigene Durchreich-PPD `Letterxpress-PDF.ppd` für den Mac). Jede aus T2med darauf gedruckte Rechnung wird wie im Rechnungseingang gelesen (gleiche Rechnungen, gleiche Erkennung, Rechnungseingang muss dafür nicht eingeschaltet sein) und **sofort per Post an Letterxpress** gesendet. Voraussetzung: Letterxpress ist eingeschaltet (Einstellungen → Letterxpress, Zugang unter Verwaltung → Verbindungen).
- **Lesbarer Text:** Letterxpress nimmt nur PDFs mit Textebene an. Hat das PDF keine (z. B. reines Bild von einem Windows-Treiber), ergänzt die Texterkennung (tesseract, 300 dpi, bis 10 Seiten) den Text vor dem Versand; die vorhandenen Pakete reichen, es wird nichts nachinstalliert.
- **Sammelversand:** Der Drucker ist dafür gedacht, viele T2med-Rechnungen auf einmal zu versenden: in T2med vorübergehend den Rechnungsdrucker auf „Letterxpress“ umstellen, drucken, danach zurückstellen.
- **Ablage für die Steuerberatung:** Neue Einstellung „Über den Drucker „Letterxpress“ verschickte Rechnungen ablegen“ (Einstellungen → Ablage im Netzwerkordner, ab dort wie bei den Zahlungen: gleiches Ziel und gleicher Unterordner). Dann landet jede per Post verschickte Rechnung zusätzlich als PDF im Netzwerkordner, Dateiname endet auf `_Post`. Gilt auch für „Per Post senden“ an der Kasse. Ein Fehler der Ablage stoppt den Versand nicht, er steht im Protokoll. Standardmäßig aus.
- Ein zweiter Druck derselben Rechnung verschickt keinen zweiten Brief, wenn der erste live gesendet wurde (Testbriefe sperren nicht). Schlägt der Versand fehl, liegt die Rechnung trotzdem im Eingang (mit Fehlertext), der Drucker meldet es im Protokoll.
- **Vorgänge:** Per Post versandte Rechnungen stehen in der Liste mit Zahlungsart **Überweisung**, Status „per Letterxpress verschickt“ (im Testmodus mit „(Test)“, bei unklarem Ausgang „Postversand unklar“) und im Excel-Export; neuer Status-Filter „Per Post“. Das gilt auch für Rechnungen, die über „Per Post senden“ an der Kasse verschickt werden. Diese Rechnungen werden nicht mehr nach 14 Tagen aus dem Eingang gelöscht (das PDF schon).
- Einrichtungs-Überblick: Punkt „Drucker Letterxpress bereit/fehlt“ bei eingeschaltetem Letterxpress.
- Nicht auf der echten VM und nicht mit echtem Letterxpress geprüft (nur Nachbildung der API und Texterkennung mit erfundenen Beispielrechnungen).

## 2.3.4-beta.20

- **Die neue Vorgänge-Liste ersetzt „Export für die Steuerberatung“ und „T2med-Rechnungen als bezahlt abhaken“** in den Einstellungen; beide Bereiche und die Download-Adresse `tax-export.php` entfallen. Die Excel-Liste enthält zusätzlich die Spalten „davon Netto“ und „davon MwSt.“ für IGeL-Rechnungen. Die nachträgliche Betragskorrektur an T2med-Zahlungen entfällt (dafür gibt es das Storno).
- **Neue Benutzerrechte** (Verwaltung → Benutzer): „Monats- und Jahresansicht der Vorgänge“ (`periods`); die Ansichten Tag und Woche gehören zum Recht „Kassieren“. „Excel-Export der Vorgänge“ (`export`) und „T2med-Rechnungen in den Vorgängen abhaken“ (`paid`) gelten jetzt für die neue Liste. Wer nur diese Rechte hat, bekommt keinen Zugang zu den Einstellungen mehr (nur Rechnungseingang, Post und Leistungen öffnen sie).
- **Neues Benutzerrecht „Rechnungen stornieren“** (`storno`): Ohne dieses Recht fehlt die Stornieren-Funktion in der Detailansicht, und die Anfrage wird abgelehnt.
- Neue Schaltflächen in der Liste: **Alle Zahlungen · 💳 SumUp (Karte) · 💶 Bar · 🏦 Überweisung**; sie wirken auch auf die Excel-Liste.

## 2.3.4-beta.19

- **Neue Seite „Vorgänge“** (Startseite → „📋 Vorgänge & Export“): alle T2med-Rechnungen und IGeL-Zahlungen eines **Tages, einer Woche, eines Monats oder eines Jahres** (mit Blättern ◀ ▶ und Datumswahl) als übersichtliche Liste mit Art (T2med/IGeL), Nummer, Patient, Zahlungsart, Status (bezahlt, offen, zurückgestellt, storniert) und Betrag, dazu Filter nach Art und Status, Namenssuche und Summe. **Excel-Export** der angezeigten Liste (Recht „Excel-Listen“).
- **Abhaken bei T2med-Rechnungen**: Spalte „In T2med“ zeigt, ob die bezahlte Rechnung in T2med als abgerechnet markiert wurde; mehrere markieren und abhaken oder Haken entfernen (Recht „Bezahlt-Liste abhaken“).
- Diese Liste **ersetzt die alte Rechnungseingangsliste** in den Einstellungen; dort bleiben nur der Hinweis und die abgelehnten Drucke. Rechnungen aus dem Eingang löschen geht jetzt über „Löschen“ an der Rechnung in der Startseite (Recht „Rechnungseingang“). Das Versenden per Post bleibt an der einzelnen Rechnung; der Sammelversand entfällt.
- **Offene Rechnungen lassen sich stornieren** (z. B. IGeL mit Überweisung, die noch nicht bezahlt ist): Die Rechnung bekommt dafür ihre Nummer und eine Stornorechnung mit neuer Nummer.
- In den Listen der Startseite lässt sich zusätzlich nach **Art T2med / IGeL** filtern.

## 2.3.4-beta.18

- **Verarbeitete Rechnungen der letzten 7 Tage** stehen links in der Liste: neue Reiter **Offen · Bezahlt · Zurückgestellt · Alle**. „Bezahlt“ zeigt Karte, Bar und bezahlte Überweisungen (mit Symbol je Zahlungsart, T2med-Rechnungen und IGeL), „Zurückgestellt“ zeigt zurückgestellte und per Überweisung offene Rechnungen. Der frühere Reiter „Überwiesen“ ist darin aufgegangen.
- **Storno bei allen Zahlungsarten** (Karte, Bar, Überweisung; IGeL und T2med-Rechnungen): Klick auf eine verarbeitete Rechnung → „Stornieren …“. Es entsteht eine **Stornorechnung mit eigener neuer Nummer** (PDF); die ursprüngliche Rechnung bleibt unverändert für die Buchhaltung erhalten, zählt aber nicht mehr in Tageswerten und Steuerliste. Eine stornierte T2med-Rechnung wird wieder offen. Die Rückzahlung selbst (Karte am Terminal/im SumUp-Konto, Bargeld, Überweisung) erfolgt nicht automatisch; die Abfrage vor dem Storno weist darauf hin.
- **Rechnungsnummern für IGeL-Leistungen frei gestaltbar** unter Einstellungen → „Rechnungsnummern“: Platzhalter `{JJJJ}`, `{JJ}`, `{MM}`, `{NNNN}` (Voreinstellung `IGEL-{JJJJ}-{NNNN}`); ebenso das Format der Stornonummer (`STORNO-{JJJJ}-{NNNN}`). Die Nummer wird beim Bezahlen vergeben, gilt für Karte, Bar und Überweisung (bisher: SumUp-Transaktions-ID bzw. BAR-/UEB-Nummern), zählt jährlich neu, wenn das Jahr im Format steht, und wird nie ein zweites Mal vergeben. Bereits bezahlte ältere Rechnungen behalten ihre Nummer.
- Intern: Datenbankversion 20 (Tabellen `doc_numbers`, `stornos`, Spalten `doc_number`, `storno_at`), neue API-Aktionen `processed` und `storno`, neue Seite `beleg.php` für die PDFs.

## 2.3.4-beta.17

- **Patient aus T2med wird gemerkt**: Hat T2med den Patienten kürzlich per FHIR an die Kasse übergeben und liegt eine passende T2med-Rechnung vor (Name + Geburtsdatum), läuft die Zahlung über diesen Vorgang. Karte, Bar und auch IGeL-Zahlungen werden dann wie beim Start aus T2med in der Akte dokumentiert.
- Kopfzeile der Rechnungsansicht zeigt den Patienten, bei Verbindung mit ❤ **T2med**; in der Rechnungsliste steht dieselbe Marke.
- Neuer Umschalter **T2med-Rechnung | IGeL-Leistungen**: für denselben Patienten lassen sich zusätzlich IGeL-Leistungen kassieren.
- Zahlungsknöpfe der T2med-Rechnung vereinheitlicht: dieselben Symbolfelder wie in der freien Eingabe (💳 Karte, 💶 Bar, 🏦 Überweisung, ✉ Per Post) und ein großer Knopf.

## 2.3.4-beta.16

- Startseite der Kasse neu gestaltet, ohne Scrollen: links die Rechnungen aus dem Eingang, rechts die Kassenansicht in zwei Spalten (Leistungen und Zahlung). Die Marke „Kasse“ in der Arbeitsfläche entfällt.
- Rechnungsliste: Filter **Offen / Zurückgestellt / Überwiesen / Alle** mit Zählern, Namenssuche, Marken je Rechnung („neu · vor 3 Min“ für Rechnungen der letzten 10 Minuten, „zurückgestellt“, „überwiesen“, „per Post“). Neueste Rechnung zuerst; beim Öffnen der Startseite ist die neueste offene Rechnung vorgewählt, **Karte ist vorbelegt** (ein Klick kassiert). Eine später aus T2med eintreffende Rechnung wird nur dann automatisch vorgewählt, wenn gerade nichts in Arbeit ist.
- **Zurückstellen** (neu): die Rechnung bleibt im Rechnungseingang, verschwindet aber aus der Liste „Offen“ und lässt sich unter „Zurückgestellt“ direkt anklicken und bezahlen. „Überwiesen“ lässt sich von Hand setzen und wird bei „Patient zahlt später (Überweisung)“ automatisch gesetzt (diese Taste druckt weiterhin, falls eingestellt).
- Freie Eingabe: Suche nach GOÄ-Ziffer oder Namensteilen in der Leistungsliste (mehrere Wörter, alle müssen vorkommen), Zahnrad oben rechts in der Liste führt zur Bearbeitung der Leistungen.
- Rechnungen aus dem Eingang sieht jeder mit dem Recht „Kassieren“ (bisher zusätzlich „Rechnungseingang“ nötig); die Einstellungen bleiben an ihre Rechte gebunden.
- Intern: neue Spalten `parked_at` und `transfer_at`, neue API-Aktion `inbox_tag`; eingebettete Ansicht mit `embed.css`.

## 2.3.4-beta.15

- In der Startseite eingebettete Kassenansicht: Marke „Kasse“ und Willkommenskarte werden dort nicht mehr doppelt gezeigt.

## 2.3.4-beta.14

- Anonymisierte Beispielrechnung im Aufbau der T2med-Liquidation (`tests/fixtures/t2med-beispielrechnung.pdf`, vollständig erfunden, mit Erzeugungsskript und Beschreibung) und ein Test, der sie mit dem Rechnungsleser prüft. Nur Tests und Dokumentation.

## 2.3.4-beta.13

- Einrichtung in den Einstellungen im Stil der Basis: Themenblöcke als Ausklappzeilen mit Statuspunkt und Etikett („n rot“, „n offen“, „fertig“). Rotes (erneut nötig, Fehler) steht oben, dann Offenes, dann Fertiges; Rotes und Offenes ist aufgeklappt.
- Startseite: am Zahnrad „Einstellungen“ steht eine rote Zahl mit den noch nicht erledigten Einrichtungspunkten (nur für Benutzer mit Einstellungsrechten).
- Intern: neue API-Aktion `setup_count`.

## 2.3.4-beta.12

- Neue Startseite der Kasse (im Rahmen der Suite, zwei Spalten): Links steht gleich die Eingabemaske für Leistungen, IGeL und GOÄ bereit – auch ohne T2med-Rechnung, mit frei eingebbarem Betrag, Name und Geburtsdatum (beides optional) und der vorhandenen Kartenzahlung, Bar und Überweisung. Rechts die offenen T2med-Rechnungen mit Direktwahl **Karte, Bar, Überweisung oder Per Post (Letterxpress)** sowie die Tageswerte.
- Die schlanke Ansicht für den Start aus T2med liegt jetzt unter „…/kassieren“ (weiterhin ohne Menü); der Starter ändert sich nicht.
- Intern: neue Aktionen `manual_open`, `inbox_visit`, `open_inbox`, `day_stats`; Anzeige der Kasse darf im Rahmen der Suite (gleiche Herkunft) eingebettet werden.

## 2.3.4-beta.11

- Die Einstellungen der Kasse stehen jetzt im Rahmen der Suite: Seitenleiste mit der Kasse als aktivem Eintrag, Kopf „Kasse – Einstellungen“, gleiche Schrift und Abstände wie die übrigen Seiten. Die Kassieren-Ansicht (aus T2med) bleibt schlank und ohne Menü.
- Erster Schritt der Umgestaltung (Startseite mit Offenem und Zahlen, Einstellungen als einklappbare Zeilen folgen).

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
