import os
import re
import sqlite3
import sys
import tempfile
import unittest
import zlib

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'lib'))
import paddienst  # noqa: E402
import padpdf  # noqa: E402


def texte(pdf: bytes) -> str:
    """Alle Textstücke aus den (komprimierten) Inhaltsströmen, als WinAnsi gelesen."""
    out = []
    for roh in re.findall(rb'/Filter /FlateDecode /Length \d+ >>\nstream\n(.*?)\nendstream', pdf, re.S):
        try:
            inhalt = zlib.decompress(roh)
        except zlib.error:
            continue
        for t in re.findall(rb'\((.*?)(?<!\\)\) Tj', inhalt):
            out.append(t.replace(b'\\(', b'(').replace(b'\\)', b')').replace(b'\\\\', b'\\').decode('cp1252'))
    return '\n'.join(out)


class PdfTest(unittest.TestCase):
    def setUp(self):
        self.ordner = tempfile.mkdtemp(prefix='sg-pdf-')
        self.addCleanup(lambda: [os.remove(os.path.join(self.ordner, f)) for f in os.listdir(self.ordner)] and None or os.rmdir(self.ordner))

    def test_umbrechen(self):
        zeilen = padpdf.umbrechen('Wort ' * 60 + '\n\nX' * 1 + '\n' + 'A' * 300, 10.5, 200)
        self.assertTrue(all(padpdf.breite(z, 10.5) <= 200 for z in zeilen))
        self.assertIn('', zeilen)
        self.assertEqual(''.join(z for z in zeilen if z.startswith('A')), 'A' * 300)

    def test_kodieren(self):
        self.assertEqual(padpdf.kodieren('Ä € „x“ – • 😀\x07'), 'Ä € „x“ – • ?'.encode('cp1252'))

    def test_vorgang_pdf(self):
        from PIL import Image, ImageDraw
        c = sqlite3.connect(':memory:')
        c.row_factory = sqlite3.Row
        c.executescript("""CREATE TABLE pads (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE vorgaenge (id INTEGER PRIMARY KEY, titel TEXT, text TEXT, patient TEXT, status TEXT, unterschrieben INTEGER, pad_id INTEGER,
              geburtsdatum TEXT, praxis TEXT);
            INSERT INTO pads VALUES (1, 'Empfang');""")
        c.execute('INSERT INTO vorgaenge VALUES (7, ?, ?, ?, ?, ?, 1, ?, ?)', ('IGeL-Vereinbarung', 'Sehr geehrter Herr Mustermann,\n\nKosten: 26,23 € (GOÄ 417) – „selbst“\n'
                  + 'Fülltext für mehrere Seiten. ' * 600, 'Max Mustermann', 'unterschrieben', 1795000000, '01.02.1970',
                  '{"name": "Praxis Dr. med. Erika Beispiel", "street": "Musterstraße 1", "city": "12345 Musterstadt"}'))
        bild = Image.new('RGBA', (600, 200), (255, 255, 255, 0))
        ImageDraw.Draw(bild).line([(50, 150), (300, 40), (550, 160)], fill=(0, 0, 0, 255), width=6)
        bild.save(os.path.join(self.ordner, '7.png'))
        with open(os.path.join(self.ordner, '7.sdb'), 'wb') as fh:
            fh.write(b'erfundene Unterschriftsdaten')
        pfad = padpdf.vorgang_pdf(c, 7, self.ordner)
        with open(pfad, 'rb') as fh:
            pdf = fh.read()
        self.assertTrue(pdf.startswith(b'%PDF-1.4') and pdf.rstrip().endswith(b'%%EOF'))
        self.assertIn(b'/Subtype /Image', pdf)
        t = texte(pdf)
        for erwartet in ('Praxis Dr. med. Erika Beispiel', 'Max Mustermann', 'geb. 01.02.1970', 'IGeL-Vereinbarung', 'Kosten: 26,23 € (GOÄ 417) – „selbst“',
                         'Unterschriftsnachweis', 'Gerät: signotec-Pad „Empfang“', 'Vorgang: 7', 'Biometrische Daten: in der Praxis gespeichert'):
            self.assertIn(erwartet, t)
        seiten = int(re.search(rb'/Count (\d+)', pdf).group(1))
        self.assertGreater(seiten, 1)
        self.assertIn(f'Seite {seiten} von {seiten}', t)
        # xref stimmt: jede Lage zeigt auf „n 0 obj“
        start = int(re.search(rb'startxref\n(\d+)', pdf).group(1))
        lagen = re.findall(rb'(\d{10}) 00000 n', pdf[start:])
        for i, lage in enumerate(lagen, 1):
            self.assertTrue(pdf[int(lage):].startswith(b'%d 0 obj' % i))

    def test_fremde_schrift_eingebettet(self):
        # Sprachfassung mit Zeichen außerhalb von WinAnsi: DejaVu Sans eingebettet, Text per ToUnicode lesbar, keine „?“.
        ziel = os.path.join(self.ordner, 'uni.pdf')
        text = 'Rıza ve onay: Şükrü Ğüneş\nZgoda: Łódź, ąęść\nСогласие пациента\nΣυναίνεση'
        padpdf.erzeugen({'titel': 'Onam formu', 'text': text, 'patient': 'Max Mustermann', 'zeit': 1795000000, 'vorgang': 3}, ziel)
        with open(ziel, 'rb') as fh:
            pdf = fh.read()
        self.assertIn(b'/Subtype /Type0', pdf)
        self.assertIn(b'/FontFile2', pdf)
        self.assertNotIn(b'/WinAnsiEncoding', pdf)
        unicode = ''
        for m in re.findall(rb'stream\n(.*?)\nendstream', pdf, re.S):
            try:
                roh = zlib.decompress(m)
            except zlib.error:
                continue
            if b'beginbfchar' in roh:
                unicode += ''.join(bytes.fromhex(h.decode()).decode('utf-16-be') for h in re.findall(rb'<[0-9a-f]{4}> <([0-9a-f]+)>', roh))
        for ch in 'ıŞşĞŁąСΣ':
            self.assertIn(ch, unicode)
        self.assertIsNone(padpdf._SCHRIFT, 'danach wieder Standardschrift')
        # gleiche Lage der Objekte (xref)
        start = int(re.search(rb'startxref\n(\d+)', pdf).group(1))
        for i, lage in enumerate(re.findall(rb'(\d{10}) 00000 n', pdf[start:]), 1):
            self.assertTrue(pdf[int(lage):].startswith(b'%d 0 obj' % i))
        t = padpdf.TrueType(padpdf.SCHRIFTEN[0])
        self.assertGreater(t.glyphe('Ж'), 0)
        self.assertAlmostEqual(t.breite(' '), 317.87, delta=2)

    def test_ohne_bild_und_alte_spalten(self):
        c = sqlite3.connect(':memory:')
        c.row_factory = sqlite3.Row
        c.executescript("""CREATE TABLE pads (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE vorgaenge (id INTEGER PRIMARY KEY, titel TEXT, text TEXT, patient TEXT, status TEXT, unterschrieben INTEGER, pad_id INTEGER);
            INSERT INTO vorgaenge VALUES (3, 'Datenschutz', 'Kurz.', 'Max Mustermann', 'unterschrieben', 0, 0);""")
        with open(padpdf.vorgang_pdf(c, 3, self.ordner), 'rb') as fh:
            t = texte(fh.read())
        self.assertIn('Prüfsumme: keine Unterschriftsdaten', t)

    def test_nachholen(self):
        c = sqlite3.connect(':memory:')
        c.row_factory = sqlite3.Row
        c.executescript("""CREATE TABLE pads (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE vorgaenge (id INTEGER PRIMARY KEY, titel TEXT, text TEXT, patient TEXT, status TEXT, unterschrieben INTEGER, pad_id INTEGER, meldung TEXT);
            INSERT INTO vorgaenge VALUES (1, 'A', 'x', 'Max Mustermann', 'unterschrieben', 0, 0, '');
            INSERT INTO vorgaenge VALUES (2, 'B', 'x', 'Max Mustermann', 'wartet', 0, 0, '');""")
        for vid in (1, 2):
            open(os.path.join(self.ordner, f'{vid}.png'), 'wb').close()
        self.assertEqual(paddienst.pdf_nachholen(c, self.ordner), 1)
        self.assertTrue(os.path.isfile(os.path.join(self.ordner, '1.pdf')))
        self.assertFalse(os.path.exists(os.path.join(self.ordner, '2.pdf')), 'nur unterschriebene')
        self.assertEqual(paddienst.pdf_nachholen(c, self.ordner), 0, 'schon vorhanden')


