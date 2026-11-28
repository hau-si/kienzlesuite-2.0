# Release-Notizen Homepage

## 0.1.0-beta.3

- **Automatische Mitteilungen aus Praxis → Öffnungszeiten der Basis** (Verbindung `zeiten`): Schließungen, Sonderöffnungen und Feiertage mit Haken „Homepage“ erscheinen von selbst, mit den Vertretungspraxen je Zeitraum. Karte „Automatisch aus den Öffnungszeiten“ auf der Seite Mitteilungen mit Einstellungen: ein/aus, Orte, Farbe, Symbol, wie viele Tage vorher (Schließungen Vorgabe 14, Feiertage 3). Die Vorschau zeigt sie mit.
- Neuer Dienst `kienzle-homepage-zeiten` (.path auf die Verbindungsdatei): Ändert sich etwas in der Basis, wird sofort übertragen (nur bei neuem Stand).
- Das Plugin „Praxisherz-Hinweise“ bleibt unverändert (0.2.0); automatische Mitteilungen tragen Nummern ab 900000000.
- Die eigene Liste der Vertretungspraxen im Modul bleibt für eigene Mitteilungen bestehen; die automatischen nutzen die Liste der Basis.
- Braucht Basis 0.22.0-beta.52. Geprüft mit Tests (inkl. Übertragung an das nachgebaute WordPress); **nicht mit echtem WordPress und nicht auf der VM geprüft.**

## 0.1.0-beta.2

- **Fehler behoben (Meldung des Nutzers): „Kasten auf der Startseite“ erschien nicht**, wenn das Theme die Startseite selbst zusammenbaut (feste Abschnitte statt des Inhalts einer Seite, z. B. Startseite als Beitragsliste). Das Plugin gibt den Kasten dann am Seitenende aus, und ein kleines Skript setzt ihn an die richtige Stelle: automatisch über den Inhalt bzw. unter den Kopf der Seite, oder an eine Stelle, die man in WordPress unter **Einstellungen → Praxisherz-Hinweise → „Stelle für den Kasten“** einträgt (CSS-Selektor wie `#aktuelles`, davor/am Anfang/am Ende/danach). Auf statischen Startseiten bleibt es beim Kasten über dem Seiteninhalt.
- **Farbe je Mitteilung:** Blau, Grün, Petrol, Gelb, Rot, Violett, Grau oder „Passend zur Art“ (wie bisher).
- **Symbol je Mitteilung:** Hinweis, Achtung, Praxis, Impfung, Kalender, Uhr, Telefon, Urlaub, Herz, kein Symbol oder „Passend zur Art“. Symbole stehen in der Leiste, im Kasten, im Fenster und in der Liste der Mitteilungen.
- **Plugin „Praxisherz-Hinweise“ 0.2.0** – bitte in WordPress ersetzen (Homepage → Einrichtung → Plugin herunterladen, dann in WordPress hochladen und „Ersetzen“ wählen). Ältere Übertragungen ohne Farbe und Symbol zeigt es passend zur Art.
- Datenbank des Moduls: Spalten `farbe` und `symbol` (Schema 2, wird beim Start ergänzt).
- Geprüft mit Tests und Chromium, der Ausweichweg mit dem HTML der Test-Homepage des Nutzers (lokal nachgestellt, nicht auf dem Webserver installiert). **Nicht mit echtem WordPress und nicht auf der VM geprüft.**

## 0.1.0-beta.1

Erste Fassung des Moduls `homepage` (Webpfad `/homepage`), Konzept B (Plugin in WordPress, Praxisserver schickt) und Entwurf B2 (Liste links, Vorschau rechts) vom 28.11.2026.

