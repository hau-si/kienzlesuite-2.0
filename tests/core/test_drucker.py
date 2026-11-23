"""Tests für die Druckerverwaltung (core/lib/kienzle/drucker.py) mit nachgebautem CUPS. Nur erfundene Adressen."""
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
FAKE = ROOT / 'tests' / 'core' / 'fake_cups.py'


class Umgebung(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.t)
        (self.t / 'bin').mkdir()
        for p in ('lpadmin', 'lpstat', 'lpinfo', 'lp', 'cancel', 'testparm', 'systemctl'):
            (self.t / 'bin' / p).symlink_to(FAKE)
        env = {'FAKE_CUPS': self.t / 'cups.json', 'KIENZLE_DRUCKER_STATE': self.t / 'state', 'KIENZLE_DRUCKER_REQUEST': self.t / 'anfrage.json',
               'KIENZLE_DRUCKER_MODULES': self.t / 'modules.json', 'KIENZLE_DRUCKER_PROGRAMME': self.t / 'opt', 'KIENZLE_DRUCKER_BACKENDS': self.t / 'backend',
               'KIENZLE_DRUCKER_PPDS': self.t / 'ppd', 'KIENZLE_DRUCKER_SAMBA': self.t / 'kienzle-drucker.conf', 'KIENZLE_DRUCKER_UID': os.getuid(),
               'KIENZLE_DRUCKER_CHOWN': '0', 'KIENZLE_DRUCKER_PROBE': 'ja', 'KIENZLE_SMBCONTROL': self.t / 'fehlt',
               'KIENZLE_DRUCKER_SAMBA_NETZ': self.t / 'kienzle-netz.conf', 'KIENZLE_DRUCKER_UNITS': self.t / 'units', 'KIENZLE_DRUCKER_SYSTEMCTL': self.t / 'bin/systemctl',
               'KIENZLE_DRUCKER_DATA': self.t / 'data', 'KIENZLE_DRUCKER_WARTEN': '1', 'KIENZLE_SYSTEMD_RUN': self.t}
        env.update({f'KIENZLE_{p.upper()}': self.t / 'bin' / p for p in ('lpadmin', 'lpstat', 'lpinfo', 'lp', 'cancel', 'testparm')})
        p = mock.patch.dict(os.environ, {k: str(v) for k, v in env.items()})
        p.start()
        self.addCleanup(p.stop)
        import kienzle.drucker
        self.d = importlib.reload(kienzle.drucker)

    def cups(self):
        return json.loads((self.t / 'cups.json').read_text())

    def module(self, mid='beispiel', printers=None):
        src = self.t / 'opt' / mid / 'current' / 'printer'
        src.mkdir(parents=True, exist_ok=True)
        shutil.copy(ROOT / 'modules/beispiel/printer/beispiel.ppd', src / 'beispiel.ppd')
        shutil.copy(ROOT / 'modules/beispiel/printer/kienzle-beispiel-backend', src / 'backend')
        printers = printers if printers is not None else [{'name': 'Beispiel-Drucker', 'label': 'Virtueller Drucker', 'ppd': 'printer/beispiel.ppd',
                                                           'backend': 'printer/backend', 'share': ['samba', 'ipp']}]
        return {'module': {'id': mid}, 'printers': printers}

    def registry(self, *mods):
        (self.t / 'modules.json').write_text(json.dumps({'modules': list(mods)}))


