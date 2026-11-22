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
