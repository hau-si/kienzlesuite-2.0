"""Absage-Block: runder Balken mit Aufforderung oben, QR-Code im runden Rahmen darunter (ohne python3-qrcode testbar)."""
import importlib.util
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "lib"))
spec = importlib.util.spec_from_file_location("ticket_absage_test", ROOT / "lib" / "terminzettel.py")
app = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = app
spec.loader.exec_module(app)

from PIL import Image  # noqa: E402


def fake_qr(size: int = 200):
    return Image.new("1", (size, size), 1)


class AbsageBlock(unittest.TestCase):
    def test_zeilen_nach_dem_fragezeichen(self):
        self.assertEqual(app._bar_lines("Verhindert? Bitte absagen."), ["Verhindert?", "Bitte absagen."])
        self.assertEqual(app._bar_lines("Termin absagen"), ["Termin absagen"])
        self.assertEqual(app._bar_lines("Bitte rechtzeitig den Termin absagen"), ["Bitte rechtzeitig", "den Termin absagen"])

    def test_block_mit_balken_ist_hoeher_und_passt_ins_raster(self):
        ohne = app.cancel_block_image(fake_qr(), "")
        mit = app.cancel_block_image(fake_qr(), "Verhindert? Bitte absagen.")
        self.assertEqual(mit.mode, "1")
        self.assertEqual(mit.width, ohne.width)
        self.assertGreater(mit.height, ohne.height)
        self.assertLessEqual(mit.width, 384)
        # Balken ist oben schwarz (Mitte der ersten Zeilen), der Rahmen ist gezeichnet.
        self.assertEqual(mit.getpixel((mit.width // 2, 3)), 0)
        self.assertEqual(ohne.getpixel((mit.width // 2, 2)), 0)

    def test_groesster_qr_bleibt_im_raster(self):
        block = app.cancel_block_image(fake_qr(352), "Verhindert? Bitte absagen.")
        self.assertLessEqual(block.width, 384)


if __name__ == "__main__":
    unittest.main()
