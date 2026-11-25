# Bildschirmfotos und Erklärvideo

Alle Bilder zeigen erfundene Daten (Praxis Beispiel, example.org). Neu erzeugen: `bash scripts/bildschirmfotos.sh` (benötigt `php`, Python mit Playwright und Chrome oder Chromium).

- Seiten der Basis: `scripts/bildschirmfotos.php` baut sie aus der Testumgebung (`tests/php/lib.php`, `tests/php/browser.php`) mit den Modul-Manifesten (inkl. Starter-Seite und erfundenen Update-Daten) und speichert sie als HTML mit eingebettetem `core.css`.
- Seiten der Kasse (`kasse-*.png`): `scripts/kasse-vorschau/` startet die Kasse lokal mit erfundenen `KIENZLE_*`-Werten (`init.php` legt Grundwerte, Zahlungen und Datenbank an, `router.php` ist der Router für `php -S`, Port 8099).
- Seiten anderer Module (Terminzettel, Signatur): `scripts/modul-vorschau/` (Port 8098). Die Terminzettel-Vorschau ruft `lib/preview.py` mit `/usr/bin/python3` auf und braucht dort Pillow und `qrcode`.
- Aufnahme der Seiten mit Playwright: `scripts/bildschirmfotos-seiten.py` (klappt Zeilen auf, blendet nichts Echtes ein). Nie Daten einer echten Installation verwenden.
- Erklärvideo: `python3 scripts/video-bauen.py [Bilderordner] [Ausgabe.mp4]` (Pillow und ffmpeg; 1920x1080, 30 fps, eine Datei je Szene, ohne Ton). Das Video gehört nicht ins Repository; `--probe` rendert nur einige Szenen. Szenen und Texte stehen oben im Skript in `SZENEN`.
- `weg-der-rechnung.png` ist ein Schaubild, das `scripts/bildschirmfotos-seiten.py` selbst zeichnet (`wege_html`); `kasse-igel.png` und `kasse-goae.png` bedienen die eingebettete Kassenansicht (GOÄ-Ziffer 410, Endbetrag 30,00 €).
