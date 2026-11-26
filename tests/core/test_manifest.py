"""Tests für das Prüfprogramm der Modul-Beschreibung (core/lib/kienzle/manifest.py, core/bin/kienzle-modul-pruefen).

Grundlage ist das gültige Beispielmodul modules/beispiel. Jeder Test verändert genau eine Stelle und erwartet einen
Fehler an genau dieser Stelle. Aufruf: python3 -B -m unittest discover -s tests/core
"""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'core' / 'lib'))
from kienzle import manifest  # noqa: E402

BEISPIEL_DIR = ROOT / 'modules' / 'beispiel'
BEISPIEL = (BEISPIEL_DIR / 'module.toml').read_text(encoding='utf-8')
CLI = ROOT / 'core' / 'bin' / 'kienzle-modul-pruefen'
BEISPIEL_VERSION = re.search(r'^version = "([^"]+)"$', BEISPIEL, re.M).group(1)
VERSION_LINE = f'version = "{BEISPIEL_VERSION}"'


def replace(old: str, new: str, text: str = BEISPIEL) -> str:
    if old not in text:
        raise AssertionError(f'Testvorlage enthält „{old}“ nicht')
    return text.replace(old, new, 1)


def drop_section(name: str, text: str = BEISPIEL) -> str:
    """Abschnitt [name] bzw. alle [[name]] bis zum nächsten Abschnitt entfernen."""
    return re.sub(r'^\[\[?' + re.escape(name) + r'\]\]?\n(?:(?!\[).*\n)*', '', text, flags=re.M)


def code_blocks(markdown: str, lang: str) -> list[str]:
    return re.findall(r'^```' + lang + r'\n(.*?)^```', markdown, flags=re.M | re.S)


class Fehler(unittest.TestCase):
    """Hilfen: erwartet Invalid mit einer Meldung, die Ort und Text enthält."""

    def invalid(self, text: str, where: str, contains: str = '') -> list[str]:
        with self.assertRaises(manifest.Invalid) as ctx:
            manifest.check_text(text)
        return self.assert_error(ctx.exception.errors, where, contains)

    def assert_error(self, errors: list[str], where: str, contains: str = '') -> list[str]:
        hits = [e for e in errors if e.startswith(where) and contains in e]
        self.assertTrue(hits, f'Erwartet Fehler bei „{where}“ mit „{contains}“, erhalten: {errors}')
        return errors


class Gueltig(unittest.TestCase):
    def test_beispielmodul_gueltig(self):
        m = manifest.check_folder(BEISPIEL_DIR)
        self.assertEqual(m['module']['id'], 'beispiel')
        self.assertEqual(m['system']['user'], 'ks-beispiel')
        self.assertEqual(m['firewall'][1], {'name': 'Beispiel-Bereich für Sprachdaten', 'proto': 'udp',
                                            'from': 10000, 'to': 10010, 'scope': 'nets'})
        self.assertEqual([r['id'] for r in m['rights']], ['ansehen', 'bearbeiten'])
        self.assertEqual(m['hooks']['apparmor'], ['gs'])

    def test_alle_module_im_repository_gueltig(self):
        result = manifest.check_all(ROOT / 'modules')
        self.assertEqual(result['core_api'], ['1', '2'])
        self.assertIn('beispiel', [m['module']['id'] for m in result['modules']])

    def test_beispiel_in_der_dokumentation_entspricht_dem_beispielmodul(self):
        blocks = code_blocks((ROOT / 'docs' / 'MODULE-TOML.md').read_text(encoding='utf-8'), 'toml')
        self.assertEqual(len(blocks), 1)
        without_comments = '\n'.join(line for line in BEISPIEL.splitlines() if not line.startswith('#')).strip()
        self.assertEqual(blocks[0].strip(), without_comments)

    def test_beispiel_in_der_architektur_gueltig(self):
        blocks = code_blocks((ROOT / 'docs' / 'ARCHITEKTUR.md').read_text(encoding='utf-8'), 'toml')
        self.assertEqual(len(blocks), 1)
        m = manifest.check_text(blocks[0])
        self.assertEqual(m['module']['id'], 'kasse')

    def test_minimal(self):
        text = BEISPIEL
        for section in ('web', 'firewall', 'rights', 'backup', 'printers', 'connections', 'setup', 'hooks'):
            text = drop_section(section, text)
        text = replace('packages = ["sqlite3"]\nservices = ["kienzle-beispiel-worker.service"]\n', '', text)
        text = replace('description = "Zeigt alle Felder der Modul-Beschreibung; nur für Tests."\n', '', text)
        m = manifest.check_text(text)
        self.assertIsNone(m['web'])
        self.assertIsNone(m['backup'])
        self.assertEqual(m['firewall'], [])
        self.assertEqual(m['system']['packages'], [])
        self.assertIsNone(m['hooks']['install'])
        self.assertIsNone(m['module']['description'])
        self.assertEqual(m['connections'], [])

    def test_vorabversion_und_scope_any(self):
        text = replace(VERSION_LINE, 'version = "2.1.0-beta.3"')
        text = replace('port = 8766\nproto = "tcp"', 'port = 51820\nproto = "udp"\nscope = "any"', text)
        m = manifest.check_text(text)
        self.assertEqual(m['module']['version'], '2.1.0-beta.3')
        self.assertEqual(m['firewall'][0]['scope'], 'any')

    def test_api_aus_core_api(self):
        self.assertEqual(manifest.supported_api(), frozenset({'1', '2'}))
        self.assertRegex((ROOT / 'core' / 'VERSION').read_text().strip(), manifest.VERSION)


