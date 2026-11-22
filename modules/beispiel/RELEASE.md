# Beispielmodul – Release-Notizen

## 0.2.0

- Zweiter virtueller Drucker „Beispiel-PDF“ als IPP-Everywhere-Drucker (`command`, Basis 0.10.0).

## 0.1.3

- `[backup] settings`: `einstellungen.json` kommt in den verschlüsselten Export der Einstellungen (Basis 0.6.1).

## 0.1.2

- Socket im eigenen Ordner: `upstream = "unix:/run/kienzle/beispiel/web.sock"` (Format der Basis 0.2.0).

## 0.1.1

- Zeigt das neue Feld `[[connections]]` (T2med als Pflicht, E-Mail optional).

## 0.1.0

- Erste Fassung: zeigt alle Felder von `module.toml` (Format der Basis 0.1.0, `core_api` 1) und dient den Tests der Basis.
  Kein echtes Modul, wird nie auf einem Praxisserver installiert.
