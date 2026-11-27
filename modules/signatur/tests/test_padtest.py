import importlib.util
import os
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('padtest', os.path.join(os.path.dirname(__file__), '..', 'lib', 'padtest.py'))
padtest = importlib.util.module_from_spec(spec)
spec.loader.exec_module(padtest)


class PadTest(unittest.TestCase):
    def test_ohne_ip_gibt_hilfe(self):
        self.assertEqual(padtest.main([]), 2)

    def test_tiff_verweis_nur_wenn_noetig(self):
        with tempfile.TemporaryDirectory() as d:
            padtest.tiff_verweis(d)
            ziel = os.path.join(d, 'libtiff.so.5')
            if os.path.exists('/usr/lib/x86_64-linux-gnu/libtiff.so.6'):
                self.assertTrue(os.path.islink(ziel))
            padtest.tiff_verweis(d)  # zweiter Aufruf ändert nichts und wirft nichts

    def test_fehlende_bibliothek_wird_gemeldet(self):
        with tempfile.TemporaryDirectory() as d:
            os.environ['LD_LIBRARY_PATH'] = d
            self.assertEqual(padtest.main(['192.0.2.5', '1002', d]), 1)


class SignTest(unittest.TestCase):
    def test_ohne_ip_gibt_hilfe(self):
        spec2 = importlib.util.spec_from_file_location('padsign', os.path.join(os.path.dirname(__file__), '..', 'lib', 'padsign.py'))
        m = importlib.util.module_from_spec(spec2)
        spec2.loader.exec_module(m)
        self.assertEqual(m.main([]), 2)


class DienstEinheitTest(unittest.TestCase):
    def test_netlink_fuer_stiftpunkte(self):
        with open(os.path.join(os.path.dirname(__file__), '..', 'systemd', 'kienzle-signatur-pad.service'), encoding='utf-8') as fh:
            einheit = fh.read()
        zeile = [z for z in einheit.splitlines() if z.startswith('RestrictAddressFamilies=')]
        self.assertEqual(len(zeile), 1)
        self.assertIn('AF_NETLINK', zeile[0].split('=', 1)[1].split(), 'ohne AF_NETLINK liefert das Pad keine Stiftpunkte')


if __name__ == '__main__':
    unittest.main()
