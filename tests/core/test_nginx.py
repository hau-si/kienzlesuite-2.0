"""Tests für die erzeugte nginx-Konfiguration (core/lib/kienzle/nginx.py) und den Befehl kienzle-web.

Die Konfiguration mit echtem nginx prüft tests/integration/nginx.sh (GitHub Actions, Ubuntu 26.04).
"""
import copy
import json
import os
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'core' / 'lib'))
from kienzle import manifest, nginx  # noqa: E402

HOSTS = ['kienzle.example.org', '192.0.2.5']


def registry():
    reg = manifest.check_list([ROOT / 'modules' / 'beispiel'])
    php = copy.deepcopy(reg['modules'][0])
    php['module']['id'] = 'kasse'
    php['web'] = {'path': '/kasse', 'upstream': 'unix:/run/kienzle/kasse/web.sock', 'protocol': 'fastcgi', 'entry': 'public/index.php', 'starter': True}
    reg['modules'].append(php)
    return reg


def block(conf: str, start: str) -> str:
    i = conf.index(start)
    return conf[i:conf.index('\n    }', i)]


class Vorlage(unittest.TestCase):
    def setUp(self):
        self.conf = nginx.render(HOSTS, registry())

    def test_wartungsseite_statt_502(self):
        self.assertIn('error_page 502 503 504 @wartung;', self.conf)
        self.assertIn('location @wartung', self.conf)
        self.assertIn('http-equiv="refresh" content="3"', self.conf)
        self.assertIn('error_page 500 502 503 504 @wartung;', self.conf)   # auch in den Modulpfaden

    def test_nur_443_und_eigene_namen(self):
        self.assertNotIn('listen 80', self.conf)
        self.assertIn('server_name kienzle.example.org 192.0.2.5;', self.conf)
        self.assertNotIn('ssl_reject_handshake', self.conf)       # sonst ginge der Aufruf über die IP (ohne SNI) nicht
        self.assertIn('    return 421;\n}', self.conf)              # fremde Namen: keine Seite
        self.assertIn('ssl_protocols TLSv1.2 TLSv1.3;', self.conf)
        self.assertIn('access_log off;', self.conf)
        # Ubuntu 26.04 setzt server_tokens schon in nginx.conf (http-Ebene); hier nur in den server-Blöcken.
        self.assertFalse(re.search(r'^server_tokens', self.conf, re.M))
        self.assertEqual(self.conf.count('    server_tokens off;'), 2)

    def test_jedes_modul_wird_geprueft(self):
        for mid, path in (('beispiel', '/beispiel'), ('kasse', '/kasse')):
            b = block(self.conf, f'location ^~ {path}/ {{')
            self.assertIn(f'auth_request /_auth/{mid};', b)
            self.assertIn('error_page 401 = @anmelden;', b)

    def test_http_modul_kopfzeilen_ersetzt(self):
        b = block(self.conf, 'location ^~ /beispiel/ {')
        for header in ('Benutzer', 'Name', 'Rolle', 'Rechte', 'Csrf', 'Bestaetigt'):
            self.assertIn(f'proxy_set_header X-Kienzle-{header} $kienzle_', b)
        self.assertIn('proxy_pass http://unix:/run/kienzle/beispiel/web.sock:;', b)

    def test_fastcgi_modul_ohne_http_vorsilbe(self):
        b = block(self.conf, 'location ^~ /kasse/ {')
        self.assertIn('fastcgi_param KIENZLE_BENUTZER $kienzle_benutzer;', b)
        self.assertIn('fastcgi_param SCRIPT_FILENAME /opt/kienzle/kasse/current/public/index.php;', b)
        self.assertNotIn('HTTP_X_KIENZLE', self.conf)

    def test_starter_ohne_anmeldung_nur_api_php(self):
        b = block(self.conf, 'location = /api.php {')
        self.assertNotIn('auth_request', b)
        self.assertIn('limit_except POST { deny all; }', b)
        self.assertIn('fastcgi_param KIENZLE_OEFFENTLICH starter;', b)
        self.assertIn('fastcgi_param SCRIPT_FILENAME /opt/kienzle/kasse/current/public/index.php;', b)
        self.assertNotIn('KIENZLE_BENUTZER', b)
        self.assertEqual(self.conf.count('location = /api.php'), 1)
        reg = registry()
        reg['modules'][0]['web']['starter'] = True                   # HTTP-Modul: kein Starter
        with self.assertRaises(nginx.Fehler):
            nginx.render(HOSTS, reg)

    def test_startkontexte_pruefung_durch_die_basis(self):
        reg = registry()
        reg['modules'][1]['starters'] = [{'schema': 'kienzle-sumup', 'label': 'Kasse'}]
        conf = nginx.render(HOSTS, reg)
        b = block(conf, 'location = /starter/kasse {')
        self.assertIn('auth_request /_starter-pruefen;', b)
        self.assertIn('limit_except POST { deny all; }', b)
        self.assertIn('fastcgi_param KIENZLE_STARTER_GEPRUEFT 1;', b)
        self.assertIn('fastcgi_param KIENZLE_OEFFENTLICH starter;', b)
        self.assertNotIn('KIENZLE_BENUTZER', b)
        p = block(conf, 'location = /_starter-pruefen {')
        self.assertIn('internal;', p)
        self.assertIn('fastcgi_param REQUEST_URI /_starter-pruefen;', p)
        self.assertNotIn('location = /starter/', nginx.render(HOSTS, registry()))      # ohne Startkontexte kein Ort
        reg['modules'][0]['starters'] = [{'schema': 'kienzle-b', 'label': 'B'}]   # HTTP-Modul
        with self.assertRaises(nginx.Fehler):
            nginx.render(HOSTS, reg)

    def test_interne_pfade(self):
        auth = block(self.conf, 'location ^~ /_auth/ {')
        self.assertIn('client_max_body_size 25m;', auth)  # sonst 413 schon bei der Anmeldeprüfung großer Uploads
        self.assertIn('internal;', auth)
        self.assertGreater(auth.index('fastcgi_param REQUEST_URI $uri;'), auth.index('include fastcgi_params;'))   # überschreibt den Vorgabewert
        h = block(self.conf, 'location = /_health {')
        self.assertIn('allow 127.0.0.1;', h)
        self.assertIn('deny all;', h)

    def test_module_ohne_web_fehlen(self):
        reg = registry()
        reg['modules'][0]['web'] = None
        self.assertNotIn('/_auth/beispiel', nginx.render(HOSTS, reg))

    def test_unzulaessige_werte(self):
        for change in ({'path': '/x;deny'}, {'upstream': 'unix:/run/kienzle/x/web.sock'}, {'upstream': 'unix:/tmp/a b'},
                       {'protocol': 'fastcgi', 'entry': '../../etc/passwd'}):
            reg = registry()
            reg['modules'][0]['web'].update(change)
            with self.subTest(change), self.assertRaises(nginx.Fehler):
                nginx.render(HOSTS, reg)
        for hosts in ([], ['bad host'], ['x;y']):
            with self.subTest(hosts), self.assertRaises(nginx.Fehler):
                nginx.render(hosts, registry())

    def test_klammern_und_variablen(self):
        self.assertEqual(self.conf.count('{'), self.conf.count('}'))
        for path in ('/beispiel', '/kasse'):
            b = block(self.conf, f'location ^~ {path}/ {{')
            defined = set(re.findall(r'auth_request_set (\$kienzle_[a-z]+) ', b))
            used = set(re.findall(r'(\$kienzle_[a-z]+);', b))
            self.assertEqual(used, defined, path)


