"""Tests für Netzwerkspeicher (core/lib/kienzle/ablage.py) und zentrale Verbindungen (core/lib/kienzle/verbindungen.py).

Nachgebautes NAS: ein Ordner und nachgebaute Programme smbclient, sftp und ssh-keyscan. Für T2med ein echter kleiner
HTTPS-Server auf localhost mit eigenem Testzertifikat. Nur erfundene Zugangsdaten und Namen (example.org).
"""
import http.server
import importlib
import io
import contextlib
import time
import json
import os
import shutil
import ssl
import subprocess
import sys
import tempfile
import threading
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'core' / 'lib'))

FAKE_NAS = r'''#!/usr/bin/env python3
"""Nachgebautes smbclient bzw. sftp: führt cd/mkdir/put/get/rename/del/rm in $FAKE_NAS aus."""
import os, re, shutil, sys
from pathlib import Path
root = Path(os.environ['FAKE_NAS']); prog = Path(sys.argv[0]).name; args = sys.argv[1:]
if prog == 'smbclient':
    script = args[args.index('-c') + 1].split('; ')
    if '-A' in args:
        auth = Path(args[args.index('-A') + 1]).read_text()
        if f"password = {os.environ.get('FAKE_PW', '')}\n" not in auth:
            print('session setup failed: NT_STATUS_LOGON_FAILURE'); sys.exit(1)
    elif os.environ.get('FAKE_GUEST') != '1':
        print('NT_STATUS_ACCESS_DENIED'); sys.exit(1)
    share = args[0].rsplit('/', 1)[1]; base = root / share
else:
    script = [l for l in sys.stdin.read().splitlines() if l]
    known = [a for a in args if a.startswith('UserKnownHostsFile=')][0].split('=', 1)[1]
    if os.environ['FAKE_HOSTKEY'] not in Path(known).read_text():
        print('Host key verification failed.', file=sys.stderr); sys.exit(255)
    base = root / 'sftp'
base.mkdir(parents=True, exist_ok=True); cwd = base
def p(x): x = x.strip('"').replace('\\', '/'); return Path(x) if x.startswith('/tmp') or x.startswith(str(Path(os.environ.get('TMPDIR', '/tmp')))) else cwd / x
for line in script:
    ignore = line.startswith('-'); line = line.lstrip('-')
    parts = re.findall(r'"[^"]*"|\S+', line); cmd, rest = parts[0], [x.strip('"') for x in parts[1:]]
    try:
        if cmd == 'cd':
            cwd = cwd / rest[0].replace('\\', '/')
            if not cwd.is_dir(): raise OSError('kein Ordner')
        elif cmd == 'mkdir':
            t = cwd / rest[0].replace('\\', '/')
            if t.exists():
                print('NT_STATUS_OBJECT_NAME_COLLISION' if prog == 'smbclient' else 'mkdir failed');
                if not ignore and prog != 'smbclient': sys.exit(1)
            else: t.mkdir()
        elif cmd == 'put': shutil.copy(rest[0], cwd / rest[1].replace('\\', '/'))
        elif cmd == 'get':
            data = (cwd / rest[0].replace('\\', '/')).read_bytes()
            if os.environ.get('FAKE_CORRUPT') == '1': data = data + b'x'
            Path(rest[1]).write_bytes(data)
        elif cmd == 'rename': os.replace(cwd / rest[0].replace('\\', '/'), cwd / rest[1].replace('\\', '/'))
        elif cmd in ('del', 'rm'): (cwd / rest[0].replace('\\', '/')).unlink()
        elif cmd == 'ls':
            if not cwd.is_dir(): raise OSError('kein Ordner')
            for f in sorted(cwd.iterdir()):
                if prog == 'smbclient': print('  %-30s %s %9d  Mon Jan  1 00:00:00 2024' % (f.name, 'D' if f.is_dir() else 'A', 0 if f.is_dir() else f.stat().st_size))
                else: print(f.name)
    except OSError as e:
        print('NT_STATUS_OBJECT_PATH_NOT_FOUND' if prog == 'smbclient' else str(e)); sys.exit(1)
'''
import base64 as _b64  # noqa: E402


def _ed25519(seed: bytes) -> str:
    blob = b'\x00\x00\x00\x0bssh-ed25519' + b'\x00\x00\x00\x20' + seed.ljust(32, b'.')[:32]
    return 'ssh-ed25519 ' + _b64.b64encode(blob).decode()


HOSTKEY = 'nas.example.org ' + _ed25519(b'testschluessel-nas')
OTHER_KEY = 'nas.example.org ' + _ed25519(b'anderer-schluessel')


