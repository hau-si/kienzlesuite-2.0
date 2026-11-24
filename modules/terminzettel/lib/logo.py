#!/usr/bin/env python3
"""Praxislogo für den Bon: Hochgeladenes Bild prüfen und verkleinern, später als 1-Bit-Raster drucken.

Aufruf als Programm: Bild (PNG, JPEG oder GIF) auf stdin, verkleinertes Graustufen-PNG auf stdout.
Fehler: Exit-Code 2 und eine feste Meldung auf stderr (nie Bildinhalte).
"""
from __future__ import annotations

import io
import sys

MAX_BYTES = 2 * 1024 * 1024
MAX_PIXELS = 25_000_000
MAX_DOTS = 384          # breiteste Grafik, die der Druckdienst als Raster sendet
MAX_HEIGHT = 240        # höher als ca. 3 cm wird ein Logo auf dem Bon unhandlich
FORMATS = ("PNG", "JPEG", "GIF")


class LogoError(ValueError):
    """Feste Meldung für die Einstellungsseite."""


def normalize(data: bytes) -> bytes:
    """Bild prüfen, auf weißen Grund legen, auf höchstens 384×240 Punkte verkleinern, als Graustufen-PNG zurückgeben."""
    from PIL import Image
    if not data or len(data) > MAX_BYTES:
        raise LogoError("Das Logo darf höchstens 2 MB groß sein.")
    Image.MAX_IMAGE_PIXELS = MAX_PIXELS
    try:
        image = Image.open(io.BytesIO(data))
        if image.format not in FORMATS:
            raise LogoError("Das Logo muss ein PNG-, JPEG- oder GIF-Bild sein.")
        image.load()
    except LogoError:
        raise
    except Exception:
        raise LogoError("Das Bild konnte nicht gelesen werden (erlaubt: PNG, JPEG, GIF bis 2 MB).") from None
    rgba = image.convert("RGBA")
    ground = Image.new("RGBA", rgba.size, (255, 255, 255, 255))
    gray = Image.alpha_composite(ground, rgba).convert("L")
    gray.thumbnail((MAX_DOTS, MAX_HEIGHT), Image.LANCZOS)
    out = io.BytesIO()
    gray.save(out, "PNG", optimize=True)
    return out.getvalue()


def bitmap(path: str, width_dots: int, page_width: int = 420):
    """Gespeichertes Logo auf die gewünschte Breite bringen und als 1-Bit-Bild (Floyd-Steinberg) zurückgeben.

    Gibt None zurück, wenn kein lesbares Logo vorliegt; der Bon wird dann ohne Logo gedruckt.
    """
    from PIL import Image
    try:
        Image.MAX_IMAGE_PIXELS = MAX_PIXELS
        image = Image.open(path)
        image.load()
    except Exception:
        return None
    image = image.convert("L")
    width = max(32, min(int(width_dots), MAX_DOTS, page_width))
    height = max(1, round(image.height * width / image.width))
    if height > MAX_HEIGHT:
        width, height = max(1, round(image.width * MAX_HEIGHT / image.height)), MAX_HEIGHT
    image = image.resize((width, height), Image.LANCZOS)
    # Unten etwas Weiß, damit der nächste Text nicht am Logo klebt.
    padded = Image.new("L", (width, height + 8), 255)
    padded.paste(image, (0, 0))
    return padded.convert("1")


def main() -> int:
    try:
        sys.stdout.buffer.write(normalize(sys.stdin.buffer.read(MAX_BYTES + 1)))
        return 0
    except LogoError as exc:
        print(str(exc), file=sys.stderr)
        return 2
    except ModuleNotFoundError:
        print("Für das Logo fehlt das Paket python3-pil. Bitte das Modul erneut installieren.", file=sys.stderr)
        return 1
    except Exception:
        print("Das Logo konnte nicht verarbeitet werden.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
