"""Ansagen erzeugen (lib/ansagen-auftrag, Auftrag „erzeugen“) mit nachgestelltem Piper und echtem ffmpeg: Kienzlefon 2.7 erzeugt
die Ansagen über die Startskripte des Moduls in den Ansagenordner des Moduls. Der echte Piper wird nur auf Wunsch geladen
(„Piper einrichten“, braucht Internet); hier liefert ein kleines Ersatzmodul „piper“ einen Ton."""
import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
MODUL = HERE.parent

FAKE_PIPER = '''import argparse, math, struct, wave
p = argparse.ArgumentParser()
p.add_argument("-m"); p.add_argument("--data-dir"); p.add_argument("--volume"); p.add_argument("--length-scale"); p.add_argument("--sentence-silence")
p.add_argument("-f"); p.add_argument("text", nargs="*")
a = p.parse_args()
with wave.open(a.f, "wb") as w:
    w.setnchannels(1); w.setsampwidth(2); w.setframerate(22050)
    w.writeframes(b"".join(struct.pack("<h", int(8000 * math.sin(2 * math.pi * 440 * i / 22050))) for i in range(22050)))
'''


@unittest.skipUnless(shutil.which('ffmpeg') and subprocess.run([sys.executable, '-c', 'import cryptography'], capture_output=True).returncode == 0,
                     'ffmpeg oder python3-cryptography fehlt')
class Ansagen(unittest.TestCase):
    def test_erzeugen_mit_ersatz_piper(self):
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t, True)
        fake = t / 'fake'
        (fake / 'piper').mkdir(parents=True)
        (fake / 'piper' / '__init__.py').write_text('')
        (fake / 'piper' / '__main__.py').write_text(FAKE_PIPER)
        venv = t / 'daten' / 'piper' / 'venv' / 'bin'
        venv.mkdir(parents=True)
        (venv / 'python').write_text(f'#!/bin/sh\nexec {sys.executable} "$@"\n')
        (venv / 'python').chmod(0o755)
        (t / 'daten' / 'piper' / 'bereit.json').write_text('{}')
        env = {'PATH': '/usr/bin:/bin', 'KIENZLE_DATEN': str(t / 'daten'), 'KIENZLE_PROGRAMM': str(MODUL), 'KIENZLE_ASTERISK': '/bin/false',
               'PYTHONPATH': str(fake), 'LANG': 'C.UTF-8'}
        cfg = {'leitungen': [{'id': 'l1', 'anbieter': 'andere', 'benutzer': '4930123456', 'passwort': 'nurtest', 'domain': 'sip.example.invalid'}],
               'telefon': {'leitung_ein': 'l1', 'nummer_ein': '4930123456'}, 'netz': {'lokal': '192.0.2.0/24'}, 'kienzlefon': {'aktiv': True, 'tts': 'piper'},
               'nebenstellen': [{'nummer': '201', 'name': 'Empfang', 'passwort': 'A' * 24}]}
        r = subprocess.run([sys.executable, '-B', str(MODUL / 'lib/anlage.py'), 'anwenden'], input=json.dumps(cfg), capture_output=True, text=True, env=env)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        (t / 'daten' / 'kienzlefon' / 'auftrag.json').write_text(json.dumps({'aktion': 'erzeugen'}))
        r = subprocess.run([sys.executable, '-B', str(MODUL / 'lib/ansagen-auftrag')], capture_output=True, text=True, env=env, timeout=900)
        status = json.loads((t / 'daten' / 'kienzlefon' / 'auftrag-status.json').read_text())
        self.assertEqual(status['stand'], 'fertig', status['meldung'] + r.stderr[-500:])
        self.assertFalse((t / 'daten' / 'kienzlefon' / 'auftrag.json').exists())
        ansagen = t / 'daten' / 'kienzlefon' / 'ansagen'
        self.assertTrue(list(ansagen.glob('greeting_closed.*')), sorted(p.name for p in ansagen.iterdir())[:10])
        self.assertTrue(list(ansagen.glob('*.g722')))

    def test_unbekannter_auftrag_und_entfernen(self):
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t, True)
        kzf = t / 'kienzlefon'
        (kzf / 'piper-voices').mkdir(parents=True)
        (kzf / 'piper-voices' / 'de_DE-thorsten-high.onnx').write_text('x')
        (t / 'piper' / 'venv').mkdir(parents=True)
        env = {'PATH': '/usr/bin:/bin', 'KIENZLE_DATEN': str(t)}
        (kzf / 'auftrag.json').write_text('{"aktion": "rm -rf"}')
        subprocess.run([sys.executable, '-B', str(MODUL / 'lib/ansagen-auftrag')], env=env)
        self.assertEqual(json.loads((kzf / 'auftrag-status.json').read_text())['stand'], 'fehler')
        (kzf / 'auftrag.json').write_text('{"aktion": "piper-entfernen"}')
        subprocess.run([sys.executable, '-B', str(MODUL / 'lib/ansagen-auftrag')], env=env)
        self.assertEqual(json.loads((kzf / 'auftrag-status.json').read_text())['stand'], 'fertig')
        self.assertFalse((t / 'piper').exists())
        self.assertFalse((kzf / 'piper-voices' / 'de_DE-thorsten-high.onnx').exists())


