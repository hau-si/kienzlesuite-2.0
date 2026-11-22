"""Tests für die Datensicherung (core/lib/kienzle/sicherung.py).

Mit echtem restic, wenn installiert (GitHub Actions im Container ubuntu:26.04), sonst mit tests/core/fake_restic.py.
Ziel ist ein lokaler Ordner bzw. für SMB ein nachgebautes mount. Nur erfundene Daten.
"""
import importlib
import json
import os
import shutil
import sqlite3
import stat
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'core' / 'lib'))
REAL = shutil.which('restic')
RESTIC = REAL or str(ROOT / 'tests' / 'core' / 'fake_restic.py')


class Umgebung(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.t)
        for d in ('etc/kienzle/core', 'etc/kienzle/ca', 'data/core/anfragen', 'repo-eltern', 'anfragen', 'bin'):
            (self.t / d).mkdir(parents=True)
        (self.t / 'etc/kienzle/core/core.json').write_text('{"hostnames":["kienzle.example.org"]}')
        (self.t / 'etc/kienzle/ca/ca.key').write_text('erfundener-ca-schluessel')
        self.db('erste Fassung')
        (self.t / 'data/core/audit.log').write_text('2026-11-22 anna anmelden\n')
        (self.t / 'bin/mount').write_text('#!/bin/sh\necho "$@" >> "$FAKE_MOUNT_LOG"\n'
                                          'cp "$(echo "$@" | sed -n "s/.*credentials=\\([^,]*\\).*/\\1/p")" "$FAKE_MOUNT_LOG.cred" 2>/dev/null\nexit 0\n')
        (self.t / 'bin/umount').write_text('#!/bin/sh\necho "umount $@" >> "$FAKE_MOUNT_LOG"\n')
        for f in ('mount', 'umount'):
            (self.t / 'bin' / f).chmod(0o755)
        env = {'KIENZLE_RESTIC': RESTIC, 'KIENZLE_MOUNT': self.t / 'bin/mount', 'KIENZLE_UMOUNT': self.t / 'bin/umount',
               'KIENZLE_SICHERUNG_ETC': self.t / 'etc/kienzle/sicherung',
               'KIENZLE_SICHERUNG_STATE': self.t / 'data/sicherung', 'KIENZLE_SICHERUNG_CACHE': self.t / 'cache', 'KIENZLE_SICHERUNG_REQUEST': self.t / 'anfragen/sicherung.json',
               'KIENZLE_SICHERUNG_KIENZLE_ETC': self.t / 'etc/kienzle', 'KIENZLE_SICHERUNG_DATA': self.t / 'data', 'KIENZLE_SICHERUNG_MODULES': self.t / 'data/core/modules.json',
               'KIENZLE_SICHERUNG_ZIELE': self.t / 'etc/kienzle/verbindungen/konfiguration.json', 'KIENZLE_SICHERUNG_MNT': self.t / 'mnt',
               'KIENZLE_SICHERUNG_UID': os.getuid(), 'KIENZLE_SICHERUNG_CHOWN': '0', 'FAKE_MOUNT_LOG': self.t / 'mount.log'}
        patcher = mock.patch.dict(os.environ, {k: str(v) for k, v in env.items()})
        patcher.start()
        self.addCleanup(patcher.stop)
        import kienzle.sicherung
        self.s = importlib.reload(kienzle.sicherung)
        self.s.STOP = {'core': ['true']}
        self.s.START = {'core': ['true']}

    def db(self, text):
        p = self.t / 'data/core/core.sqlite'
        con = sqlite3.connect(p)
        con.execute('CREATE TABLE IF NOT EXISTS t (v TEXT)')
        con.execute('DELETE FROM t')
        con.execute('INSERT INTO t VALUES (?)', (text,))
        con.commit()
        con.close()

    def db_value(self):
        con = sqlite3.connect(self.t / 'data/core/core.sqlite')
        try:
            return con.execute('SELECT v FROM t').fetchone()[0]
        finally:
            con.close()

    def ready(self):
        self.s.setup({'art': 'lokal', 'pfad': str(self.t / 'repo-eltern' / 'repo')}, 'Praxis Beispiel')

    def status(self):
        return json.loads((self.t / 'data/sicherung/status.json').read_text())

    def restore_all(self):
        out = self.t / 'zurueck'
        shutil.rmtree(out, ignore_errors=True)
        with self.s.Repo(self.s.settings()['ziel']) as repo:
            repo.ok('restore', 'latest', '--target', str(out))
        return out


