"""Anrufername (lib/anrufername.agi) und Anrufstatistik (lib/telefon-ereignisse) ohne Asterisk. Erfundene Daten."""
import importlib.machinery
import importlib.util
import io
import json
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
LIB = HERE.parent / 'lib'


def lade(name, datei):
    loader = importlib.machinery.SourceFileLoader(name, str(LIB / datei))
    spec = importlib.util.spec_from_loader(name, loader)
    mod = importlib.util.module_from_spec(spec)
    loader.exec_module(mod)
    return mod


agi = lade('anrufername', 'anrufername.agi')
ereignisse = lade('telefon_ereignisse', 'telefon-ereignisse')


def verzeichnis(daten: Path, ortsvorwahl='030', anrufername=True):
    d = daten / 't2med'
    d.mkdir(parents=True)
    (d / 'einstellungen.json').write_text(json.dumps({'ortsvorwahl': ortsvorwahl, 'anrufername': anrufername}))
    con = sqlite3.connect(d / 'patienten.sqlite')
    con.executescript('''CREATE TABLE patienten (rid INTEGER PRIMARY KEY, id TEXT, nummer TEXT, vorname TEXT, nachname TEXT, geburt TEXT, suchname TEXT);
        CREATE TABLE telefone (rid INTEGER, original TEXT, schluessel TEXT);''')
    con.execute("INSERT INTO patienten VALUES (1, 'p1', '4711', 'Max', 'Mustermann', '1970-01-01', 'max mustermann')")
    con.execute("INSERT INTO patienten VALUES (2, 'p2', '4712', 'Maria', 'Mustermann', '', 'maria mustermann')")
    con.execute("INSERT INTO patienten VALUES (3, 'p3', '4713', 'Erika \"X\"', 'Beispiel', '', 'erika beispiel')")
    con.executemany('INSERT INTO telefone VALUES (?, ?, ?)', [(1, '0170 1234567', '491701234567'), (1, '030 1234567', '49301234567'),
                                                              (2, '030 1234567', '49301234567'), (3, '040 9876543', '49409876543')])
    con.commit()
    con.close()


class Nummern(unittest.TestCase):
    def test_gleich_wie_php(self):
        for roh, vw, soll in json.loads((HERE / 'nummern.json').read_text()):
            self.assertEqual(agi.schluessel(roh, vw), soll, roh)


class Anrufername(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        verzeichnis(self.t)

    def test_name(self):
        self.assertEqual(agi.name_fuer(self.t, '+491701234567'), 'Mustermann, Max')
        self.assertIsNone(agi.name_fuer(self.t, '0301234567'), 'zwei Patienten: kein Name')
        self.assertIsNone(agi.name_fuer(self.t, 'anonymous'))
        self.assertEqual(agi.name_fuer(self.t, '0409876543'), 'Beispiel, Erika X', 'Anführungszeichen entfernt')

    def test_aus(self):
        (self.t / 't2med' / 'einstellungen.json').write_text(json.dumps({'anrufername': False}))
        self.assertIsNone(agi.name_fuer(self.t, '+491701234567'))

    def test_agi_protokoll(self):
        r = subprocess.run([sys.executable, '-B', str(LIB / 'anrufername.agi'), str(self.t)], input='agi_callerid: 01701234567\nagi_channel: Local/x\n\n200 result=1\n',
                           capture_output=True, text=True, timeout=10)
        self.assertEqual(r.stdout, 'SET VARIABLE "CALLERID(name)" "Mustermann, Max"\n')
        r = subprocess.run([sys.executable, '-B', str(LIB / 'anrufername.agi'), str(self.t / 'fehlt')],
                           input='agi_callerid: 01701234567\n\n', capture_output=True, text=True, timeout=10)
        self.assertEqual((r.returncode, r.stdout), (0, ''), 'ohne Verzeichnis nichts tun')


class Statistik(unittest.TestCase):
    def test_ereignisse(self):
        t = Path(tempfile.mkdtemp())
        con = ereignisse.db(t / 'statistik.sqlite')
        cid = '0123abcd-1234-4abc-8def-0123456789ab'
        from datetime import datetime
        from zoneinfo import ZoneInfo
        jetzt = int(datetime(2026, 11, 26, 10, 0, tzinfo=ZoneInfo('Europe/Berlin')).timestamp())
        ereignisse.verarbeiten(con, {'event': 'UserEvent', 'userevent': 'KienzlefonCallEntered', 'callid': cid, 'enteredat': str(jetzt - 5)}, jetzt)
        ereignisse.verarbeiten(con, {'event': 'UserEvent', 'userevent': 'KienzlefonCallRoute', 'callid': cid, 'route': 'prescription'}, jetzt)
        ereignisse.verarbeiten(con, {'event': 'UserEvent', 'userevent': 'KienzlefonCallRoute', 'callid': 'kaputt', 'route': 'x'}, jetzt)
        ereignisse.verarbeiten(con, {'event': 'QueueCallerJoin', 'queue': 'praxis', 'linkedid': '1795.1', 'chanvariable(kf_stats_call_id)': cid,
                                     'calleridnum': '01701234567'}, jetzt)
        ereignisse.verarbeiten(con, {'event': 'AgentConnect', 'queue': 'praxis', 'linkedid': '1795.1', 'chanvariable(kf_stats_call_id)': cid, 'holdtime': '42'}, jetzt)
        # Anruf ohne Sprachmenü: Eintritt in die Warteschlange zählt, aufgelegt nach 90 s
        ereignisse.verarbeiten(con, {'event': 'QueueCallerJoin', 'queue': 'praxis', 'linkedid': '1795.2'}, jetzt)
        ereignisse.verarbeiten(con, {'event': 'QueueCallerAbandon', 'queue': 'praxis', 'linkedid': '1795.2', 'holdtime': '90'}, jetzt)
        ereignisse.verarbeiten(con, {'event': 'QueueCallerJoin', 'queue': 'fremd', 'linkedid': '1795.3'}, jetzt)
        con.commit()
        self.assertEqual(con.execute('SELECT id, tag, stunde, wochentag FROM anrufe ORDER BY id').fetchall(),
                         [(cid, '2026-11-26', 9, 4), ('q:1795.2', '2026-11-26', 10, 4)])
        self.assertEqual(sorted(con.execute('SELECT route FROM routen').fetchall()), [('prescription',), ('queue',)])
        self.assertEqual(sorted(con.execute('SELECT ergebnis, sekunden FROM warten').fetchall()), [('angenommen', 42), ('aufgelegt', 90)])
        roh = (t / 'statistik.sqlite').read_bytes()
        self.assertNotIn(b'01701234567', roh)
        ereignisse.aufraeumen(con, jetzt + 3 * 366 * 86400)
        con.commit()
        self.assertEqual(con.execute('SELECT COUNT(*) FROM anrufe').fetchone()[0], 0)

    def test_ami_lesen(self):
        class Sock:
            def __init__(self, daten):
                self.d = io.BytesIO(daten)

            def recv(self, n):
                return self.d.read(7)
        msgs = list(ereignisse.lesen(Sock(b'Response: Success\r\nMessage: ok\r\n\r\nEvent: UserEvent\r\nUserEvent: KienzlefonCallEntered\r\nCallId: x\r\n\r\n')))
        self.assertEqual(msgs, [{'response': 'Success', 'message': 'ok'}, {'event': 'UserEvent', 'userevent': 'KienzlefonCallEntered', 'callid': 'x'}])


if __name__ == '__main__':
    unittest.main()
