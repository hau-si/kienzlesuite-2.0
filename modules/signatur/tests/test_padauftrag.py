import importlib.util
import os
import sqlite3
import sys
import tempfile
import unittest

LIB = os.path.join(os.path.dirname(__file__), '..', 'lib')
sys.path.insert(0, LIB)
import padauftrag  # noqa: E402
import paddienst  # noqa: E402
import padseiten  # noqa: E402

SCHEMA = """
CREATE TABLE pads (id INTEGER PRIMARY KEY, name TEXT, arbeitsplatz TEXT DEFAULT '', adresse TEXT, port INTEGER DEFAULT 1002, aktiv INTEGER DEFAULT 1);
CREATE TABLE vorgaenge (id INTEGER PRIMARY KEY, titel TEXT, text TEXT, patient TEXT, status TEXT DEFAULT 'wartet', erstellt INTEGER DEFAULT 0,
  unterschrieben INTEGER DEFAULT 0, meldung TEXT DEFAULT '', pad_id INTEGER DEFAULT 0);
"""
H = padauftrag.EV_HOTSPOT


class Fake:
    """Ersatz für das Pad: spielt eine Folge von Tastendrücken ab (Namen der Schaltflächen)."""
    def __init__(self, tasten, unterschrieben=True):
        self.tasten, self.unterschrieben, self.log, self.ids, self.sicht = list(tasten), unterschrieben, [], {}, None

    def zeige(self, bild): self.log.append('zeige')
    def knoepfe(self, r):
        self.ids = {n: i + 1 for i, n in enumerate(r)}
        return self.ids
    def unterschrift_starten(self, feld): self.log.append('start')
    def unterschrift_neu(self): self.log.append('neu')
    def unterschrift_abbrechen(self): self.log.append('stop')
    def raeumen(self): self.log.append('raeumen')
    def danke(self, bild): self.log.append('danke')
    def bestaetigen(self, ausgabe, vid):
        self.log.append('ok')
        return True if self.unterschrieben else None
    def ereignis(self, t):
        if not self.tasten:
            return (padauftrag.EV_DISCONNECT, 0)
        t = self.tasten.pop(0)
        return (H, self.ids[t]) if t in self.ids else None


class AblaufTest(unittest.TestCase):
    def setUp(self):
        self.c = sqlite3.connect(':memory:')
        self.c.row_factory = sqlite3.Row
        self.c.executescript(SCHEMA)
        self.c.execute("INSERT INTO pads (id, name, adresse) VALUES (1, 'Pad Empfang', '192.0.2.10')")
        self.c.execute("INSERT INTO vorgaenge (id, titel, text, patient, status, pad_id) VALUES (1, 'IGeL-Vertrag', 'Text für Max Mustermann.', 'Max Mustermann', 'am_pad', 1)")
        self.c.commit()

    def lauf(self, tasten, **kw):
        v = self.c.execute('SELECT * FROM vorgaenge WHERE id=1').fetchone()
        f = Fake(tasten, **kw)
        return padauftrag.ablauf(self.c, v, f, '/tmp'), f

    def status(self):
        return tuple(self.c.execute('SELECT status, meldung FROM vorgaenge WHERE id=1').fetchone())

    def test_unterschrieben(self):
        erg, f = self.lauf(['Weiter', 'OK'])
        self.assertEqual(erg, 'unterschrieben')
        self.assertEqual(self.status(), ('unterschrieben', ''))
        self.assertIn('danke', f.log)
        self.assertGreater(self.c.execute('SELECT unterschrieben FROM vorgaenge WHERE id=1').fetchone()[0], 0)

    def test_zurueck_loeschen_und_leere_bestaetigung(self):
        erg, f = self.lauf(['Weiter', 'Zurück', 'Weiter', 'Löschen', 'OK'], unterschrieben=False)
        # ohne Unterschrift bleibt OK wirkungslos, am Ende trennt das Testgerät: Fehler, aber nie „unterschrieben“
        self.assertEqual(erg, 'fehler')
        self.assertEqual(self.status()[0], 'fehler')
        self.assertIn('stop', f.log)
        self.assertIn('neu', f.log)

    def test_ohne_unterschrift_meldung_an_der_liste(self):
        class Ohne(Fake):
            hinweis = 'Keine Unterschriftsdaten (Rückgabe -5, Größe 0): Testfehler'
        v = self.c.execute('SELECT * FROM vorgaenge WHERE id=1').fetchone()
        padauftrag.ablauf(self.c, v, Ohne(['Weiter', 'OK'], unterschrieben=False), '/tmp')
        # danach trennt das Testgerät (Fehler); die Meldung wurde vorher gesetzt, dann vom Fehler überschrieben
        self.assertEqual(self.status()[0], 'fehler')

    def test_abbrechen_am_pad(self):
        erg, f = self.lauf(['Abbrechen'])
        self.assertEqual(erg, 'zurueck')
        self.assertEqual(self.status(), ('wartet', 'Am Pad abgebrochen.'))
        self.assertIn('raeumen', f.log)

    def test_abbruch_von_der_seite_gewinnt(self):
        self.c.execute("UPDATE vorgaenge SET status='abgebrochen' WHERE id=1")
        self.c.commit()
        erg, f = self.lauf(['Weiter', 'OK'])
        self.assertEqual(erg, 'abgebrochen')
        self.assertEqual(self.status()[0], 'abgebrochen')

    def test_zeit_abgelaufen(self):
        v = self.c.execute('SELECT * FROM vorgaenge WHERE id=1').fetchone()
        t = [0.0]
        def uhr():
            t[0] += 400
            return t[0]
        class Still(Fake):
            def ereignis(self, w): return None
        erg = padauftrag.ablauf(self.c, v, Still([]), '/tmp', jetzt=uhr, leerlauf=600)
        self.assertEqual(erg, 'zurueck')
        self.assertIn('Zeit abgelaufen', self.status()[1])


