"""Tests für die lokale Zertifizierungsstelle (core/lib/kienzle/zertifikat.py, core/bin/kienzle-zertifikat).

Nur Platzhalter-Adressen: Für die Tests gilt 192.0.2.0/24 als „privat“, 203.0.113.0/24 als fremd.
"""
import datetime as dt
import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'core' / 'lib'))
from kienzle import zertifikat as z  # noqa: E402

NAMES = ['kienzle.example.org', '192.0.2.5']
TEST_NETS = ('192.0.2.0/24',)


@mock.patch.object(z, 'PRIVATE_V4', TEST_NETS)
class Zertifizierungsstelle(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.ca = z.Zertifikate(Path(self.tmp.name) / 'ca')

    def tearDown(self):
        self.tmp.cleanup()

    def ready(self):
        self.ca.create_ca('Praxis Beispiel', NAMES)
        self.ca.issue(NAMES)

    def openssl(self, *args):
        return subprocess.run(['openssl', *args], capture_output=True, text=True)

    def test_einrichten_und_pruefen(self):
        self.ready()
        self.assertTrue(self.ca.verify())
        self.assertEqual(sorted(self.ca.names()), sorted(NAMES))
        text = self.openssl('x509', '-in', str(self.ca.ca_crt), '-noout', '-text').stdout
        self.assertIn('X509v3 Name Constraints: critical', text)
        self.assertIn('pathlen:0', text)
        self.assertRegex(text, r'CN ?= ?Kienzle-Suite Praxis Beispiel')   # Schreibweise je nach OpenSSL-Version
        self.assertIn('ASN1 OID: prime256v1', text)
        server = self.openssl('x509', '-in', str(self.ca.server_crt), '-noout', '-text').stdout
        self.assertIn('TLS Web Server Authentication', server)
        self.assertIn('CA:FALSE', server)

    def test_laufzeiten(self):
        self.ready()
        now = dt.datetime.now(dt.timezone.utc)
        self.assertLessEqual(self.ca.expires() - now, dt.timedelta(days=825))
        self.assertGreater(self.ca.expires() - now, dt.timedelta(days=824))
        self.assertGreater(self.ca.expires(self.ca.ca_crt) - now, dt.timedelta(days=3649))

    def test_rechte_der_dateien(self):
        self.ready()
        for path, mode in ((self.ca.ca_key, 0o600), (self.ca.server_key, 0o600), (self.ca.ca_crt, 0o644), (self.ca.server_crt, 0o644)):
            self.assertEqual(stat.S_IMODE(os.stat(path).st_mode), mode, path.name)

    def test_fremde_namen_trotz_ca_schluessel_ungueltig(self):
        """Kern der Namensbeschränkung: Wer den CA-Schlüssel hat, kann trotzdem keine fremden Seiten fälschen."""
        self.ready()
        key = z._openssl('genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:P-256')
        for dns, ips in ((['bank.example.net'], []), (['kienzle.example.org.example.net'], []), ([], ['203.0.113.5'])):
            with self.subTest(dns + ips):
                out = Path(self.tmp.name) / 'falsch.crt'
                self.ca._sign(key, dns, ips, 30, (dns or ips)[0], out)
                self.assertFalse(self.ca.verify(out))
                self.assertIn('permitted subtree violation', self.openssl('verify', '-CAfile', str(self.ca.ca_crt), str(out)).stdout
                              + self.openssl('verify', '-CAfile', str(self.ca.ca_crt), str(out)).stderr)
        # Unterhalb des eigenen Namens ist erlaubt (z. B. für spätere Dienste).
        out = Path(self.tmp.name) / 'unter.crt'
        self.ca._sign(key, ['dienst.kienzle.example.org'], [], 30, 'dienst', out)
        self.assertTrue(self.ca.verify(out))

    def test_keine_zweite_ca(self):
        self.ready()
        fingerprint = self.ca.fingerprint()
        with self.assertRaises(z.Fehler):
            self.ca.create_ca('Praxis Beispiel', NAMES)
        self.assertEqual(self.ca.fingerprint(), fingerprint)
        self.assertRegex(fingerprint, r'^([0-9A-F]{2}:){31}[0-9A-F]{2}$')

    def test_ungueltige_namen(self):
        for names in (['203.0.113.5'], ['bad host'], ['*.example.org'], ['-x.example.org'], [], ['kienzle.example.org', '8.8.8.8']):
            with self.subTest(names), self.assertRaises(z.Fehler):
                z.split_names(names)
        with self.assertRaises(z.Fehler):
            self.ca.create_ca('Praxis; rm -rf /', NAMES)
        with self.assertRaises(z.Fehler):
            self.ca.create_ca('Praxis\nCN=x', NAMES)

    def test_erneuern(self):
        self.ready()
        self.assertFalse(self.ca.needs_renewal(NAMES))
        self.assertTrue(self.ca.needs_renewal(NAMES + ['kienzle2.example.org']))
        self.assertTrue(self.ca.needs_renewal(NAMES, dt.datetime.now(dt.timezone.utc) + dt.timedelta(days=800)))
        old = self.ca.server_key.read_bytes()
        self.ca.issue(NAMES)
        self.assertNotEqual(self.ca.server_key.read_bytes(), old)


class Befehl(unittest.TestCase):
    def run_cli(self, folder, *args, names=None):
        cfg = Path(folder) / 'core.json'
        cfg.write_text(json.dumps({'hostnames': names or ['kienzle.example.org'], 'practice': 'Praxis Beispiel'}))
        env = dict(os.environ, KIENZLE_CORE_CONFIG=str(cfg), KIENZLE_CA_DIR=str(Path(folder) / 'ca'), KIENZLE_ZERTIFIKAT_TEST='1')
        cli = str(ROOT / 'core' / 'bin' / 'kienzle-zertifikat')
        return subprocess.run([sys.executable, '-IB', cli, *args], capture_output=True, text=True, env=env, timeout=60)

    def test_ablauf(self):
        with tempfile.TemporaryDirectory() as tmp:
            r = self.run_cli(tmp, 'einrichten')
            self.assertEqual(r.returncode, 0, r.stderr)
            self.assertIn('Fingerabdruck der CA', r.stdout)
            self.assertEqual(self.run_cli(tmp, 'erneuern').returncode, 0)
            self.assertEqual(self.run_cli(tmp, 'erneuern', names=['kienzle.example.org', 'suite.example.org']).returncode, 3)
            self.assertIn('suite.example.org', self.run_cli(tmp, 'status', names=['kienzle.example.org', 'suite.example.org']).stdout)
            self.assertEqual(self.run_cli(tmp).returncode, 2)
            self.assertEqual(self.run_cli(tmp, 'einrichten', names=['203.0.113.5']).returncode, 1)


if __name__ == '__main__':
    unittest.main()