class Umgebung(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.t)
        (self.t / 'bin').mkdir()
        for name in ('smbclient', 'sftp'):
            (self.t / 'bin' / name).write_text(FAKE_NAS)
            (self.t / 'bin' / name).chmod(0o755)
        (self.t / 'bin' / 'ssh-keyscan').write_text(f'#!/bin/sh\necho "# kommentar"\necho "{HOSTKEY}"\n')
        (self.t / 'bin' / 'ssh-keyscan').chmod(0o755)
        (self.t / 'nas').mkdir()
        (self.t / 'ssh').mkdir()
        (self.t / 'ssh' / 'id_ed25519.pub').write_text('ssh-ed25519 AAAATESTSCHLUESSEL ks-ablage@kienzle\n')
        env = {'KIENZLE_ABLAGE_ZIELE': self.t / 'etc/ablage.json', 'KIENZLE_ABLAGE_SPOOL': self.t / 'spool', 'KIENZLE_ABLAGE_STATE': self.t / 'state-ablage',
               'KIENZLE_SMBCLIENT': self.t / 'bin/smbclient', 'KIENZLE_SFTP': self.t / 'bin/sftp', 'KIENZLE_SSH_KEYSCAN': self.t / 'bin/ssh-keyscan',
               'KIENZLE_ABLAGE_SCHLUESSEL': self.t / 'ssh/id_ed25519', 'FAKE_NAS': self.t / 'nas', 'FAKE_PW': 'Nas-Passwort-1', 'FAKE_HOSTKEY': HOSTKEY.split(' ', 1)[1],
               'KIENZLE_VERB_ETC': self.t / 'etc', 'KIENZLE_VERB_SCHLUESSEL': self.t / 'schluessel', 'KIENZLE_VERB_STATE': self.t / 'state-verb',
               'KIENZLE_VERB_REQUEST': self.t / 'anfragen/verbindungen.json', 'KIENZLE_VERB_MODULES': self.t / 'modules.json', 'KIENZLE_VERB_CHOWN': '0',
               'KIENZLE_VERB_UID': os.getuid(), 'KIENZLE_NACHRICHTEN_SPOOL': self.t / 'nachrichten', 'KIENZLE_NACHRICHTEN_KONFIG': self.t / 'etc/nachrichten.json',
               'KIENZLE_NACHRICHTEN_STATE': self.t / 'state-nachrichten'}
        patcher = mock.patch.dict(os.environ, {k: str(v) for k, v in env.items()})
        patcher.start()
        self.addCleanup(patcher.stop)
        import kienzle.ablage
        import kienzle.verbindungen
        self.ab = importlib.reload(kienzle.ablage)
        self.vb = importlib.reload(kienzle.verbindungen)
        (self.t / 'anfragen').mkdir()

    SMB = {'id': 'steuerbuero', 'name': 'Steuerbüro', 'art': 'smb', 'host': 'nas.example.org', 'freigabe': 'praxis', 'ordner': 'Kienzle/Ablage',
           'benutzer': 'kienzle', 'passwort': 'Nas-Passwort-1'}
    SFTP = {'id': 'sicherung', 'name': 'NAS (SFTP)', 'art': 'sftp', 'host': 'nas.example.org', 'port': 22, 'ordner': 'kienzle', 'benutzer': 'kienzle',
            'hostkey': HOSTKEY}

    def targets(self, *targets):
        (self.t / 'etc').mkdir(exist_ok=True)
        (self.t / 'etc' / 'ablage.json').write_text(json.dumps({'ziele': list(targets)}))

    def job(self, module='kasse', name='2026-03-15_RE-42_42,50EUR.pdf', ziel='steuerbuero', data=b'%PDF-1.4 erfunden', job='auftrag-0001', **extra):
        d = self.t / 'spool' / module
        d.mkdir(parents=True, exist_ok=True)
        (d / f'{job}.daten').write_bytes(data)
        (d / f'{job}.json').write_text(json.dumps({'ziel': ziel, 'ordner': 'Rechnungen/{jahr}/{monat}', 'name': name, 'jahr': '2026', 'monat': '03', **extra}))
        return d


class Ziele(Umgebung):
    def test_gueltig(self):
        t = self.ab.check_target(self.SMB)
        self.assertEqual((t['art'], t['ordner'], t['gast']), ('smb', 'Kienzle/Ablage', False))
        self.assertEqual(self.ab.check_target(self.SFTP)['port'], 22)
        self.assertTrue(self.ab.check_target({**self.SMB, 'gast': True, 'benutzer': '', 'passwort': ''})['gast'])

    def test_ungueltig(self):
        for change in ({'id': 'X'}, {'name': ''}, {'host': 'nas;rm'}, {'host': ''}, {'freigabe': 'a/b'}, {'ordner': '../etc'}, {'ordner': 'a/../../b'},
                       {'benutzer': 'a b'}, {'passwort': 'a\nb'}, {'art': 'ftp'}, {'ordner': 'a"b'}, {'ordner': 'Rechnungen/{jahr}'}):
            with self.subTest(change), self.assertRaises(self.ab.Fehler):
                self.ab.check_target({**self.SMB, **change})
        for change in ({'port': 0}, {'port': '22'}, {'hostkey': 'irgendwas'}, {'benutzer': ''}):
            with self.subTest(change), self.assertRaises(self.ab.Fehler):
                self.ab.check_target({**self.SFTP, **change})

    def test_pfade(self):
        self.assertEqual(self.ab.folder_for('Rechnungen/{jahr}/{monat}', '2026', '03'), 'Rechnungen/2026/03')
        for bad in ('a;b', 'a"b', 'a\\b', 'a*b', 'a\nb'):
            with self.subTest(bad), self.assertRaises(self.ab.Fehler):
                self.ab.quote(bad)
        with self.assertRaises(self.ab.Fehler):
            self.ab.folder_for('x', '26', '3')


