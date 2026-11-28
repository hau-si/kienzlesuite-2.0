"""Tests für die Übersetzungs-Verbindung (DeepL) in core/lib/kienzle/verbindungen.py: Eingaben, Adresse Free/Pro, Status ohne Schlüssel, Verteilen, Prüfung."""
import json
import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'core' / 'lib'))
from kienzle import verbindungen as v  # noqa: E402


class Antwort:
    def __init__(self, daten): self.daten = daten
    def __enter__(self): return self
    def __exit__(self, *a): return False
    def read(self, n): return json.dumps(self.daten).encode()


class Uebersetzung(unittest.TestCase):
    def test_speichern_und_adresse(self):
        c = {}
        v.set_uebersetzung(c, {'api_key': ' 0000-beispiel-schluessel:fx '})
        self.assertEqual(c['uebersetzung'], {'anbieter': 'deepl', 'api_key': '0000-beispiel-schluessel:fx'})
        v.set_uebersetzung(c, {'api_key': ''})
        self.assertEqual(c['uebersetzung']['api_key'], '0000-beispiel-schluessel:fx', 'leer behält den Schlüssel')
        self.assertEqual(v.deepl_url('x:fx'), 'https://api-free.deepl.com/v2')
        self.assertEqual(v.deepl_url('x'), 'https://api.deepl.com/v2')
        for bad in ({'api_key': ''}, {'api_key': 'mit leer zeichen'}, {'api_key': 'a/b'}, {'api_key': 7}):
            with self.assertRaises(v.Fehler):
                v.set_uebersetzung({}, bad)

    def test_status_ohne_schluessel_und_verteilen(self):
        c = {'t2med': None, 'mail': None, 'ablage': [], 'uebersetzung': {'anbieter': 'deepl', 'api_key': 'geheim-0000:fx'}}
        with mock.patch.object(v, '_write') as w, mock.patch.object(v, 'registry', return_value=[]):
            v.view(c)
        self.assertNotIn('geheim', json.dumps(w.call_args[0][1]))
        self.assertTrue(w.call_args[0][1]['uebersetzung']['gratis'])
        mods = [{'module': {'id': 'signatur'}, 'connections': [{'id': 'uebersetzung'}]}, {'module': {'id': 'andere'}, 'connections': [{'id': 'mail'}]}]
        with mock.patch.object(v, '_write') as w, mock.patch.object(v, 'registry', return_value=mods), mock.patch.object(v, '_group_exists', return_value=True), \
                mock.patch.object(v, 'KEYS', Path('/nonexistent-keys')), mock.patch.object(Path, 'exists', return_value=True), \
                mock.patch.object(Path, 'is_dir', return_value=False):
            v.distribute(c)
        written = {Path(call.args[0]).name: call.args[1] for call in w.call_args_list}
        self.assertEqual(written['signatur.json']['uebersetzung']['url'], 'https://api-free.deepl.com/v2')
        self.assertNotIn('uebersetzung', written['andere.json'])

    def test_pruefen(self):
        c = {'anbieter': 'deepl', 'api_key': '0000-beispiel:fx'}
        with mock.patch('urllib.request.urlopen', return_value=Antwort({'character_count': 1200, 'character_limit': 500000})) as call:
            msg = v.test_uebersetzung(c)
        self.assertIn('DeepL API Free', msg)
        self.assertIn('1.200 von 500.000', msg)
        anfrage = call.call_args[0][0]
        self.assertEqual(anfrage.full_url, 'https://api-free.deepl.com/v2/usage')
        self.assertEqual(anfrage.get_header('Authorization'), 'DeepL-Auth-Key 0000-beispiel:fx')
        with mock.patch('urllib.request.urlopen', side_effect=OSError('403')):
            with self.assertRaises(v.Fehler):
                v.test_uebersetzung(c)
        with self.assertRaises(v.Fehler):
            v.test_uebersetzung(None)


if __name__ == '__main__':
    unittest.main()
