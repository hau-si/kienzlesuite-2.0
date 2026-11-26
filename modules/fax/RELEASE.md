# Release-Notizen Fax

## 0.1.0-beta.1

- **Neues Modul „Fax“** auf Grundlage von Kienzlefax 3.5.0 (`kienzlefax.php`, Dr. Thomas Kienzle, MIT-Lizenz). Senden und Empfangen über die Telefonanlage des Moduls Telefon (gemeinsamer Austauschordner der Basis).
- **Faxdrucker vFax1 bis vFax5** (virtuelle Drucker der Basis, IPP und Samba): Am PC drucken, das Dokument erscheint unter „Fax senden“. PostScript wird in PDF gewandelt.
- **Fax senden:** Dokumente wählen, bis zu fünf Empfänger (aus dem Telefonbuch oder neu, auf Wunsch gespeichert), Auflösung und Fehlerkorrektur; je Dokument und Empfänger ein Auftrag im Format von Kienzlefax. Laufende Aufträge mit Stand (wartet, wählt, Seite x von y, Versuch) und Abbrechen, alle 5 Sekunden aktualisiert.
- **Sendeprotokoll** und **Fehlerberichte** (mit Grund und Zahl der Versuche, Bericht als PDF, „Erledigt“ übernimmt ins Protokoll); das Original eines fehlgeschlagenen Faxes lässt sich unter „Sendefehler“ erneut senden.
- **Faxeingang:** empfangene Faxe mit Absender (Name aus dem Telefonbuch), Seiten, „neu“-Markierung, Öffnen und Löschen.
- **Telefonbuch** (SQLite) mit Suche.
- **Aufbewahrung** einstellbar (Vorgabe: Eingang und Berichte 90 Tage, gedruckte, nicht gesendete Dokumente 14 Tage). Berichte und empfangene Faxe holt das Modul sofort aus dem Austauschordner in seinen eigenen Datenordner.
- Rechte `senden`, `eingang`, `telefonbuch`; Einstellungen nur für Admins; keine Anmeldung mehr ohne Passwort wie bei der alten Weboberfläche.
- **Noch nicht übernommen** (folgt): Texterkennung (OCR) empfangener Faxe und Scans, Mail-Hinweis bei neuem Fax, Ablage in T2med.
- Geprüft mit Tests (auch ein durchgehender Faxversand mit Asterisk in der Testumgebung, siehe Modul Telefon). **Nicht geprüft:** echte VM, Drucken von einem Windows-PC auf vFax1 bis vFax5, echtes Faxgerät.
