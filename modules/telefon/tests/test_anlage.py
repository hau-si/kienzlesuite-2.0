"""Tests für lib/anlage.py (Modul telefon): Prüfung, erzeugte Asterisk-Dateien, Auswertung von Asterisk-Ausgaben.
Nur erfundene Daten (Rufnummern aus dem Bereich 030 123…, Netze 192.0.2.0/24, example.org)."""
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
LIB = HERE.parent / 'lib' / 'anlage.py'


def load(data: Path):
    os.environ['KIENZLE_DATEN'] = str(data)
    os.environ['KIENZLE_ASTERISK'] = '/bin/false'
    os.environ['KIENZLE_PROGRAMM'] = '/opt/kienzle/telefon/current'
    spec = importlib.util.spec_from_file_location('anlage', LIB)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def beispiel() -> dict:
    return {
        'leitungen': [
            {'id': 'l1', 'name': '1und1 Fax', 'anbieter': '1und1', 'benutzer': '4930123459', 'passwort': 'geheim;mit\\zeichen', 'nummer': '4930123459'},
            {'id': 'l2', 'name': 'Telefon', 'anbieter': 'fonial', 'benutzer': 'u12345', 'passwort': 'xyz', 'nummer': '4930123456'},
            {'id': 'l3', 'name': 'Ueberlauf', 'anbieter': 'sipgate-trunk', 'benutzer': '1234567t0', 'passwort': 'abc'},
        ],
        'fax': {'leitung': 'l1', 'nummer': '+4930123459', 'kennung': '', 'name': 'Praxis Beispiel'},
        'telefon': {'leitung_ein': 'l2', 'nummer_ein': '4930123456', 'leitung_aus': '', 'kennung_aus': '', 'aus_zaehlt': True},
        'ueberlauf': {'leitung': 'l3', 'nummer': '4930123457'},
        'kanaele': {'gesamt': 4, 'telefon': 3, 'fax': 2, 'ueberlauf': 2},
        'warteschlange': {'max_wartend': 6},
        'nebenstellen': [{'nummer': '201', 'name': 'Empfang 1', 'passwort': 'A' * 24, 'warteschlange': True},
                         {'nummer': '202', 'name': 'Empfang 2', 'passwort': 'B' * 24, 'warteschlange': True},
                         {'nummer': '203', 'name': 'Labor', 'passwort': 'C' * 24, 'warteschlange': False}],
        'netz': {'lokal': '192.0.2.0/24', 'erlaubt': '', 'extern': ''},
        'praxis': 'Praxis Dr. med. Erika Beispiel',
    }