class Befehl(unittest.TestCase):
    def run_cli(self, tmp, *args, nginx_ok=True):
        cfg = Path(tmp) / 'core.json'
        cfg.write_text(json.dumps({'hostnames': HOSTS}))
        fake = Path(tmp) / 'nginx'
        fake.write_text('#!/bin/sh\nexit %d\n' % (0 if nginx_ok else 1))
        fake.chmod(0o755)
        env = dict(os.environ, KIENZLE_CORE_CONFIG=str(cfg), KIENZLE_MODULES_JSON=str(Path(tmp) / 'modules.json'),
                   KIENZLE_NGINX_SITE=str(Path(tmp) / 'kienzle.conf'), KIENZLE_NGINX=str(fake), KIENZLE_NGINX_RELOAD='', KIENZLE_CORE_USER='', KIENZLE_VERTEILEN='',
                   KIENZLE_DRUCKER_ABGLEICH='', KIENZLE_APPARMOR_DIR=str(Path(tmp) / 'apparmor'))
        return subprocess.run([sys.executable, '-IB', str(ROOT / 'core' / 'bin' / 'kienzle-web'), *args], capture_output=True, text=True, env=env, timeout=60)

    def test_schreiben(self):
        with tempfile.TemporaryDirectory() as tmp:
            r = self.run_cli(tmp, 'schreiben', str(ROOT / 'modules'))
            self.assertEqual(r.returncode, 0, r.stderr)
            reg = json.loads((Path(tmp) / 'modules.json').read_text())
            self.assertEqual([m['module']['id'] for m in reg['modules']], ['beispiel', 'signatur', 'sumup', 'terminzettel'])
            self.assertIn('/_auth/beispiel', (Path(tmp) / 'kienzle.conf').read_text())
            self.assertEqual(oct((Path(tmp) / 'modules.json').stat().st_mode & 0o777), '0o640')

    def test_installierte_module(self):
        """kienzle-web module: Modulverzeichnis aus /opt/kienzle/<id>/current (ohne Basis, ohne lose Ordner)."""
        import shutil
        with tempfile.TemporaryDirectory() as tmp:
            opt = Path(tmp) / 'opt'
            shutil.copytree(ROOT / 'modules' / 'sumup', opt / 'sumup' / 'releases' / '2.0.0' / 'sumup')
            os.symlink('releases/2.0.0/sumup', opt / 'sumup' / 'current')
            (opt / 'core' / 'current').mkdir(parents=True)
            (opt / 'leer').mkdir()
            os.environ['KIENZLE_MODULE_PROGRAMME'] = str(opt)
            try:
                r = self.run_cli(tmp, 'module')
            finally:
                del os.environ['KIENZLE_MODULE_PROGRAMME']
            self.assertEqual(r.returncode, 0, r.stderr)
            reg = json.loads((Path(tmp) / 'modules.json').read_text())
            self.assertEqual([m['module']['id'] for m in reg['modules']], ['sumup'])
            conf = (Path(tmp) / 'kienzle.conf').read_text()
            self.assertIn('location = /api.php {', conf)
            self.assertIn('location ^~ /kasse/ {', conf)

    def test_abgelehnte_konfiguration_bleibt_alt(self):
        with tempfile.TemporaryDirectory() as tmp:
            self.assertEqual(self.run_cli(tmp, 'schreiben').returncode, 0)
            before = (Path(tmp) / 'kienzle.conf').read_text()
            r = self.run_cli(tmp, 'schreiben', str(ROOT / 'modules'), nginx_ok=False)
            self.assertEqual(r.returncode, 1)
            self.assertEqual((Path(tmp) / 'kienzle.conf').read_text(), before)
            self.assertEqual(json.loads((Path(tmp) / 'modules.json').read_text())['modules'], [])

    def test_ungueltige_module_aendern_nichts(self):
        with tempfile.TemporaryDirectory() as tmp:
            (Path(tmp) / 'mods' / 'kaputt').mkdir(parents=True)
            self.assertEqual(self.run_cli(tmp, 'schreiben', str(Path(tmp) / 'mods')).returncode, 1)
            self.assertFalse((Path(tmp) / 'kienzle.conf').exists())

    def test_falscher_aufruf(self):
        with tempfile.TemporaryDirectory() as tmp:
            self.assertEqual(self.run_cli(tmp).returncode, 2)
            self.assertEqual(self.run_cli(tmp, 'loeschen').returncode, 2)


if __name__ == '__main__':
    unittest.main()
