"""Programmteil des Moduls terminzettel: Auftragsdatei, Drucken über lp, Prüf- und Selbsttest. Nur erfundene Beispieldaten."""
import contextlib
import importlib.util
import io
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("ticket_runtime_test", ROOT / "lib" / "terminzettel.py")
app = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = app
spec.loader.exec_module(app)
TEXT = "TERMINE\nTestperson Alpha\nTerminzeitpunkt              Termintyp\nDo. 17.09.2026, 09:00 Kontrolle\n"


class RuntimeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.source = self.root / "job"
        self.source.write_text(TEXT)
        self.config = self.root / "config.toml"
        self.config.write_text('[output]\nqueue="Bon-1"\n')

    def main(self, *args):
        stdout, stderr = io.StringIO(), io.StringIO()
        with patch.object(sys, "argv", ["terminzettel", "--config", str(self.config), *args]), contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            with patch.object(app, "WEB_OVERLAY", str(self.root / "fehlt.json")), patch.object(app, "BASIS", str(self.root / "fehlt2.json")):
                result = app.main()
        return result, stdout.getvalue(), stderr.getvalue()

    def test_druck_ueber_lp_und_auftrag_verschwindet(self):
        with patch.object(app.subprocess, "run") as run:
            run.return_value = app.subprocess.CompletedProcess([], 0, b"")
            result, out, err = self.main(str(self.source))
        self.assertEqual(result, 0)
        args = run.call_args.args[0]
        self.assertEqual(args[:3], ["/usr/bin/lp", "-d", "Bon-1"])
        self.assertIn("raw", args)
        self.assertTrue(run.call_args.kwargs["input"].startswith(b"\x1b"))
        self.assertFalse(self.source.exists())
        self.assertNotIn("Testperson", out + err)
        self.assertNotIn("Testperson", " ".join(args))

    def test_fehler_ohne_inhalt_und_auftrag_verschwindet(self):
        self.source.write_text("kein Beleg Testperson")
        result, out, err = self.main(str(self.source))
        self.assertEqual(result, 1)
        self.assertNotIn("Testperson", out + err)
        self.assertFalse(self.source.exists())

    def test_ohne_bondrucker_klare_meldung(self):
        self.config.write_text("[output]\n")
        result, _, err = self.main(str(self.source))
        self.assertEqual(result, 1)
        self.assertIn("Kein Bondrucker zugeordnet", err)

    def test_lp_abgelehnt(self):
        with patch.object(app.subprocess, "run") as run:
            run.return_value = app.subprocess.CompletedProcess([], 1, b"")
            result, _, err = self.main(str(self.source))
        self.assertEqual(result, 1)
        self.assertIn("abgelehnt", err)

    def test_pruefen_behaelt_datei_und_druckt_nicht(self):
        with patch.object(app.subprocess, "run") as run:
            result, out, _ = self.main("--check", str(self.source))
        self.assertEqual(result, 0)
        run.assert_not_called()
        self.assertTrue(self.source.exists())
        self.assertNotIn("Testperson", out)

    def test_selbsttest(self):
        result, out, _ = self.main("--self-test")
        self.assertEqual((result, out.strip()), (0, "Selbsttest erfolgreich."))

    def test_symlink_wird_nicht_gefolgt(self):
        link = self.root / "link"
        link.symlink_to(self.source)
        result, _, _ = self.main(str(link))
        self.assertEqual(result, 1)
        self.assertTrue(self.source.exists())

    def test_zu_grosse_datei(self):
        self.source.write_bytes(b"x" * (app.MAX_INPUT + 1))
        result, _, err = self.main(str(self.source))
        self.assertEqual(result, 1)
        self.assertIn("zu groß", err)

    def test_ungueltige_warteschlange(self):
        self.config.write_text('[output]\nqueue="../x"\n')
        with patch.object(app.subprocess, "run") as run:
            result, _, err = self.main(str(self.source))
        self.assertEqual(result, 1)
        run.assert_not_called()

    def test_steuerzeichen_im_beleg_werden_abgelehnt(self):
        text = "TERMINE\nTest\x1bperson\nTerminzeitpunkt   Termintyp\nDo. 17.09.2026, 09:00 Kontrolle\n"
        with self.assertRaisesRegex(app.TicketError, "Steuerzeichen"):
            app.make_payload(text.encode(), {})


if __name__ == "__main__":
    unittest.main()
