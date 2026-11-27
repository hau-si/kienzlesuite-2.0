"""Durchgehender Faxtest mit echtem Asterisk (nur wenn installiert, sonst übersprungen): Auftrag im Austauschordner →
Fax-Worker aus Kienzlefax → AMI → fax-out → SendFAX → lokale Schleife → fax-in → ReceiveFAX → fax-empfangen → PDF.

Die Leitung zeigt auf sip.example.invalid (löst nie auf); der Faxanruf geht nicht hinaus, sondern über Local/s@fax-in.
Nur erfundene Daten."""
import json
import os
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
MODUL = HERE.parent
ASTERISK = '/usr/sbin/asterisk'
NEEDED = [ASTERISK, '/usr/bin/gs', '/usr/bin/qpdf', '/usr/bin/tiff2pdf', '/usr/bin/tiffinfo']


def port_free(port: int) -> bool:
    with socket.socket() as s:
        return s.connect_ex(('127.0.0.1', port)) != 0


def have_python_modules() -> bool:
    return subprocess.run(['/usr/bin/python3', '-c', 'import reportlab, pypdf'], capture_output=True).returncode == 0


@unittest.skipUnless(all(Path(p).exists() for p in NEEDED) and have_python_modules() and port_free(5038),
                     'Asterisk, Ghostscript, qpdf, libtiff-tools oder reportlab/pypdf fehlen (oder Port 5038 belegt)')
class FaxSchleife(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        self.daten = self.t / 'daten'
        self.aus = self.t / 'austausch'
        self.daten.mkdir()
        self.aus.mkdir()
        self.env = {**os.environ, 'KIENZLE_DATEN': str(self.daten), 'KIENZLE_PROGRAMM': str(MODUL), 'KFX_BASE': str(self.aus),
                    'KIENZLE_AUSTAUSCH': str(self.aus), 'KFX_POST_CALL_COOLDOWN_SEC': '1'}
        self.procs = []

    def tearDown(self):
        for p in self.procs:
            if p.poll() is None:
                p.send_signal(signal.SIGTERM)
                try:
                    p.wait(10)
                except subprocess.TimeoutExpired:
                    p.kill()
        shutil.rmtree(self.t, ignore_errors=True)

    def rx(self, cmd: str) -> str:
        return subprocess.run([ASTERISK, '-C', str(self.daten / 'asterisk/etc/asterisk.conf'), '-rx', cmd], capture_output=True, text=True, timeout=20).stdout

    def test_fax_hin_und_zurueck(self):
        cfg = {'leitungen': [{'id': 'l1', 'name': 'Test', 'anbieter': 'andere', 'benutzer': '4930123459', 'passwort': 'nurtest', 'domain': 'sip.example.invalid'}],
               'fax': {'leitung': 'l1', 'nummer': '4930123459', 'name': 'Praxis Beispiel'}, 'netz': {'lokal': '192.0.2.0/24'},
               'praxis': 'Praxis Dr. med. Erika Beispiel'}
        r = subprocess.run(['/usr/bin/python3', '-B', str(MODUL / 'lib/anlage.py'), 'anwenden'], input=json.dumps(cfg), capture_output=True, text=True, env=self.env)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        ext = self.daten / 'asterisk/etc/extensions.conf'
        text = ext.read_text()
        self.assertIn('Dial(PJSIP/${EXTEN}@kfx-leitung-l1,60,gU(', text)
        ext.write_text(text.replace('Dial(PJSIP/${EXTEN}@kfx-leitung-l1,60,gU(', 'Dial(Local/s@fax-in/n,60,gU('))   # Schleife statt Anbieter
        self.procs.append(subprocess.Popen([ASTERISK, '-f', '-n', '-C', str(self.daten / 'asterisk/etc/asterisk.conf')], env=self.env,
                                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        for _ in range(40):
            time.sleep(0.5)
            if 'Asterisk' in self.rx('core show version'):
                break
        self.assertIn('Running', self.rx('module show like res_fax_spandsp'))
        self.assertIn('Running', self.rx('module show like res_agi'))
        self.assertIn('kienzle-fax', self.rx('manager show users'))
        job = self.aus / 'queue' / '20260101-120000-test01'
        job.mkdir(parents=True)
        subprocess.run(['/usr/bin/python3', '-c', 'import sys\nfrom reportlab.pdfgen import canvas\nc = canvas.Canvas(sys.argv[1])\n'
                        'c.drawString(72, 700, "Befundanforderung (erfundenes Beispiel)")\nc.showPage()\nc.save()', str(job / 'doc.pdf')], check=True)
        shutil.copy(job / 'doc.pdf', job / 'source.pdf')
        (job / 'job.json').write_text(json.dumps({'job_id': job.name, 'created_at': '2026-01-01T12:00:00+01:00', 'status': 'queued',
                                                  'source': {'src': 'vFax1', 'filename_original': 'befund.pdf'},
                                                  'recipient': {'name': 'Dr. Erika Beispiel', 'number': '030123499'}, 'options': {'ecm': True, 'resolution': 'fine'}}))
        self.procs.append(subprocess.Popen(['/usr/bin/python3', '-B', str(MODUL / 'lib/kfx/fax-dienst')], env=self.env,
                                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        deadline = time.time() + 120
        while time.time() < deadline and not list((self.aus / 'sendeberichte').glob('*.json')):
            time.sleep(1)
        berichte = list((self.aus / 'sendeberichte').glob('*.json'))
        self.assertEqual(len(berichte), 1, 'kein Sendebericht nach 2 Minuten')
        j = json.loads(berichte[0].read_text())
        self.assertEqual((j['status'], j['result']['faxstatus']), ('OK', 'SUCCESS'))
        self.assertTrue(list((self.aus / 'sendeberichte').glob('*__OK.pdf')))
        self.assertTrue(list((self.aus / 'sendeberichte' / 'eingang').glob('*.pdf')), 'Original für erneutes Senden fehlt in sendeberichte/eingang')
        for _ in range(20):
            if list((self.aus / 'empfang').glob('*.json')):
                break
            time.sleep(0.5)
        meta = json.loads(next((self.aus / 'empfang').glob('*.json')).read_text())
        self.assertEqual((meta['status'], meta['seiten']), ('SUCCESS', 1))
        self.assertTrue((self.aus / 'empfang' / f'{meta["name"]}.pdf').stat().st_size > 0)
        self.assertFalse(list((self.daten / 'asterisk/spool/fax-empfang').glob('*.tif')))
        self.rx('core stop now')


if __name__ == '__main__':
    unittest.main()
