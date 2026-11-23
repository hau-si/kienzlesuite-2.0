"""Tests für signierte Releases (core/lib/kienzle/release.py) und den Updater (core/lib/kienzle/update.py).

Echte Ed25519-Schlüssel nur für die Tests (im Testordner erzeugt), Releases mit nachgebautem Installer,
Quelle „ordner:“ statt GitHub. Nichts davon berührt das System.
"""
import gzip
import importlib
import io
import json
import os
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'core' / 'lib'))
from kienzle import release  # noqa: E402

FAKE_INSTALLER = r'''#!/usr/bin/env bash
# Nachgebauter Installer: kopiert das Programm in den Versionsordner und setzt „current“ (wie installer/install.sh).
set -eu
here=$(cd -- "$(dirname -- "$0")/.." && pwd)
if [ -f "$here/core/VERSION" ]; then src=$here/core; else src=$here; fi
v=$(cat "$src/VERSION")
if [ -f "$src/INSTALLER_FEHLER" ]; then echo kaputt > "$KIENZLE_UPDATE_DATA/core.sqlite"; echo 'Installer kaputt' >&2; exit 3; fi
dest="$KIENZLE_UPDATE_CORE/releases/$v"
if [ "$src" != "$dest" ]; then
  rm -rf "$dest"; mkdir -p "$dest"; cp -r "$src/." "$dest/"; mkdir -p "$dest/installer"; cp -r "$here/installer/." "$dest/installer/"
fi
ln -sfn "releases/$v" "$KIENZLE_UPDATE_CORE/.c" && mv -Tf "$KIENZLE_UPDATE_CORE/.c" "$KIENZLE_UPDATE_CORE/current"
echo "installiert $v" >> "$KIENZLE_UPDATE_DATA/installer.log"
'''
FAKE_HEALTH = '#!/bin/sh\n[ -f "$KIENZLE_UPDATE_CORE/current/KAPUTT" ] && exit 1\nprintf \'{"status":"ok","version":"%s"}\' "$(cat "$KIENZLE_UPDATE_CORE/current/VERSION")"\n'


def openssl(*args):
    subprocess.run(['openssl', *args], check=True, capture_output=True)