class Grundregeln(Fehler):
    def test_kein_toml(self):
        self.invalid('[module\nid = ', 'module.toml', 'kein gültiges TOML')

    def test_zu_gross(self):
        self.invalid(BEISPIEL + '#' * manifest.MAX_BYTES, 'module.toml', 'größer als')

    def test_unbekannter_abschnitt(self):
        self.invalid(BEISPIEL + '\n[extra]\na = 1\n', 'module.toml', 'unbekannter Abschnitt „extra“')

    def test_unbekannter_schluessel(self):
        self.invalid(replace('name = "Beispielmodul"', 'name = "Beispielmodul"\nfarbe = "rot"'), 'module',
                     'unbekannter Schlüssel „farbe“')

    def test_unbekannter_schluessel_in_liste(self):
        self.invalid(replace('label = "Beispiel-Liste ansehen"', 'label = "Beispiel-Liste ansehen"\nstufe = 2'),
                     'rights', 'unbekannter Schlüssel „stufe“')

    def test_pflichtabschnitte(self):
        self.invalid(drop_section('module'), 'module.toml', 'Abschnitt [module] fehlt')
        self.invalid(drop_section('system'), 'module.toml', 'Abschnitt [system] fehlt')

    def test_abschnitt_mit_falschem_typ(self):
        self.invalid('web = "x"\n' + drop_section('web'), 'web', 'muss ein Abschnitt [web] sein')
        self.invalid(drop_section('rights') + '\n[rights]\nid = "x"\nlabel = "y"\n', 'rights', 'als [[rights]]')

    def test_steuerzeichen(self):
        self.invalid(replace('name = "Beispielmodul"', 'name = "Beispiel\\u0007modul"'), 'module.name', 'Steuerzeichen')

    def test_leerer_und_zu_langer_text(self):
        self.invalid(replace('name = "Beispielmodul"', 'name = "   "'), 'module.name', 'leer')
        self.invalid(replace('name = "Beispielmodul"', 'name = "' + 'x' * 81 + '"'), 'module.name', 'zu lang')
        self.invalid(replace('name = "Beispielmodul"', 'name = 7'), 'module.name', 'Text')

    def test_alle_fehler_auf_einmal(self):
        text = replace(VERSION_LINE, 'version = "1.0"', replace('user = "ks-beispiel"', 'user = "root"'))
        errors = self.invalid(text, 'module.version')
        self.assert_error(errors, 'system.user')

    def test_fehlermeldung_kuerzt_fremde_werte(self):
        errors = self.invalid(replace('id = "beispiel"', 'id = "' + 'X' * 500 + '"'), 'module.id')
        self.assertTrue(all(len(e) < 200 for e in errors), errors)


class Modul(Fehler):
    def test_id(self):
        for bad in ('Beispiel', '1abc', 'a', 'ab--c', 'abc-', 'ab_c', 'ab c'):
            with self.subTest(bad):
                self.invalid(replace('id = "beispiel"', f'id = "{bad}"'), 'module.id')

    def test_id_zu_lang(self):
        self.invalid(replace('id = "beispiel"', 'id = "' + 'a' * 30 + '"'), 'module.id', '2 bis 29 Zeichen')

    def test_id_reserviert(self):
        self.invalid(replace('id = "beispiel"', 'id = "core"'), 'module.id', 'reserviert')

    def test_version(self):
        for bad in ('1.0', '01.0.0', '1.0.0-', 'v1.0.0', '1.0.0+build'):
            with self.subTest(bad):
                self.invalid(replace(VERSION_LINE, f'version = "{bad}"'), 'module.version')

    def test_core_api(self):
        self.invalid(replace('core_api = "1"', 'core_api = 1'), 'module.core_api', 'ungültig')
        self.invalid(replace('core_api = "1"', 'core_api = "3"'), 'module.core_api', 'nicht unterstützt')
        self.invalid(replace('core_api = "1"\n', ''), 'module', '„core_api“ fehlt')


