"""Tests für die SMS-Verbindung (core/lib/kienzle/verbindungen.py): Prüfung der Eingaben und Versand mit nachgebautem seven.io. Nur erfundene Nummern."""
import json
import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'core' / 'lib'))
from kienzle import verbindungen as v  # noqa: E402


class Sms(unittest.TestCase):
    def test_speichern_behaelt_schluessel(self):
        c = {}
        v.set_sms(c, {'api_key': 'abc123', 'absender': 'Praxis'})
        self.assertEqual(c['sms'], {'anbieter': 'seven', 'api_key': 'abc123', 'absender': 'Praxis'})
        v.set_sms(c, {'api_key': '', 'absender': ''})
        self.assertEqual(c['sms']['api_key'], 'abc123')
        with self.assertRaises(v.Fehler):
            v.set_sms({}, {'api_key': '', 'absender': ''})
        with self.assertRaises(v.Fehler):
            v.set_sms({}, {'api_key': 'a b', 'absender': ''})
        with self.assertRaises(v.Fehler):
            v.set_sms({}, {'api_key': 'x', 'absender': 'Praxis Dr. Beispiel'})

    def test_senden(self):
        c = {'anbieter': 'seven', 'api_key': 'abc123', 'absender': 'Praxis'}
        with mock.patch.object(v, '_sms_call', return_value={'success': '100'}) as call:
            v.send_sms(c, '+4915112345678', 'Code 123456')
        path, payload = call.call_args[0][1:]
        self.assertEqual((path, payload), ('sms', {'to': '+4915112345678', 'text': 'Code 123456', 'from': 'Praxis'}))
        with mock.patch.object(v, '_sms_call', return_value={'success': '900'}):
            with self.assertRaises(v.Fehler):
                v.send_sms(c, '+4915112345678', 'x')
        for bad in ('015112345678', '+49', 'abc'):
            with self.assertRaises(v.Fehler):
                v.send_sms(c, bad, 'x')
        with self.assertRaises(v.Fehler):
            v.send_sms(None, '+4915112345678', 'x')
        with self.assertRaises(v.Fehler):
            v.send_sms(c, '+4915112345678', 'a' * 400)

    def test_status_ohne_schluessel(self):
        c = {'t2med': None, 'mail': None, 'ablage': [], 'sms': {'anbieter': 'seven', 'api_key': 'geheim', 'absender': ''}}
        with mock.patch.object(v, '_write') as w, mock.patch.object(v, 'registry', return_value=[]):
            v.view(c)
        self.assertNotIn('geheim', json.dumps(w.call_args[0][1]))
        self.assertTrue(w.call_args[0][1]['sms']['schluessel_gesetzt'])


if __name__ == '__main__':
    unittest.main()
