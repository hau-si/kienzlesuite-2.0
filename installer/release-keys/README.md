# Öffentliche Release-Schlüssel

Der Installer kopiert alle `*.pem` aus diesem Ordner nach `/etc/kienzle/release-keys/`. Der Updater nimmt nur Releases an,
deren Manifest mit einem dieser Schlüssel signiert ist.

**Noch kein Schlüssel eingetragen:** Solange hier keine `*.pem` liegt, sind Updates über das Webinterface gesperrt.
Erzeugen und Eintragen: `docs/RELEASE-ABLAUF.md`. Hier gehören nur **öffentliche** Schlüssel hin (`*.pub.pem`).
