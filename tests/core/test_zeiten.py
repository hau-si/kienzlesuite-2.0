"""Verbindung „zeiten“ (Praxis → Öffnungszeiten) in core/lib/kienzle/verbindungen.py: Aufbau prüfen, nur an anmeldende Module verteilen;
Google-Unternehmensprofil mit nachgestellter Google-Schnittstelle (keine echten Anfragen)."""
import json
import sys
import unittest
from datetime import date, timedelta
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'core' / 'lib'))
from kienzle import manifest, verbindungen as v  # noqa: E402

PAKET = {'format': 1, 'stand': 'ab' * 16, 'praxis': 'Praxis Beispiel', 'woche': {'sprech': {'mo': ['08:00-12:00']}, 'telefon': {'mo': []}},
         'ereignisse': [{'id': 'f-neujahr-2027', 'art': 'feiertag', 'titel': 'Neujahr', 'von': '2027-01-01', 'bis': '2027-01-01'}]}


class Zeiten(unittest.TestCase):
    def test_pruefen(self):
        c = {}
        v.set_zeiten(c, dict(PAKET))
        self.assertEqual(c['zeiten']['stand'], 'ab' * 16)
        for bad in ({**PAKET, 'format': 2}, {**PAKET, 'stand': 'x'}, {**PAKET, 'fremd': 1}, {**PAKET, 'ereignisse': 'nein'}, {**PAKET, 'ereignisse': [1]},
                    {**PAKET, 'ereignisse': [{'titel': 'x' * 70000}]}, 'nein'):
            with self.assertRaises(v.Fehler):
                v.set_zeiten({}, bad)
        self.assertIn('zeiten', manifest.CONNECTIONS)

    def test_verteilen(self):
        c = {'t2med': None, 'mail': None, 'ablage': [], 'zeiten': PAKET}
        mods = [{'module': {'id': 'homepage'}, 'connections': [{'id': 'zeiten'}]}, {'module': {'id': 'andere'}, 'connections': [{'id': 'mail'}]}]
        with mock.patch.object(v, '_write') as w, mock.patch.object(v, 'registry', return_value=mods), mock.patch.object(v, '_group_exists', return_value=True), \
                mock.patch.object(v, 'KEYS', Path('/nonexistent-keys')), mock.patch.object(Path, 'exists', return_value=True), \
                mock.patch.object(Path, 'is_dir', return_value=False):
            v.distribute(c)
        written = {Path(call.args[0]).name: call.args[1] for call in w.call_args_list}
        self.assertEqual(written['homepage.json']['zeiten'], PAKET)
        self.assertNotIn('zeiten', written['andere.json'])
        with mock.patch.object(v, '_write') as w, mock.patch.object(v, 'registry', return_value=[]):
            v.view(c)
        self.assertEqual(w.call_args[0][1]['zeiten'], {'stand': 'ab' * 16})


class Antwort:
    def __init__(self, daten): self.daten = daten
    def __enter__(self): return self
    def __exit__(self, *a): return False
    def read(self, n): return json.dumps(self.daten).encode()


