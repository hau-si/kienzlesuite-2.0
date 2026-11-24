"""Tests für die Letterxpress-Verbindung (core/lib/kienzle/verbindungen.py): Eingaben, Status ohne Schlüssel, Verteilen an Module, Prüfung mit nachgebautem Aufruf."""
import json
import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'core' / 'lib'))
from kienzle import verbindungen as v  # noqa: E402


class Letterxpress(unittest.TestCase):
    def test_speichern_behaelt_schluessel(self):
        c = {}
        v.set_letterxpress(c, {'username': 'praxis-test', 'api_key': 'lxp-1', 'modus': 'live'})
        self.assertEqual(c['letterxpress'], {'username': 'praxis-test', 'api_key': 'lxp-1', 'modus': 'live'})
        v.set_letterxpress(c, {'username': 'praxis-test', 'api_key': '', 'modus': 'test'})
        self.assertEqual((c['letterxpress']['api_key'], c['letterxpress']['modus']), ('lxp-1', 'test'))
        for bad in ({'username': '', 'api_key': 'x'}, {'username': 'a', 'api_key': ''}, {'username': 'a', 'api_key': 'x', 'modus': 'dev'}):
            with self.assertRaises(v.Fehler):
                v.set_letterxpress({}, bad)

    def test_status_ohne_schluessel(self):
        c = {'t2med': None, 'mail': None, 'ablage': [], 'letterxpress': {'username': 'praxis-test', 'api_key': 'geheim', 'modus': 'test'}}
        with mock.patch.object(v, '_write') as w, mock.patch.object(v, 'registry', return_value=[]):
            v.view(c)
        self.assertNotIn('geheim', json.dumps(w.call_args[0][1]))
        self.assertTrue(w.call_args[0][1]['letterxpress']['schluessel_gesetzt'])

    def test_verteilen_nur_an_anmeldende_module(self):
        c = {'t2med': None, 'mail': None, 'ablage': [], 'letterxpress': {'username': 'u', 'api_key': 'k', 'modus': 'test'}}
        mods = [{'module': {'id': 'kasse'}, 'connections': [{'id': 'letterxpress'}]}, {'module': {'id': 'andere'}, 'connections': [{'id': 'mail'}]}]
        with mock.patch.object(v, '_write') as w, mock.patch.object(v, 'registry', return_value=mods), mock.patch.object(v, '_group_exists', return_value=True), \
                mock.patch.object(v, 'KEYS', Path('/nonexistent-keys')), mock.patch.object(Path, 'exists', return_value=True), \
                mock.patch.object(Path, 'is_dir', return_value=False):
            v.distribute(c)
        written = {Path(call.args[0]).name: call.args[1] for call in w.call_args_list}
        self.assertEqual(written['kasse.json']['letterxpress']['api_key'], 'k')
        self.assertNotIn('letterxpress', written['andere.json'])

    def test_guthaben_wird_gemerkt(self):
        c = {'username': 'u', 'api_key': 'k', 'modus': 'live'}
        class Antwort:
            def __enter__(self): return self
            def __exit__(self, *a): return False
            def read(self, n): return b'{"data": {"balance": 7.25}}'
        with mock.patch('urllib.request.urlopen', return_value=Antwort()), mock.patch.object(v, '_balance_record') as rec:
            self.assertEqual(v.balance_letterxpress(c), 'Guthaben bei Letterxpress: 7.25 €.')
        rec.assert_called_once_with('letterxpress', 7.25)
        with mock.patch.object(v, 'letterxpress_balance', return_value=None), mock.patch.object(v, '_balance_record'):
            self.assertIn('kein Guthaben', v.balance_letterxpress(c))

    def test_pruefen(self):
        c = {'username': 'u', 'api_key': 'k', 'modus': 'test'}
        class Antwort:
            def __enter__(self): return self
            def __exit__(self, *a): return False
            def read(self, n): return b'{"data": {"balance": 12.5}}'
        with mock.patch('urllib.request.urlopen', return_value=Antwort()) as call, mock.patch.object(v, '_balance_record'):
            msg = v.test_letterxpress(c)
        self.assertIn('12.50', msg)
        self.assertEqual(call.call_args[0][0].full_url, 'https://api.letterxpress.de/v3/balance')
        with mock.patch('urllib.request.urlopen', side_effect=OSError('abgelehnt')):
            with self.assertRaises(v.Fehler):
                v.test_letterxpress(c)
        with self.assertRaises(v.Fehler):
            v.test_letterxpress(None)


if __name__ == '__main__':
    unittest.main()