class Praxisdrucker(Umgebung):
    def test_anlegen_und_entfernen(self):
        self.assertIn('angelegt', self.d.add('tm-m30iii', '', '192.0.2.80', []))
        self.assertIn('angelegt', self.d.add('office', 'A4-Empfang', '192.0.2.90', ['samba', 'ipp']))
        q = self.cups()['queues']
        self.assertEqual(q['TM-m30III']['uri'], 'socket://192.0.2.80:9100')
        self.assertIn('printer-is-shared=false', q['TM-m30III']['options'])
        self.assertEqual(q['A4-Empfang']['uri'], 'ipp://192.0.2.90/ipp/print')
        self.assertIn('media-default=iso_a4_210x297mm', q['A4-Empfang']['options'])
        call = next(c for c in self.cups()['calls'] if c[0] == 'lpadmin' and 'A4-Empfang' in c)
        self.assertEqual(call[call.index('-m') + 1], 'everywhere')
        names = {x['name']: x for x in self.d.queues()}
        self.assertTrue(names['TM-m30III']['managed'] and names['A4-Empfang']['share'] == ['ipp', 'samba'])
        self.d.samba()
        conf = (self.t / 'kienzle-drucker.conf').read_text()
        self.assertIn('[A4-Empfang]', conf)
        self.assertNotIn('[TM-m30III]', conf)
        self.assertIn('entfernt', self.d.remove('A4-Empfang'))
        self.d.samba()
        self.assertNotIn('A4-Empfang', (self.t / 'kienzle-drucker.conf').read_text())

    def test_eingaben(self):
        for kind, name, addr in (('egal', '', '192.0.2.80'), ('bon', 'mit Leerzeichen', '192.0.2.80'), ('bon', 'x' * 41, '192.0.2.80'),
                                 ('bon', '', 'kein host!'), ('bon', '', 'file:///etc/passwd'), ('bon', '', 'socket://192.0.2.80:9100;rm'),
                                 ('bon', '-p', '192.0.2.80')):
            with self.subTest((kind, name, addr)), self.assertRaises(self.d.Fehler):
                self.d.add(kind, name, addr, [])
        with self.assertRaises(self.d.Fehler):
            self.d.add('bon', '', '192.0.2.80', ['nfs'])
        os.environ['KIENZLE_DRUCKER_PROBE'] = 'nein'
        with self.assertRaisesRegex(self.d.Fehler, 'antwortet kein Bondrucker'):
            self.d.add('bon', '', '192.0.2.1', [])

    def test_fremde_drucker_bleiben(self):
        (self.t / 'cups.json').write_text(json.dumps({'queues': {'Fremd': {'uri': 'ipp://192.0.2.7/ipp', 'desc': 'Von Hand', 'options': []}}, 'jobs': [], 'calls': []}))
        with self.assertRaisesRegex(self.d.Fehler, 'nicht von der Suite'):
            self.d.remove('Fremd')
        with self.assertRaisesRegex(self.d.Fehler, 'bereits'):
            self.d.add('bon', 'fremd', '192.0.2.80', [])
        with self.assertRaises(self.d.Fehler):
            self.d.set_share('Fremd', ['samba'])

    def test_suche_testseite_fehler(self):
        found = self.d.discover()
        self.assertEqual({f['kind'] for f in found[:3]}, {'tm-m30iii', 'tm-m10', 'office'})
        self.assertEqual(len(found), 3)
        self.assertNotIn('bad', json.dumps(found))
        self.assertIn('EPSON TM-m30III', [f['name'] for f in found])
        self.d.add('bon', 'Bon', '192.0.2.80', [])
        self.d.add('office', 'A4', '192.0.2.90', [])
        self.assertIn('Testseite', self.d.test_page('Bon'))
        self.assertIn('Testseite', self.d.test_page('A4'))
        jobs = self.cups()['jobs']
        self.assertIn('raw', jobs[0]['args'])
        self.assertNotIn('raw', jobs[1]['args'])
        os.environ['FAKE_CUPS_FAIL'] = 'Kaputt'
        # „Unable to query printer: Connection refused“ kommt vom Drucker, nicht von CUPS.
        with self.assertRaisesRegex(self.d.Fehler, 'IPP Everywhere'):
            self.d.add('office', 'Kaputt', '192.0.2.91', [])
        self.assertIn('IP-Adresse', self.d.explain('lpadmin: Unable to connect to Drucker-MFC.local:631: Temporary failure in name resolution'))
        self.assertIn('CUPS', self.d.explain('lpadmin: Unable to connect to server: Connection refused'))
        self.assertIn('CUPS', self.d.explain('lpstat: scheduler is not running'))
        self.assertIn('IPP Everywhere', self.d.explain('lpadmin: Unable to connect to printer; please try again in a few moments.'))
        self.assertEqual(self.d.print_job('A4', b'%PDF-1.4', 'Rechnung <42>', []), 'A4-3')