class AblageTest(unittest.TestCase):
    def test_anstossen(self):
        c = sqlite3.connect(':memory:')
        c.execute("CREATE TABLE vorgaenge (id INTEGER PRIMARY KEY, status TEXT, ablage TEXT)")
        uhr = [100.0]
        gestartet = []

        class P:
            def __init__(self): self.fertig = None
            def poll(self): return self.fertig

        def starten():
            gestartet.append(P())
            return gestartet[-1]
        paddienst.ABLAGE.update(proc=None, zuletzt=0.0)
        self.assertFalse(paddienst.ablage_anstossen(c, jetzt=lambda: uhr[0], starten=starten), 'nichts offen')
        c.execute("INSERT INTO vorgaenge VALUES (1, 'unterschrieben', '')")
        self.assertTrue(paddienst.ablage_anstossen(c, jetzt=lambda: uhr[0], starten=starten))
        uhr[0] += 60
        self.assertFalse(paddienst.ablage_anstossen(c, jetzt=lambda: uhr[0], starten=starten), 'läuft noch: nicht doppelt')
        gestartet[-1].fertig = 0
        uhr[0] += 5
        self.assertFalse(paddienst.ablage_anstossen(c, jetzt=lambda: uhr[0] - 60, starten=starten), 'Takt abwarten')
        self.assertTrue(paddienst.ablage_anstossen(c, jetzt=lambda: uhr[0], starten=starten))
        self.assertEqual(len(gestartet), 2)
        c2 = sqlite3.connect(':memory:')
        c2.execute("CREATE TABLE vorgaenge (id INTEGER PRIMARY KEY, status TEXT)")
        self.assertFalse(paddienst.ablage_offen(c2), 'alte Datenbank ohne Spalte')


if __name__ == '__main__':
    unittest.main()