class System(Fehler):
    def test_benutzer(self):
        self.invalid(replace('user = "ks-beispiel"', 'user = "root"'), 'system.user', 'ks-beispiel')
        self.invalid(replace('user = "ks-beispiel"\n', ''), 'system', '„user“ fehlt')

    def test_datenordner(self):
        for bad in ('/var/lib/kienzle/core', '/var/lib/kienzle/beispiel/../core', '/tmp', 'var/lib/kienzle/beispiel'):
            with self.subTest(bad):
                self.invalid(replace('data = "/var/lib/kienzle/beispiel"', f'data = "{bad}"'), 'system.data')

    def test_pakete(self):
        self.invalid(replace('packages = ["sqlite3"]', 'packages = ["sqlite3; rm -rf /"]'), 'system.packages', 'ungültig')
        self.invalid(replace('packages = ["sqlite3"]', 'packages = ["sqlite3", "sqlite3"]'), 'system.packages', 'doppelt')
        self.invalid(replace('packages = ["sqlite3"]', 'packages = "sqlite3"'), 'system.packages', 'Liste')
        self.invalid(replace('packages = ["sqlite3"]', 'packages = [' + ', '.join(f'"p{i}x"' for i in range(51)) + ']'),
                     'system.packages', 'höchstens 50')

    def test_dienste(self):
        for bad in ('ssh.service', 'kienzle-andere-worker.service', 'kienzle-beispiel-worker.mount', '../x.service'):
            with self.subTest(bad):
                self.invalid(replace('"kienzle-beispiel-worker.service"', f'"{bad}"'), 'system.services')
        units = '"kienzle-beispiel.service", "kienzle-beispiel-sync.timer"'
        manifest.check_text(replace('"kienzle-beispiel-worker.service"', units))


class Austausch(Fehler):
    """[[exchange]] und [system] disables (core_api 2)."""
    API2 = replace('core_api = "1"', 'core_api = "2"')

    def test_austausch(self):
        m = manifest.check_text(self.API2 + '\n[[exchange]]\nmodule = "telefon"\npurpose = "Faxaufträge"\n')
        self.assertEqual(m['exchange'], [{'module': 'telefon', 'purpose': 'Faxaufträge', 'path': '/var/spool/kienzle/austausch/beispiel_telefon'}])
        self.assertEqual(manifest.exchange_dir('telefon', 'fax'), '/var/spool/kienzle/austausch/fax_telefon')

    def test_dateifreigabe(self):
        """[[fileshare]]: Ordner im Datenverzeichnis, den ein Admin als Windows-Freigabe zum Ablegen einschalten kann."""
        eintrag = '\n[[fileshare]]\nid = "ablage"\nname = "Fax-Ausgang"\nlabel = "Faxe zum Senden ablegen"\npath = "ablage"\n'
        m = manifest.check_text(self.API2 + eintrag)
        self.assertEqual(m['fileshares'], [{'id': 'ablage', 'name': 'Fax-Ausgang', 'label': 'Faxe zum Senden ablegen', 'path': '/var/lib/kienzle/beispiel/ablage'}])
        self.invalid(BEISPIEL + eintrag, 'module.core_api', 'core_api = "2"')
        self.invalid(self.API2 + eintrag.replace('"ablage"\nname', '"ablage"\nname', 1) + eintrag, 'fileshare[2].id', 'doppelt')
        self.invalid(self.API2 + eintrag.replace('path = "ablage"', 'path = "../etc"'), 'fileshare[1].path', 'kein gültiger Pfad')
        self.invalid(self.API2 + eintrag.replace('Fax-Ausgang', 'Fax Ausgang/x'), 'fileshare[1].name', 'ungültig')
        self.invalid(self.API2 + eintrag.replace('\npath = "ablage"', ''), 'fileshare[1]', '„path“ fehlt')

    def test_austausch_regeln(self):
        self.invalid(BEISPIEL + '\n[[exchange]]\nmodule = "telefon"\npurpose = "x"\n', 'module.core_api', 'core_api = "2"')
        self.invalid(self.API2 + '\n[[exchange]]\nmodule = "beispiel"\npurpose = "x"\n', 'exchange[1].module', 'anderes Modul')
        self.invalid(self.API2 + '\n[[exchange]]\nmodule = "../core"\npurpose = "x"\n', 'exchange[1].module', 'ungültig')
        self.invalid(self.API2 + '\n[[exchange]]\nmodule = "fax"\n', 'exchange[1]', '„purpose“ fehlt')
        two = '\n[[exchange]]\nmodule = "fax"\npurpose = "x"\n'
        self.invalid(self.API2 + two + two, 'exchange[2].module', 'doppelt')
        self.invalid(self.API2 + '\n[[exchange]]\nmodule = "fax"\npurpose = "x"\nweg = 1\n', 'exchange', 'unbekannter Schlüssel')

    def test_paketdienste_abschalten(self):
        text = replace('user = "ks-beispiel"', 'user = "ks-beispiel"\ndisables = ["asterisk.service"]', self.API2)
        self.assertEqual(manifest.check_text(text)['system']['disables'], ['asterisk.service'])
        self.assertEqual(manifest.check_text(BEISPIEL)['system']['disables'], [])
        for bad in ('ssh.service', 'nginx.service', 'kienzle-core-php.service'):
            with self.subTest(bad):
                self.invalid(text.replace('asterisk.service', bad), 'system.disables', 'darf nicht')
        self.invalid(replace('user = "ks-beispiel"', 'user = "ks-beispiel"\ndisables = ["asterisk.service"]'), 'module.core_api', 'core_api = "2"')


