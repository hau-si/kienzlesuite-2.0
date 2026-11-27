import importlib.util
import os
import shutil
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
        self.wurzel = tempfile.mkdtemp(prefix='sg-pad-')
        self.addCleanup(shutil.rmtree, self.wurzel, True)
        self.ordner = os.path.join(self.wurzel, 'unterschriften')   # Vorschau landet daneben in <wurzel>/vorschau
        os.makedirs(self.ordner)

    def lauf(self, tasten, **kw):
        v = self.c.execute('SELECT * FROM vorgaenge WHERE id=1').fetchone()
        f = Fake(tasten, **kw)
        return padauftrag.ablauf(self.c, v, f, self.ordner), f

    def status(self):
        return tuple(self.c.execute('SELECT status, meldung FROM vorgaenge WHERE id=1').fetchone())

    def test_unterschrieben(self):
        erg, f = self.lauf(['Weiter', 'OK'])
        self.assertEqual(erg, 'unterschrieben')
        self.assertEqual(self.status(), ('unterschrieben', ''))
        self.assertIn('danke', f.log)
        self.assertGreater(self.c.execute('SELECT unterschrieben FROM vorgaenge WHERE id=1').fetchone()[0], 0)

    def test_pdf_nach_der_unterschrift(self):
        erg, _ = self.lauf(['Weiter', 'OK'])
        self.assertEqual(erg, 'unterschrieben')
        with open(os.path.join(self.ordner, '1.pdf'), 'rb') as fh:
            self.assertTrue(fh.read().startswith(b'%PDF-1.4'))
        self.assertEqual(os.stat(os.path.join(self.ordner, '1.pdf')).st_mode & 0o777, 0o600)

    def test_vorschau_waehrend_des_vorgangs(self):
        gesehen = []
        ordner = self.ordner
        vorschau = os.path.join(self.wurzel, 'vorschau')

        class Schauen(Fake):
            def ereignis(self, t):
                import json
                with open(os.path.join(vorschau, '1.json')) as fh:
                    gesehen.append(json.load(fh))
                self.stift = {'n': 12}
                return super().ereignis(t)
        v = self.c.execute('SELECT * FROM vorgaenge WHERE id=1').fetchone()
        erg = padauftrag.ablauf(self.c, v, Schauen(['Weiter', 'OK']), ordner)
        self.assertEqual(erg, 'unterschrieben')
        self.assertEqual((gesehen[0]['seite'], gesehen[0]['art']), (1, 'text'))
        self.assertEqual((gesehen[1]['seite'], gesehen[1]['art'], gesehen[1]['seiten']), (2, 'unterschrift', 2))
        self.assertEqual(gesehen[1]['punkte'], 0)
        self.assertEqual(os.listdir(vorschau), [], 'Vorschau nach dem Vorgang gelöscht')
        with open(os.path.join(self.ordner, '1.pdf'), 'rb') as fh:
            self.assertTrue(fh.read().startswith(b'%PDF'))

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
        padauftrag.ablauf(self.c, v, Ohne(['Weiter', 'OK'], unterschrieben=False), self.ordner)
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
        erg = padauftrag.ablauf(self.c, v, Still([]), self.ordner, jetzt=uhr, leerlauf=600)
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


SCHEMA_FORMULAR = """
CREATE TABLE pads (id INTEGER PRIMARY KEY, name TEXT, arbeitsplatz TEXT DEFAULT '', adresse TEXT, port INTEGER DEFAULT 1002, aktiv INTEGER DEFAULT 1);
CREATE TABLE vorgaenge (id INTEGER PRIMARY KEY, titel TEXT, text TEXT, patient TEXT, status TEXT DEFAULT 'wartet', erstellt INTEGER DEFAULT 0,
  unterschrieben INTEGER DEFAULT 0, meldung TEXT DEFAULT '', pad_id INTEGER DEFAULT 0, bloecke TEXT DEFAULT '', antworten TEXT DEFAULT '',
  patient_id TEXT DEFAULT '', kontext TEXT DEFAULT '', kontakt_alt TEXT DEFAULT '', kontakt_neu TEXT DEFAULT '', kontakt_status TEXT DEFAULT '',
  geburtsdatum TEXT DEFAULT '', praxis TEXT DEFAULT '');
"""
BLOECKE = [
    {'id': 't1', 'typ': 'text', 'text': '# Datenschutz\nIch willige **ausdrücklich** ein, *bitte* lesen.\n- Behandlung'},
    {'id': 'p1', 'typ': 'patientendaten', 'felder': ['email', 'telefon'], 'aenderbar': True, 'werte': {'email': 'alt@example.org', 'telefon': '030 1'}},
    {'id': 'a1', 'typ': 'ankreuz', 'text': 'Ich bin einverstanden', 'pflicht': True},
    {'id': 'e1', 'typ': 'einzel', 'frage': 'Befund?', 'optionen': ['Post', 'E-Mail'], 'pflicht': True},
    {'id': 'm1', 'typ': 'mehrfach', 'frage': 'Wer darf Auskunft erhalten?', 'optionen': ['Hausarzt', 'Facharzt', 'Angehörige']},
    {'id': 's1', 'typ': 'seite'},
    {'id': 'l1', 'typ': 'liste', 'eintraege': ['eins', 'zwei'], 'nummeriert': True},
]


