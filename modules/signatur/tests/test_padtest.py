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


if __name__ == '__main__':
    unittest.main()