class Virtuell(Umgebung):
    def test_abgleich(self):
        self.registry(self.module())
        notes = self.d.sync()
        self.assertIn('Beispiel-Drucker: angelegt bzw. aktualisiert.', notes)
        backend = self.t / 'backend' / 'kienzle-beispiel-beispiel-drucker'
        self.assertEqual(stat.S_IMODE(backend.stat().st_mode), 0o755)
        self.assertEqual(stat.S_IMODE((self.t / 'ppd' / 'kienzle-beispiel-beispiel-drucker.ppd').stat().st_mode), 0o644)
        q = self.cups()['queues']['Beispiel-Drucker']
        self.assertEqual(q['uri'], 'kienzle-beispiel-beispiel-drucker:/Beispiel-Drucker')
        self.assertTrue(q['desc'].startswith('Kienzle-Suite Modul beispiel:'))
        self.assertIn('printer-is-shared=true', q['options'])
        self.assertIn('[Beispiel-Drucker]', (self.t / 'kienzle-drucker.conf').read_text())
        calls = len(self.cups()['calls'])
        self.assertEqual(self.d.sync(), [], 'zweiter Abgleich ändert etwas')
        self.assertFalse([c for c in self.cups()['calls'][calls:] if c[0] == 'lpadmin'])
        with self.assertRaisesRegex(self.d.Fehler, 'Modul'):
            self.d.remove('Beispiel-Drucker')
        # Modul entfernt: Warteschlange, Backend, PPD und Samba-Freigabe verschwinden.
        self.registry()
        self.assertIn('Beispiel-Drucker: entfernt (Modul beispiel nicht mehr installiert).', self.d.sync())
        self.assertNotIn('Beispiel-Drucker', self.cups()['queues'])
        self.assertFalse(backend.exists())
        self.assertNotIn('Beispiel', (self.t / 'kienzle-drucker.conf').read_text())

    def test_fremder_name_und_symlink(self):
        (self.t / 'cups.json').write_text(json.dumps({'queues': {'Beispiel-Drucker': {'uri': 'ipp://192.0.2.7/x', 'desc': 'Von Hand', 'options': []}}, 'jobs': [], 'calls': []}))
        self.registry(self.module())
        self.assertIn('fremden Drucker', ' '.join(self.d.sync()))
        self.assertEqual(self.cups()['queues']['Beispiel-Drucker']['uri'], 'ipp://192.0.2.7/x')
        m = self.module('fax', [{'name': 'Fax', 'label': 'Fax', 'ppd': 'printer/beispiel.ppd', 'backend': 'printer/link', 'share': []}])
        (self.t / 'opt/fax/current/printer/link').symlink_to('/etc/passwd')
        self.registry(m)
        self.assertIn('keine reguläre Datei', ' '.join(self.d.sync()))
        self.assertNotIn('Fax', self.cups()['queues'])


class Auftraege(Umgebung):
    def test_anzeigen_und_abbrechen(self):
        self.d.add('bon', 'Bon', '192.0.2.80', [])
        self.d.add('office', 'A4', '192.0.2.90', [])
        for q in ('Bon', 'A4', 'A4'):
            self.d.print_job(q, b'x' * 10, 'Rechnung', [])
        jobs = self.d.jobs()
        self.assertEqual([(j['id'], j['drucker'], j['groesse']) for j in jobs], [('Bon-1', 'Bon', 10), ('A4-2', 'A4', 10), ('A4-3', 'A4', 10)])
        self.assertIn('abgebrochen', self.d.cancel('Bon-1'))
        with self.assertRaises(self.d.Fehler):
            self.d.cancel('Bon-1')
        for bad in ('-a', 'A4-2; rm', '../x-1'):
            with self.subTest(bad), self.assertRaises(self.d.Fehler):
                self.d.cancel(bad)
        self.d.cancel_all('A4')
        self.assertEqual(self.d.jobs(), [])


class WindowsNetz(Umgebung):
    def test_arbeitsgruppe(self):
        self.assertEqual(self.d.network()['arbeitsgruppe'], 'WORKGROUP')
        self.assertIn('PRAXIS', self.d.set_workgroup('praxis'))
        self.assertIn('workgroup = PRAXIS\n   server role = standalone server', (self.t / 'kienzle-netz.conf').read_text())
        for bad in ('', 'mit leer', 'x' * 16, 'A\nsecurity = user'):
            with self.subTest(bad), self.assertRaises(self.d.Fehler):
                self.d.set_workgroup(bad)