class Abhaengigkeit(Fehler):
    API2 = replace('core_api = "1"', 'core_api = "2"')

    def test_requires(self):
        text = replace('core_api = "2"', 'core_api = "2"\nrequires = ["telefon"]', self.API2)
        self.assertEqual(manifest.check_text(text)['module']['requires'], ['telefon'])
        self.assertEqual(manifest.check_text(BEISPIEL)['module']['requires'], [])
        self.invalid(text.replace('"telefon"', '"beispiel"'), 'module.requires', 'andere Modul-ID')
        self.invalid(text.replace('"telefon"', '"../x"'), 'module.requires', 'andere Modul-ID')
        self.invalid(replace('core_api = "1"', 'core_api = "1"\nrequires = ["telefon"]'), 'module.requires', 'core_api = "2"')


class Web(Fehler):
    def test_pfad(self):
        for bad in ('/Beispiel', 'beispiel', '/beispiel/', '/a/b', '/', '/-x', '/' + 'a' * 32):
            with self.subTest(bad):
                self.invalid(replace('path = "/beispiel"', f'path = "{bad}"'), 'web.path')

    def test_pfad_reserviert(self):
        for path in ('/anmelden', '/verwaltung', '/api'):
            with self.subTest(path):
                self.invalid(replace('path = "/beispiel"', f'path = "{path}"'), 'web.path', 'reserviert')

    def test_upstream(self):
        for bad in ('http://127.0.0.1:8080', 'unix:/run/kienzle/core/web.sock', 'unix:/tmp/beispiel.sock', 'unix:/run/kienzle/beispiel.sock'):
            with self.subTest(bad):
                self.invalid(replace('upstream = "unix:/run/kienzle/beispiel/web.sock"', f'upstream = "{bad}"'), 'web.upstream')

    def test_protokoll(self):
        up = 'upstream = "unix:/run/kienzle/beispiel/web.sock"'
        m = manifest.check_text(replace(up, up + '\nprotocol = "fastcgi"\nentry = "public/index.php"'))
        self.assertEqual(m['web'], {'path': '/beispiel', 'upstream': 'unix:/run/kienzle/beispiel/web.sock', 'protocol': 'fastcgi', 'entry': 'public/index.php',
                          'einstellungen': None, 'uploads': False})
        self.assertEqual(manifest.check_text(BEISPIEL)['web']['protocol'], 'http')
        self.invalid(replace(up, up + '\nprotocol = "fastcgi"'), 'web', '„entry“ fehlt')
        self.invalid(replace(up, up + '\nprotocol = "uwsgi"'), 'web.protocol')
        self.invalid(replace(up, up + '\nentry = "public/index.php"'), 'web.entry', 'nur mit')
        self.invalid(replace(up, up + '\nprotocol = "fastcgi"\nentry = "../x.php"'), 'web.entry')

    def test_alter_schluessel_web_starter_wird_angenommen(self):
        # Bereits installierte Module (z. B. Kasse vor der Umbenennung) tragen noch „starter = true“; die Basis darf sie deshalb nicht ablehnen.
        up = 'upstream = "unix:/run/kienzle/beispiel/web.sock"'
        manifest.check_text(replace(up, up + '\nstarter = true'))
        self.invalid(replace(up, up + '\nstarter = "ja"'), 'web.starter', 'true oder false')

    def test_startkontexte(self):
        fcgi = replace('upstream = "unix:/run/kienzle/beispiel/web.sock"\n', 'upstream = "unix:/run/kienzle/beispiel/web.sock"\nprotocol = "fastcgi"\nentry = "public/index.php"\n')
        m = manifest.check_text(fcgi + '\n[[starters]]\nschema = "kienzle-beispiel"\nlabel = "Beispiel"\n')
        self.assertEqual(m['starters'], [{'schema': 'kienzle-beispiel', 'label': 'Beispiel'}])
        self.assertEqual(manifest.check_text(BEISPIEL)['starters'], [])
        self.invalid(fcgi + '\n[[starters]]\nschema = "Fremd"\nlabel = "x"\n', 'starters[1].schema', 'kienzle-')
        self.invalid(fcgi + '\n[[starters]]\nschema = "kienzle-a"\nlabel = "x"\n[[starters]]\nschema = "kienzle-a"\nlabel = "y"\n', 'starters[2].schema', 'doppelt')
        self.invalid(fcgi + '\n[[starters]]\nschema = "kienzle-a"\n', 'starters[1]', 'label')
        self.invalid(BEISPIEL + '\n[[starters]]\nschema = "kienzle-a"\nlabel = "x"\n', 'starters', 'fastcgi')
        self.invalid(drop_section('web', BEISPIEL) + '\n[[starters]]\nschema = "kienzle-a"\nlabel = "x"\n', 'starters', 'fastcgi')


