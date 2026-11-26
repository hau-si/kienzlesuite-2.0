"""Durchgehender Test mit echtem Asterisk (nur wenn installiert): Ein Anruf in den Praxiseingang (kfx-phone-in) landet im
Sprachmenü von Kienzlefon 2.7; dessen AGI läuft mit der Konfiguration des Moduls und spielt Ansagen aus dem Ordner des Moduls.
Leitungen zeigen auf sip.example.invalid (keine echten Anbieter). Ansagen gibt es im Test nicht: Asterisk meldet die
fehlende Datei – genau daran erkennt der Test, dass das AGI gelaufen ist."""
import json
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


def ok() -> bool:
    if not Path(ASTERISK).exists():
        return False
    with socket.socket() as s:
        if s.connect_ex(('127.0.0.1', 5038)) == 0:
            return False
    return subprocess.run(['/usr/bin/python3', '-c', 'import cryptography'], capture_output=True).returncode == 0


@unittest.skipUnless(ok(), 'Asterisk oder python3-cryptography fehlt (oder Port 5038 belegt)')
class Sprachmenue(unittest.TestCase):
    def test_anruf_erreicht_das_sprachmenue(self):
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t, True)
        env = {'PATH': '/usr/bin:/bin', 'KIENZLE_DATEN': str(t), 'KIENZLE_PROGRAMM': str(MODUL), 'KIENZLE_ASTERISK': '/bin/false', 'LANG': 'C.UTF-8'}
        cfg = {'leitungen': [{'id': 'l1', 'name': 'Test', 'anbieter': 'andere', 'benutzer': '4930123456', 'passwort': 'nurtest', 'domain': 'sip.example.invalid'}],
               'telefon': {'leitung_ein': 'l1', 'nummer_ein': '4930123456'}, 'netz': {'lokal': '192.0.2.0/24'},
               'nebenstellen': [{'nummer': '201', 'name': 'Empfang', 'passwort': 'A' * 24}], 'kienzlefon': {'aktiv': True, 'tts': 'piper'}}
        r = subprocess.run(['/usr/bin/python3', '-B', str(MODUL / 'lib/anlage.py'), 'anwenden'], input=json.dumps(cfg), capture_output=True, text=True, env=env)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        conf = str(t / 'asterisk/etc/asterisk.conf')
        p = subprocess.Popen([ASTERISK, '-f', '-n', '-C', conf], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(lambda: (p.poll() is None and p.send_signal(signal.SIGTERM), p.wait(10)))

        def rx(cmd):
            return subprocess.run([ASTERISK, '-C', conf, '-rx', cmd], capture_output=True, text=True, timeout=20).stdout
        for _ in range(40):
            time.sleep(0.5)
            if 'Asterisk' in rx('core show version'):
                break
        self.assertIn('kienzlefon-entry', rx('dialplan show kienzlefon-entry'))
        log = t / 'asterisk/log/messages.log'
        rx('channel originate Local/s@kfx-phone-in application Wait 20')
        deadline = time.time() + 30
        while time.time() < deadline and f'{t}/kienzlefon/ansagen/' not in (log.read_text() if log.exists() else ''):
            time.sleep(0.5)
        self.assertIn(f'{t}/kienzlefon/ansagen/', log.read_text(), 'Das Sprachmenü hat keine Ansage aus dem Modul angefordert.')
        self.assertNotIn('ERROR', ''.join(line for line in log.read_text().splitlines(True) if 'res_pjsip_outbound_registration' not in line))
        rx('core stop now')


if __name__ == '__main__':
    unittest.main()
