# Modul „Kasse“ (sumup) – Release-Notizen

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