class Firewall(Fehler):
    def test_ports(self):
        for bad in ('0', '65536', '"20-10"', '"abc"', '"80,81"', 'true', '8766.0', '"-5"'):
            with self.subTest(bad):
                self.invalid(replace('port = 8766', f'port = {bad}'), 'firewall[1].port', 'ungültig')

    def test_reservierte_ports(self):
        for port, proto in (('443', 'tcp'), ('22', 'tcp'), ('445', 'tcp'), ('631', 'tcp'), ('"600-700"', 'tcp')):
            with self.subTest(port):
                self.invalid(replace('port = 8766\nproto = "tcp"', f'port = {port}\nproto = "{proto}"'),
                             'firewall[1].port', 'gehört der Basis')
        manifest.check_text(replace('port = 8766\nproto = "tcp"', 'port = 443\nproto = "udp"'))

    def test_protokoll_und_bereich(self):
        self.invalid(replace('proto = "tcp"', 'proto = "icmp"'), 'firewall[1].proto')
        self.invalid(replace('proto = "tcp"', 'proto = "tcp"\nscope = "alle"'), 'firewall[1].scope')
        self.invalid(replace('name = "Beispiel-Schnittstelle für Geräte im Praxisnetz"\n', ''), 'firewall[1]', '„name“ fehlt')

    def test_ueberschneidung_im_modul(self):
        self.invalid(replace('port = 8766\nproto = "tcp"', 'port = 10005\nproto = "udp"'), 'firewall[2].port', 'überschneidet')

    def test_hoechstzahl(self):
        extra = ''.join(f'\n[[firewall]]\nname = "P{i}"\nport = {20000 + i}\nproto = "tcp"\n' for i in range(19))
        self.invalid(BEISPIEL + extra, 'firewall', 'höchstens 20')


class Rechte(Fehler):
    def test_id(self):
        self.invalid(replace('id = "ansehen"', 'id = "Ansehen"'), 'rights[1].id')
        self.invalid(replace('id = "ansehen"', 'id = "admin"'), 'rights[1].id', 'reserviert')
        self.invalid(replace('id = "ansehen"', 'id = "bearbeiten"'), 'rights[2].id', 'doppelt')

    def test_bezeichnung(self):
        self.invalid(replace('label = "Beispiel-Liste ansehen"\n', ''), 'rights[1]', '„label“ fehlt')


