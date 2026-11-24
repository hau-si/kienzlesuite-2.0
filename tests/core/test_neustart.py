"""Test des Neustart-Dienstes (core/bin/kienzle-neustart) im Probelauf: es wird nichts wirklich neu gestartet."""
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

BIN = Path(__file__).resolve().parents[2] / 'core' / 'bin' / 'kienzle-neustart'


class Neustart(unittest.TestCase):
    def lauf(self, art):
        with tempfile.TemporaryDirectory() as d:
            f = Path(d) / 'neustart.json'
            f.write_text(json.dumps({'art': art}))
            r = subprocess.run([str(BIN), 'anwenden'], capture_output=True, text=True, env={**os.environ, 'KIENZLE_NEUSTART_ANFRAGE': str(f), 'KIENZLE_NEUSTART_PROBE': '1'})
            return r, f.exists()

    def test_dienste(self):
        r, bleibt = self.lauf('dienste')
        self.assertEqual(r.returncode, 0)
        self.assertIn('systemctl restart kienzle-core-php.service', r.stdout)
        self.assertNotIn('reboot', r.stdout)
        self.assertFalse(bleibt)

    def test_server(self):
        r, bleibt = self.lauf('server')
        self.assertEqual((r.returncode, bleibt), (0, False))
        self.assertIn('reboot', r.stdout)

    def test_unbekannt(self):
        r, bleibt = self.lauf('alles')
        self.assertEqual((r.returncode, bleibt), (1, False))
        self.assertEqual(r.stdout, '')


if __name__ == '__main__':
    unittest.main()
