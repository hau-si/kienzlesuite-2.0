"""Tests für den root-Dienst der Firewall (core/lib/kienzle/firewall.py) mit nachgebautem nft und ss.

Übernommen aus kienzle-suite-beta (tests/firewall-apply.sh). Nur Platzhalter-Adressen: 192.0.2.0/24 gilt in den
Tests als Praxisnetz, 203.0.113.0/24 und 198.51.100.0/24 als fremde Netze.
"""
import importlib
import ipaddress
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'core' / 'lib'))

FAKE_NFT = r'''#!/usr/bin/env bash
# Merkt sich die geladene Tabelle in $FAKE_TABLE; „-c“ prüft nur.
echo "$*" >> "$FAKE_LOG"
case "$1" in
  -c) cat > /dev/null; exit 0;;
  -f) if [ "$2" = - ]; then input=$(cat); else input=$(cat "$2"); fi
      if [ "${FAKE_FAIL:-0}" = 1 ]; then echo 'Error: kaputt' >&2; exit 1; fi
      printf '%s\n' "$input" | sed -n '/^table inet kienzle {/,$p' > "$FAKE_TABLE"; exit 0;;
  list) [ -s "$FAKE_TABLE" ] && cat "$FAKE_TABLE" || exit 1; exit 0;;
  delete) : > "$FAKE_TABLE"; exit 0;;
esac
'''
FAKE_SS = '#!/bin/sh\necho \'LISTEN 0 128 0.0.0.0:2222 0.0.0.0:* users:(("sshd",pid=1,fd=3))\'\n'
TEST_NETS = ('192.0.2.0/24', 'fc00::/7')