class Sicherung(Fehler):
    def test_pfade(self):
        for bad in ('/etc/passwd', '/var/lib/kienzle/beispiel/../core', '/var/lib/kienzle/beispielx',
                    '/var/lib/kienzle/beispiel//x', 'var/lib/kienzle/beispiel', '/var/lib/kienzle/beispiel/./x'):
            with self.subTest(bad):
                self.invalid(replace('paths = ["/var/lib/kienzle/beispiel"]', f'paths = ["{bad}"]'), 'backup.paths',
                             'nicht im Datenordner')
        manifest.check_text(replace('paths = ["/var/lib/kienzle/beispiel"]', 'paths = ["/var/lib/kienzle/beispiel/db"]'))

    def test_pfade_fehlen(self):
        self.invalid(replace('paths = ["/var/lib/kienzle/beispiel"]', 'paths = []'), 'backup.paths', 'mindestens')
        self.invalid(replace('paths = ["/var/lib/kienzle/beispiel"]\n', ''), 'backup', '„paths“ fehlt')

    def test_snapshot_pfad(self):
        self.invalid(replace('snapshot = "bin/backup-snapshot"', 'snapshot = "/bin/sh"'), 'backup.snapshot')


class Drucker(Fehler):
    def test_name(self):
        self.invalid(replace('name = "Beispiel-Drucker"', 'name = "Beispiel Drucker!"'), 'printers[1].name')
        doppelt = BEISPIEL + '\n[[printers]]\nname = "beispiel-drucker"\nlabel = "x"\nppd = "printer/beispiel.ppd"\n'
        self.invalid(doppelt, 'printers[3].name', 'doppelt')

    def test_ipp_everywhere(self):
        self.invalid(replace('formats = ["application/pdf"]', 'formats = ["pdf"]'), 'printers[2].formats', 'kein Dateityp')
        self.invalid(replace('command = "printer/kienzle-beispiel-druck"\n', 'command = "printer/kienzle-beispiel-druck"\nppd = "printer/beispiel.ppd"\n'),
                     'printers[2]', 'nicht beides')
        self.invalid(replace('ppd = "printer/beispiel.ppd"\n', 'ppd = "printer/beispiel.ppd"\nformats = ["application/pdf"]\n'), 'printers[1].formats', 'nur zusammen')
        m = manifest.check_text(replace('formats = ["application/pdf"]\n', '', BEISPIEL))
        self.assertEqual(m['printers'][1]['formats'], ['application/pdf'])

    LISTE = ('\n[printer_list]\nprefix = "vBsp"\nlabel = "Beispiel"\ncommand = "printer/kienzle-beispiel-druck"\nfile = "drucker.json"\nmax = 3\n')

    def test_druckerliste(self):
        m = manifest.check_text(BEISPIEL + self.LISTE)
        pl = m['printer_list']
        self.assertEqual((pl['prefix'], pl['default'], pl['max'], pl['paper'], pl['formats']), ('vBsp', ['vBsp'], 3, 'A4', ['application/pdf']))
        self.assertIsNone(manifest.check_text(BEISPIEL)['printer_list'])
        self.invalid(BEISPIEL + self.LISTE.replace('max = 3', 'max = 9'), 'printer_list.max')
        self.invalid(BEISPIEL + self.LISTE.replace('"vBsp"', '"v Bsp"'), 'printer_list.prefix')
        self.invalid(BEISPIEL + self.LISTE + 'default = ["vXyz"]\n', 'printer_list.default', 'beginnt nicht')
        self.invalid(BEISPIEL + self.LISTE.replace('printer/kienzle-beispiel-druck', '../x'), 'printer_list.command')
        self.invalid(BEISPIEL + self.LISTE.replace('drucker.json', '../drucker.json'), 'printer_list.file')

    def test_pflichtfelder_und_freigabe(self):
        self.invalid(replace('ppd = "printer/beispiel.ppd"\n', ''), 'printers[1]', '„command“ oder „ppd“ fehlt')
        self.invalid(replace('share = ["samba", "ipp"]', 'share = ["ftp"]'), 'printers[1].share', 'unbekannt')
        self.invalid(replace('backend = "printer/kienzle-beispiel-backend"', 'backend = "../../usr/lib/cups/backend/ipp"'),
                     'printers[1].backend')


