# Bildschirmfotos

Alle Bilder zeigen erfundene Daten (Praxis Beispiel, example.org). Neu erzeugen: `bash scripts/bildschirmfotos.sh` (benötigt `php` und Chrome oder Chromium).

- Seiten der Basis: `scripts/bildschirmfotos.php` baut sie aus der Testumgebung (`tests/php/lib.php`, `tests/php/browser.php`) mit den Modul-Manifesten und speichert sie als HTML mit eingebettetem `core.css`.
- Seiten der Kasse (`kasse-*.png`): `scripts/kasse-vorschau/` startet die Kasse lokal mit erfundenen `KIENZLE_*`-Werten (`init.php` legt Grundwerte und Datenbank an, `router.php` ist der Router für `php -S`).
- Aufnahme mit `google-chrome --headless=new --screenshot`. Nie Daten einer echten Installation verwenden.
