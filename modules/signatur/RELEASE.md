# Release-Notizen Signatur

## 0.1.0-beta.3
- Pad-Software: Die Anleitung auf der Seite beschreibt das Hochladen Schritt für Schritt (Herunterladen, nicht entpacken, Lizenz, Hochladen, Ergebnis, Neue Version) mit direktem Link zur Version 9.0.1. Nicht auf der VM geprüft.

## 0.1.0-beta.2
- Das Modul heißt jetzt **Signatur** (vorher „Unterschrift“): neue Kennung `signatur`, Adresse `/signatur`, Datenordner `/var/lib/kienzle/signatur`, Benutzer `ks-signatur`.
- Pad-Software von signotec (signoPAD-API Linux) wird nicht mitgeliefert: Der Admin lädt die ZIP-Datei bei signotec herunter und auf der Seite „Signatur“ unter „Pad-Software von signotec“ hoch (Anleitung und Lizenzhinweis dort). Übernommen werden nur die zwei Bibliotheken und der Lizenztext nach Prüfung (ZIP, Dateinamen, Linux x86-64); Entfernen per Knopf.
- Neues Paket `php-zip` (Modul aktualisieren). Nicht an echter Hardware und nicht auf der VM geprüft.

## 0.1.0-beta.1
- Neues Modul: Vorlagen (IGeL-Vertrag, Aufklärung, Datenschutz, eigene) mit Platzhaltern, Vorgänge mit Status (wartet, unterschrieben, übertragen, Fehler, abgebrochen), zeitversetzt nutzbar.
- Noch nicht enthalten: Pad-Anbindung (signotec Delta per IP), PDF-Erzeugung, Druck, Rückschreiben nach T2med, Knopf in der Kasse.
- Nicht an echter Hardware getestet.
