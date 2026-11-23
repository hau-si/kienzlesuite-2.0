"""Test: gestoppte Modul-Dienste werden wieder gestartet (core/lib/kienzle/module.py, ensure_running)."""
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'core' / 'lib'))
from kienzle import module as m  # noqa: E402


class Wiederstart(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        (self.t / 'kienzle-demo-php.service').write_text('[Service]\nType=notify\n')
        (self.t / 'kienzle-demo-einmal.service').write_text('[Service]\nType=oneshot\n')
        self.calls = []

    def systemctl(self, *args):
        self.calls.append(args)
        name = args[1] if len(args) > 1 else ''
        code = 3 if args[0] == 'is-active' else 0   # eingeschaltet, aber nicht aktiv
        return subprocess.CompletedProcess(args, code)

    def lauf(self, running=None):
        with mock.patch.object(m, 'UNITS', self.t), mock.patch.object(m, 'installed', return_value={'demo': {}}), \
                mock.patch.object(m, 'read_status', return_value={'running': running}), mock.patch.object(m, '_systemctl', side_effect=self.systemctl), \
                mock.patch.object(m, 'log'):
            return m.ensure_running()

    def test_gestoppten_dienst_starten_einmaldienst_nicht(self):
        self.assertEqual(self.lauf(), ['kienzle-demo-php.service'])
        self.assertIn(('start', 'kienzle-demo-php.service'), self.calls)
        self.assertNotIn(('start', 'kienzle-demo-einmal.service'), self.calls)

    def test_dienstzustaende_und_start(self):
        def antwort(*args):
            self.calls.append(args)
            return subprocess.CompletedProcess(args, 3 if args[0] == 'is-active' else 0)
        with mock.patch.object(m, 'UNITS', self.t), mock.patch.object(m, 'installed', return_value={'demo': {}}), \
                mock.patch.object(m, '_systemctl', side_effect=antwort), mock.patch.object(m, 'log'), \
                mock.patch.object(m, 'read_status', return_value={'installed': [{'id': 'demo'}]}), mock.patch.object(m, 'set_status') as st:
            self.assertEqual(m.service_states(), {'demo': [{'name': 'kienzle-demo-php.service', 'aktiv': False}]})
            msg = m.service_action('demo', restart=True)
            self.assertIn(('restart', 'kienzle-demo-php.service'), self.calls)
            self.assertNotIn(('restart', 'kienzle-demo-einmal.service'), self.calls)
            self.assertIn('neu gestartet', msg)
            self.assertEqual(st.call_args.kwargs['installed'][0]['dienste'][0]['aktiv'], False)
            with self.assertRaises(m.Fehler):
                m.service_action('unbekannt', restart=False)

    def test_nichts_waehrend_update(self):
        self.assertEqual(self.lauf({'action': 'aktualisieren', 'id': 'demo'}), [])
        self.assertEqual(self.calls, [])


if __name__ == '__main__':
    unittest.main()