class Einrichten(Umgebung):
    def test_einrichten(self):
        self.ready()
        pw = (self.t / 'etc/kienzle/sicherung/passwort').read_text().strip()
        self.assertRegex(pw, r'^([A-Za-z2-9]{5}-){7}[A-Za-z2-9]{5}$')
        self.assertEqual(stat.S_IMODE((self.t / 'etc/kienzle/sicherung/passwort').stat().st_mode), 0o600)
        sheet = (self.t / 'data/sicherung/blatt.html').read_text()
        self.assertIn(pw, sheet)
        self.assertIn(self.s.settings()['repository_id'], sheet)
        self.assertEqual(stat.S_IMODE((self.t / 'data/sicherung/blatt.html').stat().st_mode), 0o640)
        w = self.s.set_status()['warnungen']
        self.assertTrue(any('blatt' in x for x in w) and any('Schnappschüsse am NAS' in x for x in w) and any('fehlt' in x for x in w), w)
        with self.assertRaisesRegex(self.s.Fehler, 'schon eingerichtet'):
            self.ready()

    def test_ziele_pruefen(self):
        ok = self.s.check_target({'art': 'rest', 'url': 'https://nas.example.org:8000/kienzle', 'benutzer': 'kienzle', 'passwort': 'x'})
        self.assertEqual(ok['url'], 'https://nas.example.org:8000/kienzle')
        for bad in ({'art': 'rest', 'url': 'http://nas.example.org'}, {'art': 'rest', 'url': 'https://a@nas.example.org'}, {'art': 'lokal', 'pfad': '/etc/kienzle'},
                    {'art': 'lokal', 'pfad': 'relativ'}, {'art': 'lokal', 'pfad': '/media/../etc'}, {'art': 'lokal', 'pfad': '/var/lib/kienzle/x'},
                    {'art': 'ziel', 'id': 'gibtsnicht'}, {'art': 'ftp'}, 'x'):
            with self.subTest(bad), self.assertRaises(self.s.Fehler):
                self.s.check_target(bad)

    def test_smb_nur_waehrend_der_sicherung_eingehaengt(self):
        (self.t / 'etc/kienzle/verbindungen').mkdir(parents=True)
        (self.t / 'etc/kienzle/verbindungen/konfiguration.json').write_text(json.dumps({'ablage': [
            {'id': 'nas', 'name': 'NAS', 'art': 'smb', 'host': 'nas.example.org', 'freigabe': 'sicherung', 'ordner': 'Kienzle', 'benutzer': 'kienzle',
             'passwort': 'Nas-Passwort-1'}]}))
        self.s.setup({'art': 'ziel', 'id': 'nas', 'ordner': 'restic'}, 'Praxis Beispiel')
        log = (self.t / 'mount.log').read_text()
        self.assertIn('-t cifs //nas.example.org/sicherung', log)
        self.assertIn('vers=3.1.1,seal,sec=ntlmssp', log)
        self.assertNotIn('Nas-Passwort-1', log)                       # Passwort nur in der Zugangsdatei
        self.assertIn('password=Nas-Passwort-1', (self.t / 'mount.log.cred').read_text())
        self.assertIn(f'umount {self.t / "mnt"}', log)
        self.assertTrue((self.t / 'mnt' / 'Kienzle' / 'restic' / 'config').exists())

    def test_sftp_braucht_serverschluessel(self):
        (self.t / 'etc/kienzle/verbindungen').mkdir(parents=True)
        (self.t / 'etc/kienzle/verbindungen/konfiguration.json').write_text(json.dumps({'ablage': [
            {'id': 'nas', 'name': 'NAS', 'art': 'sftp', 'host': 'nas.example.org', 'port': 22, 'ordner': 'kienzle', 'benutzer': 'kienzle', 'hostkey': ''}]}))
        with self.assertRaisesRegex(self.s.Fehler, 'nicht bestätigt'):
            self.s.setup({'art': 'ziel', 'id': 'nas'}, 'Praxis Beispiel')


