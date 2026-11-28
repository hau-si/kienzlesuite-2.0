"""Öffnungszeiten der Basis im Sprachmenü (lib/zeiten-abgleich): Wochenzeiten in kienzlefon.toml und Sonderansagen je Schließung/Feiertag,
mit nachgestelltem Piper und echtem ffmpeg (wie test_ansagen.py). Nur erfundene Daten."""
import json
import shutil
import subprocess
import sys
import tempfile
import tomllib
import unittest
from datetime import date, timedelta
from pathlib import Path

HERE = Path(__file__).resolve().parent
MODUL = HERE.parent
sys.path.insert(0, str(HERE))
from test_ansagen import FAKE_PIPER  # noqa: E402

T = [(date.today() + timedelta(days=n)).isoformat() for n in range(40)]
ANSAGE = 'Liebe Patientinnen und Patienten, unsere Praxis ist vom 23. Dezember bis 3. Januar geschlossen.'


def paket(stand: str, ereignisse: list) -> dict:
    return {'zeiten': {'format': 1, 'stand': stand, 'praxis': 'Praxis Beispiel', 'ereignisse': ereignisse,
                       'woche': {'sprech': {'mo': ['08:00-12:00', '14:00-18:00'], 'di': ['08:00-12:00'], 'mi': [], 'do': [], 'fr': ['08:00-13:00'], 'sa': [], 'so': []},
                                 'telefon': {'mo': ['08:00-11:30'], 'di': ['08:00-12:00'], 'mi': [], 'do': [], 'fr': [], 'sa': [], 'so': []}}}}


@unittest.skipUnless(shutil.which('ffmpeg') and subprocess.run([sys.executable, '-c', 'import cryptography'], capture_output=True).returncode == 0,
                     'ffmpeg oder python3-cryptography fehlt')
