#!/usr/bin/env python3
"""Haken des Moduls für die Basis (laufen als ks-terminzettel, nie als root): install, update, remove, busy, health."""
from __future__ import annotations

import json
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import terminzettel as app  # noqa: E402

PROGRAMME = ("pdftotext", "pdfinfo", "pdftoppm", "tesseract", "gs")  # XPS (xpstopdf) ist nur für Windows-Druck per XPS nötig


def health() -> int:
    fehlt = [p for p in PROGRAMME if not shutil.which(p, path="/usr/bin:/bin")]
    if fehlt:
        print("Es fehlen Programme: " + ", ".join(fehlt))
        return 1
    for modul in ("PIL", "qrcode"):
        if subprocess.run([sys.executable, "-c", "import " + modul], capture_output=True).returncode != 0:
            print("Python-Paket fehlt: " + modul)
            return 1
    app.apply_overlay({}, {})  # Layout-Prüfung ohne Datei
    layout = app.DATA / "layout.json"
    if layout.is_file():
        try:
            app.apply_overlay({}, json.loads(layout.read_text(encoding="utf-8")))
        except (OSError, ValueError, app.TicketError):
            print("layout.json ist beschädigt. Bitte auf der Einstellungsseite erneut speichern.")
            return 1
    ticket = app.parse_t2med("TERMINE\nTestperson\nTerminzeitpunkt   Termintyp\nDo. 17.09.2026, 09:00 Kontrolle\n")
    app.render(ticket, {})
    return 0


def main() -> int:
    hook = sys.argv[1] if len(sys.argv) > 1 else ""
    if hook in ("install", "update"):
        app.DATA.mkdir(mode=0o700, parents=True, exist_ok=True)
        print("Terminzettel bereit.")
        return 0
    if hook == "remove":
        return 0  # Daten bleiben; löschen nur auf ausdrücklichen Wunsch (Basis)
    if hook == "busy":
        return 0  # Aufträge dauern Sekunden und gehen bei einem Update verloren, T2med druckt dann erneut
    if hook == "health":
        return health()
    print("Unbekannter Haken.")
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