class Umgebung(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.t)
        for d in ('core/releases', 'daten/anfragen', 'einstellungen', 'keys', 'quelle', 'state', 'bau'):
            (self.t / d).mkdir(parents=True)
        openssl('genpkey', '-algorithm', 'ed25519', '-out', str(self.t / 'privat.pem'))
        openssl('pkey', '-in', str(self.t / 'privat.pem'), '-pubout', '-out', str(self.t / 'keys' / 'test.pub.pem'))
        openssl('genpkey', '-algorithm', 'ed25519', '-out', str(self.t / 'fremd.pem'))
        (self.t / 'health').write_text(FAKE_HEALTH)
        (self.t / 'health').chmod(0o755)
        self.install_local('0.3.0')
        (self.t / 'daten' / 'core.sqlite').write_text('daten 0.3.0')
        (self.t / 'einstellungen' / 'core.json').write_text('{"hostnames":["kienzle.example.org"]}')
        (self.t / 'quelle' / 'releases.json').write_text('[]')
        env = {'KIENZLE_UPDATE_STATE': self.t / 'state', 'KIENZLE_UPDATE_REQUEST': self.t / 'daten/anfragen/update.json', 'KIENZLE_UPDATE_KEYS': self.t / 'keys',
               'KIENZLE_UPDATE_CORE': self.t / 'core', 'KIENZLE_UPDATE_DATA': self.t / 'daten', 'KIENZLE_UPDATE_SETTINGS': self.t / 'einstellungen',
               'KIENZLE_UPDATE_MODULES': self.t / 'modules.json', 'KIENZLE_UPDATE_QUELLE': f'ordner:{self.t / "quelle"}', 'KIENZLE_UPDATE_STOP': 'true',
               'KIENZLE_UPDATE_START': 'true', 'KIENZLE_UPDATE_HEALTH': str(self.t / 'health'), 'KIENZLE_UPDATE_HEALTH_SECONDS': '1', 'KIENZLE_UPDATE_UID': os.getuid(),
               'KIENZLE_UPDATE_SICHERUNG': '', 'KIENZLE_SICHERUNG_ETC': self.t / 'sicherung'}
        patcher = mock.patch.dict(os.environ, {k: str(v) for k, v in env.items()})
        patcher.start()
        self.addCleanup(patcher.stop)
        import kienzle.update
        self.up = importlib.reload(kienzle.update)

    def install_local(self, version):
        d = self.t / 'core' / 'releases' / version
        (d / 'installer').mkdir(parents=True)
        (d / 'VERSION').write_text(version + '\n')
        (d / 'installer' / 'install.sh').write_text(FAKE_INSTALLER)
        os.symlink(f'releases/{version}', self.t / 'core' / '.c')
        os.replace(self.t / 'core' / '.c', self.t / 'core' / 'current')

    def publish(self, version, prerelease=False, channel=None, key='privat.pem', extra=None, tamper=None, part='core', signed=True):
        """Release bauen, signieren und in der Ordner-Quelle veröffentlichen."""
        b = self.t / 'bau' / version
        (b / 'core').mkdir(parents=True)
        (b / 'installer').mkdir()
        (b / 'core' / 'VERSION').write_text(version + '\n')
        (b / 'installer' / 'install.sh').write_text(FAKE_INSTALLER)
        (b / 'installer' / 'install.sh').chmod(0o755)
        for name, text in (extra or {}).items():
            (b / 'core' / name).write_text(text)
        sources = {str(p.relative_to(b)): p for p in b.rglob('*') if p.is_file()}
        archive, manifest = release.build(part, version, channel or ('beta' if prerelease else 'stabil'), sources, {'core_api': ['1']})
        n = release.names('core', version)
        (self.t / 'quelle' / n['manifest']).write_bytes(manifest)
        sig = release.sign(self.t / 'quelle' / n['manifest'], self.t / key)
        if tamper == 'archive':
            archive = archive[:-10] + bytes(10)
        if tamper == 'manifest':
            (self.t / 'quelle' / n['manifest']).write_bytes(manifest.replace(b'"stabil"', b'"beta"  '))
        (self.t / 'quelle' / n['archive']).write_bytes(archive)
        (self.t / 'quelle' / n['signature']).write_bytes(sig)
        listing = json.loads((self.t / 'quelle' / 'releases.json').read_text())
        assets = {v: v for v in n.values() if signed or v != n['signature']}
        listing.append({'part': 'core', 'version': version, 'prerelease': prerelease, 'notes': f'Neu in {version}', 'published': '2026-11-22', 'assets': assets})
        (self.t / 'quelle' / 'releases.json').write_text(json.dumps(listing))

    def current(self):
        return (self.t / 'core' / 'current' / 'VERSION').read_text().strip()

    def status(self):
        return json.loads((self.t / 'state' / 'status.json').read_text())

    def data(self):
        return (self.t / 'daten' / 'core.sqlite').read_text()


class Versionen(unittest.TestCase):
    def test_reihenfolge(self):
        for a, b in (('0.4.0', '0.3.9'), ('1.0.0', '0.99.99'), ('0.10.0', '0.9.0'), ('0.4.0', '0.4.0-beta.2'), ('0.4.0-beta.10', '0.4.0-beta.2'),
                     ('0.4.0-rc.1', '0.4.0-beta.1')):
            self.assertTrue(release.newer(a, b), (a, b))
            self.assertFalse(release.newer(b, a), (b, a))
        self.assertFalse(release.newer('0.4.0', '0.4.0'))
        for bad in ('1.0', 'v1.0.0', '01.0.0', '1.0.0-', '1.0.0+x', ''):
            with self.assertRaises(release.Fehler):
                release.parse_version(bad)