class Anlage(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.a = load(self.tmp)

    def tearDown(self):
        subprocess.run(['rm', '-r', '--', str(self.tmp)], check=False)

    def fehler(self, cfg: dict, text: str):
        with self.assertRaises(self.a.Fehler) as ctx:
            self.a.check(cfg)
        self.assertIn(text, str(ctx.exception))

    def test_pruefung_vereinheitlicht(self):
        c = self.a.check(beispiel())
        self.assertEqual(c['fax']['nummer'], '4930123459')            # führendes + entfernt
        self.assertEqual(c['fax']['kennung'], '4930123459')           # Vorgabe: Faxnummer
        self.assertEqual(c['telefon']['leitung_aus'], 'l2')           # Vorgabe: wie eingehend
        self.assertEqual(c['leitungen'][0]['domain'], 'sip.1und1.de')
        self.assertEqual(c['leitungen'][0]['identify'], '212.227.0.0/16')
        self.assertEqual(c['leitungen'][1]['proxy'], 'sbc.sip.plusnet.de')
        self.assertEqual(c['netz']['erlaubt'], '192.0.2.0/24')
        self.assertFalse(c['telefon']['ausland'])

    def test_pruefung_fehler(self):
        b = beispiel()
        b['telefon']['nummer_ein'] = b['fax']['nummer'].lstrip('+')
        self.fehler(b, 'verschieden')
        b = beispiel()
        b['nebenstellen'][0]['nummer'] = '112'
        self.fehler(b, 'Notrufe')
        b = beispiel()
        b['nebenstellen'][1]['nummer'] = '201'
        self.fehler(b, 'doppelt')
        b = beispiel()
        b['nebenstellen'][0]['passwort'] = 'kurz'
        self.fehler(b, 'zu schwach')
        b = beispiel()
        b['leitungen'][1]['nummer'] = ''
        self.fehler(b, 'braucht die Rufnummer')
        b = beispiel()
        b['ueberlauf']['leitung'] = 'l1'
        self.fehler(b, 'sipgate trunking')
        b = beispiel()
        b['fax']['name'] = 'Praxis; exten => x'
        self.fehler(b, 'Faxkennung')
        b = beispiel()
        b['leitungen'][0]['passwort'] = 'a\nb'
        self.fehler(b, 'Steuerzeichen')
        b = beispiel()
        b['kanaele']['telefon'] = 9
        self.fehler(b, 'Gesamtzahl')
        b = beispiel()
        b['netz']['lokal'] = ''
        self.fehler(b, 'Netz der Telefone')
        b = beispiel()
        b['fax']['leitung'] = 'l9'
        self.fehler(b, 'unbekannt')
        b = beispiel()
        b['leitungen'][0]['domain'] = 'sip.example.org\nx'
        self.fehler(b, 'ungültig')

    def test_pjsip(self):
        p = ''.join(self.a.render_pjsip(self.a.check(beispiel())))
        self.assertIn('[transport-udp]\ntype=transport\nprotocol=udp\nbind=0.0.0.0:5070\n', p)
        self.assertIn('[transport-kfx-phone]\ntype=transport\nprotocol=udp\nbind=0.0.0.0:5060\nlocal_net=192.0.2.0/24\n', p)
        self.assertIn('password=geheim\\;mit\\\\zeichen\n', p)                     # ; und \ maskiert
        self.assertIn('[kfx-leitung-l1]\ntype=endpoint\ntransport=transport-udp\ncontext=kfx-leitung-l1-in\ndisallow=all\nallow=alaw,ulaw\n', p)
        self.assertIn('contact_user=4930123456\nline=yes\nendpoint=kfx-leitung-l2\n', p)  # Fonial: Rufnummer als contact_user
        self.assertIn('outbound_proxy=sip:sbc.sip.plusnet.de\\;lr\n', p)   # wie Kienzlefax: ; maskiert
        self.assertIn('server_uri=sip:1234567t0@sipconnect.sipgate.de\n', p)
        self.assertIn('match=217.10.68.150\n', p)
        self.assertIn('deny=0.0.0.0/0.0.0.0\npermit=192.0.2.0/24\n', p)
        self.assertIn('callerid=Labor <203>\n', p)
        self.assertNotIn('transport-1und1-tls', p)

    def test_tls(self):
        b = beispiel()
        b['leitungen'][0]['anbieter'] = '1und1-tls'
        p = ''.join(self.a.render_pjsip(self.a.check(b)))
        self.assertIn('[transport-1und1-tls]\ntype=transport\nprotocol=tls\nbind=0.0.0.0:5061\n', p)
        self.assertIn('server_uri=sip:tls-sip.1und1.de:5061\\;transport=tls\n', p)
        self.assertIn('media_encryption=sdes\n', p)

    def test_dialplan(self):
        e = ''.join(self.a.render_extensions(self.a.check(beispiel())))
        self.assertIn('[kfx-leitung-l1-in]\nexten => _+X.,1,Goto(kfx-leitung-l1-in,${EXTEN:1},1)\nexten => 4930123459,1,Goto(fax-in,s,1)\n', e)
        self.assertIn('exten => _X.,1,Goto(fax-in,s,1)\n', e)            # nur ein Ziel auf der Leitung: jede Nummer
        self.assertIn('[kfx-leitung-l3-in]', e)
        self.assertIn('Goto(kfx-phone-overflow-in,s,1)', e)
        self.assertIn('GotoIf($[${KFX_PRIMARY_TOTAL_COUNT} >= 4]?full)', e)
        self.assertIn('GotoIf($[${KFX_PRIMARY_FAX_COUNT} >= 2]?full)', e)
        self.assertIn('GotoIf($[${KFX_QUEUE_WAITING} >= 6]?full)', e)
        self.assertIn('Dial(PJSIP/${EXTEN}@kfx-leitung-l1,60,gU(kfx_sendfax^${KFX_JOBID}^${KFX_FILE}))', e)
        self.assertIn('AGI(/opt/kienzle/telefon/current/lib/kfx/kfx_update_status.agi,${KFX_JOBID},dial_start)', e)
        self.assertIn('Set(CALLERID(name)=Praxis Beispiel)', e)
        self.assertIn('Dial(PJSIP/${EXTEN}@kfx-leitung-l2,120)', e)
        self.assertIn('exten => 112,1,Goto(kfx-phone-out,${EXTEN},1)', e)
        self.assertIn('exten => _00X.,1,NoOp(Auslandsgespraech gesperrt)', e)
        self.assertIn('exten => _0900X.,1,NoOp(Sonderrufnummer gesperrt)', e)
        self.assertIn('exten => 203,1,NoOp(Interner Anruf zu 203)', e)
        self.assertNotIn('Goto(00${EXTEN:1},1)', e)                       # kein voip2gsm
        b = beispiel()
        b['leitungen'][1]['anbieter'] = 'voip2gsm'
        b['telefon']['ausland'] = True
        b['telefon']['aus_zaehlt'] = False
        e = ''.join(self.a.render_extensions(self.a.check(b)))
        self.assertIn('exten => _+X.,1,Goto(00${EXTEN:1},1)', e)
        self.assertNotIn('Auslandsgespraech gesperrt', e)
        self.assertIn(' same => n,Goto(dial)\n', e)

    def test_nur_telefon_ohne_fax(self):
        b = beispiel()
        b['fax'] = {'leitung': ''}
        e = ''.join(self.a.render_extensions(self.a.check(b)))
        self.assertNotIn('[fax-out]', e)
        self.assertNotIn('[fax-in]', e)
        self.assertIn('[kfx-leitung-l1-in]\nexten => _+X.,1,Goto(kfx-leitung-l1-in,${EXTEN:1},1)\nexten => _X.,1,NoOp(Leitung ohne Ziel)', e)

    def test_gemeinsame_leitung_verteilt_nach_nummer(self):
        b = beispiel()
        b['telefon']['leitung_ein'] = 'l1'
        b['telefon']['nummer_ein'] = '4930123456'
        b['ueberlauf'] = {}
        e = ''.join(self.a.render_extensions(self.a.check(b)))
        block = e.split('[kfx-leitung-l1-in]')[1].split('\n\n')[0]
        self.assertIn('exten => 4930123459,1,Goto(fax-in,s,1)', block)
        self.assertIn('exten => 4930123456,1,Goto(kfx-phone-in,s,1)', block)
        self.assertNotIn('_X.', block)

    def test_warteschlange(self):
        q, r = self.a.render_queues(self.a.check(beispiel()))
        self.assertIn('member => PJSIP/201,0,Empfang 1,PJSIP/201,no\nmember => PJSIP/202,1,Empfang 2,PJSIP/202,no\n', q)
        self.assertNotIn('PJSIP/203', q)
        self.assertIn('maxlen=6\n', q)
        self.assertEqual(r.splitlines()[-1], 'penaltychange => 20,,,1')

    def test_grunddateien_und_anwenden(self):
        self.a.grundgeruest()
        etc = self.tmp / 'asterisk' / 'etc'
        conf = (etc / 'asterisk.conf').read_text()
        self.assertIn(f'astetcdir => {etc}\n', conf)
        self.assertIn('astdatadir => /usr/share/asterisk\n', conf)
        manager = (etc / 'manager.conf').read_text()
        self.assertIn('bindaddr=127.0.0.1\n', manager)
        self.assertIn('[kienzle-fax]\n', manager)
        secret = json.loads((self.tmp / 'ami.json').read_text())['secret']
        self.assertIn(f'secret={secret}\n', manager)
        self.a.grundgeruest()
        self.assertEqual(json.loads((self.tmp / 'ami.json').read_text())['secret'], secret)   # bleibt gleich
        self.assertEqual((etc / 'pjsip.conf').read_text(), '; Noch keine Leitung eingerichtet.\n')
        mods = (etc / 'modules.conf').read_text()
        self.assertIn('autoload=no\n', mods)
        self.assertIn('load => res_fax_spandsp.so\n', mods)
        self.assertNotIn('cdr_csv', mods)          # keine Anrufliste mit Rufnummern
        self.assertIn('messages.log => warning,error\n', (etc / 'logger.conf').read_text())
        r = self.a.anwenden(beispiel())
        self.assertEqual(sorted(r['geaendert']), sorted(['extensions.conf', 'extensions-kfx-telefonie.conf', 'pjsip.conf', 'pjsip-kfx-telefonie.conf',
                                                     'queuerules.conf', 'queuerules-kfx.conf', 'queues.conf', 'queues-kfx.conf',
                                                     'manager_kienzlefon_control.conf']))
        self.assertIn(f'#tryinclude "{etc}/extensions-kfx-telefonie.conf"', (etc / 'extensions.conf').read_text())   # /etc/asterisk ersetzt
        self.assertFalse(r['laeuft'])
        self.assertEqual(oct((etc / 'pjsip.conf').stat().st_mode & 0o777), '0o600')
        self.assertEqual((self.tmp / 'praxis.txt').read_text(), 'Praxis Dr. med. Erika Beispiel\n')
        self.assertTrue(json.loads((self.tmp / 'fax.json').read_text())['aktiv'])
        self.assertEqual(self.a.anwenden(beispiel())['geaendert'], [])
        b = beispiel()
        b['praxis'] = ''
        self.a.anwenden(b)
        self.assertEqual((self.tmp / 'praxis.txt').read_text(), 'Praxis Dr. med. Erika Beispiel\n')   # Haken ohne Praxisnamen

    def test_transportaenderung_erkannt(self):
        c = self.a.check(beispiel())
        p1 = ''.join(self.a.render_pjsip(c))
        c['nebenstellen'][0]['name'] = 'Empfang neu'
        self.assertEqual(self.a.transports(p1), self.a.transports(''.join(self.a.render_pjsip(c))))
        b = beispiel()
        b['leitungen'][0]['anbieter'] = '1und1-tls'
        self.assertNotEqual(self.a.transports(p1), self.a.transports(''.join(self.a.render_pjsip(self.a.check(b)))))

    def test_asterisk_ausgaben(self):
        reg = (' <Registration/ServerURI..............................>  <Auth....................>  <Status.......>\n'
               '==========================================================================================\n'
               ' kfx-leitung-l1-registration/sip:sip.1und1.de            kfx-leitung-l1-auth        Registered        (exp. 112s)\n'
               ' kfx-leitung-l2-registration/sip:sip.plusnet.de          kfx-leitung-l2-auth        Rejected\n')
        self.assertEqual(self.a.parse_registrations(reg), {'l1': 'Registered', 'l2': 'Rejected'})
        con = ('  Contact:  <Aor/ContactUri..........................> <Hash....> <Status> <RTT(ms)..>\n'
               '  Contact:  201/sip:201@192.0.2.21:5060;ob              4d6f3a2c1b Avail        12.345\n'
               '  Contact:  kfx-leitung-l1-aor/sip:sip.1und1.de         0a1b2c3d4e NonQual       nan\n')
        self.assertEqual(self.a.parse_contacts(con), {'201': 'Avail'})

    def test_pflege(self):
        log = self.tmp / 'asterisk' / 'log'
        log.mkdir(parents=True)
        (log / 'messages.log.alt').write_text('x')
        (log / 'messages.log').write_text('x')
        old = 1_000_000_000
        os.utime(log / 'messages.log.alt', (old, old))
        self.assertEqual(self.a.pflege(), 1)
        self.assertTrue((log / 'messages.log').exists())

    def test_aufruf_ueber_stdin(self):
        env = {**os.environ, 'KIENZLE_DATEN': str(self.tmp), 'KIENZLE_ASTERISK': '/bin/false'}
        r = subprocess.run([sys.executable, '-B', str(LIB), 'pruefen'], input=json.dumps(beispiel()), capture_output=True, text=True, env=env)
        self.assertEqual((r.returncode, json.loads(r.stdout)), (0, {'ok': True}))
        b = beispiel()
        b['nebenstellen'][0]['nummer'] = '110'
        r = subprocess.run([sys.executable, '-B', str(LIB), 'pruefen'], input=json.dumps(b), capture_output=True, text=True, env=env)
        self.assertEqual(r.returncode, 1)
        self.assertIn('Notrufe', json.loads(r.stdout)['fehler'])
        r = subprocess.run([sys.executable, '-B', str(LIB), 'status'], capture_output=True, text=True, env=env)
        self.assertEqual(json.loads(r.stdout), {'laeuft': False})


class KienzlefonBruecke(unittest.TestCase):
    """Sprachmenü: Kienzlefon 2.7 setzt auf die Dateien der Anlage auf; feste Pfade werden durch die des Moduls ersetzt."""

    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.a = load(self.tmp)
        os.environ['KIENZLE_PROGRAMM'] = str(HERE.parent)
        self.a.PROGRAM = HERE.parent
        self.a.KZF_SOURCE = HERE.parent / 'kienzlefon'
        self.a.KZF_BIN = HERE.parent / 'lib' / 'kzf-bin'

    def tearDown(self):
        subprocess.run(['rm', '-r', '--', str(self.tmp)], check=False)

    def cfg(self, aktiv=True, tts='piper'):
        b = beispiel()
        b['kienzlefon'] = {'aktiv': aktiv, 'tts': tts}
        return b

    def test_pruefung(self):
        b = self.cfg()
        b['telefon'] = {}
        with self.assertRaises(self.a.Fehler):
            self.a.check(b)
        with self.assertRaises(self.a.Fehler):
            self.a.check(self.cfg(tts='espeak'))
        self.assertEqual(self.a.check(beispiel())['kienzlefon'], {'aktiv': False, 'tts': 'piper', 'wartemusik': 'klingelzeichen', 'whisper_genau': False, 'rufgruppen': False,
                         'google': {'schluessel': '', 'stimme': 'de-DE-Chirp3-HD-Enceladus', 'tempo': 0.93}})
        b = self.cfg()
        b['kienzlefon']['wartemusik'] = 'radio'
        with self.assertRaises(self.a.Fehler):
            self.a.check(b)
        with self.assertRaises(self.a.Fehler) as e:
            self.a.check(self.cfg(tts='google'))
        self.assertIn('Google-Schlüssel', str(e.exception))
        for bad in ({'schluessel': 'kurz'}, {'schluessel': 'A' * 30, 'tempo': '3'}, {'schluessel': 'A' * 30, 'stimme': 'x; rm'}):
            b = self.cfg(tts='google')
            b['kienzlefon']['google'] = bad
            with self.assertRaises(self.a.Fehler):
                self.a.check(b)
        b = self.cfg()
        b['nebenstellen'][1]['nummer'] = '205'
        with self.assertRaises(self.a.Fehler) as e:
            self.a.check(b)
        self.assertIn('fortlaufend', str(e.exception))
        b = self.cfg()
        for x in b['nebenstellen']:
            x['warteschlange'] = False
        with self.assertRaises(self.a.Fehler) as e:
            self.a.check(b)
        self.assertIn('mindestens ein Telefon', str(e.exception))

    def test_toml_set(self):
        t = '[a]\n# Kommentar\nx = 1\n\n[b]\ny = "z"\n'
        t = self.a.toml_set(t, 'a', 'x', 5)
        t = self.a.toml_set(t, 'b', 'y', 'neu "q"')
        t = self.a.toml_set(t, 'c', 'w', True)
        self.assertEqual(t, '[a]\n# Kommentar\nx = 5\n\n[b]\ny = "neu \\"q\\""\n\n[c]\nw = true\n')

    def test_google_konfiguration(self):
        b = self.cfg(tts='google')
        b['kienzlefon']['google'] = {'schluessel': 'AIza' + 'x' * 35, 'stimme': 'de-DE-Chirp3-HD-Achernar', 'tempo': '1,1'}
        self.a.anwenden(b)
        conf = self.tmp / 'kienzlefon' / 'google-tts.conf'
        self.assertEqual(conf.read_text().splitlines()[1:], ['API_KEY=AIza' + 'x' * 35, 'VOICE=de-DE-Chirp3-HD-Achernar', 'LANG=de-DE', 'RATE=1.1'])
        self.assertEqual(oct(conf.stat().st_mode & 0o777), '0o600')
        toml = (self.tmp / 'kienzlefon' / 'kienzlefon.toml').read_text()
        self.assertIn('engine = "google"', toml)
        self.assertNotIn('AIza', toml)                      # Schlüssel nur in der Generator-Datei
        self.assertIn(f'google_generator = "{HERE.parent}/lib/kzf-bin/kienzlefon-google-tts-generate"', toml)
        b['kienzlefon'] = {'aktiv': True, 'tts': 'piper'}
        self.a.anwenden(b)
        self.assertFalse(conf.exists())                     # ohne Schlüssel keine Datei

    def test_sprachmenue_eingebunden(self):
        r = self.a.anwenden(self.cfg())
        etc = self.tmp / 'asterisk' / 'etc'
        self.assertIn('extensions-kienzlefon.conf', r['geaendert'])
        phone = (etc / 'extensions-kfx-telefonie.conf').read_text()
        self.assertIn('same => n(accepted),Goto(kienzlefon-entry,s,1)', phone)
        ivr = (etc / 'extensions-kienzlefon.conf').read_text()
        self.assertIn(f'AGI({HERE.parent}/lib/kzf-bin/kienzlefon-agi,--config,{self.tmp}/kienzlefon/kienzlefon.toml', ivr)
        for f in etc.iterdir():
            text = f.read_text()
            for bad in ('/opt/kienzlefon', '/etc/kienzlefon', '/etc/asterisk', 'telefon-kienzlefon-'):
                self.assertNotIn(bad, text, f'{bad} in {f.name}')
        self.assertIn(f'#include "{etc}/features-kienzlefon.conf"', (etc / 'features.conf').read_text())
        toml = (self.tmp / 'kienzlefon' / 'kienzlefon.toml').read_text()
        self.assertIn(f'ansagen = "{self.tmp}/kienzlefon/ansagen"', toml)
        self.assertIn('engine = "piper"', toml)
        self.assertIn('hauptausgang_endpoint = "kfx-leitung-l2"', toml)
        self.assertNotIn('CHANGE_ME_RED_PHONE_SECRET', toml)
        self.assertEqual(oct((self.tmp / 'kienzlefon' / 'kienzlefon.toml').stat().st_mode & 0o777), '0o600')
        # Zweiter Lauf: nichts ändert sich (das Passwort des roten Telefons bleibt).
        self.assertEqual(self.a.anwenden(self.cfg())['geaendert'], [])
        # Grundgerüst beim Start von Asterisk überschreibt die Einbindungen von Kienzlefon nicht.
        self.a.grundgeruest()
        self.assertIn('features-kienzlefon.conf', (etc / 'features.conf').read_text())
        # Ausschalten: Eingang wieder direkt in die Warteschlange.
        self.a.anwenden(self.cfg(aktiv=False))
        self.assertIn('same => n(accepted),Goto(kfx-phone-queue,s,1)', (etc / 'extensions-kfx-telefonie.conf').read_text())


    @unittest.skipUnless(shutil.which('ffmpeg') and shutil.which('ffprobe'), 'ffmpeg fehlt')
    def test_wartemusik(self):
        self.a.PLACHETKA = HERE.parent / 'medien' / 'johannes-plachetka-warteschlange.mp3'
        b = self.cfg()
        b['kienzlefon']['wartemusik'] = 'musik'
        self.a.anwenden(b)                                   # Musik gewählt, aber noch nicht umgewandelt → Klingelzeichen
        toml = (self.tmp / 'kienzlefon' / 'kienzlefon.toml').read_text()
        self.assertIn('installiert = false', toml)
        self.assertIn('modus = "klingelzeichen"', toml)
        r = self.a.wartemusik_einrichten({'quelle': 'plachetka'})
        self.assertEqual((r['bereit'], r['quelle'], r['dauer']), (True, 'plachetka', 41))
        moh = self.tmp / 'kienzlefon' / 'wartemusik'
        self.assertEqual(sorted(p.name for p in moh.iterdir()), ['quelle.json', 'wartemusik.alaw', 'wartemusik.g722', 'wartemusik.sln16', 'wartemusik.ulaw', 'wartemusik.wav16'])
        self.a.anwenden(b)
        etc = self.tmp / 'asterisk' / 'etc'
        toml = (self.tmp / 'kienzlefon' / 'kienzlefon.toml').read_text()
        self.assertIn('installiert = true', toml)
        self.assertIn('modus = "musik"', toml)
        self.assertIn(f'directory={moh}\n', (etc / 'musiconhold-kienzlefon.conf').read_text())
        self.assertIn('musicclass=kienzlefon-wartemusik', (etc / 'queues-kienzlefon.conf').read_text())
        for f in etc.iterdir():
            self.assertNotIn('/var/lib/asterisk/moh', f.read_text(), f.name)
        # Eigene Datei: unlesbare Daten werden abgelehnt und gelöscht; die bisherige Musik bleibt.
        up = self.tmp / 'kienzlefon' / 'wartemusik-upload'
        up.write_bytes(b'keine Musik' * 100)
        with self.assertRaises(self.a.Fehler):
            self.a.wartemusik_einrichten({'quelle': 'eigene'})
        self.assertFalse(up.exists())
        self.assertTrue(self.a.wartemusik_bereit())
        # Eigene Datei (5 s Ton als WAV) ersetzt die Musik.
        subprocess.run(['ffmpeg', '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=5', '-f', 'wav', str(up)], check=True)
        self.assertEqual(self.a.wartemusik_einrichten({'quelle': 'eigene'})['quelle'], 'eigene')
        self.assertFalse(up.exists())
        with self.assertRaises(self.a.Fehler):
            self.a.wartemusik_einrichten({'quelle': '../x'})
        self.assertFalse(self.a.wartemusik_entfernen()['bereit'])
        self.a.anwenden(b)
        self.assertIn('modus = "klingelzeichen"', (self.tmp / 'kienzlefon' / 'kienzlefon.toml').read_text())

    def test_vorgang_vom_spool_bis_zur_ablage(self):
        """Worker von Kienzlefon mit Ersatz-Whisper: Aufnahme im Spool → Vorgang verschlüsselt mit dem Schlüssel des Moduls, Ton gelöscht;
        die Pflege löscht später verarbeitete Anrufe und erledigte Vorgänge."""
        import base64
        import sys
        from cryptography.hazmat.primitives import serialization
        from cryptography.hazmat.primitives.asymmetric import padding, rsa
        from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
        self.a.anwenden(self.cfg())
        toml = (self.tmp / 'kienzlefon' / 'kienzlefon.toml').read_text()
        self.assertNotIn('audio_beilegen', toml)                            # ohne Eintrag behält Kienzlefon die Tonaufnahmen
        for zeile in ('demo = false', 'modell_namen = "large-v3-turbo"', f'ausgabeverzeichnis = "{self.tmp}/kienzlefon/vorgaenge"'):
            self.assertIn(zeile, toml)
        geheim = rsa.generate_private_key(public_exponent=65537, key_size=3072)
        (self.tmp / 'kienzlefon' / 'telepraxis-public.pem').write_bytes(geheim.public_key().public_bytes(
            serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo))
        sys.path.insert(0, str(HERE.parent / 'kienzlefon'))
        from kienzlefon.config import load_config
        from kienzlefon.models import CallType, FieldName
        from kienzlefon.spool import Spool, WorkingCall
        from kienzlefon.worker import Worker
        config = load_config(self.tmp / 'kienzlefon' / 'kienzlefon.toml')
        spool = Spool(config.paths.spool, config.practice.timezone)
        call = spool.create_call(CallType.PRESCRIPTION, '+4930123456', 'rezept')
        for field, name, index in ((FieldName.FIRST_NAME, 'vorname.wav', None), (FieldName.LAST_NAME, 'nachname.wav', None),
                                   (FieldName.MEDICATION, 'medikament-01.wav', 1)):
            call.begin_audio(field, name, index).write_bytes(b'RIFF' + b'0' * 100)
        queued = spool.finish_recording(call, 'abgeschlossen')
        claimed = spool.claim_next()

        class Ersatz:
            def transcribe(self, path, _field):
                return {'vorname.wav': 'Max.', 'nachname.wav': 'Mustermann.', 'medikament-01.wav': 'Ibuprofen 400.'}[path.name]
        Worker(config, transcriber=Ersatz()).process(WorkingCall(claimed.path, config.practice.timezone))
        ziel = self.tmp / 'kienzlefon' / 'vorgaenge' / f'{queued.call_id}.json.enc'
        w = json.loads(ziel.read_text())
        self.assertNotIn('Mustermann', ziel.read_text())
        key = geheim.decrypt(base64.b64decode(w['ek']), padding.PKCS1v15())
        d = Cipher(algorithms.AES(key), modes.CBC(base64.b64decode(w['iv']))).decryptor()
        roh = d.update(base64.b64decode(w['ct'])) + d.finalize()
        payload = json.loads(roh[:-roh[-1]])['payload']
        self.assertEqual((payload['vorname'], payload['nachname'], payload['medikamente']), ('Max', 'Mustermann', 'Ibuprofen 400'))
        bereit = next((self.tmp / 'kienzlefon' / 'spool' / 'ready').iterdir())
        self.assertEqual(len(list((bereit / 'audio').glob('*.wav'))), 3)      # Tonaufnahmen bleiben zum Anhören
        # Pflege: verarbeitete Anrufe nach 2 Tagen, erledigte Vorgänge nach 7 Tagen.
        erledigt = self.tmp / 'kienzlefon' / 'vorgaenge' / 'erledigt'
        erledigt.mkdir()
        alt = erledigt / 'x.json.enc'
        alt.write_text('{}')
        neu = erledigt / 'y.json.enc'
        neu.write_text('{}')
        vor = time.time() - 8 * 86400
        os.utime(alt, (vor, vor))
        self.a.pflege(now=time.time() + 3 * 86400)                            # nach 3 Tagen: Abschrift weg, Ton bleibt
        self.assertFalse(alt.exists())
        self.assertTrue(neu.exists())
        self.assertFalse((bereit / 'call.json').exists())
        self.assertEqual(len(list((bereit / 'audio').glob('*.wav'))), 3)
        self.a.pflege(now=time.time() + 15 * 86400)                           # nach 14 Tagen: Ton weg
        self.assertFalse(bereit.exists())
        self.assertTrue(ziel.exists())

    def test_sprachmenue_seite_lesen_und_schreiben(self):
        self.assertEqual(self.a.sprachmenue_lesen(), {'ok': True, 'bereit': False})
        self.a.anwenden(self.cfg())
        d = self.a.sprachmenue_lesen()
        self.assertTrue(d['bereit'])
        self.assertEqual(set(d['zeiten']), {'oeffnungszeiten', 'telefonzeiten', 'apothekenzeiten', 'fachstellenzeiten'})
        namen = {x['name']: x for x in d['ansagen']}
        self.assertEqual(namen['greeting_open']['modus'], 'offen')
        self.assertEqual(namen['opening_hours_prefix']['modus_name'], 'opening_hours')
        self.assertEqual(d['tasten'][0]['tasten'][1], {'id': 'praxis', 'label': 'Praxiswarteschlange', 'taste': '1', 'standard': '1'})
        r = self.a.sprachmenue_schreiben({'stand': d['stand'], 'zeiten': {'oeffnungszeiten': {'montag': '08:00-12:00; 14:00 - 18:00'}},
                                          'ansagen': {'greeting_open': 'Guten Tag, hier ist die Praxis Beispiel.'},
                                          'modi': {'emergency': 'zu'}, 'tasten': {'rezept': '7'}})
        self.assertEqual(r, {'ok': True, 'geaendert': True})
        toml = (self.tmp / 'kienzlefon' / 'kienzlefon.toml').read_text()
        self.assertIn('montag = ["08:00-12:00", "14:00-18:00"]', toml)
        self.assertIn('greeting_open = "Guten Tag, hier ist die Praxis Beispiel."', toml)
        self.assertIn('emergency = "zu"', toml)
        self.assertIn('rezept = "7"', toml)
        # Neu Erzeugen der Anlage behält die Werte der Seite.
        self.a.anwenden(self.cfg())
        d2 = self.a.sprachmenue_lesen()
        self.assertEqual(d2['zeiten']['oeffnungszeiten']['tage']['montag'], '08:00-12:00, 14:00-18:00')
        self.assertTrue({x['name']: x for x in d2['ansagen']}['menu_open']['erzeugt'])   # Tasten geändert → Menütext wird erzeugt
        # Veralteter Stand, ungültige Zeit, doppelte Taste, leerer Pflichttext, fremder Schlüssel → Fehler, Datei unverändert.
        for job in ({'stand': d['stand']}, {'stand': d2['stand'], 'zeiten': {'telefonzeiten': {'montag': '25:00-26:00'}}},
                    {'stand': d2['stand'], 'tasten': {'termin': '7'}}, {'stand': d2['stand'], 'ansagen': {'greeting_open': ' '}},
                    {'stand': d2['stand'], 'ansagen': {'gibtsnicht': 'x'}}, {'stand': d2['stand'], 'pfade': {}},
                    {'stand': d2['stand'], 'modi': {'emergency': 'manchmal'}}):
            with self.assertRaises(self.a.Fehler, msg=str(job)):
                self.a.sprachmenue_schreiben(job)
        self.assertEqual(self.a.sprachmenue_lesen()['stand'], d2['stand'])
        self.assertFalse((self.tmp / 'kienzlefon' / '.kienzlefon.toml.neu').exists())

class FaxEmpfang(unittest.TestCase):
    """lib/kfx/fax-empfangen: TIFF → PDF im Austauschordner (tiff2pdf nachgestellt)."""

    def test_empfang(self):
        tmp = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-r', '--', str(tmp)], check=False)
        spool = tmp / 'daten' / 'asterisk' / 'spool' / 'fax-empfang'
        spool.mkdir(parents=True)
        (tmp / 'austausch').mkdir()
        fake = tmp / 'tiff2pdf'
        fake.write_text('#!/bin/sh\n# tiff2pdf -o ZIEL QUELLE\ncp "$3" "$2"\n')
        fake.chmod(0o755)
        name = '20260101-120000_4930999999_1767268800123'
        (spool / f'{name}.tif').write_bytes(b'II*\x00seiten')
        env = {**os.environ, 'KIENZLE_DATEN': str(tmp / 'daten'), 'KFX_BASE': str(tmp / 'austausch'), 'KFX_TIFF2PDF': str(fake)}
        script = HERE.parent / 'lib' / 'kfx' / 'fax-empfangen'
        r = subprocess.run([sys.executable, '-B', str(script), name, 'SUCCESS', '2'], env=env, capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)
        meta = json.loads((tmp / 'austausch' / 'empfang' / f'{name}.json').read_text())
        self.assertEqual((meta['absender'], meta['seiten'], meta['status']), ('4930999999', 2, 'SUCCESS'))
        self.assertTrue((tmp / 'austausch' / 'empfang' / f'{name}.pdf').is_file())
        self.assertFalse((spool / f'{name}.tif').exists())
        r = subprocess.run([sys.executable, '-B', str(script), '../x', 'SUCCESS', '1'], env=env, capture_output=True, text=True)
        self.assertEqual(r.returncode, 2)



class FaxkennungVorgabe(unittest.TestCase):
    def test_praxisname_als_vorgabe(self):
        with tempfile.TemporaryDirectory() as d:
            a = load(Path(d))
            self.assertEqual(a.kennung_aus_praxis('Praxis Dr. Müller & Partner Hausärzte'), 'Praxis Dr. Mueller & Partner Hausaerzte')
            self.assertEqual(a.kennung_kurz('Praxis Dr. Müller & Partner Hausärzte'), 'Praxis Dr. Mueller')
            cfg0 = beispiel()
            cfg0['fax']['name'] = 'Gemeinschaftspraxis Dr. Mueller und Partner'
            c0 = a.check(cfg0)
            self.assertEqual(c0['fax']['name'], 'Gemeinschaftspraxis Dr. Mueller und Partner')
            ext = a.render_extensions(c0)
            text = ext[0] + ext[1]
            self.assertIn('Set(CALLERID(name)=Gemeinschaftspraxis Dr. Mueller und Partner)', text)
            self.assertIn('Set(FAXOPT(headerinfo)=Gemeinschaftspraxis Dr. Mueller und Partner)', text)
            self.assertIn('Set(FAXOPT(localstationid)=Gemeinschaftspraxis)', text)
            self.assertEqual(a.kennung_aus_praxis(''), 'Fax')
            cfg = beispiel()
            cfg['fax']['name'] = ''
            cfg['praxis'] = 'Praxis Beispiel'
            self.assertEqual(a.check(cfg)['fax']['name'], 'Praxis Beispiel')
            cfg['fax']['name'] = 'Eigener Name'
            self.assertEqual(a.check(cfg)['fax']['name'], 'Eigener Name')
            cfg['fax']['name'] = ''
            cfg['praxis'] = ''
            self.assertEqual(a.check(cfg)['fax']['name'], 'Fax')

if __name__ == '__main__':
    unittest.main()
