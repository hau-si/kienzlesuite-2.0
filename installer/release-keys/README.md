# Öffentliche Release-Schlüssel

Der Installer kopiert alle `*.pem` aus diesem Ordner nach `/etc/kienzle/release-keys/`. Der Updater nimmt nur Releases an,
deren Manifest mit einem dieser Schlüssel signiert ist.

Eingetragen am 22.11.2026 (Ed25519, Fingerabdruck SHA-256 über den öffentlichen Schlüssel im DER-Format):

| Datei | Zweck | Fingerabdruck |
|---|---|---|
| `kienzle-release-2026.pub.pem` | Hauptschlüssel | `06dcb35f70c31d6fa5cab0bf983e4b929bd4513715650cb5834bd6a550646c0f` |
| `kienzle-reserve-2026.pub.pem` | Reserve (bei Verlust des Hauptschlüssels) | `ddacb5541655b3aafe816d18ea19612f7ef186d0bb7a704cf8dd7796470361af` |

Prüfen: `openssl pkey -pubin -in DATEI -outform DER | sha256sum`. Hier gehören nur **öffentliche** Schlüssel hin;
die privaten liegen offline beim Herausgeber (`docs/RELEASE-ABLAUF.md`).