class Release(Umgebung):
    def built(self, **kw):
        self.publish('0.4.0', **kw)
        n = release.names('core', '0.4.0')
        return (self.t / 'quelle' / n['archive']).read_bytes(), (self.t / 'quelle' / n['manifest']).read_bytes(), (self.t / 'quelle' / n['signature']).read_bytes()

    def test_signatur(self):
        archive, manifest, sig = self.built()
        keys = release.trusted_keys(self.t / 'keys')
        release.verify_signature(manifest, sig, keys)
        for bad_manifest, bad_sig in ((manifest + b' ', sig), (manifest, bytes(64)), (manifest, sig[:-1])):
            with self.assertRaises(release.Fehler):
                release.verify_signature(bad_manifest, bad_sig, keys)
        other = release.sign(self.t / 'quelle' / release.names('core', '0.4.0')['manifest'], self.t / 'fremd.pem')
        with self.assertRaises(release.Fehler):
            release.verify_signature(manifest, other, keys)

    def test_keine_schluessel(self):
        with self.assertRaisesRegex(release.Fehler, 'Kein Release-Schlüssel'):
            release.trusted_keys(self.t / 'leer')

    def test_manifest_und_archiv(self):
        archive, manifest, _ = self.built()
        m = release.parse_manifest(manifest, 'core')
        release.check_archive(archive, m)
        release.extract(archive, m, self.t / 'aus')
        self.assertEqual((self.t / 'aus' / 'core' / 'VERSION').read_text(), '0.4.0\n')
        self.assertEqual(oct((self.t / 'aus' / 'installer' / 'install.sh').stat().st_mode & 0o777), '0o755')
        with self.assertRaisesRegex(release.Fehler, 'gehört zu'):
            release.parse_manifest(manifest, 'sumup')
        with self.assertRaises(release.Fehler):
            release.check_archive(archive + b'x', m)
        for change in ({'format': 'x'}, {'channel': 'nacht'}, {'version': '1.0'}, {'files': {}}, {'files': {'../x': {'sha256': '0' * 64, 'size': 1, 'mode': '0o644'}}},
                       {'files': {'a': {'sha256': '0' * 64, 'size': 1, 'mode': '0o4755'}}}, {'archive': {'name': 'x.tar.gz', 'sha256': '0' * 64, 'size': 1}}):
            bad = {**json.loads(manifest), **change}
            with self.subTest(change), self.assertRaises(release.Fehler):
                release.parse_manifest(json.dumps(bad).encode(), 'core')

    def tar(self, entries):
        raw = io.BytesIO()
        with tarfile.open(fileobj=raw, mode='w') as t:
            for info, data in entries:
                t.addfile(info, io.BytesIO(data) if data is not None else None)
        return gzip.compress(raw.getvalue())

    def test_gefaehrliche_archive(self):
        files = {'core/VERSION': {'sha256': release.hashlib.sha256(b'1\n').hexdigest(), 'size': 2, 'mode': '0o644'}}
        manifest = {'files': files}

        def info(name, kind=tarfile.REGTYPE, size=2, link=''):
            i = tarfile.TarInfo(name)
            i.type, i.size, i.linkname = kind, size, link
            return i
        cases = {'Pfad nach außen': [(info('../../etc/passwd'), b'1\n')], 'Symlink': [(info('core/VERSION', tarfile.SYMTYPE, 0, '/etc/passwd'), None)],
                 'Hardlink': [(info('core/VERSION', tarfile.LNKTYPE, 0, '/etc/shadow'), None)], 'Zusatzdatei': [(info('core/VERSION'), b'1\n'), (info('core/boese'), b'1\n')],
                 'fehlende Datei': [], 'falscher Inhalt': [(info('core/VERSION'), b'2\n')], 'doppelt': [(info('core/VERSION'), b'1\n'), (info('core/VERSION'), b'1\n')],
                 'Gerätedatei': [(info('core/VERSION', tarfile.CHRTYPE, 0), None)]}
        for n, (name, entries) in enumerate(cases.items()):
            with self.subTest(name), self.assertRaises(release.Fehler):
                release.extract(self.tar(entries), manifest, self.t / f'aus{n}')
        self.assertFalse((self.t / 'etc').exists())
        release.extract(self.tar([(info('core/VERSION'), b'1\n')]), manifest, self.t / 'gut')


