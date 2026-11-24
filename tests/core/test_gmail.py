"""Tests für die Gmail-Verbindung (OAuth, core/lib/kienzle/verbindungen.py) mit nachgebautem Google-Aufruf; nur erfundene Adressen und Schlüssel."""
import base64
import json
import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'core' / 'lib'))
from kienzle import verbindungen as v  # noqa: E402

CID = '1234567890-abc.apps.googleusercontent.com'
G = {'art': 'gmail', 'client_id': CID, 'client_secret': 'GOCSPX-x', 'refresh_token': 'r-token', 'absender': 'praxis@gmail.example'}


class Gmail(unittest.TestCase):
    def test_code_wird_getauscht(self):
        c = {}
        with mock.patch.object(v, '_google_post', return_value={'refresh_token': 'r-token', 'access_token': 'a'}) as post:
            v.set_gmail(c, {'client_id': CID, 'client_secret': 'GOCSPX-x', 'absender': 'praxis@gmail.example', 'code': '4/abc'})
        self.assertEqual(c['mail'], G)
        url, data, _ = post.call_args[0]
        self.assertEqual(url, v.GOOGLE_TOKEN)
        self.assertIn(b'grant_type=authorization_code', data)
        self.assertIn(b'redirect_uri=http%3A%2F%2Flocalhost', data)

    def test_fehler(self):
        with mock.patch.object(v, '_google_post', return_value={'access_token': 'a'}):
            with self.assertRaises(v.Fehler):   # kein Dauer-Token
                v.set_gmail({}, {'client_id': CID, 'client_secret': 's', 'absender': 'praxis@gmail.example', 'code': '4/abc'})
        for bad in ({'client_id': 'kurz'}, {'client_secret': 'a b'}, {'absender': 'keine-adresse'}, {'code': 'a b'}):
            d = {'client_id': CID, 'client_secret': 's', 'absender': 'praxis@gmail.example', 'code': ''} | bad
            with self.assertRaises(v.Fehler):
                v.set_gmail({}, d)
        with self.assertRaises(v.Fehler):       # ohne Code und ohne bestehende Anmeldung
            v.set_gmail({}, {'client_id': CID, 'client_secret': 's', 'absender': 'praxis@gmail.example', 'code': ''})

    def test_ohne_code_bleibt_anmeldung(self):
        c = {'mail': dict(G)}
        v.set_gmail(c, {'client_id': CID, 'client_secret': '', 'absender': 'neu@gmail.example', 'code': ''})
        self.assertEqual((c['mail']['refresh_token'], c['mail']['client_secret'], c['mail']['absender']), ('r-token', 'GOCSPX-x', 'neu@gmail.example'))

    def test_pruefen_und_senden(self):
        with mock.patch.object(v, '_google_post', return_value={'access_token': 'acc'}):
            self.assertIn('erfolgreich', v.test_mail(G))
        calls = []
        def fake(url, data, headers):
            calls.append((url, data, headers))
            return {'access_token': 'acc'} if url == v.GOOGLE_TOKEN else {'id': '1'}
        with mock.patch.object(v, '_google_post', side_effect=fake):
            v.send_mail(G, ['it@example.org'], 'Betreff', 'Text')
        url, data, headers = calls[1]
        self.assertEqual((url, headers['Authorization']), (v.GMAIL_SEND, 'Bearer acc'))
        raw = base64.urlsafe_b64decode(json.loads(data)['raw']).decode()
        self.assertIn('To: it@example.org', raw)
        self.assertIn('From: praxis@gmail.example', raw)

    def test_status_ohne_geheimnisse(self):
        c = {'t2med': None, 'mail': dict(G), 'ablage': []}
        with mock.patch.object(v, '_write') as w, mock.patch.object(v, 'registry', return_value=[]):
            v.view(c)
        out = json.dumps(w.call_args[0][1])
        self.assertNotIn('GOCSPX', out)
        self.assertNotIn('r-token', out)
        self.assertTrue(w.call_args[0][1]['mail']['verbunden'])


if __name__ == '__main__':
    unittest.main()
