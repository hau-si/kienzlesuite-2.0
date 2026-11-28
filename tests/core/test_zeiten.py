"""Verbindung „zeiten“ (Praxis → Öffnungszeiten) in core/lib/kienzle/verbindungen.py: Aufbau prüfen, nur an anmeldende Module verteilen."""
import sys
import unittest
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


if __name__ == '__main__':
    unittest.main()