class DienstTest(unittest.TestCase):
    def setUp(self):
        paddienst.LAUFEND.clear()
        self.c = sqlite3.connect(':memory:')
        self.c.row_factory = sqlite3.Row
        self.c.executescript(SCHEMA)
        self.c.execute("INSERT INTO pads (id, name, adresse) VALUES (1, 'A', '192.0.2.10'), (2, 'B', '192.0.2.11')")
        for i, (st, pad) in enumerate([('am_pad', 1), ('am_pad', 1), ('am_pad', 2), ('wartet', 2)], 1):
            self.c.execute("INSERT INTO vorgaenge (id, titel, text, patient, status, pad_id, erstellt) VALUES (?, 't', 'x', 'p', ?, ?, ?)", (i, st, pad, i))
        self.c.commit()

    def test_ein_prozess_je_pad(self):
        gestartet = []
        class P:
            returncode = None
            def poll(self): return None
        paddienst.einmal(self.c, lambda vid: (gestartet.append(vid), P())[1])
        self.assertEqual(sorted(gestartet), [1, 3])  # ältester je Pad
        paddienst.einmal(self.c, lambda vid: (gestartet.append(vid), P())[1])
        self.assertEqual(sorted(gestartet), [1, 3])  # läuft noch: nichts Neues

    def test_absturz_setzt_fehler(self):
        class P:
            returncode = 1
            def poll(self): return 1
        paddienst.LAUFEND[1] = (P(), 1)
        paddienst.einmal(self.c, lambda vid: type('Q', (), {'poll': lambda s: None, 'returncode': None})())
        self.assertEqual(self.c.execute('SELECT status FROM vorgaenge WHERE id=1').fetchone()[0], 'fehler')

    def test_start_setzt_zurueck(self):
        paddienst.beim_start(self.c)
        self.assertEqual(self.c.execute("SELECT COUNT(*) FROM vorgaenge WHERE status='am_pad'").fetchone()[0], 0)


class SeitenTest(unittest.TestCase):
    def test_seiten_und_knoepfe(self):
        s = padseiten.textseiten('IGeL-Vertrag', 'Wort ' * 2000)
        self.assertGreater(len(s), 1)
        self.assertEqual(list(s[0]['knoepfe']), ['Abbrechen', 'Weiter'])
        self.assertEqual(list(s[1]['knoepfe']), ['Abbrechen', 'Zurück', 'Weiter'])
        u = padseiten.unterschriftsseite('t', 'Max Mustermann')
        for r in [*u['knoepfe'].values(), u['feld']]:
            self.assertTrue(0 <= r[0] and r[0] + r[2] <= padseiten.BREITE and 0 <= r[1] and r[1] + r[3] <= padseiten.HOEHE)
        self.assertEqual(s[0]['bild'].size, (padseiten.BREITE, padseiten.HOEHE))

    def test_leerer_text(self):
        self.assertEqual(len(padseiten.textseiten('t', '')), 1)


if __name__ == '__main__':
    unittest.main()