- **Mitteilungen** für die WordPress-Homepage der Praxis: **Vertretung** (eine oder mehrere Praxen ankreuzen), **Impfstoff lieferbar**, **Sonderöffnungszeiten** (Tage mit Zeiten, leer = „geschlossen“) und **freier Text**. Je Mitteilung ein **Schalter an/aus**, ein Zeitraum („Vom“ leer = sofort, „Bis“ leer = ohne Ende; bei Öffnungszeiten bis zum letzten Tag) und die **Orte**, auch mehrere: Leiste oben (alle Seiten), Kasten auf der Startseite, Fenster beim Öffnen, Kurzcode-Stelle `[praxisherz_hinweise]`.
- Seite „Mitteilungen“: „Jetzt auf der Homepage“ und „Geplant und ausgeschaltet“ als Ausklappzeilen mit Schalter, Bearbeiten in der Zeile; rechts die **Vorschau** einer nachgebauten Homepage, die Änderungen schon beim Tippen zeigt (noch vor dem Speichern), mit „Ansehen am“ für einen anderen Tag.
- Seite **„Vertretungspraxen“** als eigene Liste mit Knopf „+ Praxis anlegen“ oben (Name, Straße, PLZ/Ort, Telefon, Zusatz wie Sprechzeiten); Bearbeiten und Löschen je Zeile. Eine Praxis, die in einer noch nicht abgelaufenen Mitteilung steht, lässt sich nicht löschen.
- Seite **„Einrichtung“** (nur Admin): Plugin „Praxisherz-Hinweise“ herunterladen (ZIP), Anleitung für WordPress, Adresse der Homepage und Schlüssel eintragen, „Speichern und prüfen“, „Jetzt übertragen“, „Verbindung trennen“.
- **Übertragung:** Nach jeder Änderung schickt der Praxisserver alle eingeschalteten, nicht abgelaufenen Mitteilungen an `https://<homepage>/?rest_route=/praxisherz/v1/hinweise`, unterschrieben mit HMAC-SHA256 über Zeit und Inhalt (Uhrzeit ±5 Minuten, ältere Übertragungen werden abgelehnt). Die Homepage muss den Praxisserver nicht erreichen. Fehler stehen als rote Marke mit Grund und Knopf „Erneut übertragen“ oben auf der Seite (nicht erreichbar, Schlüssel falsch, Plugin fehlt, Weiterleitung). Nur https (außer 127.0.0.1 für Tests), keine Weiterleitungen.
- **WordPress-Plugin „Praxisherz-Hinweise“ 0.1.0** (`wordpress/praxisherz-hinweise`, MIT): erzeugt den Schlüssel (Einstellungen → Praxisherz-Hinweise, erneuerbar), prüft die Unterschrift, säubert die Daten (nur bekannte Felder, Längen begrenzt) und gibt alles maskiert aus. Zeiträume prüft das Plugin selbst und noch einmal im Browser, damit Mitteilungen auch bei Seitencache und ausgeschaltetem Praxisserver pünktlich erscheinen und verschwinden. Fenster einmal je Browser-Sitzung (sessionStorage), schließbar mit ×, Klick daneben oder Esc. Leert den Cache von WP Super Cache, W3 Total Cache, WP Rocket und LiteSpeed. Keine Cookies, keine fremden Server.
- Rechte: `mitteilungen` (anlegen, ändern, ein- und ausschalten), `praxen` (Liste der Vertretungspraxen). Einrichtung nur Admin. Benötigt `php-curl` und `php-zip`.
- Sicherheit: CSRF (Formulare und Kopfzeile) plus `Sec-Fetch-Site`, Fehlermeldungen in Adressen nur mit Unterschrift der Sitzung, CSP ohne Inline-Skripte/-Stile, Schlüssel verschlüsselt gespeichert (`verschluesseln(…, 'wordpress')`) und nie wieder angezeigt oder protokolliert. Keine Patientendaten; gespeichert werden nur Praxen und Texte.
- Geprüft mit Tests (Logik, alle Seiten, Plugin mit nachgebautem WordPress und echter Übertragung über HTTP an einen lokalen Testserver) und im Browser (Chromium: Vorschau beim Tippen, Felder je Art, Schalter, Plugin-Ausgabe mit Leiste und Fenster). **Nicht mit einem echten WordPress und nicht auf der VM geprüft.**
