"""Tests für die lokalen AppArmor-Ergänzungen (core/lib/kienzle/apparmor.py)."""
import importlib
import os
import shutil
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'core' / 'lib'))


class AppArmor(unittest.TestCase):
    def test_abschnitt(self):
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t)
        (t / 'local').mkdir()
        (t / 'gs').write_text('profile gs {}\n')
        (t / 'local' / 'gs').write_text('# eigene Zeile\n/srv/eigen/** r,\n')
        log = t / 'parser.log'
        (t / 'parser').write_text(f'#!/bin/sh\necho "$@" >> {log}\n')
        (t / 'parser').chmod(0o755)
        with mock.patch.dict(os.environ, {'KIENZLE_APPARMOR_DIR': str(t), 'KIENZLE_APPARMOR_PARSER': str(t / 'parser')}):
            import kienzle.apparmor
            a = importlib.reload(kienzle.apparmor)
            reg = {'modules': [{'module': {'id': 'kasse'}, 'hooks': {'apparmor': ['gs', 'qpdf']}}, {'module': {'id': 'fax'}, 'hooks': {'apparmor': ['gs']}}]}
            self.assertEqual(a.apply(reg), ['gs'])                     # qpdf hat hier kein Profil
            text = (t / 'local' / 'gs').read_text()
            self.assertIn('/srv/eigen/** r,', text)
            self.assertIn('/var/lib/kienzle/fax/** rwk,\n/var/lib/kienzle/kasse/** rwk,', text)
            self.assertEqual(a.apply(reg), [], 'zweiter Lauf ändert etwas')
            self.assertEqual(log.read_text().count('-r'), 1)
            # Austauschordner: Die Telefonanlage wandelt Faxaufträge dort mit gs um (sonst „Permission denied“ unter Ubuntu 26.04).
            reg['modules'].append({'module': {'id': 'telefon'}, 'hooks': {'apparmor': ['gs']},
                                   'exchange': [{'module': 'fax', 'purpose': 'x', 'path': '/var/spool/kienzle/austausch/fax_telefon'}]})
            self.assertEqual(a.apply(reg), ['gs'])
            text = (t / 'local' / 'gs').read_text()
            self.assertIn('/var/lib/kienzle/telefon/** rwk,', text)
            self.assertEqual(text.count('/var/spool/kienzle/austausch/fax_telefon/** rwk,'), 1)
            self.assertEqual(a.apply({'modules': []}), ['gs'])
            self.assertEqual((t / 'local' / 'gs').read_text(), '# eigene Zeile\n/srv/eigen/** r,\n')


if __name__ == '__main__':
    unittest.main()