class Ablage(Umgebung):
    def test_smb_ablegen(self):
        self.targets(self.SMB)
        d = self.job()
        jobs = self.ab.process()
        self.assertEqual(jobs['kasse/auftrag-0001']['zustand'], 'abgelegt', jobs)
        self.assertEqual((self.t / 'nas/praxis/Kienzle/Ablage/Rechnungen/2026/03/2026-03-15_RE-42_42,50EUR.pdf').read_bytes(), b'%PDF-1.4 erfunden')
        self.assertEqual(jobs['kasse/auftrag-0001']['pfad'], 'Kienzle/Ablage/Rechnungen/2026/03/2026-03-15_RE-42_42,50EUR.pdf')
        self.assertEqual(list(d.iterdir()), [])
        self.assertFalse(list((self.t / 'nas').rglob('*.part')))
        self.job(job='auftrag-0002', name='zweite.pdf')
        self.assertEqual(self.ab.process()['kasse/auftrag-0002']['zustand'], 'abgelegt')      # Ordner gibt es schon

    def lauschen(self, ziel='steuerbuero', ordner='Fax-Ausgang', module='fax', **extra):
        d = self.t / 'spool' / module
        d.mkdir(parents=True, exist_ok=True)
        (d / 'lauschen.anfrage').write_text(json.dumps({'ziel': ziel, 'ordner': ordner, **extra}))
        return d

    def test_lauschordner_smb(self):
        self.targets(self.SMB)
        nas = self.t / 'nas/praxis/Kienzle/Ablage/Fax-Ausgang'
        nas.mkdir(parents=True)
        (nas / 'brief.pdf').write_bytes(b'%PDF-1.4 brief')
        (nas / 'notiz.txt').write_bytes(b'nicht gewollt')
        (nas / 'unter').mkdir()
        (nas / 'halb.pdf.part').write_bytes(b'x')
        d = self.lauschen()
        jobs = self.ab.process()
        self.assertEqual(jobs['fax/lauschen']['zustand'], 'ok', jobs)
        self.assertEqual(jobs['fax/lauschen']['anzahl'], 1)
        self.assertEqual((d / 'eingang/brief.pdf').read_bytes(), b'%PDF-1.4 brief')
        self.assertEqual(oct((d / 'eingang/brief.pdf').stat().st_mode & 0o777), '0o660')
        self.assertFalse((nas / 'brief.pdf').exists(), 'auf dem NAS gelöscht')
        self.assertTrue((nas / 'notiz.txt').exists() and (nas / 'halb.pdf.part').exists())
        (nas / 'brief.pdf').write_bytes(b'%PDF-1.4 zweiter')                      # gleicher Name: nichts überschreiben
        self.ab.process()
        self.assertEqual(sorted(p.name for p in (d / 'eingang').iterdir()), ['1-brief.pdf', 'brief.pdf'])
        self.assertEqual((d / 'eingang/brief.pdf').read_bytes(), b'%PDF-1.4 brief')

    def test_lauschordner_sftp_endungen_und_fehler(self):
        self.targets(self.SFTP)
        nas = self.t / 'nas/sftp/kienzle/Fax-Ausgang'
        nas.mkdir(parents=True)
        (nas / 'a.pdf').write_bytes(b'%PDF-1.4 a')
        (nas / 'b.png').write_bytes(b'png')
        d = self.lauschen(ziel='sicherung', endungen=['pdf', 'png', 'x y'])
        self.assertEqual(self.ab.process()['fax/lauschen']['anzahl'], 2)
        self.assertEqual(sorted(p.name for p in (d / 'eingang').iterdir()), ['a.pdf', 'b.png'])
        self.lauschen(ziel='gibtsnicht')
        self.assertEqual(self.ab.process()['fax/lauschen']['zustand'], 'fehler')
        self.lauschen(ordner='../draussen')
        self.assertEqual(self.ab.process()['fax/lauschen']['zustand'], 'fehler')
        self.lauschen(ordner='nicht-vorhanden')
        self.assertEqual(self.ab.process()['fax/lauschen']['anzahl'], 0)          # fehlender Ordner ist kein Fehler

    def test_sftp_ablegen_und_serverschluessel(self):
        self.targets(self.SFTP)
        self.job(ziel='sicherung')
        self.assertEqual(self.ab.process()['kasse/auftrag-0001']['zustand'], 'abgelegt')
        self.assertTrue((self.t / 'nas/sftp/kienzle/Rechnungen/2026/03/2026-03-15_RE-42_42,50EUR.pdf').is_file())
        self.targets({**self.SFTP, 'hostkey': OTHER_KEY})
        self.job(ziel='sicherung', job='auftrag-0002')
        self.assertEqual(self.ab.process()['kasse/auftrag-0002']['zustand'], 'fehler')     # anderer Serverschlüssel
        self.targets({**self.SFTP, 'hostkey': ''})
        self.assertIn('nicht bestätigt', self.ab.process()['kasse/auftrag-0002']['meldung'])

    def test_falsches_passwort_wiederholen_und_aufgeben(self):
        self.targets({**self.SMB, 'passwort': 'falsch'})
        d = self.job()
        j = self.ab.process()['kasse/auftrag-0001']
        self.assertEqual((j['zustand'], j['versuche']), ('fehler', 1))
        self.assertIn('NT_STATUS_LOGON_FAILURE', j['meldung'])
        self.assertTrue((d / 'auftrag-0001.daten').exists())
        self.assertEqual(self.ab.process()['kasse/auftrag-0001']['versuche'], 2)
        with mock.patch.object(self.ab, 'MAX_TRIES', 3):
            self.assertEqual(self.ab.process()['kasse/auftrag-0001']['zustand'], 'aufgegeben')
        self.assertTrue((d / 'fehlgeschlagen' / 'auftrag-0001.daten').exists())

    def test_beschaedigt_beim_uebertragen(self):
        self.targets(self.SMB)
        self.job()
        os.environ['FAKE_CORRUPT'] = '1'
        j = self.ab.process()['kasse/auftrag-0001']
        self.assertIn('Prüfung nach dem Hochladen', j['meldung'])
        self.assertFalse(list((self.t / 'nas').rglob('2026-03-15_RE-42_42,50EUR.pdf')))

    def test_ungueltige_auftraege(self):
        self.targets(self.SMB)
        self.job(name='../../boese.pdf', job='auftrag-0001')
        self.job(ziel='unbekannt', job='auftrag-0002')
        self.job(name='ohne-endung', job='auftrag-0003')
        d = self.job(job='auftrag-0004', jahr='20x6')
        (d / 'kurz.json').write_text('{}')
        (self.t / 'geheim.json').write_text(json.dumps({'ziel': 'steuerbuero'}))
        (d / 'auftrag-0005.json').symlink_to(self.t / 'geheim.json')
        jobs = self.ab.process()
        for key in ('auftrag-0001', 'auftrag-0002', 'auftrag-0003', 'auftrag-0004', 'kurz', 'auftrag-0005'):
            self.assertEqual(jobs[f'kasse/{key}']['zustand'], 'fehler', key)
        self.assertEqual([p for p in (self.t / 'nas').rglob('*') if p.is_file()], [])

    def test_verbindungstest_hinterlaesst_nichts(self):
        self.assertIn('erfolgreich', self.ab.test_target(self.SMB))
        self.assertEqual([p for p in (self.t / 'nas').rglob('*') if p.is_file()], [])
        with self.assertRaises(self.ab.Fehler):
            self.ab.test_target({**self.SMB, 'passwort': 'falsch'})

    def test_serverschluessel_abrufen(self):
        keys = self.ab.scan_hostkey('nas.example.org', 22)
        self.assertEqual(keys, [HOSTKEY])
        self.assertTrue(self.ab.fingerprint(HOSTKEY).startswith('SHA256:'))
        with self.assertRaises(self.ab.Fehler):
            self.ab.scan_hostkey('nas;rm -rf', 22)