class Google(unittest.TestCase):
    def setUp(self):
        self.anfragen = []

        def urlopen(req, timeout=0):
            url = req.full_url
            self.anfragen.append((req.get_method(), url, json.loads(req.data) if req.data and req.get_method() == 'PATCH' else req.data))
            if url.startswith(v.GOOGLE_TOKEN):
                return Antwort({'access_token': 'zugriff-0000', 'refresh_token': 'dauer-0000'})
            if url == v.GBP_KONTEN:
                return Antwort({'accounts': [{'name': 'accounts/111'}, {'name': '../böse'}]})
            if '/locations?' in url:
                return Antwort({'locations': [{'name': 'locations/222', 'title': 'Praxis Dr. med. Erika Beispiel',
                                               'storefrontAddress': {'addressLines': ['Musterstraße 1'], 'postalCode': '12345', 'locality': 'Musterstadt'}}]})
            return Antwort({})
        self.patch = mock.patch('urllib.request.urlopen', urlopen)
        self.patch.start()
        self.addCleanup(self.patch.stop)

    def test_inhalt(self):
        z = {'woche': {'sprech': {'mo': ['08:00-12:00', '14:00-18:30'], 'sa': []}},
             'ereignisse': [{'id': 'e1', 'art': 'urlaub', 'von': '2026-12-23', 'bis': '2026-12-25', 'google': True},
                            {'id': 'e2', 'art': 'sonder', 'von': '2026-12-24', 'bis': '2026-12-24', 'zeiten': ['08:00-12:00'], 'google': True},
                            {'id': 'e3', 'art': 'sonder', 'von': '2026-12-30', 'bis': '2026-12-30', 'zeiten': ['08:00-12:00', 'kaputt'], 'google': True},
                            {'id': 'e4', 'art': 'fortbildung', 'von': '2027-01-05', 'bis': '2027-01-05', 'google': False},
                            {'id': 'alt', 'art': 'urlaub', 'von': '2026-11-01', 'bis': '2026-12-22', 'google': True}]}
        i = v.google_inhalt(z, date(2026, 12, 22))
        self.assertEqual(i['regularHours']['periods'][1], {'openDay': 'MONDAY', 'openTime': {'hours': 14}, 'closeDay': 'MONDAY', 'closeTime': {'hours': 18, 'minutes': 30}})
        tage = i['specialHours']['specialHourPeriods']
        tag22 = {'year': 2026, 'month': 12, 'day': 22}
        self.assertEqual(tage[0], {'startDate': tag22, 'endDate': tag22, 'closed': True}, 'heute noch, vorher nicht')
        self.assertEqual(len(tage), 5, tage)
        self.assertTrue(tage[2]['closed'], 'Schließung geht vor Sonderöffnung am 24.12.')
        self.assertEqual(tage[4]['openTime'], {'hours': 8})

    def test_verbinden_ort_und_uebertragen(self):
        tag = (date.today() + timedelta(days=10)).isoformat()
        c = {'t2med': None, 'mail': None, 'ablage': [], 'zeiten': PAKET | {'ereignisse': [{'id': 'f', 'art': 'feiertag', 'von': tag, 'bis': tag, 'google': True}]}}
        with self.assertRaises(v.Fehler):
            v.set_google(c, {'client_id': 'x', 'client_secret': 's', 'code': 'c'})
        v.set_google(c, {'client_id': '1234567890.apps.googleusercontent.com', 'client_secret': 'geheim-0000', 'code': '4/abc'})
        g = c['google']
        self.assertEqual((g['ort'], g['refresh_token']), ('locations/222', 'dauer-0000'), 'einziger Standort vorgewählt')
        self.assertEqual(g['orte'][0]['adresse'], 'Musterstraße 1, 12345 Musterstadt')
        self.assertNotIn('geheim', json.dumps(v._google_status(g)))
        with self.assertRaises(v.Fehler):
            v.set_google_ort(c, {'ort': 'locations/999'})
        v.set_google_ort(c, {'ort': 'locations/222', 'regular': True, 'special': True})
        self.assertIn('1 Sondertage', v.google_sync(c))
        methode, url, body = self.anfragen[-1]
        self.assertEqual(methode, 'PATCH')
        self.assertTrue(url.startswith(v.GBP_INFO + 'locations/222?updateMask=regularHours%2CspecialHours'), url)
        self.assertEqual(body['regularHours']['periods'][0]['openDay'], 'MONDAY')
        self.assertTrue(g['letzte']['ok'])
        # leere Woche wird nie geschickt (würde bei Google alle Zeiten löschen)
        c['zeiten'] = PAKET | {'woche': {'sprech': {}}, 'ereignisse': []}
        v.google_sync(c)
        self.assertIn('updateMask=specialHours', self.anfragen[-1][1])
        self.assertNotIn('regularHours', self.anfragen[-1][2])
        v.set_google_ort(c, {'ort': 'locations/222', 'regular': False, 'special': False})
        self.assertEqual(v.google_sync(c), 'Nichts zu übertragen.')

    def test_fehler_von_google(self):
        import io
        import urllib.error

        def abgelehnt(req, timeout=0):
            if req.full_url.startswith(v.GOOGLE_TOKEN):
                return Antwort({'access_token': 'zugriff-0000'})
            meldung = {'error': {'message': 'API has not been used in project 1 before or it is disabled'}}
            raise urllib.error.HTTPError(req.full_url, 403, 'x', {}, io.BytesIO(json.dumps(meldung).encode()))
        c = {'google': {'client_id': 'a', 'client_secret': 'b', 'refresh_token': 'c', 'ort': 'locations/1', 'regular': True, 'special': True}, 'zeiten': PAKET}
        with mock.patch('urllib.request.urlopen', abgelehnt):
            with self.assertRaises(v.Fehler) as f:
                v.google_sync(c)
        self.assertIn('beantragen', str(f.exception))
        self.assertFalse(c['google']['letzte']['ok'])


if __name__ == '__main__':
    unittest.main()
