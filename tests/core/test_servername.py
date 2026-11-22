"""Tests für das Ändern der Servernamen (core/lib/kienzle/servername.py) mit nachgebauten Befehlen."""
import importlib
import json
import os
import shutil
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'core' / 'lib'))


class Servername(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.t)
        (self.t / 'bin').mkdir()
        (self.t / 'core.json').write_text(json.dumps({'hostnames': ['192.0.2.10', 'kienzle'], 'practice': 'Praxis', 'data_dir': '/x'}))
        for name in ('kienzle-zertifikat', 'kienzle-web'):
            (self.t / 'bin' / name).write_text(f'#!/bin/sh\necho "{name} $@" >> {self.t}/aufrufe\n'
                                               f'[ "{name}" = kienzle-web ] && grep -q kaputt.example.org {self.t}/core.json && {{ echo "nginx: Fehler" >&2; exit 1; }}\n'
                                               f'[ "{name}" = kienzle-zertifikat ] && exit 3\nexit 0\n')
            (self.t / 'bin' / name).chmod(0o755)
        env = {'KIENZLE_CORE_CONFIG': self.t / 'core.json', 'KIENZLE_SERVERNAME_STATE': self.t / 'state', 'KIENZLE_SERVERNAME_REQUEST': self.t / 'anfrage.json',
               'KIENZLE_SERVERNAME_BIN': self.t / 'bin', 'KIENZLE_SERVERNAME_UID': os.getuid(), 'KIENZLE_SERVERNAME_CHOWN': '0',
               'KIENZLE_TIMEDATECTL': self.t / 'bin' / 'timedatectl'}
        p = mock.patch.dict(os.environ, {k: str(v) for k, v in env.items()})
        p.start()
        self.addCleanup(p.stop)
        import kienzle.servername
        self.s = importlib.reload(kienzle.servername)

    def config(self):
        return json.loads((self.t / 'core.json').read_text())

    def test_aendern(self):
        msg = self.s.apply(['Kienzle.Praxis.Internal', '192.0.2.10', 'kienzle.praxis.internal'], 'Praxis Dr. Beispiel')
        self.assertIn('https://kienzle.praxis.internal/', msg)
        c = self.config()
        self.assertEqual(c['hostnames'], ['kienzle.praxis.internal', '192.0.2.10'])
        self.assertEqual((c['practice'], c['data_dir']), ('Praxis Dr. Beispiel', '/x'))
        self.assertEqual((self.t / 'aufrufe').read_text().split('\n')[:2], ['kienzle-zertifikat erneuern', 'kienzle-web neu'])
        self.assertEqual(self.s.apply(['kienzle.praxis.internal', '192.0.2.10'], None), 'Keine Änderung.')

    def test_ruecknahme(self):
        with self.assertRaisesRegex(self.s.Fehler, 'vorige Stand'):
            self.s.apply(['kaputt.example.org'], None)
        self.assertEqual(self.config()['hostnames'], ['192.0.2.10', 'kienzle'])

    def test_name_ausserhalb_der_ca(self):
        with mock.patch.object(self.s, 'outside', return_value=['bank.example.net']):
            with self.assertRaisesRegex(self.s.Fehler, 'außerhalb'):
                self.s.apply(['bank.example.net'], None)
            self.assertEqual(self.config()['hostnames'], ['192.0.2.10', 'kienzle'])
            self.assertIn('NEUE Zertifizierungsstelle', self.s.apply(['bank.example.net'], None, True))
        self.assertIn('kienzle-zertifikat neue-ca', (self.t / 'aufrufe').read_text())

    def test_uhrzeit(self):
        tdc = self.t / 'bin' / 'timedatectl'
        tdc.write_text(f'#!/bin/sh\necho "$@" >> {self.t}/uhr\n')
        tdc.chmod(0o755)
        (self.t / 'anfrage.json').write_text(json.dumps({'aktion': 'uhrzeit', 'delta': 3600}))
        self.s.handle()
        calls = (self.t / 'uhr').read_text().splitlines()
        self.assertEqual(calls[0], 'set-ntp false')
        self.assertRegex(calls[1], r'^set-time \d{4}-\d\d-\d\d \d\d:\d\d:\d\d$')
        self.assertEqual(calls[2], 'set-ntp true')
        st = json.loads((self.t / 'state' / 'status.json').read_text())
        self.assertTrue(st['letzte']['ok'] and st['uhr']['delta'] == 3600)
        for bad in (86401, '60', True, None):
            with self.subTest(bad), self.assertRaises(self.s.Fehler):
                self.s.set_clock(bad)

    def test_eingaben(self):
        for bad in ([], ['a b'], ['127.0.0.1'], ['-x.example.org'], ['1.2.3'], ['x' * 254], ['a'] * 11, 'kienzle', ['ok.example.org\nevil']):
            with self.subTest(bad), self.assertRaises(self.s.Fehler):
                self.s.apply(bad, None)
        with self.assertRaises(self.s.Fehler):
            self.s.apply(['ok.example.org'], '<script>')

    def test_anfrage(self):
        (self.t / 'anfrage.json').write_text(json.dumps({'namen': ['kienzle.praxis.internal'], 'praxis': 'Praxis Neu'}))
        self.s.handle()
        st = json.loads((self.t / 'state' / 'status.json').read_text())
        self.assertTrue(st['letzte']['ok'], st)
        self.assertFalse((self.t / 'anfrage.json').exists())
        os.environ['KIENZLE_SERVERNAME_UID'] = str(os.getuid() + 1)
        (self.t / 'anfrage.json').write_text(json.dumps({'namen': ['b.example.org']}))
        self.s.handle()
        self.assertEqual(self.config()['hostnames'], ['kienzle.praxis.internal'])


if __name__ == '__main__':
    unittest.main()
