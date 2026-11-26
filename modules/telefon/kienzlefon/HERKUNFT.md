# Kienzlefon im Modul Telefon

Unveränderte Übernahme von [thomaskien/kienzlefon](https://github.com/thomaskien/kienzlefon) 2.7 (Commit `af33f2f`, 26.11.2026),
Urheber Thomas Kienzle, MIT-Lizenz (`LICENSE`, `THIRD_PARTY_NOTICES.md`). Thomas entwickelt hier weiter.

**Übernommen:** Python-Paket `kienzlefon/`, Tests, Dokumentation, Konfigurationsvorlage, Skripte für Kokoro und Google-TTS,
die alte Weboberfläche `webinterface/` (nur als Vorlage für die Seiten des Moduls).

**Nicht übernommen:** die Bash-Installer (Einrichtung übernimmt das Modul), die Demo-Webseite und die SMS-Teile der
Praxis-App (SMS kommt aus der Basis), die Wartemusik (MIT, Johannes Plachetka; kommt mit dem Einbau der Wartemusik).
Die Tests, die diese Installer aufrufen, lässt `tests/run-all.sh` aus.

**Einbau ohne Änderung am Code:** Kienzlefon setzt wie bisher auf die Kienzlefax-Dateien der Anlage auf
(`extensions-kfx-telefonie.conf`, `queues-kfx.conf`, Kontexte `kfx-*`). Das Modul erzeugt diese im Kienzlefax-Format und
ersetzt in den von Kienzlefon geschriebenen Asterisk-Dateien die festen Pfade (`/etc/asterisk`, `/etc/kienzlefon`,
`/opt/kienzlefon/venv/bin`) durch die Ordner des Moduls. Programmaufrufe aus dem Dialplan gehen an kleine Startskripte des
Moduls, die `kienzlefon` aus diesem Ordner laden.

**Sprachausgabe (Entscheidung 26.11.2026):** Piper (Standard, lokal) und Google (nur feste Praxistexte), Kokoro wahlweise.
Python-Pakete aus PyPI mit festen Versionen in einer eigenen Umgebung des Moduls.