class Verbindungen(Fehler):
    def test_gueltig(self):
        m = manifest.check_folder(BEISPIEL_DIR)
        self.assertEqual(m['connections'], [
            {'id': 't2med', 'purpose': 'Beispiel: Patient zum Vorgang lesen', 'required': True},
            {'id': 'mail', 'purpose': 'Beispiel: Nachricht an die Praxis senden', 'required': False}])
        ohne = manifest.check_text(drop_section('connections'))
        self.assertEqual(ohne['connections'], [])

    def test_unbekannt_und_doppelt(self):
        self.invalid(replace('id = "t2med"', 'id = "gmail"'), 'connections[1].id', 'unbekannt')
        self.invalid(replace('id = "mail"', 'id = "t2med"'), 'connections[2].id', 'doppelt')

    def test_zweck_und_pflicht(self):
        self.invalid(replace('purpose = "Beispiel: Patient zum Vorgang lesen"\n', ''), 'connections[1]', '„purpose“ fehlt')
        self.invalid(replace('required = false', 'required = "nein"'), 'connections[2].required', 'true oder false')
        self.invalid(replace('purpose = "Beispiel: Patient zum Vorgang lesen"', 'purpose = "' + 'x' * 121 + '"'),
                     'connections[1].purpose', 'zu lang')

    def test_unbekannter_schluessel(self):
        self.invalid(replace('required = false', 'required = false\napi_key = "geheim"'), 'connections',
                     'unbekannter Schlüssel „api_key“')


class Haken(Fehler):
    def test_pfade(self):
        for bad in ('../x', '/bin/sh', 'bin/../install', './bin/install', 'bin//install', 'bin/install;rm', '', 'bin/ä'):
            with self.subTest(bad):
                self.invalid(replace('install = "bin/install"', f'install = "{bad}"'), 'hooks.install')
        self.invalid(replace('install = "bin/install"', 'install = ["bin/install"]'), 'hooks.install')

    def test_apparmor(self):
        self.invalid(replace('apparmor = ["gs"]', 'apparmor = ["bash"]'), 'hooks.apparmor', 'nicht freigegeben')

    def test_ueberblick(self):
        self.invalid(replace('overview = "bin/setup-overview"', 'overview = "/usr/bin/id"'), 'setup.overview')


class Ordner(Fehler):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp)

    def module(self, name='beispiel', text=BEISPIEL, parent=None) -> Path:
        target = (parent or self.tmp) / name
        shutil.copytree(BEISPIEL_DIR, target, symlinks=True)
        (target / 'module.toml').write_text(text, encoding='utf-8')
        return target

    def folder_errors(self, folder: Path) -> list[str]:
        with self.assertRaises(manifest.Invalid) as ctx:
            manifest.check_folder(folder)
        return ctx.exception.errors

    def test_kopie_gueltig(self):
        self.assertEqual(manifest.check_folder(self.module())['module']['id'], 'beispiel')

    def test_ordnername(self):
        self.assert_error(self.folder_errors(self.module('anders')), 'module.id', 'muss „beispiel“ heißen')

    def test_datei_fehlt(self):
        folder = self.module()
        (folder / 'bin' / 'busy').unlink()
        self.assert_error(self.folder_errors(folder), 'hooks.busy', 'fehlt')

    def test_nicht_ausfuehrbar(self):
        folder = self.module()
        os.chmod(folder / 'bin' / 'health', 0o644)
        self.assert_error(self.folder_errors(folder), 'hooks.health', 'nicht ausführbar')

    def test_fuer_andere_beschreibbar(self):
        folder = self.module()
        os.chmod(folder / 'bin' / 'install', 0o777)
        self.assert_error(self.folder_errors(folder), 'hooks.install', 'beschreibbar')

    def test_verweis_auf_datei(self):
        folder = self.module()
        (folder / 'bin' / 'remove').unlink()
        (folder / 'bin' / 'remove').symlink_to('/bin/true')
        self.assert_error(self.folder_errors(folder), 'hooks.remove', 'Symlink')

    def test_verweis_auf_ordner(self):
        folder = self.module()
        shutil.move(folder / 'printer', self.tmp / 'woanders')
        (folder / 'printer').symlink_to(self.tmp / 'woanders')
        self.assert_error(self.folder_errors(folder), 'printers[1].ppd', 'Symlink')

    def test_ppd_ist_ordner(self):
        folder = self.module()
        (folder / 'printer' / 'beispiel.ppd').unlink()
        (folder / 'printer' / 'beispiel.ppd').mkdir()
        self.assert_error(self.folder_errors(folder), 'printers[1].ppd', 'keine Datei')

    def test_module_toml_fehlt_oder_verweis(self):
        folder = self.module()
        (folder / 'module.toml').unlink()
        self.assert_error(self.folder_errors(folder), str(folder), 'fehlt')
        (folder / 'module.toml').symlink_to(BEISPIEL_DIR / 'module.toml')
        self.assert_error(self.folder_errors(folder), str(folder), 'keine echte Datei')

    def test_kein_utf8(self):
        folder = self.module()
        (folder / 'module.toml').write_bytes(b'[module]\nname = "\xff"\n')
        self.assert_error(self.folder_errors(folder), 'module.toml', 'UTF-8')


