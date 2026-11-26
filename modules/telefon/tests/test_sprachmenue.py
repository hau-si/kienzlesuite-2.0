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


@unittest.skipUnless(ok(), 'Asterisk oder python3-cryptography fehlt (oder Port 5038 belegt)')
class Rufgruppen(unittest.TestCase):
    """Rufgruppen mit dem Steuerungsdienst von Kienzlefon: Er trägt die Telefone über AMI in die Warteschlange ein; ein Telefon
    lässt sich live abmelden; die Standards gelten nach jedem Neustart der Anlage."""

    def test_steuerung(self):
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t, True)
        env = {'PATH': '/usr/bin:/bin', 'KIENZLE_DATEN': str(t), 'KIENZLE_PROGRAMM': str(MODUL), 'KIENZLE_ASTERISK': '/bin/false', 'LANG': 'C.UTF-8'}
        cfg = {'leitungen': [{'id': 'l1', 'name': 'Test', 'anbieter': 'andere', 'benutzer': '4930123456', 'passwort': 'nurtest', 'domain': 'sip.example.invalid'}],
               'telefon': {'leitung_ein': 'l1', 'nummer_ein': '4930123456'}, 'netz': {'lokal': '192.0.2.0/24'},
               'nebenstellen': [{'nummer': n, 'name': f'Telefon {n}', 'passwort': 'A' * 24, 'warteschlange': True} for n in ('201', '202', '203')],
               'kienzlefon': {'aktiv': True, 'tts': 'piper', 'rufgruppen': True}}

        def anlage(aktion, eingabe=None):
            r = subprocess.run(['/usr/bin/python3', '-B', str(MODUL / 'lib/anlage.py'), aktion], input=json.dumps(eingabe or {}), capture_output=True, text=True, env=env)
            self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
            return json.loads(r.stdout)
        anlage('anwenden', cfg)
        etc = t / 'asterisk' / 'etc'
        self.assertIn('[kienzlefon-control]', (etc / 'manager_kienzlefon_control.conf').read_text())
        self.assertIn('Mitgliedschaft verwaltet durch Kienzlefon Control API', (etc / 'queues-kienzlefon.conf').read_text())
        self.assertEqual(oct((t / 'kienzlefon' / 'control-ami.json').stat().st_mode & 0o777), '0o600')
        conf = str(etc / 'asterisk.conf')
        p = subprocess.Popen([ASTERISK, '-f', '-n', '-C', conf], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(lambda: (p.poll() is None and p.send_signal(signal.SIGTERM), p.wait(10)))

        def rx(cmd):
            return subprocess.run([ASTERISK, '-C', conf, '-rx', cmd], capture_output=True, text=True, timeout=20).stdout
        for _ in range(40):
            time.sleep(0.5)
            if 'Asterisk' in rx('core show version'):
                break
        dienst = subprocess.Popen([str(MODUL / 'lib/kzf-bin/kienzlefon-control'), '--config', str(t / 'kienzlefon/kienzlefon.toml')], env=env,
                                  stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True)
        self.addCleanup(lambda: (dienst.poll() is None and dienst.terminate(), dienst.wait(10)))
        bestaetigt = t / 'kienzlefon' / 'control' / 'members-confirmed.json'
        for _ in range(60):
            time.sleep(0.5)
            if bestaetigt.exists():
                break
        self.assertTrue(bestaetigt.exists(), 'Der Steuerungsdienst hat die Rufgruppen nicht bestätigt.')
        queue = rx('queue show praxis')
        for n in ('201', '202', '203'):
            self.assertIn(f'PJSIP/{n}', queue)
        d = anlage('rufgruppen-lesen')
        self.assertTrue(d['bereit'] and d['bestaetigt'])
        self.assertEqual([x['nummer'] for x in d['telefone']], ['201', '202', '203', '299'])   # 299 = rotes Telefon von Kienzlefon
        self.assertTrue(all(x['normal']['jetzt'] and x['normal']['standard'] for x in d['telefone'][:3]))
        anlage('rufgruppen-schalten', {'revision': d['revision'], 'gruppe': 'normal', 'nummer': '202', 'an': False})
        self.assertNotIn('PJSIP/202', rx('queue show praxis'))
        d = anlage('rufgruppen-lesen')
        self.assertFalse(d['telefone'][1]['normal']['jetzt'])
        self.assertTrue(d['telefone'][1]['normal']['standard'])               # Standard bleibt: nach dem Neustart klingelt 202 wieder
        # Veralteter Stand wird abgelehnt.
        r = subprocess.run(['/usr/bin/python3', '-B', str(MODUL / 'lib/anlage.py'), 'rufgruppen-schalten'],
                           input=json.dumps({'revision': 'r1:alt:1', 'gruppe': 'normal', 'nummer': '202', 'an': True}), capture_output=True, text=True, env=env)
        self.assertEqual(r.returncode, 1)
        # Standard ändern: 203 klingelt nach Neustarts nicht mehr in Normal; der Dienst übernimmt das sofort.
        anlage('rufgruppen-speichern', {'stand': d['stand'], 'telefone': {'203': {'normal': {'standard': False, 'zugelassen': True, 'stufe': 2}}}})
        for _ in range(20):
            time.sleep(0.5)
            if 'PJSIP/203' not in rx('queue show praxis'):
                break
        self.assertNotIn('PJSIP/203', rx('queue show praxis'))
        self.assertIn('PJSIP/201', rx('queue show praxis'))
        d = anlage('rufgruppen-lesen')
        self.assertFalse(d['telefone'][2]['normal']['standard'])
        # Neu anwenden (z. B. nach einer Änderung der Anlage) behält die Rufgruppen.
        anlage('anwenden', cfg)
        self.assertFalse(anlage('rufgruppen-lesen')['telefone'][2]['normal']['standard'])
        # Neustart von Asterisk: die Warteschlange ist danach leer, der Dienst trägt die Standardtelefone wieder ein.
        rx('core restart now')
        for _ in range(60):
            time.sleep(0.5)
            if 'PJSIP/201' in rx('queue show praxis'):
                break
        queue = rx('queue show praxis')
        self.assertIn('PJSIP/201', queue)
        self.assertNotIn('PJSIP/203', queue)
        self.assertIsNone(dienst.poll(), 'Der Steuerungsdienst ist beim Neustart von Asterisk ausgestiegen.')
        rx('core stop now')


@unittest.skipUnless(ok(), 'Asterisk oder python3-cryptography fehlt (oder Port 5038 belegt)')
class Anrufe(unittest.TestCase):
    """Seite „Anrufe“: Der Zugang kienzle-web liest den Live-Stand (ein Anrufer in der Warteschlange) und darf wählen."""

    def test_live_und_waehlen(self):
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t, True)
        env = {'PATH': '/usr/bin:/bin', 'KIENZLE_DATEN': str(t), 'KIENZLE_PROGRAMM': str(MODUL), 'KIENZLE_ASTERISK': '/bin/false', 'LANG': 'C.UTF-8'}
        cfg = {'leitungen': [{'id': 'l1', 'name': 'Test', 'anbieter': 'andere', 'benutzer': '4930123456', 'passwort': 'nurtest', 'domain': 'sip.example.invalid'}],
               'telefon': {'leitung_ein': 'l1', 'nummer_ein': '4930123456', 'leitung_aus': 'l1'}, 'netz': {'lokal': '192.0.2.0/24'},
               'nebenstellen': [{'nummer': n, 'name': f'Telefon {n}', 'passwort': 'A' * 24, 'warteschlange': True} for n in ('201', '202')]}
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
        rx('channel originate Local/s@kfx-phone-in application Wait 30')
        php = ('require $argv[1] . "/src/bootstrap.php"; use KienzleTelefon\\{Anlage, Anrufe, Ami};'
               ' $cfg = ["nebenstellen" => [["nummer" => "201", "name" => "Empfang"], ["nummer" => "202", "name" => "Labor"]]];'
               ' for ($i = 0; $i < 20; $i++) { $s = Anrufe::stand($cfg); if ($s["warteschlange"]) break; usleep(500000); }'
               ' $o = Ami::verbinden()->aktion("Originate", ["Channel" => "PJSIP/201", "Context" => "kfx-phone-local", "Exten" => "202", "Priority" => 1, "Async" => "true"]);'
               ' echo json_encode(["stand" => $s, "originate" => $o[0]["response"] ?? ""]);')
        r = subprocess.run(['php', '-r', php, str(MODUL)], capture_output=True, text=True, env=env, timeout=60)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        d = json.loads(r.stdout)
        self.assertTrue(d['stand']['laeuft'], d)
        self.assertEqual(len(d['stand']['warteschlange']), 1, d)
        self.assertEqual([x['art'] for x in d['stand']['telefone']], ['aus', 'aus'])      # Telefone nicht angemeldet
        self.assertEqual(d['originate'], 'Success')
        self.assertIn('kienzle-web', rx('manager show user kienzle-web'))
        rx('core stop now')


if __name__ == '__main__':
    unittest.main()