class Sichern(Umgebung):
    def test_sichern_inhalt(self):
        self.ready()
        r = self.s.backup()
        self.assertTrue(r['ok'] and r['snapshot'])
        self.assertFalse((self.t / 'data/sicherung/stand').exists())
        out = self.restore_all()
        stand = out / str(self.t / 'data/sicherung/stand/core/core.sqlite').lstrip('/')
        self.assertTrue(stand.is_file(), 'Stand der Datenbank fehlt in der Sicherung')
        self.assertEqual(sqlite3.connect(stand).execute('SELECT v FROM t').fetchone()[0], 'erste Fassung')
        self.assertTrue((out / str(self.t / 'etc/kienzle/ca/ca.key').lstrip('/')).is_file(), 'CA fehlt in der Sicherung')
        self.assertTrue((out / str(self.t / 'data/core/audit.log').lstrip('/')).is_file())
        for never in ('etc/kienzle/sicherung/passwort', 'data/sicherung/blatt.html', 'data/core/core.sqlite'):
            self.assertFalse((out / str(self.t / never).lstrip('/')).exists(), f'{never} gehört nicht in die Sicherung')
        s = self.status()
        self.assertTrue(s['letzte_sicherung']['ok'])
        self.assertFalse(any('36 Stunden' in w or 'fehlt' in w for w in s['warnungen']), s['warnungen'])

    def test_falsches_passwort(self):
        self.ready()
        (self.t / 'etc/kienzle/sicherung/passwort').write_text('falsch\n')
        with self.assertRaises(self.s.Fehler):
            self.s.backup()
        s = self.status()
        self.assertFalse(s['letzte_sicherung']['ok'])
        self.assertNotIn('falsch\n', json.dumps(s))
        self.assertTrue(any('fehlgeschlagen' in w for w in s['warnungen']))

    def test_pruefen_probe_aufraeumen(self):
        self.ready()
        self.s.backup()
        self.assertIn('in Ordnung', self.s.check())
        self.assertIn('in Ordnung', self.s.check('5%'))
        self.assertIn('1 Datenbank(en) unversehrt', self.s.probe())
        self.assertIn('entfernt', self.s.forget())
        self.assertEqual(len(self.s.snapshots()), 1)

    @unittest.skipIf(REAL, 'nur mit nachgebautem restic')
    def test_fehler_werden_gemeldet(self):
        self.ready()
        self.s.backup()
        (self.t / 'repo-eltern/repo/kaputt').write_text('x')
        with self.assertRaises(self.s.Fehler):
            self.s.check()
        self.assertTrue(any('Prüfung' in w for w in self.status()['warnungen']))
        log = (self.t / 'repo-eltern/repo/forget.log')
        self.s.forget()
        self.assertIn('--keep-daily 7 --keep-weekly 4 --keep-monthly 12 --keep-yearly 3', log.read_text())
        # Beschädigte Datenbank in der Sicherung: Probe schlägt an.
        snap = next((self.t / 'repo-eltern/repo/snapshots').iterdir())
        next(snap.rglob('core.sqlite')).write_bytes(b'kein sqlite')
        with self.assertRaises(self.s.Fehler):
            self.s.probe()
        self.assertFalse(self.status()['letzte_probe']['ok'])

    def test_taeglicher_ablauf(self):
        self.ready()
        monday = time.struct_time((2026, 11, 23, 2, 30, 0, 0, 327, 0))
        first_sunday = time.struct_time((2026, 11, 1, 2, 30, 0, 6, 305, 0))
        self.assertEqual(self.s.daily(monday), ['gesichert'])
        self.assertEqual(self.s.daily(first_sunday), ['gesichert', 'aufgeräumt', 'geprüft', 'Probe'])
        self.assertEqual(self.status()['letzte_pruefung']['umfang'], '5%')

    def test_modul_mit_sicherung(self):
        (self.t / 'data/beispiel').mkdir()
        (self.t / 'data/beispiel/daten.txt').write_text('Modul-Daten')
        registry = {'modules': [{'module': {'id': 'beispiel'}, 'backup': {'paths': [str(self.t / 'data/beispiel')], 'snapshot': None}}]}
        (self.t / 'data/core/modules.json').write_text(json.dumps(registry))
        self.ready()
        self.s.backup()
        (self.t / 'data/beispiel/daten.txt').write_text('verändert')
        (self.t / 'data/beispiel/neu.txt').write_text('neu')
        self.assertIn('wiederhergestellt', self.s.restore_part('latest', 'beispiel', ['true'], ['true']))
        self.assertEqual((self.t / 'data/beispiel/daten.txt').read_text(), 'Modul-Daten')


