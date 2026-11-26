"""Tests für die Modulverwaltung (core/lib/kienzle/module.py): Angebot, Installieren, Aktualisieren mit Rücksprung,
Entfernen, Haken, Anfragedatei. Alles in einem Testordner, ohne root, ohne systemd und ohne apt.
Releases von GitHub werden über die Ordner-Quelle des Updaters nachgestellt.
"""
import importlib
import json
import os
import shutil
import stat
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'core' / 'lib'))
from kienzle import manifest, release  # noqa: E402

HOOK = '#!/bin/sh\n# Testhaken: Name und Version in den Datenordner schreiben.\necho "$1 $KIENZLE_MODUL_VERSION" >> "$KIENZLE_DATEN/haken.log"\n' \
       '[ -f "$KIENZLE_DATEN/FEHLER-$1" ] && exit 1\n[ "$1" = busy ] && [ -f "$KIENZLE_DATEN/BESCHAEFTIGT" ] && exit 75\nexit 0\n'


def module_toml(version: str, web: bool = True, services: str = '') -> str:
    text = (f'[module]\nid = "test-kasse"\nname = "Testkasse"\nversion = "{version}"\ncore_api = "1"\ndescription = "Nur für Tests."\n\n'
            f'[system]\nuser = "ks-test-kasse"\ndata = "/var/lib/kienzle/test-kasse"\n{services}\n')
    if web:
        text += '[web]\npath = "/testkasse"\nupstream = "unix:/run/kienzle/test-kasse/web.sock"\nprotocol = "fastcgi"\nentry = "public/index.php"\n\n'
    text += '[hooks]\n' + ''.join(f'{h} = "bin/{h}"\n' for h in ('install', 'update', 'remove', 'busy', 'health'))
    return text


