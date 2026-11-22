"""Tests für das Einsammeln der Einrichtungs-Überblicke (core/lib/kienzle/ueberblick.py)."""
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


class Ueberblick(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.t)
        env = {'KIENZLE_UEBERBLICK_STATE': self.t / 'state', 'KIENZLE_UEBERBLICK_MODULES': self.t / 'modules.json', 'KIENZLE_UEBERBLICK_PROGRAMME': self.t / 'opt',
               'KIENZLE_UEBERBLICK_REQUEST': self.t / 'anfrage.json', 'KIENZLE_UEBERBLICK_RUNAS': '0'}
        p = mock.patch.dict(os.environ, {k: str(v) for k, v in env.items()})
        p.start()
        self.addCleanup(p.stop)
        import kienzle.ueberblick
        self.u = importlib.reload(kienzle.ueberblick)

    def module(self, mid, script):
        d = self.t / 'opt' / mid / 'current' / 'bin'
        d.mkdir(parents=True)
        (d / 'setup-overview').write_text('#!/bin/sh\n' + script)
        (d / 'setup-overview').chmod(0o755)
        return {'module': {'id': mid, 'name': mid.title()}, 'web': {'path': f'/{mid}'}, 'setup': {'overview': 'bin/setup-overview'}}

    def test_sammeln(self):
        ok = self.module('kasse', """echo '{"items": [{"title": "Schlüssel", "state": "done", "link": "/kasse/einstellungen"}]}'\n""")
        bad = self.module('fax', """echo '{"items": [{"title": "x", "state": "done", "link": "/verwaltung"}]}'\n""")
        broken = self.module('zeit', 'exit 3\n')
        (self.t / 'modules.json').write_text(json.dumps({'modules': [ok, bad, broken, {'module': {'id': 'ohne'}, 'setup': {'overview': None}}]}))
        (self.t / 'state').mkdir()
        (self.t / 'state' / 'alt.json').write_text('{}')
        (self.t / 'anfrage.json').write_text('{}')
        self.assertEqual(self.u.collect(), 3)
        k = json.loads((self.t / 'state' / 'kasse.json').read_text())
        self.assertEqual(k['items'], [{'title': 'Schlüssel', 'state': 'done', 'hint': '', 'link': '/kasse/einstellungen'}])
        self.assertIn('eigenen Pfad', json.loads((self.t / 'state' / 'fax.json').read_text())['fehler'])
        self.assertIn('Fehler 3', json.loads((self.t / 'state' / 'zeit.json').read_text())['fehler'])
        self.assertFalse((self.t / 'state' / 'alt.json').exists(), 'Überblick eines entfernten Moduls bleibt liegen')
        self.assertFalse((self.t / 'anfrage.json').exists())

    def test_format(self):
        for bad in ('[]', '{"items": 1}', '{"items": [{"title": "x", "state": "fertig"}]}', '{"items": [{"state": "done"}]}',
                    json.dumps({'items': [{'title': 'x', 'state': 'done'}] * 31}), '{"items": [{"title": "x", "state": "done", "link": "/kasse/../verwaltung"}]}'):
            with self.subTest(bad), self.assertRaises(ValueError):
                self.u.check_output(bad, '/kasse')
        out = self.u.check_output('{"items": [{"title": "  a\\u0007b ", "state": "open", "hint": "h"}]}', '/kasse')
        self.assertEqual(out[0]['title'], 'ab')


if __name__ == '__main__':
    unittest.main()