class Updater(Umgebung):
    def request(self, data):
        (self.t / 'daten/anfragen/update.json').write_text(json.dumps(data))
        self.up.handle()

    def test_pruefen_und_kanaele(self):
        self.publish('0.2.0')
        self.publish('0.4.0')
        self.publish('0.5.0-beta.1', prerelease=True)
        s = self.up.check()
        self.assertEqual([a['version'] for a in s['available']['core']], ['0.4.0'])
        self.assertEqual(s['installed'], {'core': '0.3.0'})
        self.request({'action': 'kanal', 'kanal': 'beta'})
        self.assertEqual([a['version'] for a in self.status()['available']['core']], ['0.5.0-beta.1', '0.4.0'])
        os.environ['KIENZLE_UPDATE_QUELLE'] = f'ordner:{self.t / "fehlt"}'
        self.assertIsNotNone(self.up.check()['error'])

    def test_erfolg(self):
        self.publish('0.4.0')
        self.request({'action': 'installieren', 'version': '0.4.0'})
        s = self.status()
        self.assertEqual(s['state'], 'done', s)
        self.assertEqual(self.current(), '0.4.0')
        self.assertEqual(self.data(), 'daten 0.3.0')
        self.assertTrue(self.up.latest_snapshot('0.3.0'))
        self.assertFalse((self.t / 'state' / 'arbeit' / 'core-0.4.0').exists())
        self.assertIn('Signatur, Manifest und alle Dateien geprüft', (self.t / 'state' / 'update.log').read_text())
        self.assertTrue((self.t / 'core' / 'releases' / '0.4.0' / 'installer' / 'install.sh').is_file())

    def test_rueckschritt_stellt_besitzer_wieder_her(self):
        """Der Rückschritt läuft als root; die Dateien dürfen danach nicht root gehören (sonst öffnet ks-core die Datenbank nicht mehr)."""
        from unittest import mock
        self.publish('0.4.0')
        self.request({'action': 'installieren', 'version': '0.4.0'})
        snap = self.up.latest_snapshot('0.3.0')
        owners = json.loads((snap / 'besitz.json').read_text())
        self.assertIn('daten', owners)
        self.assertIn('einstellungen', owners)
        with mock.patch.object(self.up.os, 'lchown') as chown:
            self.up.restore(snap)
        self.assertTrue(chown.called)
        gesetzt = {Path(c.args[0]).name: (c.args[1], c.args[2]) for c in chown.call_args_list}
        eigene = (os.getuid(), os.getgid())
        for name in ('core.sqlite', 'secret.key', 'core.json'):
            if name in gesetzt:
                self.assertEqual(gesetzt[name], eigene)

    def test_sicherung_vor_dem_update(self):
        (self.t / 'sicherung').mkdir()
        (self.t / 'sicherung' / 'einstellungen.json').write_text('{"ziel": {"art": "lokal", "pfad": "/media/x"}}')
        fake = self.t / 'kienzle-sicherung'
        fake.write_text(f'#!/bin/sh\necho "$@" > {self.t / "sicherung-aufgerufen"}\nexit 1\n')
        fake.chmod(0o755)
        os.environ['KIENZLE_UPDATE_SICHERUNG'] = str(fake)
        self.publish('0.4.0')
        self.request({'action': 'installieren', 'version': '0.4.0'})
        self.assertEqual((self.t / 'sicherung-aufgerufen').read_text().strip(), 'jetzt')
        self.assertEqual(self.status()['state'], 'done', 'fehlgeschlagene Sicherung darf das Update nicht aufhalten')
        self.assertIn('Sicherung vor dem Update: fehlgeschlagen', (self.t / 'state' / 'update.log').read_text())

    def assert_unchanged(self, needle):
        s = self.status()
        self.assertEqual(s['state'], 'failed', s)
        self.assertIn(needle, s['message'])
        self.assertEqual(self.current(), '0.3.0')
        self.assertFalse((self.t / 'daten' / 'installer.log').exists())

    def test_fremder_schluessel(self):
        self.publish('0.4.0', key='fremd.pem')
        self.request({'action': 'installieren', 'version': '0.4.0'})
        self.assert_unchanged('Signatur ungültig')

    def test_verandertes_archiv(self):
        self.publish('0.4.0', tamper='archive')
        self.request({'action': 'installieren', 'version': '0.4.0'})
        self.assert_unchanged('Archiv passt nicht')

    def test_verandertes_manifest(self):
        self.publish('0.4.0', tamper='manifest')
        self.request({'action': 'installieren', 'version': '0.4.0'})
        self.assert_unchanged('Signatur ungültig')

    def test_ohne_schluessel(self):
        self.publish('0.4.0')
        shutil.rmtree(self.t / 'keys')
        self.request({'action': 'installieren', 'version': '0.4.0'})
        self.assert_unchanged('Kein Release-Schlüssel')

    def test_falscher_teil_aelter_unbekannt(self):
        self.publish('0.2.0')
        self.request({'action': 'installieren', 'version': '0.2.0'})
        self.assert_unchanged('nicht neuer')
        self.request({'action': 'installieren', 'version': '0.9.0'})
        self.assert_unchanged('nicht verfügbar')
        self.publish('0.4.0', part='sumup')
        self.request({'action': 'installieren', 'version': '0.4.0'})
        self.assert_unchanged('gehört zu')

    def test_beta_nur_im_beta_kanal(self):
        self.publish('0.4.0-beta.1', prerelease=True)
        self.request({'action': 'installieren', 'version': '0.4.0-beta.1'})
        self.assert_unchanged('nicht verfügbar')
        # Als „stabil“ veröffentlicht, im Manifest aber beta: ebenfalls nein.
        self.publish('0.4.1', channel='beta')
        self.request({'action': 'installieren', 'version': '0.4.1'})
        self.assert_unchanged('Kanal')
        self.request({'action': 'kanal', 'kanal': 'beta'})
        self.request({'action': 'installieren', 'version': '0.4.0-beta.1'})
        self.assertEqual(self.current(), '0.4.0-beta.1')

    def test_unsigniert_nur_beta_und_nur_mit_freigabe(self):
        self.publish('0.4.0', signed=False)
        self.publish('0.5.0-beta.1', prerelease=True, signed=False)
        self.request({'action': 'kanal', 'kanal': 'beta', 'signatur': 'pflicht'})
        self.assertEqual(self.status()['available']['core'], [], 'unsignierte Versionen bei Pflicht angeboten')
        self.request({'action': 'installieren', 'version': '0.5.0-beta.1'})
        self.assert_unchanged('nicht signiert')
        self.request({'action': 'kanal', 'kanal': 'stabil', 'unsigniert': True})       # Freigabe gilt nur im Beta-Kanal
        self.assertFalse(self.status()['unsigned'])
        self.request({'action': 'kanal', 'kanal': 'beta', 'unsigniert': True})
        self.assertEqual([(a['version'], a['signed']) for a in self.status()['available']['core']], [('0.5.0-beta.1', False)])
        self.request({'action': 'installieren', 'version': '0.4.0'})                 # stabil bleibt Pflicht
        self.assert_unchanged('nicht signiert')
        self.request({'action': 'installieren', 'version': '0.5.0-beta.1'})
        self.assertEqual(self.current(), '0.5.0-beta.1')
        self.assertIn('OHNE Signatur', (self.t / 'state' / 'update.log').read_text())

    def test_vorgabe_ohne_signaturpruefung(self):
        self.publish('0.4.0', signed=False)
        self.request({'action': 'pruefen'})
        self.assertEqual(self.status()['signatur'], 'aus')
        self.assertEqual([(a['version'], a['signed']) for a in self.status()['available']['core']], [('0.4.0', False)])

    def test_signaturpruefung_aus(self):
        self.publish('0.4.0', signed=False)
        self.request({'action': 'kanal', 'kanal': 'stabil', 'signatur': 'pflicht'})
        self.assertEqual(self.status()['available']['core'], [])
        self.request({'action': 'kanal', 'kanal': 'stabil', 'signatur': 'beta'})   # gilt nur im Beta-Kanal
        self.assertEqual(self.status()['signatur'], 'pflicht')
        self.request({'action': 'kanal', 'kanal': 'stabil', 'signatur': 'aus'})
        self.assertEqual(self.status()['signatur'], 'aus')
        self.assertEqual([(a['version'], a['signed']) for a in self.status()['available']['core']], [('0.4.0', False)])
        self.request({'action': 'installieren', 'version': '0.4.0'})
        self.assertEqual(self.current(), '0.4.0')
        self.assertIn('OHNE Signatur (vom Admin zugelassen: alle Versionen)', (self.t / 'state' / 'update.log').read_text())

    def test_signatur_aus_aber_falsche_signatur_bleibt_verboten(self):
        self.publish('0.4.0', key='fremd.pem')
        self.request({'action': 'kanal', 'kanal': 'stabil', 'signatur': 'aus'})
        self.request({'action': 'installieren', 'version': '0.4.0'})
        self.assertEqual(self.current(), '0.3.0')

    def test_unsigniert_aber_falsche_signatur_bleibt_verboten(self):
        self.publish('0.5.0-beta.1', prerelease=True, key='fremd.pem')
        self.request({'action': 'kanal', 'kanal': 'beta', 'unsigniert': True})
        self.request({'action': 'installieren', 'version': '0.5.0-beta.1'})
        self.assert_unchanged('Signatur ungültig')

    def test_unsigniert_ohne_vertraute_schluessel(self):
        shutil.rmtree(self.t / 'keys')
        self.publish('0.5.0-beta.1', prerelease=True, signed=False)
        self.request({'action': 'kanal', 'kanal': 'beta', 'unsigniert': True})
        self.request({'action': 'installieren', 'version': '0.5.0-beta.1'})
        self.assertEqual(self.current(), '0.5.0-beta.1')

    def test_unsigniert_beschaedigt(self):
        self.publish('0.5.0-beta.1', prerelease=True, signed=False, tamper='archive')
        self.request({'action': 'kanal', 'kanal': 'beta', 'unsigniert': True})
        self.request({'action': 'installieren', 'version': '0.5.0-beta.1'})
        self.assert_unchanged('Archiv passt nicht')

    def test_installer_fehler_ruecksprung(self):
        self.publish('0.4.0', extra={'INSTALLER_FEHLER': '1'})
        self.request({'action': 'installieren', 'version': '0.4.0'})
        s = self.status()
        self.assertEqual(s['state'], 'failed')
        self.assertIn('Installer von 0.4.0 fehlgeschlagen', s['message'])
        self.assertIn('0.3.0 wiederhergestellt', s['message'])
        self.assertEqual(self.current(), '0.3.0')
        self.assertEqual(self.data(), 'daten 0.3.0')              # vom kaputten Installer veränderte Daten zurück

    def test_pruefung_nach_start_ruecksprung(self):
        self.publish('0.4.0', extra={'KAPUTT': '1'})
        self.request({'action': 'installieren', 'version': '0.4.0'})
        self.assertIn('antwortet nach dem Start nicht', self.status()['message'])
        self.assertEqual(self.current(), '0.3.0')

    def test_unterbrochen_beim_neustart(self):
        self.publish('0.4.0')
        self.request({'action': 'installieren', 'version': '0.4.0'})
        snap = self.up.latest_snapshot('0.3.0')
        (self.t / 'daten' / 'core.sqlite').write_text('halb migriert')
        self.up.set_status(state='running', step='installieren', from_version='0.3.0', to_version='0.4.0', snapshot=str(snap))
        self.up.recover()
        self.assertEqual(self.current(), '0.3.0')
        self.assertEqual(self.data(), 'daten 0.3.0')
        self.assertIn('unterbrochen', self.status()['message'])
        self.up.set_status(state='running', step='laden', from_version='0.3.0')
        self.up.recover()
        self.assertIn('nichts verändert', self.status()['message'])
        self.up.recover()      # nichts mehr zu tun

    def test_von_hand_zurueck(self):
        self.publish('0.4.0')
        self.request({'action': 'installieren', 'version': '0.4.0'})
        (self.t / 'daten' / 'core.sqlite').write_text('daten 0.4.0')
        self.request({'action': 'zurueck', 'version': '0.3.0'})
        self.assertEqual(self.status()['state'], 'done', self.status())
        self.assertEqual(self.current(), '0.3.0')
        self.assertEqual(self.data(), 'daten 0.3.0')
        self.request({'action': 'zurueck', 'version': '0.2.0'})
        self.assertIn('nicht aufbewahrt', self.status()['message'])

    def test_aufraeumen(self):
        for v in ('0.4.0', '0.5.0', '0.6.0', '0.7.0'):
            self.publish(v)
            self.request({'action': 'installieren', 'version': v})
        self.assertEqual(self.current(), '0.7.0')
        self.assertEqual(self.up.local_releases(), ['0.5.0', '0.6.0', '0.7.0'])

    def test_beschaeftigtes_modul(self):
        self.publish('0.4.0')
        with mock.patch.object(self.up, 'busy_modules', return_value=['kasse']):
            self.request({'action': 'installieren', 'version': '0.4.0'})
        self.assert_unchanged('beschäftigt: kasse')

    def test_ungueltige_anfragen(self):
        for bad in ('kein json', json.dumps({'action': 'installieren', 'version': '../1'}), json.dumps({'action': 'loeschen'}), json.dumps({'action': 'kanal', 'kanal': 'x'})):
            (self.t / 'daten/anfragen/update.json').write_text(bad)
            self.up.handle()
            self.assertEqual(self.status()['message'], 'Anfrage ungültig.')
        os.environ['KIENZLE_UPDATE_UID'] = str(os.getuid() + 1)
        self.request({'action': 'pruefen'})
        self.assertEqual(self.status()['message'], 'Anfrage ungültig.')
        self.assertFalse((self.t / 'daten/anfragen/update.json').exists())

    def test_befehl(self):
        cli = [sys.executable, '-IB', str(ROOT / 'core' / 'bin' / 'kienzle-update')]
        self.assertEqual(subprocess.run(cli, capture_output=True).returncode, 2)
        self.assertEqual(subprocess.run([*cli, 'start'], capture_output=True, env=dict(os.environ)).returncode, 0)