def make_cert(folder: Path, cn='localhost') -> tuple[Path, Path]:
    key, crt = folder / f'{cn}.key', folder / f'{cn}.crt'
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:P-256', '-nodes', '-keyout', str(key), '-out', str(crt),
                    '-days', '2', '-subj', f'/CN={cn}', '-addext', f'subjectAltName=DNS:{cn},IP:127.0.0.1'], check=True, capture_output=True)
    return key, crt


class Fhir(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        ok = self.path == '/fhir/metadata' and self.headers.get('X-API-Key') == 'api-schluessel-1'
        body = b'{"resourceType":"CapabilityStatement"}' if ok else b'{"resourceType":"OperationOutcome"}'
        self.send_response(200 if ok else 401)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *a):
        pass


class Verbindungen(Umgebung):
    T2MED = {'base_url': 'https://t2med.example.org:16567/aps/fhir/api/r4', 'launch_urls': ['https://t2med.example.org:16567/aps/fhir/api/r4'], 'api_key': 'geheim-1'}
    MAIL = {'host': 'smtp.example.org', 'port': 587, 'sicherheit': 'starttls', 'benutzer': 'praxis@example.org', 'passwort': 'Mail-Passwort-1', 'absender': 'praxis@example.org'}

    def registry(self, extra_connections=()):
        reg = json.loads(subprocess.run([sys.executable, '-IB', str(ROOT / 'core/bin/kienzle-modul-pruefen'), '--alle', '--json', str(ROOT / 'modules')],
                                        capture_output=True, text=True, check=True).stdout)
        other = json.loads(json.dumps(reg['modules'][0]))
        other['module']['id'], other['module']['name'] = 'zeit', 'Zeiterfassung'
        other['connections'] = list(extra_connections)
        reg['modules'].append(other)
        (self.t / 'modules.json').write_text(json.dumps(reg))
        (self.t / 'modules.json').chmod(0o644)

    def request(self, data):
        (self.t / 'anfragen' / 'verbindungen.json').write_text(json.dumps(data))
        self.vb.handle()
        return json.loads((self.t / 'state-verb' / 'status.json').read_text())

    def test_t2med_pruefung(self):
        c = {}
        self.vb.set_t2med(c, self.T2MED)
        self.assertEqual(c['t2med']['launch_urls'], ['https://t2med.example.org:16567/aps/fhir/api/r4'])
        for change in ({'base_url': 'http://t2med.example.org/fhir'}, {'base_url': 'https://user@t2med.example.org/'}, {'base_url': 'https://t2med.example.org/?x=1'},
                       {'base_url': 'https://t2med.example.org/../x'}, {'launch_urls': ['https://evil example']}, {'launch_urls': 'x'}, {'api_key': 'a\nb'},
                       {'pin': True}, {'ca_pem': 'kein zertifikat'}):
            with self.subTest(change), self.assertRaises(self.vb.Fehler):
                self.vb.set_t2med({}, {**self.T2MED, **change})
        c3 = {'t2med': dict(c['t2med'], ca_pem='x')}
        self.vb.set_t2med(c3, {**self.T2MED, 'api_key': '', 'api_key_entfernen': True})
        self.assertEqual((c3['t2med']['api_key'], c3['t2med']['ca_pem']), ('', 'x'), 'Schlüssel entfernen darf das Zertifikat nicht löschen')
        c2 = {}
        self.vb.set_t2med(c2, {**self.T2MED, 'api_key': '  geheim-2 \t'})
        self.assertEqual(c2['t2med']['api_key'], 'geheim-2', 'Leerzeichen am Rand bleiben im Schlüssel')
        self.vb.set_t2med(c, {**self.T2MED, 'api_key': ''})
        self.assertEqual(c['t2med']['api_key'], 'geheim-1', 'leerer Schlüssel überschreibt den gespeicherten')

    def test_mail_pruefung(self):
        c = {}
        self.vb.set_mail(c, self.MAIL)
        self.vb.set_mail(c, {**self.MAIL, 'passwort': ''})
        self.assertEqual(c['mail']['passwort'], 'Mail-Passwort-1')
        for change in ({'host': 'a b'}, {'port': 0}, {'sicherheit': 'none'}, {'absender': 'keine-adresse'}, {'passwort': 'a\x00'}):
            with self.subTest(change), self.assertRaises(self.vb.Fehler):
                self.vb.set_mail({}, {**self.MAIL, **change})

    def test_verteilen_nur_an_anmeldende_module(self):
        self.registry(extra_connections=[{'id': 'ablage', 'purpose': 'Export', 'required': False}])
        s = self.request({'action': 't2med', 'daten': self.T2MED})
        self.request({'action': 'mail', 'daten': self.MAIL})
        self.request({'action': 'ziel', 'daten': self.SMB})
        beispiel = json.loads((self.t / 'etc/module/beispiel.json').read_text())
        self.assertEqual(sorted(beispiel), ['mail', 't2med'])           # beispiel meldet t2med und mail an
        self.assertEqual(beispiel['t2med']['api_key'], 'geheim-1')
        zeit = json.loads((self.t / 'etc/module/zeit.json').read_text())
        self.assertEqual(sorted(zeit), ['ablage'])                      # zeit bekommt weder T2med noch E-Mail
        self.assertEqual(zeit['ablage']['ziele'], [{'id': 'steuerbuero', 'name': 'Steuerbüro'}])
        self.assertNotIn('passwort', json.dumps(zeit))
        self.assertEqual(oct((self.t / 'spool/zeit').stat().st_mode & 0o7777), '0o2770')
        for f in ('etc/module/beispiel.json', 'etc/ablage.json', 'schluessel/beispiel.key', 'schluessel/zeit.key'):
            self.assertEqual(oct((self.t / f).stat().st_mode & 0o777), '0o640', f)
        self.assertEqual(oct((self.t / 'etc/konfiguration.json').stat().st_mode & 0o777), '0o600')
        self.assertEqual(json.loads((self.t / 'etc/ablage.json').read_text())['ziele'][0]['passwort'], 'Nas-Passwort-1')
        status = (self.t / 'state-verb/status.json').read_text()
        for secret in ('geheim-1', 'Mail-Passwort-1', 'Nas-Passwort-1'):
            self.assertNotIn(secret, status)
        s = json.loads(status)
        self.assertTrue(s['t2med']['api_key_gesetzt'] and s['mail']['passwort_gesetzt'] and s['ablage'][0]['passwort_gesetzt'])
        self.assertIn({'modul': 'beispiel', 'name': 'Beispielmodul', 'verbindung': 't2med', 'zweck': 'Beispiel: Patient zum Vorgang lesen', 'pflicht': True}, s['nutzung'])
        self.assertEqual(s['sftp_schluessel'], 'ssh-ed25519 AAAATESTSCHLUESSEL ks-ablage@kienzle')
        key = (self.t / 'schluessel/beispiel.key').read_text()
        self.request({'action': 'verteilen'})
        self.assertEqual((self.t / 'schluessel/beispiel.key').read_text(), key, 'Modulschlüssel neu erzeugt')
        self.request({'action': 't2med', 'loeschen': True})
        self.assertNotIn('t2med', json.loads((self.t / 'etc/module/beispiel.json').read_text()))

    def test_ziele_und_serverschluessel(self):
        self.registry()
        self.request({'action': 'ziel', 'daten': {**self.SFTP, 'hostkey': ''}})
        s = self.request({'action': 'hostkey-holen', 'id': 'sicherung'})
        fp = s['hostkeys']['sicherung'][0]['fingerprint']
        s = self.request({'action': 'hostkey-uebernehmen', 'id': 'sicherung', 'fingerprint': 'SHA256:falsch'})
        self.assertFalse(s['letzte']['ok'])
        s = self.request({'action': 'hostkey-uebernehmen', 'id': 'sicherung', 'fingerprint': fp})
        self.assertTrue(s['letzte']['ok'], s)
        self.assertEqual(s['ablage'][0]['fingerprint'], fp)
        s = self.request({'action': 'pruefen', 'art': 'ziel', 'id': 'sicherung'})
        self.assertTrue(s['tests']['ziel:sicherung']['ok'], s)
        s = self.request({'action': 'ziel', 'daten': {**self.SFTP, 'host': 'nas2.example.org'}})
        self.assertEqual(s['ablage'][0]['fingerprint'], '', 'Serverschlüssel bleibt bei neuem Server')
        # Prüfen ohne bestätigten Schlüssel ruft ihn gleich ab, damit die Schaltfläche zum Übernehmen erscheint
        s = self.request({'action': 'pruefen', 'art': 'ziel', 'id': 'sicherung'})
        self.assertFalse(s['tests']['ziel:sicherung']['ok'])
        self.assertTrue(s['hostkeys']['sicherung'], 'Schlüssel nicht abgerufen')
        self.request({'action': 'ziel', 'daten': {**self.SMB, 'passwort': ''}})
        self.assertEqual(json.loads((self.t / 'etc/ablage.json').read_text())['ziele'][1]['passwort'], '')
        self.request({'action': 'ziel', 'daten': self.SMB})
        self.request({'action': 'ziel', 'daten': {**self.SMB, 'passwort': '', 'name': 'Steuerbüro neu'}})
        z = {t['id']: t for t in json.loads((self.t / 'etc/ablage.json').read_text())['ziele']}
        self.assertEqual((z['steuerbuero']['passwort'], z['steuerbuero']['name']), ('Nas-Passwort-1', 'Steuerbüro neu'))
        s = self.request({'action': 'ziel-loeschen', 'id': 'steuerbuero'})
        self.assertEqual([t['id'] for t in s['ablage']], ['sicherung'])

    def test_fhir_verbindung(self):
        key, crt = make_cert(self.t)
        srv = http.server.HTTPServer(('127.0.0.1', 0), Fhir)
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(crt, key)
        srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        self.addCleanup(srv.shutdown)
        base = f'https://localhost:{srv.server_address[1]}/fhir'
        pem = crt.read_text()
        c = {}
        self.vb.set_t2med(c, {'base_url': base, 'api_key': 'api-schluessel-1', 'ca_pem': pem, 'pin': True})
        self.assertIn('CapabilityStatement', self.vb.test_t2med(c['t2med']))
        self.assertIn('Token', self.vb.test_t2med({**c['t2med'], 'api_key': 'falsch'}))   # 401: Secret allein nicht prüfbar
        _, other = make_cert(self.t / '..' / self.t.name, 'anders') if False else make_cert(self.t, 'localhost2')
        with self.assertRaises(self.vb.Fehler):
            self.vb.test_t2med({**c['t2med'], 'ca_pem': other.read_text(), 'pin': False})   # fremde CA: TLS-Fehler
        with self.assertRaisesRegex(self.vb.Fehler, 'nicht erreichbar'):
            self.vb.test_t2med({**c['t2med'], 'ca_pem': ''})                                # ohne CA: unbekanntes Zertifikat
        # Selbstsigniertes Zertifikat im Webinterface abrufen und nach Fingerabdruck-Prüfung fest übernehmen
        self.registry()
        self.request({'action': 't2med', 'daten': {'base_url': base, 'api_key': 'api-schluessel-1'}})
        with self.assertRaisesRegex(self.vb.Fehler, 'Verbindungen'):
            self.vb.test_t2med(json.loads((self.t / 'etc/konfiguration.json').read_text())['t2med'])
        s = self.request({'action': 'zertifikat-holen'})
        fp = s['zertifikate']['t2med']['fingerprint']
        self.assertEqual(fp, __import__('hashlib').sha256(ssl.PEM_cert_to_DER_cert(pem)).hexdigest())
        s = self.request({'action': 'zertifikat-uebernehmen', 'fingerprint': 'falsch'})
        self.assertNotIn('zertifikat', s['t2med'] and s['t2med']['zertifikat'] or '')
        s = self.request({'action': 'zertifikat-uebernehmen', 'fingerprint': fp})
        self.assertEqual((s['t2med']['zertifikat'], s['t2med']['pin'], s['zertifikate']), (fp, True, {}))
        self.assertIn('CapabilityStatement', self.vb.test_t2med(json.loads((self.t / 'etc/konfiguration.json').read_text())['t2med']))

    def test_mail_verbindung(self):
        calls = []

        class Smtp:
            def __init__(self, host, port, timeout=0, **kw): calls.append(('verbinden', host, port))
            def __enter__(self): return self
            def __exit__(self, *a): return False
            def starttls(self, context=None): calls.append(('starttls',))
            def login(self, u, p):
                calls.append(('login', u))
                if p != 'Mail-Passwort-1':
                    raise self.vb.smtplib.SMTPAuthenticationError(535, b'falsch')
        Smtp.vb = self.vb
        with mock.patch.object(self.vb.smtplib, 'SMTP', Smtp), mock.patch.object(self.vb.smtplib, 'SMTP_SSL', Smtp):
            self.assertIn('keine Nachricht', self.vb.test_mail(self.MAIL))
            self.assertEqual(calls, [('verbinden', 'smtp.example.org', 587), ('starttls',), ('login', 'praxis@example.org')])
            with self.assertRaisesRegex(self.vb.Fehler, 'fehlgeschlagen'):
                self.vb.test_mail({**self.MAIL, 'passwort': 'falsch'})

    def test_testnachricht(self):
        sent = []

        class Smtp:
            def __init__(self, *a, **kw): pass
            def __enter__(self): return self
            def __exit__(self, *a): return False
            def starttls(self, context=None): pass
            def login(self, u, p): pass
            def send_message(self, msg): sent.append(msg)
        self.registry()
        with mock.patch.object(self.vb.smtplib, 'SMTP', Smtp):
            self.request({'action': 'mail', 'daten': self.MAIL})
            s = self.request({'action': 'pruefen', 'art': 'testmail', 'an': 'it@example.org'})
            self.assertTrue(s['letzte']['ok'], s)
            self.assertEqual(sent[0]['To'], 'it@example.org')
            self.assertEqual(sent[0]['From'], self.MAIL['absender'])
            self.assertIn('Testnachricht', sent[0]['Subject'])
            for bad in ('kein-mail', 'a@example.org\nBcc: x@example.org', None):
                with self.subTest(bad):
                    self.assertFalse(self.request({'action': 'pruefen', 'art': 'testmail', 'an': bad})['letzte']['ok'])
            self.assertEqual(len(sent), 1)
            with self.assertRaises(self.vb.Fehler):
                self.vb.send_mail(None, ['it@example.org'], 'x', 'y')

    def test_anfragen_absichern(self):
        self.registry()
        s = self.request({'action': 'loeschen-alles'})
        self.assertFalse(s['letzte']['ok'])
        (self.t / 'anfragen' / 'verbindungen.json').write_text('kein json')
        self.vb.handle()
        os.environ['KIENZLE_VERB_UID'] = str(os.getuid() + 1)
        s = self.request({'action': 't2med', 'daten': self.T2MED})
        self.assertFalse(s['letzte']['ok'])
        self.assertIsNone(self.vb.load()['t2med'])
        (self.t / 'modules.json').chmod(0o666)
        os.environ['KIENZLE_VERB_UID'] = str(os.getuid())
        s = self.request({'action': 'verteilen'})
        self.assertIn('nicht vertrauenswürdig', s['letzte']['meldung'])


