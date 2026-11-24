"""Praxislogo: Upload prüfen und verkleinern, als Raster in den Bon einbauen, in der Vorschau zeigen."""
import contextlib
import importlib.util
import io
from pathlib import Path
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "lib"))
spec = importlib.util.spec_from_file_location("ticket_logo_test", ROOT / "lib" / "terminzettel.py")
app = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = app
spec.loader.exec_module(app)
import logo  # noqa: E402
import preview  # noqa: E402
from PIL import Image, ImageDraw  # noqa: E402

TEXT = ("TERMINE\nTestperson Alpha\nTerminzeitpunkt              Termintyp\nDo. 17.09.2026, 09:00 Kontrolle\n")


def sample_png(size=(600, 300), mode="RGBA"):
    image = Image.new(mode, size, (0, 0, 0, 0) if mode == "RGBA" else 255)
    ImageDraw.Draw(image).ellipse((20, 20, size[0] - 20, size[1] - 20), fill=(10, 90, 80, 255) if mode == "RGBA" else 0)
    out = io.BytesIO()
    image.save(out, "PNG")
    return out.getvalue()


def render(overlay):
    cfg = app.apply_overlay({"calendar_qr": {"enabled": False}}, overlay)
    with contextlib.redirect_stderr(io.StringIO()):
        return app.render_escpos(app.parse_t2med(TEXT), cfg)


class LogoTests(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = str(Path(self.dir.name) / "logo.png")
        self.old = app.LOGO
        app.LOGO = preview.app.LOGO = self.path  # die Vorschau lädt terminzettel unter eigenem Namen

    def tearDown(self):
        app.LOGO = preview.app.LOGO = self.old
        self.dir.cleanup()

    def stored(self, **kw):
        Path(self.path).write_bytes(logo.normalize(sample_png(**kw)))

    def test_normalize_scales_down_and_flattens_transparency(self):
        png = logo.normalize(sample_png((1200, 900)))
        image = Image.open(io.BytesIO(png))
        self.assertEqual(image.mode, "L")
        self.assertLessEqual(image.width, logo.MAX_DOTS)
        self.assertLessEqual(image.height, logo.MAX_HEIGHT)
        self.assertEqual(image.getpixel((0, 0)), 255)  # transparent wird weiß, nicht schwarz

    def test_normalize_rejects_non_images_and_other_formats(self):
        for bad in (b"", b"kein Bild", b"%PDF-1.4\n" + b"x" * 100, b"<svg xmlns='http://www.w3.org/2000/svg'/>"):
            with self.assertRaises(logo.LogoError):
                logo.normalize(bad)
        bmp = io.BytesIO()
        Image.new("RGB", (10, 10)).save(bmp, "BMP")
        with self.assertRaises(logo.LogoError):
            logo.normalize(bmp.getvalue())
        with self.assertRaises(logo.LogoError):
            logo.normalize(b"\x00" * (logo.MAX_BYTES + 1))

    def test_bitmap_is_one_bit_with_requested_width(self):
        self.stored()
        image = logo.bitmap(self.path, 200)
        self.assertEqual(image.mode, "1")
        self.assertEqual(image.width, 200)
        self.assertIsNone(logo.bitmap(self.path + ".fehlt", 200))

    def test_overlay_validation(self):
        for bad in ({"enabled": "ja"}, {"enabled": True, "width_dots": 10}, {"enabled": True, "width_dots": 500}, {"enabled": True, "width_dots": "256"}):
            with self.assertRaises(app.TicketError):
                app.apply_overlay({}, {"logo": bad})

    def test_logo_is_sent_as_raster_before_the_header(self):
        self.stored()
        with_logo = render({"logo": {"enabled": True, "width_dots": 256}, "header": {"enabled": True, "text": "Praxis"}})
        without = render({"logo": {"enabled": False, "width_dots": 256}, "header": {"enabled": True, "text": "Praxis"}})
        self.assertIn(b"\x1dv0\x00", with_logo)
        self.assertNotIn(b"\x1dv0\x00", without)
        self.assertLess(with_logo.index(b"\x1dv0\x00"), with_logo.index(b"Praxis"))
        self.assertIn(b"\x1ba\x01", with_logo[:with_logo.index(b"\x1dv0\x00")])  # mittig

    def test_missing_logo_file_does_not_break_printing(self):
        out = render({"logo": {"enabled": True, "width_dots": 256}, "header": {"enabled": True, "text": "Praxis"}})
        self.assertNotIn(b"\x1dv0\x00", out)
        self.assertIn(b"Praxis", out)

    def test_preview_shows_the_logo(self):
        overlay = {"model": "tm-m10", "header": {"enabled": False}}
        plain, _ = preview.render_preview({**overlay, "logo": {"enabled": False, "width_dots": 256}})
        self.stored()
        shown, _ = preview.render_preview({**overlay, "logo": {"enabled": True, "width_dots": 256}})
        a, b = Image.open(io.BytesIO(plain)), Image.open(io.BytesIO(shown))
        self.assertGreater(b.height, a.height + 100)
        self.assertEqual(b.width, a.width)


if __name__ == "__main__":
    unittest.main()
