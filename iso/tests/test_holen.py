"""holen.py gegen einen lokalen Schein-GitHub-Server: signiert, unsigniert, manipuliert, Zweig-Rückfall."""
import hashlib
import http.server
import importlib.util
import io
import json
import os
import subprocess
import tarfile
import tempfile
import threading
import unittest
from pathlib import Path

os.environ['KIENZLE_TEST_HTTP'] = '1'
spec = importlib.util.spec_from_file_location('holen', Path(__file__).resolve().parents[1] / 'holen.py')
holen = importlib.util.module_from_spec(spec)
spec.loader.exec_module(holen)


def archiv(dateien: dict, prefix: str = '') -> bytes:
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode='w:gz') as t:
        for name, inhalt in dateien.items():
            i = tarfile.TarInfo(prefix + name)
            i.size = len(inhalt)
            i.mode = 0o755 if name.endswith('.sh') else 0o644
            t.addfile(i, io.BytesIO(inhalt))
    return buf.getvalue()


class Schein(http.server.BaseHTTPRequestHandler):
    routen: dict = {}

    def do_GET(self):
        d = self.routen.get(self.path.split('?')[0])
        self.send_response(200 if d is not None else 404)
        self.end_headers()
        self.wfile.write(d or b'')

    def log_message(self, *a):
        pass


class Holen(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        t = Path(cls.tmp.name)
        cls.key = t / 'priv.pem'
        subprocess.run(['openssl', 'genpkey', '-algorithm', 'ed25519', '-out', str(cls.key)], check=True, capture_output=True)
        cls.keys = t / 'keys'
        cls.keys.mkdir()
        subprocess.run(['openssl', 'pkey', '-in', str(cls.key), '-pubout', '-out', str(cls.keys / 'a.pem')], check=True, capture_output=True)
        cls.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Schein)
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()
        cls.base = f'http://127.0.0.1:{cls.server.server_port}'
        holen.API = cls.base

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.tmp.cleanup()

    def release(self, version, signiert, vorab=False, kaputt=False):
        tar = archiv({'installer/install.sh': b'#!/bin/bash\n', 'core/VERSION': version.encode()})
        manifest = json.dumps({'archive': {'name': f'core-{version}.tar.gz', 'sha256': hashlib.sha256(tar).hexdigest(), 'size': len(tar)}}).encode()
        if kaputt:
            tar = tar + b'x'
        R = Schein.routen
        R[f'/dl/core-{version}.tar.gz'], R[f'/dl/core-{version}.manifest.json'] = tar, manifest
        assets = [{'name': f'core-{version}.tar.gz', 'browser_download_url': f'{self.base}/dl/core-{version}.tar.gz'},
                  {'name': f'core-{version}.manifest.json', 'browser_download_url': f'{self.base}/dl/core-{version}.manifest.json'}]
        if signiert:
            m = Path(self.tmp.name) / 'm'
            m.write_bytes(manifest)
            sig = subprocess.run(['openssl', 'pkeyutl', '-sign', '-inkey', str(self.key), '-rawin', '-in', str(m)], capture_output=True, check=True).stdout
            R[f'/dl/core-{version}.manifest.json.sig'] = sig
            assets.append({'name': f'core-{version}.manifest.json.sig', 'browser_download_url': f'{self.base}/dl/core-{version}.manifest.json.sig'})
        return {'tag_name': f'core-v{version}', 'prerelease': vorab, 'draft': False, 'assets': assets}

    def setze(self, releases):
        Schein.routen['/repos/hau-si/kienzlesuite-2.0/releases'] = json.dumps(releases).encode()

    def lauf(self, *args):
        ziel = Path(self.tmp.name) / ('z' + str(len(os.listdir(self.tmp.name))))
        rc = holen.main(['--ziel', str(ziel), '--schluessel', str(self.keys), '--ohne-installer', *args])
        return rc, ziel

    def test_signiertes_stabiles_release(self):
        self.setze([self.release('0.21.4', True), self.release('0.21.5-beta.1', False, vorab=True)])
        rc, ziel = self.lauf('--kanal', 'stabil')
        self.assertEqual(rc, 0)
        self.assertEqual((ziel / 'core' / 'VERSION').read_text(), '0.21.4')
        self.assertTrue(os.access(ziel / 'installer' / 'install.sh', os.X_OK))

    def test_beta_ohne_signatur_nur_mit_erlaubnis(self):
        self.setze([self.release('0.21.4', True), self.release('0.21.5-beta.1', False, vorab=True)])
        self.assertEqual(self.lauf('--kanal', 'beta')[0], 1)
        rc, ziel = self.lauf('--kanal', 'beta', '--unsigniert-erlauben')
        self.assertEqual(rc, 0)
        self.assertEqual((ziel / 'core' / 'VERSION').read_text(), '0.21.5-beta.1')

    def test_falscher_schluessel_wird_abgelehnt(self):
        self.setze([self.release('0.21.4', True)])
        fremd = Path(self.tmp.name) / 'fremd'
        fremd.mkdir(exist_ok=True)
        k = fremd / 'k.pem'
        subprocess.run(['openssl', 'genpkey', '-algorithm', 'ed25519', '-out', str(k)], check=True, capture_output=True)
        subprocess.run(['openssl', 'pkey', '-in', str(k), '-pubout', '-out', str(fremd / 'p.pem')], check=True, capture_output=True)
        (fremd / 'k.pem').unlink()
        ziel = Path(self.tmp.name) / 'zf'
        self.assertEqual(holen.main(['--ziel', str(ziel), '--schluessel', str(fremd), '--ohne-installer']), 1)
        self.assertFalse(ziel.exists())

    def test_manipuliertes_archiv(self):
        self.setze([self.release('0.21.6', True, kaputt=True)])
        self.assertEqual(self.lauf('--kanal', 'stabil')[0], 1)

    def test_auspacken_lehnt_ausbrecher_ab(self):
        schlecht = archiv({'../evil': b'x'})
        with self.assertRaises(holen.Fehler):
            holen.auspacken(schlecht, Path(self.tmp.name) / 'a')
        buf = io.BytesIO()
        with tarfile.open(fileobj=buf, mode='w:gz') as t:
            i = tarfile.TarInfo('link')
            i.type = tarfile.SYMTYPE
            i.linkname = '/etc/passwd'
            t.addfile(i)
        with self.assertRaises(holen.Fehler):
            holen.auspacken(buf.getvalue(), Path(self.tmp.name) / 'b')

    def test_kein_release_dann_zweig(self):
        self.setze([])
        inhalt = {'installer/install.sh': b'#!/bin/bash\n', 'core/VERSION': b'x'}
        Schein.routen['/hau-si/kienzlesuite-2.0/archive/refs/heads/beta.tar.gz'] = archiv(inhalt, 'kienzlesuite-2.0-beta/')
        orig = holen.holen
        holen.holen = lambda url, g=holen.MAX_ARCHIV: orig(url.replace('https://github.com', self.base), g)
        try:
            self.assertEqual(self.lauf('--kanal', 'stabil')[0], 1)
            rc, ziel = self.lauf('--kanal', 'stabil', '--unsigniert-erlauben', '--zweig', 'beta')
        finally:
            holen.holen = orig
        self.assertEqual(rc, 0)
        self.assertTrue((ziel / 'installer' / 'install.sh').is_file())

    def test_nur_https(self):
        os.environ.pop('KIENZLE_TEST_HTTP')
        try:
            with self.assertRaises(holen.Fehler):
                holen.holen('http://example.org/x')
        finally:
            os.environ['KIENZLE_TEST_HTTP'] = '1'


if __name__ == '__main__':
    unittest.main()
