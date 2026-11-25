"""Prüfungen am Installer und seinen Vorlagen (ohne root; der Durchlauf selbst ist tests/integration/nginx.sh)."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
INSTALL = (ROOT / 'installer' / 'install.sh').read_text(encoding='utf-8')
VORLAGEN = ROOT / 'installer' / 'vorlagen'


class Vorlagen(unittest.TestCase):
    def test_jede_vorlage_wird_verwendet(self):
        for f in VORLAGEN.iterdir():
            with self.subTest(f.name):
                self.assertIn(f'$vorlagen/{f.name}', INSTALL)

    def test_einheiten_werden_eingeschaltet(self):
        for f in [*VORLAGEN.glob('*.path'), *VORLAGEN.glob('*.timer')]:
            self.assertRegex(INSTALL, rf'systemctl enable --now [^\n]*{re.escape(f.name)}')

    def test_samba_nur_drucker(self):
        text = (VORLAGEN / 'smb.conf').read_text()
        self.assertEqual(re.findall(r'^\[(\w+)\]', text, re.M), ['global'])
        self.assertIn('load printers = no', text)
        self.assertTrue(text.rstrip().endswith('include = /etc/samba/kienzle-drucker.conf'))
        self.assertIn('server min protocol = SMB3', text)
        self.assertIn('disable netbios = yes', text)

    def test_cups(self):
        text = (VORLAGEN / 'cupsd.conf').read_text()
        self.assertIn('WebInterface No', text)
        self.assertIn('Browsing No', text)
        admin = text.split('<Location /admin>', 1)[1].split('</Location>', 1)[0]
        self.assertIn('Allow localhost', admin)
        self.assertNotIn('Allow all', admin)
        # Verwaltung der Drucker nur für @SYSTEM; Abfragen und Drucken für alle (sonst sehen Module ihre Drucker nicht).
        policy = text.split('<Policy default>', 1)[1]
        self.assertRegex(policy, r'<Limit CUPS-Add-Modify-Printer[^>]*>\s*AuthType Default\s*Require user @SYSTEM')
        self.assertRegex(policy, r'<Limit All>\s*Order deny,allow\s*</Limit>')

    def test_php_darf_status_lesen(self):
        text = (VORLAGEN / 'php-fpm-core.conf').read_text()
        basedir = re.search(r'^php_admin_value\[open_basedir\] = (.*)$', text, re.M).group(1).split(':')
        for d in ('/var/lib/kienzle/firewall/', '/var/lib/kienzle/update/', '/var/lib/kienzle/verbindungen/', '/var/lib/kienzle/sicherung/',
                  '/var/lib/kienzle/ueberblick/', '/usr/bin/qrencode', '/etc/kienzle/ca/ca.crt', '/etc/kienzle/ca/server.crt'):
            self.assertIn(d, basedir)
        for unit in ('firewall', 'update', 'verbindungen', 'sicherung'):
            self.assertIn(f'/etc/systemd/system/kienzle-{unit}.path', basedir)
        self.assertFalse([e for e in basedir if not e.endswith('/') and not Path(e).name], 'Vorsilben gelten in PHP nicht')

    def test_php_darf_serverwerte_lesen(self):
        # Ohne diese Einträge sieht die Startseite keine Werte für Prozessor, Arbeitsspeicher, Festplatte (open_basedir).
        text = (VORLAGEN / 'php-fpm-core.conf').read_text()
        basedir = re.search(r'^php_admin_value\[open_basedir\] = (.*)$', text, re.M).group(1).split(':')
        for e in ('/proc/meminfo', '/proc/loadavg', '/proc/cpuinfo', '/proc/uptime', '/sys/class/thermal/', '/sys/class/hwmon/', '/sys/devices/'):
            self.assertIn(e, basedir)
        # Der Standardordner für die Plattenmessung liegt innerhalb einer erlaubten Angabe.
        php = (ROOT / 'core' / 'php' / 'SystemStatus.php').read_text()
        ordner = re.search(r"private string \$dir = '([^']+)'", php).group(1).rstrip('/') + '/'
        self.assertTrue(any(ordner.startswith(e) for e in basedir if e.endswith('/')), ordner)

    def test_autoinstall(self):
        text = (ROOT / 'installer' / 'autoinstall.yaml').read_text()
        self.assertTrue(text.startswith('#cloud-config\n'))
        self.assertIn('installer/install.sh --name <SERVERNAME>', text)
        self.assertIn('id: ubuntu-server\n', text)
        try:
            import yaml
        except ImportError:
            self.skipTest('PyYAML fehlt')
        data = yaml.safe_load(text)
        self.assertEqual(data['autoinstall']['version'], 1)
        self.assertEqual(data['autoinstall']['timezone'], 'Europe/Berlin')

    def test_release_schluessel(self):
        import subprocess
        readme = (ROOT / 'README.md').read_text()
        keys = sorted((ROOT / 'installer' / 'release-keys').glob('*.pem'))
        self.assertEqual([k.name for k in keys], ['kienzle-release-2026.pub.pem', 'kienzle-reserve-2026.pub.pem'])
        for k in keys:
            text = k.read_text()
            self.assertNotIn('PRIVATE', text, f'{k.name}: privater Schlüssel im Repository')
            der = subprocess.run(['openssl', 'pkey', '-pubin', '-in', str(k), '-outform', 'DER'], capture_output=True, check=True).stdout
            import hashlib
            self.assertIn(hashlib.sha256(der).hexdigest(), readme, f'{k.name}: Fingerabdruck fehlt in README.md')

    def test_keine_echten_adressen(self):
        for f in [ROOT / 'installer' / 'autoinstall.yaml', *VORLAGEN.iterdir()]:
            for ip in re.findall(r'\b(\d{1,3}(?:\.\d{1,3}){3})\b', f.read_text()):
                self.assertTrue(ip.startswith(('192.0.2.', '127.', '0.0.0.0')), f'{f.name}: {ip}')


if __name__ == '__main__':
    unittest.main()