class Zeiten(unittest.TestCase):
    def setUp(self):
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t, True)
        fake = t / 'fake'
        (fake / 'piper').mkdir(parents=True)
        (fake / 'piper' / '__init__.py').write_text('')
        (fake / 'piper' / '__main__.py').write_text(FAKE_PIPER)
        venv = t / 'daten' / 'piper' / 'venv' / 'bin'
        venv.mkdir(parents=True)
        (venv / 'python').write_text(f'#!/bin/sh\nexec {sys.executable} "$@"\n')
        (venv / 'python').chmod(0o755)
        (t / 'daten' / 'piper' / 'bereit.json').write_text('{}')
        self.t = t
        self.verb = t / 'telefon.json'
        self.env = {'PATH': '/usr/bin:/bin', 'KIENZLE_DATEN': str(t / 'daten'), 'KIENZLE_PROGRAMM': str(MODUL), 'KIENZLE_ASTERISK': '/bin/false',
                    'PYTHONPATH': str(fake), 'LANG': 'C.UTF-8', 'KIENZLE_VERBINDUNG': str(self.verb)}

    def lauf(self) -> str:
        r = subprocess.run([sys.executable, '-B', str(MODUL / 'lib/zeiten-abgleich')], capture_output=True, text=True, env=self.env, timeout=900)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr[-800:])
        return r.stdout.strip()

    def test_ohne_sprachmenue_und_abgleich(self):
        self.assertIn('Sprachmenü aus', self.lauf())
        cfg = {'leitungen': [{'id': 'l1', 'anbieter': 'andere', 'benutzer': '4930123456', 'passwort': 'nurtest', 'domain': 'sip.example.invalid'}],
               'telefon': {'leitung_ein': 'l1', 'nummer_ein': '4930123456'}, 'netz': {'lokal': '192.0.2.0/24'}, 'kienzlefon': {'aktiv': True, 'tts': 'piper'},
               'nebenstellen': [{'nummer': '201', 'name': 'Empfang', 'passwort': 'A' * 24}]}
        r = subprocess.run([sys.executable, '-B', str(MODUL / 'lib/anlage.py'), 'anwenden'], input=json.dumps(cfg), capture_output=True, text=True, env=self.env)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        # Eine Schließung (mit Telefon), ein Feiertag (mit Telefon), eine Schließung ohne Telefon, eine vergangene
        self.verb.write_text(json.dumps(paket('a' * 32, [
            {'id': 'e1', 'art': 'urlaub', 'titel': 'Weihnachtsurlaub', 'von': T[20], 'bis': T[31], 'telefon': True, 'ansage': ANSAGE},
            {'id': 'f-neujahr-2100', 'art': 'feiertag', 'titel': 'Neujahr', 'von': T[2], 'bis': T[2], 'telefon': True, 'ansage': 'Neujahr ist geschlossen.'},
            {'id': 'e2', 'art': 'fortbildung', 'titel': 'Fortbildung', 'von': T[5], 'bis': T[5], 'telefon': False, 'ansage': 'x'},
            {'id': 'e3', 'art': 'urlaub', 'titel': 'Alt', 'von': '2000-01-01', 'bis': '2000-01-02', 'telefon': True, 'ansage': 'alt'},
        ])))
        self.assertIn('2 Ansagen', self.lauf())
        kzf = self.t / 'daten' / 'kienzlefon'
        toml = tomllib.loads((kzf / 'kienzlefon.toml').read_text())
        self.assertEqual(toml['oeffnungszeiten']['montag'], ['08:00-12:00', '14:00-18:00'])
        self.assertEqual(toml['telefonzeiten']['montag'], ['08:00-11:30'])
        self.assertEqual(toml['oeffnungszeiten']['mittwoch'], [])
        root = kzf / 'ansagen-master' / 'sonderansagen'
        metas = {d.name: json.loads((d / 'metadata.json').read_text()) for d in root.iterdir() if not d.name.startswith('.')}
        self.assertEqual(len(metas), 2, metas)
        urlaub = next(m for m in metas.values() if m['name'].startswith('Praxisherz: Weihnachtsurlaub'))
        self.assertEqual((urlaub['valid_from'], urlaub['expires_at']), (T[20] + 'T00:00:00', T[32] + 'T00:00:00'))
        self.assertTrue(urlaub['active'] and urlaub['block_phone_hours'] and urlaub['announcement'] == ANSAGE and urlaub['quelle'] == 'praxisherz-zeiten')
        feiertag = next(m for m in metas.values() if 'Neujahr' in m['name'])
        self.assertGreater(urlaub['priority'], feiertag['priority'])
        # Kienzlefon lädt die Konfiguration mit den Sonderansagen; die Ansagedatei der Sonderansage ist erzeugt
        sys.path.insert(0, str(MODUL / 'kienzlefon'))
        from kienzlefon.config import load_config
        cfg_k = load_config(kzf / 'kienzlefon.toml')
        self.assertEqual(len(cfg_k.scheduled_overrides), 2)
        self.assertTrue(list((kzf / 'ansagen').glob(f'override_{next(iter(metas))}.*')))
        self.assertEqual(self.lauf(), 'unverändert')
        # Andere (eigene) Sonderansage bleibt, Feiertag fällt weg
        # Kienzlefon verlangt eindeutige Prioritäten: die eigene Sonderansage liegt auf 899, die der Praxisherz-Ansage wechselt auf 898
        for d in root.iterdir():
            if not d.name.startswith('.'):
                m = json.loads((d / 'metadata.json').read_text())
                if m['priority'] == 899:
                    m['active'] = False
                    (d / 'metadata.json').write_text(json.dumps(m))
        fremd = root / ('f' * 32)
        fremd.mkdir()
        (fremd / 'tts.wav').write_bytes((root / next(iter(metas)) / 'tts.wav').read_bytes())
        (fremd / 'metadata.json').write_text(json.dumps({'name': 'Eigene', 'announcement': 'Hallo', 'active': True, 'priority': 899, 'block_phone_hours': False}))
        urlaub_neu = {'id': 'e1', 'art': 'urlaub', 'titel': 'Weihnachtsurlaub', 'von': T[20], 'bis': T[31], 'telefon': True, 'ansage': ANSAGE}
        self.verb.write_text(json.dumps(paket('b' * 32, [urlaub_neu])))
        self.assertIn('1 Ansagen', self.lauf())
        names = sorted(d.name for d in root.iterdir() if not d.name.startswith('.'))
        self.assertEqual(len(names), 2)
        self.assertIn('f' * 32, names)
        neu = json.loads((root / [n for n in names if n != 'f' * 32][0] / 'metadata.json').read_text())
        self.assertEqual(neu['priority'], 898, 'belegte Priorität ausgelassen')
        self.assertTrue(neu['active'], 'wieder eingeschaltet')
        self.assertEqual(len(load_config(kzf / 'kienzlefon.toml').scheduled_overrides), 2)
        # Ohne Paket: eigene Ansagen weg, fremde bleibt
        self.verb.write_text('{}')
        self.lauf()
        self.assertEqual(sorted(d.name for d in root.iterdir() if not d.name.startswith('.')), ['f' * 32])


if __name__ == '__main__':
    unittest.main()