class Dienst(unittest.TestCase):
    def setUp(self):
        self.t = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.t)
        for name, text in (('nft', FAKE_NFT), ('ss', FAKE_SS)):
            (self.t / name).write_text(text)
            (self.t / name).chmod(0o755)
        (self.t / 'anfragen').mkdir()
        (self.t / 'table').write_text('')
        (self.t / 'log').write_text('')
        self.registry({'modules': [{'module': {'id': 'beispiel'}, 'firewall': [
            {'name': 'Schnittstelle', 'proto': 'tcp', 'from': 8766, 'to': 8766, 'scope': 'nets'},
            {'name': 'Sprachdaten', 'proto': 'udp', 'from': 10000, 'to': 10010, 'scope': 'nets'},
            {'name': 'VPN', 'proto': 'udp', 'from': 51820, 'to': 51820, 'scope': 'any'}]}]})
        env = {'KIENZLE_FW_NFT': self.t / 'nft', 'KIENZLE_FW_SS': self.t / 'ss', 'KIENZLE_FW_REQUEST': self.t / 'anfragen/firewall.json',
               'KIENZLE_FW_CONFIRM': self.t / 'anfragen/firewall-bestaetigt', 'KIENZLE_FW_STATE': self.t / 'state',
               'KIENZLE_FW_RULES': self.t / 'etc/firewall.nft', 'KIENZLE_FW_MODULES': self.t / 'modules.json', 'KIENZLE_FW_UID': os.getuid(),
               'KIENZLE_FW_MODULES_OWNER': os.getuid(), 'KIENZLE_FW_WAIT': 3, 'FAKE_TABLE': self.t / 'table', 'FAKE_LOG': self.t / 'log'}
        patcher = mock.patch.dict(os.environ, {k: str(v) for k, v in env.items()})
        patcher.start()
        self.addCleanup(patcher.stop)
        import kienzle.firewall
        self.fw = importlib.reload(kienzle.firewall)
        self.fw.ALLOWED_RANGES = [ipaddress.ip_network(n) for n in TEST_NETS]

    def registry(self, data):
        (self.t / 'modules.json').write_text(json.dumps(data))
        (self.t / 'modules.json').chmod(0o644)

    def request(self, data, confirm_after=None):
        (self.t / 'anfragen/firewall.json').write_text(data if isinstance(data, str) else json.dumps(data))
        if confirm_after is not None:
            def later():
                time.sleep(confirm_after)
                (self.t / 'anfragen/firewall-bestaetigt').touch()
            threading.Thread(target=later, daemon=True).start()
        self.fw.handle()

    def state(self):
        return json.loads((self.t / 'state/status.json').read_text())

    def rules(self):
        return (self.t / 'etc/firewall.nft').read_text()

    def test_bestaetigt_wird_gespeichert(self):
        self.request({'action': 'apply', 'cidrs': ['192.0.2.7/24', 'fd12::/16'], 'ssh': True, 'ipp': True, 'samba': True}, confirm_after=1)
        s = self.state()
        self.assertEqual(s['state'], 'active', s)
        self.assertEqual(s['ports'], [443, 445, 631, 2222])
        r = self.rules()
        self.assertIn('policy drop;', r)                     # Vorgabe: alles zu
        self.assertIn('ip saddr { 192.0.2.0/24 } tcp dport { 443, 445, 631, 2222 } accept', r)
        self.assertIn('ip6 saddr { fd12::/16 } tcp dport { 443, 445, 631, 2222 } accept', r)
        self.assertEqual(oct((self.t / 'etc/firewall.nft').stat().st_mode & 0o777), '0o600')
        self.assertFalse((self.t / 'anfragen/firewall.json').exists() or (self.t / 'anfragen/firewall-bestaetigt').exists())
        self.assertEqual(self.fw.boot(), 0)
        self.assertIn(f'-f {self.t}/etc/firewall.nft', (self.t / 'log').read_text())

    def test_nur_443_ohne_weitere_wahl(self):
        self.request({'action': 'apply', 'cidrs': ['192.0.2.0/24']}, confirm_after=1)
        r = self.rules()
        self.assertIn('ip saddr { 192.0.2.0/24 } tcp dport { 443 } accept', r)
        self.assertNotIn('2222', r)
        self.assertNotIn('udp dport {', r)

    def test_modul_ports_aus_dem_verzeichnis(self):
        self.request({'action': 'apply', 'cidrs': ['192.0.2.0/24'], 'modules': ['beispiel/1', 'beispiel/2', 'beispiel/3'],
                      'custom': ['tcp 8080', 'udp 5000-5100']}, confirm_after=1)
        r = self.rules()
        self.assertIn('ip saddr { 192.0.2.0/24 } tcp dport { 443, 8080, 8766 } accept', r)
        self.assertIn('ip saddr { 192.0.2.0/24 } udp dport { 5000-5100, 10000-10010 } accept', r)
        self.assertIn('    udp dport { 51820 } accept', r)           # scope any: von überall
        self.assertEqual(self.state()['modules'], ['beispiel/1', 'beispiel/2', 'beispiel/3'])

    def test_nur_verwaltete_ports_sperren(self):
        self.request({'action': 'apply', 'cidrs': ['192.0.2.0/24'], 'strict': False, 'ssh': True}, confirm_after=1)
        r = self.rules()
        self.assertIn('policy accept;', r)
        self.assertIn('    tcp dport { 443, 2222 } drop', r)

    def test_nicht_bestaetigt_wird_zurueckgesetzt(self):
        self.request({'action': 'apply', 'cidrs': ['192.0.2.0/24']}, confirm_after=1)
        before = (self.t / 'table').read_text()
        self.request({'action': 'apply', 'cidrs': ['192.0.2.128/25'], 'ssh': True})
        self.assertEqual(self.state()['state'], 'reverted')
        self.assertEqual((self.t / 'table').read_text(), before)
        self.assertNotIn('192.0.2.128/25', self.rules())

    def test_ablehnungen(self):
        bad = [{'action': 'apply', 'cidrs': ['0.0.0.0/0']}, {'action': 'apply', 'cidrs': ['203.0.113.0/24']},
               {'action': 'apply', 'cidrs': ['192.0.2.0/24; flush ruleset']}, {'action': 'apply', 'cidrs': ['192.0.2.0/24'], 'modules': ['beispiel/9']},
               {'action': 'apply', 'cidrs': ['192.0.2.0/24'], 'modules': ['../x/1']}, {'action': 'apply', 'cidrs': ['192.0.2.0/24'], 'modules': 'beispiel/1'},
               {'action': 'apply', 'cidrs': ['192.0.2.0/24'], 'custom': ['tcp 0']},
               {'action': 'apply', 'cidrs': ['192.0.2.0/24'], 'custom': ['tcp 80; flush ruleset']},
               {'action': 'apply', 'cidrs': ['192.0.2.0/24'], 'custom': ['udp 900-100']}, {'action': 'apply', 'cidrs': []}, {'action': 'drop'},
               'kein json', {'action': 'apply', 'cidrs': '192.0.2.0/24'}, {'action': 'apply', 'cidrs': [1]}, 'x' * 9000]
        for b in bad:
            with self.subTest(b):
                (self.t / 'log').write_text('')
                self.request(b)
                self.assertEqual(self.state()['state'], 'failed')
                self.assertNotIn('-f', (self.t / 'log').read_text())

    def test_symlink_und_fremde_anfrage(self):
        secret = self.t / 'geheim'
        secret.write_text(json.dumps({'action': 'disable'}))
        (self.t / 'anfragen/firewall.json').symlink_to(secret)
        self.fw.handle()
        self.assertFalse((self.t / 'anfragen/firewall.json').exists())
        self.assertFalse((self.t / 'state/status.json').exists())          # ohne Wirkung
        os.environ['KIENZLE_FW_UID'] = str(os.getuid() + 1)
        self.request({'action': 'disable'})
        self.assertEqual(self.state()['state'], 'failed')

    def test_modulverzeichnis_muss_vertrauenswuerdig_sein(self):
        (self.t / 'modules.json').chmod(0o666)
        self.request({'action': 'apply', 'cidrs': ['192.0.2.0/24'], 'modules': ['beispiel/1']})
        self.assertIn('nicht vertrauenswürdig', self.state()['message'])

    def test_nft_lehnt_ab(self):
        os.environ['FAKE_FAIL'] = '1'
        try:
            self.request({'action': 'apply', 'cidrs': ['192.0.2.0/24']})
        finally:
            del os.environ['FAKE_FAIL']
        self.assertEqual(self.state()['state'], 'failed')

    def test_ausschalten(self):
        self.request({'action': 'apply', 'cidrs': ['192.0.2.0/24']}, confirm_after=1)
        self.request({'action': 'disable'})
        self.assertEqual(self.state()['state'], 'off')
        self.assertFalse((self.t / 'etc/firewall.nft').exists())
        self.assertEqual((self.t / 'table').read_text(), '')

    def test_befehl(self):
        cli = [sys.executable, '-IB', str(ROOT / 'core' / 'bin' / 'kienzle-firewall')]
        self.assertEqual(subprocess.run(cli, capture_output=True).returncode, 2)
        self.assertEqual(subprocess.run([*cli, 'laden'], capture_output=True, env=dict(os.environ)).returncode, 0)


class EchtesNft(unittest.TestCase):
    """Syntaxprüfung der erzeugten Regeln mit echtem nft (nur mit Rechten, z. B. sudo in GitHub Actions)."""

    def test_syntax(self):
        if not shutil.which('nft') or os.geteuid() != 0:
            self.skipTest('nft oder root-Rechte fehlen')
        from kienzle import firewall as fw
        mods = [{'proto': 'udp', 'from': 10000, 'to': 10010, 'scope': 'nets'}, {'proto': 'udp', 'from': 51820, 'to': 51820, 'scope': 'any'}]
        for strict in (True, False):
            rules = fw.build_rules(['192.0.2.0/24', 'fd12::/16'], [22], True, True, strict, mods, ['tcp 8080'])
            r = subprocess.run(['nft', '-c', '-f', '-'], input=rules, capture_output=True, text=True)
            if 'Operation not permitted' in r.stderr:
                self.skipTest('nft ohne Rechte')
            self.assertEqual(r.returncode, 0, r.stderr + rules)


if __name__ == '__main__':
    unittest.main()