class Kokoro(unittest.TestCase):
    """lib/kokoro-einrichten ruft den Installer von Kienzlefon mit Ziel im Datenordner und ohne root-Prüfung auf (Ersatz-Installer)."""

    def test_aufrufer(self):
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t, True)
        fake = t / 'install.py'
        fake.write_text('import json, os\nfrom pathlib import Path\nINSTALL_ROOT = Path("/opt/kienzlefon/kokoro")\n'
                        'def install(config):\n    if os.geteuid() != 0:\n        raise RuntimeError("root")\n'
                        '    Path(os.environ["ERGEBNIS"]).write_text(json.dumps([str(INSTALL_ROOT), str(config)]))\n')
        env = {'PATH': '/usr/bin:/bin', 'KIENZLE_DATEN': str(t / 'daten'), 'KIENZLE_KOKORO_INSTALLER': str(fake), 'ERGEBNIS': str(t / 'ergebnis.json')}
        r = subprocess.run([sys.executable, '-B', str(MODUL / 'lib/kokoro-einrichten')], env=env, capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(json.loads((t / 'ergebnis.json').read_text()), [str(t / 'daten' / 'kokoro'), str(t / 'daten' / 'kienzlefon' / 'kienzlefon.toml')])

    def test_entfernen(self):
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t, True)
        for d in ('kokoro/venv', 'kienzlefon/kokoro', 'kienzlefon/kokoro-thorsten'):
            (t / d).mkdir(parents=True)
        (t / 'kienzlefon' / 'auftrag.json').write_text('{"aktion": "kokoro-entfernen"}')
        subprocess.run([sys.executable, '-B', str(MODUL / 'lib/ansagen-auftrag')], env={'PATH': '/usr/bin:/bin', 'KIENZLE_DATEN': str(t)})
        self.assertEqual(json.loads((t / 'kienzlefon' / 'auftrag-status.json').read_text())['stand'], 'fertig')
        self.assertFalse((t / 'kokoro').exists() or (t / 'kienzlefon' / 'kokoro').exists() or (t / 'kienzlefon' / 'kokoro-thorsten').exists())


class Whisper(unittest.TestCase):
    """Whisper wird nur auf Knopfdruck geladen (Internet); hier: Paketliste, Entfernen und das Startskript des Workers."""

    def test_paketliste(self):
        zeilen = [z for z in (MODUL / 'lib/whisper/requirements-cp314.lock').read_text().splitlines() if z and not z.startswith('#')]
        namen = {z.split('==')[0].lower() for z in zeilen}
        self.assertTrue({'faster-whisper', 'ctranslate2', 'av', 'tokenizers', 'huggingface_hub'} <= namen)
        for z in zeilen:
            self.assertRegex(z, r'^[A-Za-z0-9_.-]+==[0-9][A-Za-z0-9.]* --hash=sha256:[0-9a-f]{64}$')

    def test_entfernen(self):
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t, True)
        for d in ('whisper/venv', 'kienzlefon/modelle/x'):
            (t / d).mkdir(parents=True)
        (t / 'whisper' / 'bereit.json').write_text('{}')
        (t / 'kienzlefon' / 'auftrag.json').write_text('{"aktion": "whisper-entfernen"}')
        subprocess.run([sys.executable, '-B', str(MODUL / 'lib/ansagen-auftrag')], env={'PATH': '/usr/bin:/bin', 'KIENZLE_DATEN': str(t)})
        st = json.loads((t / 'kienzlefon' / 'auftrag-status.json').read_text())
        self.assertEqual(st['stand'], 'fertig', st)
        self.assertFalse((t / 'whisper').exists() or (t / 'kienzlefon' / 'modelle').exists())

    def test_worker_startet_mit_whisper_umgebung(self):
        """Ist <daten>/whisper/venv da, startet das Startskript den Worker mit deren Python (hier ein Ersatz, der nur seine Argumente meldet)."""
        t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, t, True)
        py = t / 'whisper' / 'venv' / 'bin' / 'python'
        py.parent.mkdir(parents=True)
        py.write_text('#!/bin/sh\necho "ersatz $KIENZLE_WHISPER_UMGEBUNG $*"\n')
        py.chmod(0o755)
        r = subprocess.run([sys.executable, '-B', str(MODUL / 'lib/kzf-bin/kienzlefon-worker'), '--config', 'x.toml'],
                           env={'PATH': '/usr/bin:/bin', 'KIENZLE_DATEN': str(t)}, capture_output=True, text=True)
        self.assertEqual(r.stdout.strip(), f'ersatz 1 -B {MODUL}/lib/kzf-bin/kienzlefon-worker --config x.toml')


if __name__ == '__main__':
    unittest.main()
