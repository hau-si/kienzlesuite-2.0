# Offene Fragen und Entscheidungen zur Durchsicht

Gesammelt während M5–M8 (Auftrag vom 22.11.2026: „bis M8 ohne Rückfragen, Fragen für später aufheben“).
Wo eine Entscheidung nötig war, steht dabei, was umgesetzt wurde; das lässt sich ändern.

## Aus früheren Meilensteinen

1. **SSO für Dienste mit eigener Anmeldung** (z. B. Kienzlefon-Oberfläche, NAS): Basis als OpenID-Connect-Anbieter als M9?
   Bisher nur vorgemerkt (`docs/PLAN.md`).
2. **Release-Schlüssel:** Der öffentliche Schlüssel des Herausgebers fehlt noch (`docs/RELEASE-ABLAUF.md`). Bis dahin
   nehmen echte Server nur unsignierte Beta-Versionen nach ausdrücklicher Freigabe an.
3. **Google-Sprachausgabe im Telefon-Modul:** Auftragsverarbeitung und übertragene Inhalte mit Thomas klären (`docs/ARCHITEKTUR.md`).
4. **Prüfliste auf der Test-VM** (`docs/VM-TEST.md`) für M2–M4 steht noch aus.

## Aus M5 (Netzwerkspeicher und Verbindungen)

5. **Basisordner und Unterordner:** Festgelegt, dass das Ziel einen festen Basisordner hat und das Modul den Unterordner
   (mit `{jahr}`/`{monat}`) bestimmt. In der Beta stand der ganze Pfad mit Platzhaltern in der Einstellung der Rechnungsablage.
   Für die Umstellung des SumUp-Moduls (Schritt 2) heißt das: Der Unterordner wird eine Einstellung des Moduls.
6. **SFTP nur mit Schlüssel:** Passwort-Anmeldung über SFTP habe ich bewusst nicht eingebaut (bräuchte `sshpass`, Passwort im Prozess).
   Passt das für die NAS in den Praxen?
7. **E-Mail prüfen ohne Versand:** „Anmeldung prüfen“ meldet sich nur am Server an. Soll es zusätzlich „Testmail an …“ geben?

## Aus M6 (Sicherung)

8. **SFTP bei Totalausfall:** Die neue VM hat zunächst einen neuen SSH-Schlüssel; ihn muss man für die Wiederherstellung
   am NAS kurz erlauben (der Installer zeigt ihn an). Danach gilt wieder der alte, mitgesicherte Schlüssel. Alternativ SMB
   oder REST für die Wiederherstellung. Reicht das, oder soll der Schlüssel zusätzlich auf dem Blatt stehen (dann wäre er auf Papier)?
9. **Zweite Kopie außer Haus** (z. B. USB-Platte im Wechsel oder ein zweites Ziel): bisher nur ein Ziel je Server. Bedarf?
10. **E-Mail bei Fehlern der Sicherung:** bisher nur die Warnung im Webinterface. Über die zentrale E-Mail-Verbindung nachrüsten?
11. **Uhrzeit:** täglich 02:30 (± 20 Min.). Laufen die Server in den Praxen nachts?

## Nachträge vom 22.11.2026 (Export, Signatur)

12. **Export und Datenbank:** Der Export enthält die Einstellungen als Dateien (`/etc/kienzle`, `[backup] settings` der Module),
    nicht die Benutzerkonten (die stehen in der Datenbank und kommen über die Datensicherung zurück). Sollen Benutzer mit?
13. **Signaturprüfung „aus“:** gilt für alle Versionen und auch, wenn kein Release-Schlüssel hinterlegt ist. Eine vorhandene,
    aber falsche Signatur lehne ich trotzdem ab (Hinweis auf Manipulation). Soll „aus“ auch das übergehen?

## Aus M7 (Installer)

14. **Samba-Gastzugriff auf Drucker:** Arbeitsplätze drucken ohne Anmeldung (nur aus freigegebenen Netzen). Soll das
    Drucken über Samba ein Konto verlangen?
15. **Arbeitsgruppe:** `WORKGROUP` fest. Nutzen die Praxen eine andere Arbeitsgruppe oder eine Domäne?
16. **autoinstall** holt die Suite per `git clone` aus dem öffentlichen Repository. Bei privatem Repository bräuchte es
    einen Token oder das Release-Archiv; welcher Weg?
