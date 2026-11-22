# Kienzle-Suite 2.0

Praxis-Appliance für Hausarztpraxen mit T2med: **eine Basis**, einmal auf einer VM installiert, und **Module**, die
einzeln dazukommen – Kartenzahlung (SumUp), Terminzettel, Fax, Telefon, Kienzlezeit. Verwaltung komplett im
Webinterface; Firewall, Benutzer, Admin-Zugang mit OTP, Updates und verschlüsselte Sicherung gibt es nur einmal, in der Basis.

Stand: **Basis 0.12.3**, Meilenstein 8 von 8 (Schritt 1 fertig bis auf die Prüfung auf der Test-VM) ([docs/PLAN.md](docs/PLAN.md)): Anmeldung für alle Module, Benutzer und
Rechte, Admin-OTP, Passkeys, lokale Zertifizierungsstelle, Firewall, Updater mit signierten Releases, Netzwerkspeicher und zentrale Verbindungen, verschlüsselte Sicherung mit restic, Installer mit CUPS/Samba und Einrichtungs-Überblick, Druckerverwaltung, Anmeldung für Dienste (OpenID Connect). Installierbar nur auf einer Test-VM ([docs/VM-TEST.md](docs/VM-TEST.md)).

![Basis und Module](docs/architektur-whiteboard.png)

- Architektur und Sicherheitskonzept: [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md)
- Installation: [docs/INSTALLATION.md](docs/INSTALLATION.md)
- Umsetzungsplan Schritt 1: [docs/PLAN.md](docs/PLAN.md)
- Schnittstelle für Module: [docs/MODUL-SCHNITTSTELLE.md](docs/MODUL-SCHNITTSTELLE.md), Format [docs/MODULE-TOML.md](docs/MODULE-TOML.md)
- Arbeitsregeln (auch für Claude Code): [AGENTS.md](AGENTS.md)
- Grundlage: [hau-si/kienzle-suite-beta](https://github.com/hau-si/kienzle-suite-beta), Fork von
  [thomaskien/t2med-sumup](https://github.com/thomaskien/t2med-sumup)

Plattform: Ubuntu Server 26.04 LTS (aktuelles Abbild, derzeit 26.04.1), VM mit mindestens 2 Kernen und 100 GB Speicher.

Lizenz: [MIT](LICENSE), wie die SumUp-Suite, aus der die Basis hervorgeht.

Release-Schlüssel (Fingerabdruck SHA-256, siehe [docs/RELEASE-ABLAUF.md](docs/RELEASE-ABLAUF.md)):

- Hauptschlüssel `kienzle-release-2026`: `06dcb35f70c31d6fa5cab0bf983e4b929bd4513715650cb5834bd6a550646c0f`
- Reserve `kienzle-reserve-2026`: `ddacb5541655b3aafe816d18ea19612f7ef186d0bb7a704cf8dd7796470361af`