class Skripte(unittest.TestCase):
    def test_bauen_und_signieren(self):
        """scripts/release-bauen mit dem echten Repository (nur wenn core/, installer/ und modules/ eingecheckt sind)."""
        st = subprocess.run(['git', '-C', str(ROOT), 'status', '--porcelain', 'core', 'installer', 'modules'], capture_output=True, text=True)
        if st.returncode != 0:
            self.skipTest('git nicht nutzbar: ' + st.stderr.strip()[:80])
        if st.stdout.strip():
            self.skipTest('nicht eingecheckte Änderungen')
        with tempfile.TemporaryDirectory() as tmp:
            r = subprocess.run([sys.executable, str(ROOT / 'scripts' / 'release-bauen'), 'core', '--ziel', tmp], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, r.stderr)
            version = (ROOT / 'core' / 'VERSION').read_text().strip()
            n = release.names('core', version)
            m = release.parse_manifest((Path(tmp) / n['manifest']).read_bytes(), 'core')
            self.assertIn('installer/install.sh', m['files'])
            self.assertIn('core/php/Web.php', m['files'])
            self.assertIn('modules/sumup/module.toml', m['files'])                          # mitgelieferte Module
            self.assertFalse([f for f in m['files'] if f.startswith('modules/beispiel/')])   # Beispielmodul nicht
            release.check_archive((Path(tmp) / n['archive']).read_bytes(), m)
            subprocess.run(['openssl', 'genpkey', '-algorithm', 'ed25519', '-out', f'{tmp}/k.pem'], check=True)
            (Path(tmp) / 'keys').mkdir()
            subprocess.run(['openssl', 'pkey', '-in', f'{tmp}/k.pem', '-pubout', '-out', f'{tmp}/keys/k.pub.pem'], check=True)
            r = subprocess.run([sys.executable, str(ROOT / 'scripts' / 'release-signieren'), str(Path(tmp) / n['manifest']), '--schluessel', f'{tmp}/k.pem',
                                '--schluessel-ordner', f'{tmp}/keys'], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, r.stderr)
            self.assertEqual(len((Path(tmp) / n['signature']).read_bytes()), 64)
            again = subprocess.run([sys.executable, str(ROOT / 'scripts' / 'release-bauen'), 'core', '--ziel', f'{tmp}/2'], capture_output=True, text=True)
            self.assertEqual(again.returncode, 0)
            # Modul-Release: nur modules/<id>/, Version aus module.toml.
            r = subprocess.run([sys.executable, str(ROOT / 'scripts' / 'release-bauen'), 'sumup', '--ziel', f'{tmp}/m'], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, r.stderr)
            mv = (ROOT / 'modules' / 'sumup' / 'VERSION').read_text().strip()
            mm = release.parse_manifest((Path(tmp) / 'm' / release.names('sumup', mv)['manifest']).read_bytes(), 'sumup')
            self.assertTrue(all(f.startswith('modules/sumup/') for f in mm['files']))
            self.assertIn('modules/sumup/module.toml', mm['files'])
            self.assertEqual(subprocess.run([sys.executable, str(ROOT / 'scripts' / 'release-bauen'), 'beispiel', '--ziel', f'{tmp}/b'], capture_output=True).returncode, 2)
            self.assertEqual((Path(tmp) / '2' / n['archive']).read_bytes(), (Path(tmp) / n['archive']).read_bytes(), 'Archiv nicht reproduzierbar')


if __name__ == '__main__':
    unittest.main()
