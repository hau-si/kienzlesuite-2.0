# Modul „Kasse“ (sumup) – Release-Notizen

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