class Alle(Ordner):
    def second(self, mid='zweites', **changes):
        """Zweites gültiges Modul (Kopie des Beispiels mit eigener ID, Pfad, Druckername und Ports)."""
        text = BEISPIEL.replace('beispiel', mid)
        # Dateien im Ordner heißen weiterhin wie im Beispiel.
        text = text.replace(f'printer/{mid}.ppd', 'printer/beispiel.ppd')
        text = text.replace(f'kienzle-{mid}-backend', 'kienzle-beispiel-backend').replace(f'kienzle-{mid}-druck', 'kienzle-beispiel-druck')
        text = replace('name = "Beispiel-PDF"', f'name = "{mid.capitalize()}-PDF"', text)
        text = replace('name = "Beispiel-Drucker"', f'name = "{mid.capitalize()}-Drucker"', text)
        text = text.replace('port = 8766', 'port = 8767').replace('"10000-10010"', '"10011-10020"')
        for old, new in changes.items():
            text = replace(old, new, text)
        return self.module(mid, text)

    def all_errors(self) -> list[str]:
        with self.assertRaises(manifest.Invalid) as ctx:
            manifest.check_all(self.tmp)
        return ctx.exception.errors

    def test_zwei_gueltige_module(self):
        self.module()
        self.second()
        result = manifest.check_all(self.tmp)
        self.assertEqual([m['module']['id'] for m in result['modules']], ['beispiel', 'zweites'])

    def test_gleicher_webpfad(self):
        self.module()
        self.second(**{'path = "/zweites"': 'path = "/beispiel"'})
        self.assert_error(self.all_errors(), 'zweites: Webpfad /beispiel', 'schon verwendet')

    def test_gleicher_druckername(self):
        self.module()
        self.second(**{'name = "Zweites-Drucker"': 'name = "BEISPIEL-Drucker"'})
        self.assert_error(self.all_errors(), 'zweites: Drucker', 'schon verwendet')

    def test_ueberschneidende_ports(self):
        self.module()
        self.second(**{'"10011-10020"': '"10010-10020"'})
        self.assert_error(self.all_errors(), 'zweites: Port udp 10010-10020', 'überschneidet sich mit „beispiel“')

    def test_ungueltiges_modul_und_ordner_ohne_beschreibung(self):
        self.module()
        self.second(**{VERSION_LINE: 'version = "x"'})
        (self.tmp / 'leer').mkdir()
        errors = self.all_errors()
        self.assert_error(errors, 'zweites: module.version')
        self.assert_error(errors, 'leer: ', 'module.toml fehlt')


class Befehl(Ordner):
    def run_cli(self, *args):
        return subprocess.run([sys.executable, '-IB', str(CLI), *args], capture_output=True, text=True, timeout=30)

    def test_gueltig(self):
        r = self.run_cli(str(BEISPIEL_DIR))
        self.assertEqual((r.returncode, r.stdout.strip()), (0, f'Gültig: beispiel {BEISPIEL_VERSION}.'), r.stderr)

    def test_json(self):
        r = self.run_cli('--json', str(BEISPIEL_DIR))
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(json.loads(r.stdout), manifest.check_folder(BEISPIEL_DIR))
        r = self.run_cli('--alle', '--json', str(ROOT / 'modules'))
        self.assertEqual(json.loads(r.stdout)['core_api'], ['1', '2'])

    def test_ungueltig(self):
        folder = self.module(text=replace(VERSION_LINE, 'version = "1"'))
        r = self.run_cli(str(folder))
        self.assertEqual(r.returncode, 1)
        self.assertEqual(r.stdout, '')
        self.assertIn('module.version', r.stderr)

    def test_falscher_aufruf(self):
        self.assertEqual(self.run_cli().returncode, 2)
        self.assertEqual(self.run_cli('--unbekannt', str(BEISPIEL_DIR)).returncode, 2)
        self.assertEqual(self.run_cli(str(self.tmp / 'gibtsnicht')).returncode, 2)
        self.assertEqual(self.run_cli(str(BEISPIEL_DIR), str(BEISPIEL_DIR)).returncode, 2)

    def test_ausfuehrbar_mit_systempython(self):
        self.assertTrue(os.access(CLI, os.X_OK))
        self.assertTrue(CLI.read_text().startswith('#!/usr/bin/python3 -IB\n'))


if __name__ == '__main__':
    unittest.main()