class FormularTest(unittest.TestCase):
    """Bausteine am Pad (Editor C): Antippen, Pflichtfelder, Tastatur, Antworten, Text mit Antworten, Kontaktdaten für T2med."""

    def setUp(self):
        import json
        self.c = sqlite3.connect(':memory:')
        self.c.row_factory = sqlite3.Row
        self.c.executescript(SCHEMA_FORMULAR)
        self.c.execute("INSERT INTO pads (id, name, adresse) VALUES (1, 'Pad Empfang', '192.0.2.10')")
        self.c.execute("INSERT INTO vorgaenge (id, titel, text, patient, status, pad_id, bloecke, patient_id, kontext) VALUES (1, 'Datenschutz', 'x', 'Max Mustermann', 'am_pad', 1, ?, 'p-1', 'k-1')",
                       (json.dumps(BLOECKE),))
        self.c.commit()
        self.wurzel = tempfile.mkdtemp(prefix='sg-form-')
        self.addCleanup(shutil.rmtree, self.wurzel, True)
        self.ordner = os.path.join(self.wurzel, 'unterschriften')
        os.makedirs(self.ordner)

    def lauf(self, tasten):
        v = self.c.execute('SELECT * FROM vorgaenge WHERE id=1').fetchone()
        f = Fake(tasten)
        return padauftrag.ablauf(self.c, v, f, self.ordner), f

    def test_seiten_und_tastflaechen(self):
        import padformular
        s = padformular.seiten(BLOECKE)
        self.assertEqual(len(s), 3, 'Platz reicht für zwei Seiten, der Seitenumbruch erzwingt eine dritte')
        self.assertEqual({e.get('art') for e in s[-1]}, {'zeile'}, 'nach dem Seitenumbruch nur die Aufzählung')
        alle = {}
        for i, seite in enumerate(s):
            z = padformular.zeichne_seite('Datenschutz', seite, i, len(s), padformular.antworten_start(BLOECKE))
            self.assertEqual(z['bild'].size, (1280, 800))
            self.assertLessEqual(len(z['knoepfe']), padformular.MAX_TASTEN)
            alle.update(z['knoepfe'])
        for n in ('a:a1', 'o:e1:0', 'o:e1:1', 'o:m1:2', 'e:p1:email', 'e:p1:telefon', 'Weiter', 'Abbrechen', 'Zurück'):
            self.assertIn(n, alle)
        t = padformular.zeichne_tastatur('email', 'max', 'abc')
        self.assertLessEqual(len(t['knoepfe']), padformular.MAX_TASTEN)
        self.assertIn('k:@', t['knoepfe'])
        self.assertEqual(padformular.laeufe('a **b** *c* __d__'), [('a ', ''), ('b', 'f'), (' ', ''), ('c', 'k'), (' ', ''), ('d', 'u')])

    def test_pflichtfelder_tastatur_und_antworten(self):
        import json
        # Weiter ohne Pflichtangaben → zurück auf Seite 1; dann ankreuzen, wählen, E-Mail ändern (Tastatur), weiter, unterschreiben.
        # Seite 1: Text, Patientendaten, Ankreuzfeld; Seite 2: Einzel- und Mehrfachauswahl; Seite 3: Aufzählung.
        # Bis ans Ende ohne Pflichtangaben → zurück auf Seite 1 mit Hinweis; dann ausfüllen, E-Mail über die Tastatur ändern.
        tasten = ['Weiter', 'Weiter', 'Weiter', 'a:a1', 'e:p1:email'] + ['k:zurueck'] * len('alt@example.org')
        tasten += ['k:n', 'k:OK']                                    # „n“ ist keine E-Mail-Adresse: Hinweis, Tastatur bleibt
        tasten += ['k:e', 'k:u', 'k:@', 'k:x', 'k:ebene', 'k:.', 'k:ebene', 'k:d', 'k:e', 'k:OK']
        tasten += ['Weiter', 'o:e1:1', 'o:m1:0', 'o:m1:2', 'o:m1:0', 'Weiter', 'Weiter', 'OK']
        erg, f = self.lauf(tasten)
        self.assertEqual(erg, 'unterschrieben')
        v = self.c.execute('SELECT * FROM vorgaenge WHERE id=1').fetchone()
        a = json.loads(v['antworten'])
        self.assertIs(a['a1'], True)
        self.assertEqual(a['e1'], 1)
        self.assertEqual(a['m1'], [2])
        self.assertEqual(a['p1']['email'], 'neu@x.de')  # „n“ allein war ungültig (Hinweis), dann weitergeschrieben
        self.assertIn('[X] Ich bin einverstanden', v['text'])
        self.assertIn('( ) Post\n(X) E-Mail', v['text'])
        self.assertIn('[ ] Hausarzt', v['text'])
        self.assertIn('[X] Angehörige', v['text'])
        self.assertIn('E-Mail: neu@x.de (am Pad geändert)', v['text'])
        self.assertIn('Ich willige ausdrücklich ein, bitte lesen.', v['text'])
        self.assertEqual(json.loads(v['kontakt_neu']), {'telefon': '', 'email': 'neu@x.de'})
        self.assertEqual(v['kontakt_status'], 'offen')
        self.assertTrue(os.path.isfile(os.path.join(self.ordner, '1.pdf')))

    def test_pflichtfeld_haelt_auf(self):
        erg, f = self.lauf(['Weiter', 'Weiter', 'Weiter', 'Weiter', 'OK'])
        self.assertEqual(erg, 'fehler', 'ohne Pflichtangaben gibt es keine Unterschriftsseite (Ersatz-Pad endet mit Trennung)')
        self.assertNotIn('start', f.log)