class Wiederherstellen(Umgebung):
    def test_basis(self):
        self.ready()
        self.s.backup()
        self.db('spätere Fassung')
        (self.t / 'data/core/core.sqlite-wal').write_text('alt')
        msg = self.s.restore_part('latest', 'core', ['true'], ['true'])
        self.assertIn('wiederhergestellt', msg)
        self.assertEqual(self.db_value(), 'erste Fassung')
        self.assertFalse((self.t / 'data/core/core.sqlite-wal').exists())
        self.assertTrue((self.t / 'data/core/anfragen').is_dir())
        with self.assertRaises(self.s.Fehler):
            self.s.restore_part('../x', 'core', [], [])
        with self.assertRaises(self.s.Fehler):
            self.s.restore_part('latest', 'gibtsnicht', [], [])


class Totalausfall(Umgebung):
    def test_alles_zurueck(self):
        (self.t / 'data/ablage/.ssh').mkdir(parents=True)
        (self.t / 'data/ablage/.ssh/id_ed25519').write_text('erfundener-ssh-schluessel')
        self.ready()
        self.s.backup()
        pw = (self.t / 'etc/kienzle/sicherung/passwort').read_text()
        # Neue VM: nichts mehr da außer der Sicherung.
        for d in ('etc/kienzle', 'data'):
            shutil.rmtree(self.t / d)
        (self.t / 'data/core').mkdir(parents=True)
        (self.t / 'etc/kienzle').mkdir(parents=True)
        ziel = {'art': 'lokal', 'pfad': str(self.t / 'repo-eltern' / 'repo')}
        with self.assertRaises(self.s.Fehler):
            self.s.full_restore(ziel, 'falsches-passwort')
        self.assertFalse((self.t / 'etc/kienzle/sicherung/passwort').exists(), 'falsches Passwort bleibt liegen')
        parts = self.s.full_restore(ziel, pw.strip())
        self.assertTrue(parts)
        self.assertEqual(self.db_value(), 'erste Fassung')
        self.assertEqual((self.t / 'etc/kienzle/ca/ca.key').read_text(), 'erfundener-ca-schluessel')
        self.assertEqual((self.t / 'data/ablage/.ssh/id_ed25519').read_text(), 'erfundener-ssh-schluessel')
        self.assertEqual(self.s.settings()['ziel'], ziel)
        self.assertEqual((self.t / 'etc/kienzle/sicherung/passwort').read_text(), pw)
        self.assertIn('wiederhergestellt', self.s.restore_part('latest', 'core', ['true'], ['true']))
        with self.assertRaisesRegex(self.s.Fehler, 'schon Daten'):
            self.s.full_restore(ziel, pw.strip())


class Anfragen(Umgebung):
    def request(self, data):
        (self.t / 'anfragen/sicherung.json').write_text(json.dumps(data) if not isinstance(data, str) else data)
        self.s.handle()
        return self.status()

    def test_ablauf_ueber_anfragen(self):
        s = self.request({'action': 'einrichten', 'ziel': {'art': 'lokal', 'pfad': str(self.t / 'repo-eltern' / 'repo')}, 'praxis': 'Praxis Beispiel'})
        self.assertTrue(s['letzte']['ok'], s)
        s = self.request({'action': 'jetzt'})
        self.assertIn('Gesichert', s['letzte']['meldung'])
        self.assertEqual(len(s['schnappschuesse']), 1)
        s = self.request({'action': 'blatt-gedruckt'})
        self.assertFalse((self.t / 'data/sicherung/blatt.html').exists())
        s = self.request({'action': 'nas-schnappschuesse', 'bestaetigt': True})
        self.assertEqual(s['warnungen'], [])
        s = self.request({'action': 'aufbewahren', 'aufbewahren': {'daily': 14, 'weekly': 8, 'monthly': 24, 'yearly': 10}})
        self.assertEqual(self.s.settings()['aufbewahren']['daily'], 14)
        for bad in ({'action': 'aufbewahren', 'aufbewahren': {'daily': -1, 'weekly': 1, 'monthly': 1, 'yearly': 1}},
                    {'action': 'aufbewahren', 'aufbewahren': {'daily': 0, 'weekly': 0, 'monthly': 0, 'yearly': 0}},
                    {'action': 'wiederherstellen', 'teil': '../core', 'snapshot': 'latest'}, {'action': 'loeschen'}, 'kein json'):
            with self.subTest(bad):
                self.assertFalse(self.request(bad)['letzte']['ok'])
        os.environ['KIENZLE_SICHERUNG_UID'] = str(os.getuid() + 1)
        self.assertFalse(self.request({'action': 'jetzt'})['letzte']['ok'])
        self.assertFalse((self.t / 'anfragen/sicherung.json').exists())


if __name__ == '__main__':
    unittest.main()