class IppEverywhere(Umgebung):
    def printer(self):
        m = self.module()
        cmd = self.t / 'opt/beispiel/current/printer/druck'
        cmd.write_text('#!/bin/sh\nexit 0\n')
        cmd.chmod(0o755)
        m['printers'] = [{'name': 'Beispiel-PDF', 'label': 'PDF', 'command': 'printer/druck', 'formats': ['application/pdf'], 'share': ['ipp']}]
        return m

    def test_anlegen_und_entfernen(self):
        self.registry(self.printer())
        with mock.patch.object(self.d, '_port_open', side_effect=lambda port: port == 47100 and (self.t / 'units').exists()):
            notes = self.d.sync()
        self.assertIn('Beispiel-PDF: angelegt bzw. aktualisiert.', notes)
        unit = (self.t / 'units' / 'kienzle-drucker-beispiel-beispiel-pdf.service').read_text()
        for part in ('User=ks-beispiel', '-p 47100 -n localhost -r off', f'-c {self.t}/opt/beispiel/current/printer/druck', '-f application/pdf',
                     'NoNewPrivileges=yes', f'ReadWritePaths={self.t}/data/beispiel'):
            self.assertIn(part, unit)
        q = self.cups()['queues']['Beispiel-PDF']
        self.assertEqual(q['uri'], 'ipp://localhost:47100/ipp/print')
        call = next(c for c in self.cups()['calls'] if c[0] == 'lpadmin' and 'Beispiel-PDF' in c)
        self.assertEqual(call[call.index('-m') + 1], 'everywhere')
        self.assertIn(['restart', 'kienzle-drucker-beispiel-beispiel-pdf.service'], self.cups()['systemctl'])
        with mock.patch.object(self.d, '_port_open', return_value=True):
            self.assertEqual(self.d.sync(), [], 'zweiter Abgleich ändert etwas')
        self.registry()
        self.d.sync()
        self.assertNotIn('Beispiel-PDF', self.cups()['queues'])
        self.assertFalse((self.t / 'units' / 'kienzle-drucker-beispiel-beispiel-pdf.service').exists())
        self.assertIn(['disable', '--now', 'kienzle-drucker-beispiel-beispiel-pdf.service'], self.cups()['systemctl'])

    def test_antwortet_nicht(self):
        self.registry(self.printer())
        with mock.patch.object(self.d, '_port_open', return_value=False):
            self.assertIn('antwortet nicht', ' '.join(self.d.sync()))
        self.assertNotIn('Beispiel-PDF', self.cups().get('queues', {}))

    def test_ohne_backend(self):
        m = self.module()
        m['printers'][0].pop('backend')
        self.registry(m)
        self.d.sync()
        self.assertEqual(self.cups()['queues']['Beispiel-Drucker']['uri'], 'kienzle-leer:/Beispiel-Drucker')
        self.assertFalse((self.t / 'backend' / 'kienzle-beispiel-beispiel-drucker').exists())


class Anfragen(Umgebung):
    def request(self, data):
        (self.t / 'anfrage.json').write_text(json.dumps(data) if not isinstance(data, str) else data)
        self.d.handle()
        return json.loads((self.t / 'state' / 'status.json').read_text())

    def test_ablauf(self):
        s = self.request({'action': 'anlegen', 'art': 'bon', 'name': 'Bon', 'adresse': '192.0.2.80', 'freigabe': ['samba']})
        self.assertTrue(s['letzte']['ok'], s)
        self.assertEqual([q['name'] for q in s['warteschlangen']], ['Bon'])
        self.assertEqual(len(self.request({'action': 'suchen'})['gefunden']), 3)
        self.assertTrue(self.request({'action': 'freigabe', 'name': 'Bon', 'freigabe': []})['letzte']['ok'])
        self.assertNotIn('[Bon]', (self.t / 'kienzle-drucker.conf').read_text())
        self.assertTrue(self.request({'action': 'testseite', 'name': 'Bon'})['letzte']['ok'])
        for bad in ({'action': 'entfernen', 'name': '../x'}, {'action': 'loeschen'}, 'kein json', {'action': 'freigabe', 'name': 'Bon', 'freigabe': 'samba'}):
            with self.subTest(bad):
                self.assertFalse(self.request(bad)['letzte']['ok'])
        os.environ['KIENZLE_DRUCKER_UID'] = str(os.getuid() + 1)
        self.assertFalse(self.request({'action': 'entfernen', 'name': 'Bon'})['letzte']['ok'])
        self.assertIn('Bon', self.cups()['queues'])


if __name__ == '__main__':
    unittest.main()