class Nachrichten(Verbindungen):
    """Nachrichten-Ausgang: nur angemeldete Module bekommen einen Ausgang, die Zugänge nur ks-nachrichten; der Dienst verschickt
    (hier mit Ersatz-Versand), schreibt Ergebnisse, wiederholt Fehlschläge 30 Minuten und begrenzt je Stunde."""

    def setUp(self):
        super().setUp()
        import kienzle.nachrichten
        self.na = importlib.reload(kienzle.nachrichten)
        self.registry(extra_connections=[{'id': 'nachrichten', 'purpose': 'SMS an Anrufer', 'required': False}])
        self.request({'action': 'sms', 'daten': {'api_key': 'SMS-Schluessel-1', 'absender': 'Praxis'}})
        self.request({'action': 'mail', 'daten': self.MAIL})
        self.aus = self.t / 'nachrichten' / 'zeit'

    def auftrag(self, name, daten):
        (self.aus / f'{name}.json').write_text(json.dumps(daten))

    def ergebnis(self, name):
        return json.loads((self.aus / f'{name}.ergebnis').read_text())

    def test_verteilen(self):
        zeit = json.loads((self.t / 'etc/module/zeit.json').read_text())
        self.assertEqual(zeit['nachrichten'], {'ausgang': str(self.aus), 'sms': True, 'mail': True})
        self.assertNotIn('SMS-Schluessel-1', json.dumps(zeit))
        self.assertNotIn('nachrichten', json.loads((self.t / 'etc/module/beispiel.json').read_text()))
        self.assertEqual(oct(self.aus.stat().st_mode & 0o7777), '0o2770')
        k = json.loads((self.t / 'etc/nachrichten.json').read_text())
        self.assertEqual((k['sms']['api_key'], k['mail']['passwort']), ('SMS-Schluessel-1', 'Mail-Passwort-1'))
        self.assertIn('zeit', k['module'])
        self.assertNotIn('beispiel', k['module'])                        # meldet „nachrichten“ nicht an
        self.assertEqual(oct((self.t / 'etc/nachrichten.json').stat().st_mode & 0o777), '0o640')
        self.assertFalse((self.t / 'nachrichten' / 'beispiel').exists())

    def test_abarbeiten(self):
        gesendet = []
        self.auftrag('20261126-0900-sms1', {'art': 'sms', 'an': '+491701234567', 'text': 'Ihr Rezept liegt bereit.'})
        self.auftrag('20261126-0900-mail', {'art': 'mail', 'an': 'fax@example.org', 'betreff': 'Neues Fax', 'text': 'Ein Fax ist eingegangen.'})
        self.auftrag('20261126-0900-falsch', {'art': 'sms', 'an': '0170 123', 'text': 'x'})
        self.auftrag('20261126-0900-steuer', {'art': 'mail', 'an': ['fax@example.org'], 'betreff': 'a\nBcc: x@example.org', 'text': 'x'})
        (self.aus / 'x.json').write_text('{}')                         # zu kurzer Name: kein Auftrag
        (self.t / 'nachrichten' / 'fremd').mkdir()
        (self.t / 'nachrichten' / 'fremd' / '20261126-0900-fremd.json').write_text(json.dumps({'art': 'sms', 'an': '+491701234567', 'text': 'x'}))
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            r = self.na.abarbeiten(send=lambda cfg, job: gesendet.append((job['art'], job['an'], cfg[job['art']]['absender'])))
        self.assertEqual(r, {'gesendet': 2, 'fehler': 2, 'wartet': 0})
        self.assertEqual(gesendet, [('mail', ['fax@example.org'], 'praxis@example.org'), ('sms', '+491701234567', 'Praxis')])
        self.assertEqual(self.ergebnis('20261126-0900-sms1')['stand'], 'gesendet')
        self.assertEqual(self.ergebnis('20261126-0900-falsch')['stand'], 'fehler')
        self.assertIn('Betreff', self.ergebnis('20261126-0900-steuer')['meldung'])
        self.assertEqual(sorted(p.name for p in self.aus.glob('*.json')), ['x.json'])              # ungültiger Name bleibt liegen
        self.assertTrue((self.t / 'nachrichten' / 'fremd' / '20261126-0900-fremd.json').exists())   # nicht angemeldetes Modul
        for geheim in ('+491701234567', 'fax@example.org', 'Rezept'):
            self.assertNotIn(geheim, buf.getvalue())

    def test_wiederholen_und_grenze(self):
        def kaputt(cfg, job):
            raise self.na.Fehler('seven.io nicht erreichbar')
        self.auftrag('20261126-0900-sms1', {'art': 'sms', 'an': '+491701234567', 'text': 'Test'})
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(self.na.abarbeiten(send=kaputt)['wartet'], 1)
            self.assertTrue((self.aus / '20261126-0900-sms1.json').exists())
            self.assertEqual(self.na.abarbeiten(now=time.time() + 31 * 60, send=kaputt)['fehler'], 1)
        self.assertEqual(self.ergebnis('20261126-0900-sms1'), {**self.ergebnis('20261126-0900-sms1'), 'stand': 'fehler', 'meldung': 'seven.io nicht erreichbar'})
        for i in range(self.na.GRENZE['sms'] + 1):
            self.auftrag(f'20261126-1000-{i:04d}', {'art': 'sms', 'an': '+491701234567', 'text': 'Test'})
        with contextlib.redirect_stdout(io.StringIO()):
            r = self.na.abarbeiten(send=lambda cfg, job: None)
        self.assertEqual((r['gesendet'], r['wartet']), (self.na.GRENZE['sms'], 1))

    def test_ohne_sms_zugang(self):
        self.request({'action': 'sms', 'loeschen': True})
        self.assertFalse(json.loads((self.t / 'etc/module/zeit.json').read_text())['nachrichten']['sms'])
        self.auftrag('20261126-0900-sms1', {'art': 'sms', 'an': '+491701234567', 'text': 'Test'})
        with contextlib.redirect_stdout(io.StringIO()):
            self.na.abarbeiten(send=lambda cfg, job: self.fail('darf nicht senden'))
        self.assertIn('nicht eingerichtet', self.ergebnis('20261126-0900-sms1')['meldung'])


if __name__ == '__main__':
    unittest.main()
