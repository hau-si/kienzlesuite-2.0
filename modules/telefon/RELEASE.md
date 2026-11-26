# Release-Notizen Telefon

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
