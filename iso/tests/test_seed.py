"""Autoinstall-Seed und Startmenü der Installations-ISO: Aufbau und die Shell-Schnipsel."""
import subprocess
import unittest
from pathlib import Path

import yaml

ISO = Path(__file__).resolve().parents[1]


class Seed(unittest.TestCase):
    def test_autoinstall(self):
        a = yaml.safe_load((ISO / 'seed' / 'autoinstall.yaml').read_text())['autoinstall']
        self.assertEqual(a['version'], 1)
        self.assertEqual(sorted(a['interactive-sections']), ['identity', 'network', 'storage'])
        self.assertEqual(a['keyboard']['layout'], 'de')
        self.assertEqual(a['identity']['hostname'], 'suite')
        self.assertTrue(a['ssh']['install-server'])
        for c in a['late-commands']:
            if isinstance(c, str) and '\n' in c:
                self.assertEqual(subprocess.run(['bash', '-n'], input=c, text=True, capture_output=True).returncode, 0)

    def test_startzeile_wird_in_optionen_uebersetzt(self):
        a = yaml.safe_load((ISO / 'seed' / 'autoinstall.yaml').read_text())['autoinstall']
        skript = next(c for c in a['late-commands'] if isinstance(c, str) and c.startswith('set -e'))
        for zeile, erwartet in [('quiet autoinstall kienzle.kanal=stabil ---', '--kanal stabil'),
                                ('x kienzle.kanal=beta kienzle.unsigniert=1 kienzle.zweig=beta', '--kanal beta --unsigniert-erlauben --zweig beta')]:
            probe = skript.replace('$(cat /proc/cmdline)', repr(zeile)).replace('/target/etc/kienzle-erstinstallation.env', '/dev/stdout')
            out = subprocess.run(['bash', '-c', probe], capture_output=True, text=True).stdout
            self.assertIn(erwartet, ' '.join(out.split()))

    def test_menue_hat_alle_eintraege(self):
        t = (ISO / 'seed' / 'grub.cfg').read_text()
        self.assertEqual(t.count('menuentry'), 4)
        self.assertIn('kienzle.kanal=beta', t)
        self.assertEqual(t.count('autoinstall'), 4)


if __name__ == '__main__':
    unittest.main()