class Modulverwaltung(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.t)
        for d in ('opt', 'mitgeliefert', 'daten', 'etc', 'units', 'tmpfiles', 'run', 'state', 'anfragen', 'quelle', 'spool'):
            (self.t / d).mkdir()
        (self.t / 'quelle' / 'releases.json').write_text('[]')
        # Statt kienzle-web: Modulverzeichnis mit dem echten Prüfprogramm schreiben (wie „kienzle-web module“).
        web = self.t / 'kienzle-web'
        web.write_text('#!/usr/bin/python3 -IB\nimport json, sys\nsys.path.insert(0, %r)\nfrom kienzle import manifest\n'
                       'r = manifest.check_list(manifest.installed_folders(%r))\nopen(%r, "w").write(json.dumps(r))\n'
                       % (str(ROOT / 'core' / 'lib'), str(self.t / 'opt'), str(self.t / 'modules.json')))
        web.chmod(0o755)
        env = {'KIENZLE_MODULE_STATE': self.t / 'state', 'KIENZLE_MODULE_REQUEST': self.t / 'anfragen' / 'module.json',
               'KIENZLE_MODULE_PROGRAMME': self.t / 'opt', 'KIENZLE_MODULE_MITGELIEFERT': self.t / 'mitgeliefert', 'KIENZLE_MODULE_DATEN': self.t / 'daten',
               'KIENZLE_MODULE_ETC': self.t / 'etc', 'KIENZLE_MODULE_UNITS': self.t / 'units', 'KIENZLE_MODULE_TMPFILES': self.t / 'tmpfiles',
               'KIENZLE_MODULE_RUN': self.t / 'run', 'KIENZLE_MODULE_SPOOL': self.t / 'spool', 'KIENZLE_MODULE_AUSTAUSCH': self.t / 'austausch', 'KIENZLE_MODULE_WEB': web,
               'KIENZLE_MODULE_APT': '', 'KIENZLE_MODULE_OWNER': os.environ.get('USER') or 'root', 'KIENZLE_MODULE_HEALTH_SECONDS': '0',
               'KIENZLE_MODULE_UID': os.getuid(), 'KIENZLE_OHNE_SYSTEMD': '1', 'KIENZLE_MODULE_PHPFPM': '/usr/sbin/php-fpm8.5',
               'KIENZLE_UPDATE_QUELLE': f'ordner:{self.t / "quelle"}', 'KIENZLE_UPDATE_STATE': self.t / 'update', 'KIENZLE_UPDATE_KEYS': self.t / 'keys'}
        patcher = mock.patch.dict(os.environ, {k: str(v) for k, v in env.items()})
        patcher.start()
        self.addCleanup(patcher.stop)
        import kienzle.update
        import kienzle.module
        importlib.reload(kienzle.update)
        self.mo = importlib.reload(kienzle.module)
        self.bundle('1.0.0')

    def bundle(self, version, web=True, services=''):
        d = self.t / 'mitgeliefert' / 'test-kasse'
        shutil.rmtree(d, ignore_errors=True)
        (d / 'bin').mkdir(parents=True)
        (d / 'public').mkdir()
        (d / 'module.toml').write_text(module_toml(version, web, services))
        (d / 'public' / 'index.php').write_text('<?php echo "Testkasse";\n')
        for h in ('install', 'update', 'remove', 'busy', 'health'):
            (d / 'bin' / h).write_text(HOOK.replace('$1', h))
            (d / 'bin' / h).chmod(0o755)
        return d

    def data(self) -> Path:
        return self.t / 'daten' / 'test-kasse'

    def log(self) -> str:
        return (self.data() / 'haken.log').read_text() if (self.data() / 'haken.log').is_file() else ''

    def test_angebot_ohne_beispielmodul(self):
        shutil.copytree(ROOT / 'modules' / 'beispiel', self.t / 'mitgeliefert' / 'beispiel')
        s = self.mo.check()
        self.assertEqual([m['id'] for m in s['available']], ['test-kasse'])
        self.assertEqual(s['available'][0]['source'], 'mitgeliefert')
        self.assertEqual(s['installed'], [])

    def test_umbenanntes_modul_wird_nicht_angeboten(self):
        # „unterschrift“ heißt jetzt „signatur“; ein altes GitHub-Release darf nicht mehr als verfügbar erscheinen.
        self.assertIn('unterschrift', self.mo.EXCLUDED)
        self.assertIn('beispiel', self.mo.EXCLUDED)

    def test_installieren(self):
        msg = self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        self.assertIn('Testkasse 1.0.0 installiert', msg)
        cur = self.t / 'opt' / 'test-kasse' / 'current'
        self.assertTrue(cur.is_symlink())
        self.assertEqual(cur.resolve(), (self.t / 'opt' / 'test-kasse' / 'releases' / '1.0.0' / 'test-kasse').resolve())
        self.assertEqual(stat.S_IMODE(os.stat(self.data()).st_mode), 0o700)
        self.assertEqual(self.log(), 'install 1.0.0\nhealth 1.0.0\n')
        conf = (self.t / 'etc' / 'test-kasse' / 'php-fpm.conf').read_text()
        self.assertIn('listen = /run/kienzle/test-kasse/web.sock', conf)
        self.assertIn('/var/lib/kienzle/test-kasse/', conf)
        self.assertNotIn('/var/lib/kienzle/core/', conf)        # keine Daten der Basis
        self.assertIn('disable_functions] = exec,passthru,shell_exec,system,popen', conf)
        unit = (self.t / 'units' / 'kienzle-test-kasse-php.service').read_text()
        self.assertIn('User=ks-test-kasse', unit)
        self.assertIn('ReadWritePaths=/var/lib/kienzle/test-kasse /run/kienzle/test-kasse', unit)
        self.assertIn('ProtectSystem=strict', unit)
        self.assertIn('d ', (self.t / 'tmpfiles' / 'kienzle-test-kasse.conf').read_text())
        reg = json.loads((self.t / 'modules.json').read_text())
        self.assertEqual([m['module']['id'] for m in reg['modules']], ['test-kasse'])
        s = self.mo.check()
        self.assertEqual(s['installed'][0]['version'], '1.0.0')
        self.assertEqual(s['available'], [])
        with self.assertRaises(self.mo.Fehler):
            self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})

    def test_aktualisieren_und_ruecksprung(self):
        self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        (self.data() / 'kasse.sqlite').write_text('daten 1.0.0')
        self.bundle('1.1.0')
        s = self.mo.check()
        self.assertEqual(s['installed'][0]['update']['version'], '1.1.0')
        self.mo.dispatch({'action': 'aktualisieren', 'id': 'test-kasse'})
        self.assertIn('update 1.1.0', self.log())
        self.assertEqual(self.mo.local_releases('test-kasse'), ['1.0.0', '1.1.0'])
        # 1.2.0 meldet nach dem Start einen Fehler: zurück auf 1.1.0, Daten wie vorher.
        self.bundle('1.2.0')
        (self.data() / 'kasse.sqlite').write_text('daten 1.1.0')
        (self.data() / 'FEHLER-health').write_text('')
        with self.assertRaises(self.mo.Fehler) as e:
            self.mo.dispatch({'action': 'aktualisieren', 'id': 'test-kasse'})
        self.assertIn('Zurück auf 1.1.0', str(e.exception))
        self.assertEqual((self.t / 'opt' / 'test-kasse' / 'current').resolve().parent.name, '1.1.0')
        self.assertEqual((self.data() / 'kasse.sqlite').read_text(), 'daten 1.1.0')
        self.assertEqual(json.loads((self.t / 'modules.json').read_text())['modules'][0]['module']['version'], '1.1.0')

    def test_update_mit_socket_im_datenordner(self):
        """Eine laufende Telefonanlage legt asterisk.ctl (Unix-Socket) im Datenordner ab; der Schnappschuss darf daran nicht scheitern."""
        import socket
        self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        (self.data() / 'kasse.sqlite').write_text('daten 1.0.0')
        (self.data() / 'run').mkdir()
        sock = socket.socket(socket.AF_UNIX)
        self.addCleanup(sock.close)
        sock.bind(str(self.data() / 'run' / 'asterisk.ctl'))
        os.mkfifo(self.data() / 'run' / 'pipe')
        self.bundle('1.1.0')
        self.mo.check()
        self.mo.dispatch({'action': 'aktualisieren', 'id': 'test-kasse'})
        self.assertEqual(json.loads((self.t / 'modules.json').read_text())['modules'][0]['module']['version'], '1.1.0')
        snaps = list((self.t / 'state' / 'schnappschuesse' / 'test-kasse').iterdir())
        self.assertEqual((snaps[-1] / 'kasse.sqlite').read_text(), 'daten 1.0.0')
        self.assertFalse((snaps[-1] / 'run' / 'asterisk.ctl').exists())

    def test_automatisch_aktualisieren(self):
        self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        self.bundle('1.1.0')
        self.mo.check()
        # beschäftigt: übersprungen, nichts kaputt
        (self.data() / 'BESCHAEFTIGT').write_text('')
        self.assertEqual(self.mo.auto_update(), [])
        self.assertFalse(self.mo.read_status()['last']['ok'])
        self.assertIn('beschäftigt', self.mo.read_status()['last']['message'])
        (self.data() / 'BESCHAEFTIGT').unlink()
        # ausgeschaltet: nichts
        self.mo.dispatch({'action': 'automatisch', 'an': False})
        self.assertEqual(self.mo.auto_update(), [])
        self.mo.dispatch({'action': 'automatisch', 'an': True})
        self.assertEqual(self.mo.auto_update(), ['test-kasse'])
        self.assertEqual((self.t / 'opt' / 'test-kasse' / 'current').resolve().parent.name, '1.1.0')
        self.assertIsNone(self.mo.read_status()['installed'][0]['update'])

    def test_nicht_waehrend_beschaeftigt(self):
        self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        (self.data() / 'BESCHAEFTIGT').write_text('')
        self.bundle('1.1.0')
        with self.assertRaises(self.mo.Fehler) as e:
            self.mo.dispatch({'action': 'aktualisieren', 'id': 'test-kasse'})
        self.assertIn('beschäftigt', str(e.exception))
        self.assertEqual((self.t / 'opt' / 'test-kasse' / 'current').resolve().parent.name, '1.0.0')
        with self.assertRaises(self.mo.Fehler):
            self.mo.dispatch({'action': 'entfernen', 'id': 'test-kasse'})

    def test_fehlgeschlagene_erstinstallation_raeumt_auf(self):
        (self.t / 'daten' / 'test-kasse').mkdir()
        (self.data() / 'FEHLER-install').write_text('')
        with self.assertRaises(self.mo.Fehler):
            self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        self.assertFalse((self.t / 'opt' / 'test-kasse').exists())
        self.assertFalse((self.t / 'units' / 'kienzle-test-kasse-php.service').exists())
        self.assertTrue(self.data().is_dir())                       # Daten bleiben
        self.assertEqual(json.loads((self.t / 'modules.json').read_text())['modules'], [])

    def test_entfernen(self):
        self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        self.mo.dispatch({'action': 'entfernen', 'id': 'test-kasse'})
        self.assertFalse((self.t / 'opt' / 'test-kasse').exists())
        self.assertFalse((self.t / 'units' / 'kienzle-test-kasse-php.service').exists())
        self.assertTrue((self.data() / 'haken.log').is_file())
        self.assertIn('remove 1.0.0', self.log())
        self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        self.mo.dispatch({'action': 'entfernen', 'id': 'test-kasse', 'daten_loeschen': True})
        self.assertFalse(self.data().exists())

    def test_dienste_nur_als_modulbenutzer(self):
        d = self.bundle('1.0.0', services='services = ["kienzle-test-kasse-worker.service"]')
        (d / 'systemd').mkdir()
        (d / 'systemd' / 'kienzle-test-kasse-worker.service').write_text('[Service]\nUser=root\nExecStart=/bin/true\n')
        with self.assertRaises(self.mo.Fehler) as e:
            self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        self.assertIn('User=ks-test-kasse', str(e.exception))
        (d / 'systemd' / 'kienzle-test-kasse-worker.service').write_text('[Service]\nUser=ks-test-kasse\nExecStart=/bin/true\n')
        self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        self.assertTrue((self.t / 'units' / 'kienzle-test-kasse-worker.service').is_file())

    def zweites_modul(self, mid: str, extra: str, api: str = '2') -> None:
        d = self.t / 'mitgeliefert' / mid
        (d / 'bin').mkdir(parents=True)
        (d / 'public').mkdir()
        (d / 'public' / 'index.php').write_text('<?php echo 1;\n')
        (d / 'module.toml').write_text(f'[module]\nid = "{mid}"\nname = "{mid}"\nversion = "1.0.0"\ncore_api = "{api}"\n\n'
                                       f'[system]\nuser = "ks-{mid}"\ndata = "/var/lib/kienzle/{mid}"\n{extra}\n'
                                       f'[web]\npath = "/{mid}"\nupstream = "unix:/run/kienzle/{mid}/web.sock"\nprotocol = "fastcgi"\nentry = "public/index.php"\n')

    def test_austauschordner_erst_wenn_beide_einander_anmelden(self):
        self.zweites_modul('test-fax', '\n[[exchange]]\nmodule = "test-tel"\npurpose = "Faxaufträge"\n')
        self.zweites_modul('test-tel', 'disables = ["asterisk.service"]\n\n[[exchange]]\nmodule = "test-fax"\npurpose = "Faxaufträge"\n')
        calls = []
        with mock.patch.object(self.mo, '_systemctl', lambda *a: calls.append(a)):
            self.mo.dispatch({'action': 'installieren', 'id': 'test-fax'})
            ordner = self.t / 'austausch' / 'test-fax_test-tel'
            self.assertFalse(ordner.exists())
            unit = (self.t / 'units' / 'kienzle-test-fax-php.service').read_text()
            self.assertIn(' -/var/spool/kienzle/austausch/test-fax_test-tel', unit)
            self.assertIn('/var/spool/kienzle/austausch/test-fax_test-tel/:', (self.t / 'etc' / 'test-fax' / 'php-fpm.conf').read_text())
            self.mo.dispatch({'action': 'installieren', 'id': 'test-tel'})
            self.assertTrue(ordner.is_dir())
            self.assertEqual(stat.S_IMODE(ordner.stat().st_mode), 0o770)
            self.assertIn(('mask', 'asterisk.service'), calls)
            # Der Partner startet neu, damit der neue Ordner in seinem Dienst beschreibbar ist.
            self.assertIn(('restart', 'kienzle-test-fax-php.service'), calls)
            calls.clear()
            self.mo.dispatch({'action': 'entfernen', 'id': 'test-tel'})
            self.assertIn(('unmask', 'asterisk.service'), calls)
            self.assertNotIn(('start', 'asterisk.service'), calls)

    def test_abhaengigkeit_beim_installieren_und_entfernen(self):
        self.zweites_modul('test-fax', '')
        d = self.t / 'mitgeliefert' / 'test-fax'
        (d / 'module.toml').write_text((d / 'module.toml').read_text().replace('core_api = "2"\n', 'core_api = "2"\nrequires = ["test-tel"]\n'))
        self.zweites_modul('test-tel', '')
        with self.assertRaises(self.mo.Fehler) as e:
            self.mo.dispatch({'action': 'installieren', 'id': 'test-fax'})
        self.assertIn('braucht zuerst', str(e.exception))
        self.mo.dispatch({'action': 'installieren', 'id': 'test-tel'})
        self.mo.dispatch({'action': 'installieren', 'id': 'test-fax'})
        with self.assertRaises(self.mo.Fehler) as e:
            self.mo.dispatch({'action': 'entfernen', 'id': 'test-tel'})
        self.assertIn('noch gebraucht von', str(e.exception))
        self.mo.dispatch({'action': 'entfernen', 'id': 'test-fax'})
        self.mo.dispatch({'action': 'entfernen', 'id': 'test-tel'})

    def test_release_von_github(self):
        """Neuere Version als Release „test-kasse-v1.1.0“ (Ordner-Quelle statt GitHub), unsigniert zugelassen (Vorgabe)."""
        self.mo.dispatch({'action': 'installieren', 'id': 'test-kasse'})
        src = self.t / 'bau' / 'modules' / 'test-kasse'
        shutil.copytree(self.bundle('1.1.0'), src)
        self.bundle('1.0.0')
        sources = {str(p.relative_to(self.t / 'bau')): p for p in (self.t / 'bau').rglob('*') if p.is_file()}
        archive, man = release.build('test-kasse', '1.1.0', 'stabil', sources, {'core_api': ['1']})
        n = release.names('test-kasse', '1.1.0')
        (self.t / 'quelle' / n['archive']).write_bytes(archive)
        (self.t / 'quelle' / n['manifest']).write_bytes(man)
        (self.t / 'quelle' / 'releases.json').write_text(json.dumps([{'part': 'test-kasse', 'version': '1.1.0', 'prerelease': False, 'notes': '', 'published': '',
                                                                      'assets': {n['archive']: n['archive'], n['manifest']: n['manifest']}}]))
        s = self.mo.check()
        self.assertEqual(s['installed'][0]['update'], {'version': '1.1.0', 'source': 'github', 'signed': False})
        self.mo.dispatch({'action': 'aktualisieren', 'id': 'test-kasse', 'version': '1.1.0'})
        self.assertEqual((self.t / 'opt' / 'test-kasse' / 'current').resolve().parent.name, '1.1.0')

    def test_anfragedatei(self):
        req = self.t / 'anfragen' / 'module.json'
        req.write_text(json.dumps({'action': 'installieren', 'id': 'test-kasse'}))
        self.mo.handle()
        self.assertFalse(req.exists())
        s = self.mo.read_status()
        self.assertTrue(s['last']['ok'], s['last'])
        self.assertIsNone(s['running'])
        req.write_text(json.dumps({'action': 'installieren', 'id': '../core'}))
        self.mo.handle()
        self.assertFalse(self.mo.read_status()['last']['ok'])
        req.write_text(json.dumps({'action': 'installieren', 'id': 'core'}))
        self.mo.handle()
        self.assertIn('ungültig', self.mo.read_status()['last']['message'])

    def test_kasse_modul_gueltig(self):
        m = manifest.check_folder(ROOT / 'modules' / 'kasse')
        self.assertEqual([x['schema'] for x in m['starters']], ['kienzle-kasse'])
        self.assertEqual(m['web']['path'], '/kasse')
        self.assertIn('kassieren', [r['id'] for r in m['rights']])
        self.assertEqual((ROOT / 'modules' / 'kasse' / 'VERSION').read_text().strip(), m['module']['version'])

    def test_uploads_nur_wenn_das_modul_sie_anfordert(self):
        tz = manifest.check_folder(ROOT / 'modules' / 'terminzettel')
        self.assertFalse(tz['web']['uploads'])
        tz['web']['uploads'] = True
        self.assertIn('php_admin_flag[file_uploads] = on', self.mo.fpm_conf(tz))
        su = manifest.check_folder(ROOT / 'modules' / 'kasse')
        self.assertFalse(su['web']['uploads'])
        self.assertIn('php_admin_flag[file_uploads] = off', self.mo.fpm_conf(su))
        self.assertIn('php_admin_value[opcache.revalidate_path] = 1', self.mo.fpm_conf(su))


if __name__ == '__main__':
    unittest.main()
