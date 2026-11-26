#!/usr/bin/env python3
"""Kienzle-Suite: holt die Basis aus GitHub und startet den Installer (läuft beim ersten Start des neuen Systems).

  holen.py --kanal stabil|beta [--unsigniert-erlauben] [--zweig main|beta] [--ziel /opt/kienzle-installer] [--name SERVER] [--praxis NAME]

Reihenfolge: Neuestes Release des Kanals (Archiv, Manifest, Signatur). Die Signatur wird mit den Schlüsseln aus
/usr/share/kienzle-iso/release-keys (oder --schluessel) geprüft. Ein Release ohne Signatur (Beta-Vorabversionen) wird nur mit
--unsigniert-erlauben genommen und gilt dann nur nach Prüfsumme aus dem Manifest. Gibt es gar kein passendes Release, holt
--unsigniert-erlauben zuletzt den Stand des Zweigs. Alles andere bricht ab, ohne etwas zu installieren.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
import re
import subprocess
import sys
import tarfile
import tempfile
import urllib.error
import urllib.request
from pathlib import Path

REPO = os.environ.get('KIENZLE_REPO', 'hau-si/kienzlesuite-2.0')
API = os.environ.get('KIENZLE_API', 'https://api.github.com')
OPENSSL = os.environ.get('KIENZLE_OPENSSL', '/usr/bin/openssl')
SCHLUESSEL = Path('/usr/share/kienzle-iso/release-keys')
MAX_ARCHIV = 100 * 1024 * 1024
TAG = re.compile(r'^core-v(\d{1,6})\.(\d{1,6})\.(\d{1,6})(?:-([0-9A-Za-z.]+))?$')


class Fehler(Exception):
    pass


def holen(url: str, grenze: int = MAX_ARCHIV) -> bytes:
    if not url.startswith('https://') and not os.environ.get('KIENZLE_TEST_HTTP'):
        raise Fehler('Nur https-Adressen erlaubt.')
    req = urllib.request.Request(url, headers={'User-Agent': 'kienzle-iso', 'Accept': 'application/vnd.github+json'})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            daten = r.read(grenze + 1)
    except (urllib.error.URLError, OSError) as e:
        raise Fehler(f'Download fehlgeschlagen ({url.rsplit("/", 1)[-1]}): {e}') from None
    if len(daten) > grenze:
        raise Fehler('Download zu groß.')
    return daten


def version_key(tag: str) -> tuple:
    m = TAG.match(tag)
    if not m:
        return ()
    pre = m.group(4)
    teile = [(0, int(p), '') if p.isdigit() else (1, 0, p) for p in pre.split('.')] if pre else None
    return (int(m.group(1)), int(m.group(2)), int(m.group(3)), (1,) if teile is None else (0, *teile))


def neuestes_release(releases: list, kanal: str) -> dict | None:
    """Stabil: nur endgültige Versionen. Beta: das neueste Release überhaupt (Vorabversionen eingeschlossen)."""
    kandidaten = []
    for r in releases:
        tag = str(r.get('tag_name', ''))
        if not TAG.match(tag) or r.get('draft'):
            continue
        if kanal == 'stabil' and (r.get('prerelease') or TAG.match(tag).group(4)):
            continue
        kandidaten.append((version_key(tag), r))
    return max(kandidaten, key=lambda k: k[0])[1] if kandidaten else None


def asset(release: dict, name: str) -> str | None:
    for a in release.get('assets', []):
        if a.get('name') == name:
            return str(a.get('browser_download_url'))
    return None


def signatur_pruefen(manifest: bytes, signatur: bytes, schluessel: Path) -> None:
    keys = sorted(p for p in schluessel.glob('*.pem') if p.is_file() and not p.is_symlink())
    if not keys:
        raise Fehler('Keine Release-Schlüssel hinterlegt.')
    if len(signatur) != 64:
        raise Fehler('Signatur hat die falsche Länge.')
    with tempfile.TemporaryDirectory() as t:
        m, s = Path(t) / 'm', Path(t) / 's'
        m.write_bytes(manifest)
        s.write_bytes(signatur)
        for k in keys:
            r = subprocess.run([OPENSSL, 'pkeyutl', '-verify', '-pubin', '-inkey', str(k), '-rawin', '-in', str(m), '-sigfile', str(s)],
                               capture_output=True, timeout=30)
            if r.returncode == 0:
                return
    raise Fehler('Signatur ungültig: Das Release stammt nicht vom Herausgeber oder wurde verändert.')


def auspacken(archiv: bytes, ziel: Path, abschneiden: int = 0) -> int:
    """Nur normale Dateien und Ordner, keine absoluten oder ausbrechenden Pfade, Rechte auf 0644/0755 begrenzt."""
    n = 0
    ziel.mkdir(parents=True, exist_ok=True)
    wurzel = ziel.resolve()
    with tarfile.open(fileobj=io.BytesIO(archiv), mode='r:gz') as tar:
        for mitglied in tar:
            teile = [p for p in mitglied.name.split('/') if p not in ('', '.')][abschneiden:]
            if not teile:
                continue
            if any(p == '..' for p in teile):
                raise Fehler('Archiv enthält ungültige Pfade.')
            pfad = (wurzel.joinpath(*teile)).resolve()
            if wurzel not in pfad.parents:
                raise Fehler('Archiv enthält ungültige Pfade.')
            if mitglied.isdir():
                pfad.mkdir(parents=True, exist_ok=True)
            elif mitglied.isfile():
                pfad.parent.mkdir(parents=True, exist_ok=True)
                quelle = tar.extractfile(mitglied)
                pfad.write_bytes(quelle.read() if quelle else b'')
                pfad.chmod(0o755 if mitglied.mode & 0o100 else 0o644)
                n += 1
            else:
                raise Fehler('Archiv enthält Verknüpfungen oder Sonderdateien.')
    return n


def release_holen(kanal: str, unsigniert: bool, schluessel: Path, ziel: Path) -> str:
    try:
        releases = json.loads(holen(f'{API}/repos/{REPO}/releases?per_page=100', 5 * 1024 * 1024))
    except ValueError:
        raise Fehler('Antwort von GitHub nicht lesbar.') from None
    rel = neuestes_release(releases if isinstance(releases, list) else [], kanal)
    if rel is None:
        raise Fehler(f'Kein Release im Kanal „{kanal}“ gefunden.')
    version = str(rel['tag_name'])[len('core-v'):]
    basis = f'core-{version}'
    url_a, url_m, url_s = asset(rel, basis + '.tar.gz'), asset(rel, basis + '.manifest.json'), asset(rel, basis + '.manifest.json.sig')
    if not url_a or not url_m:
        raise Fehler(f'Release {version} ist unvollständig.')
    manifest = holen(url_m, 5 * 1024 * 1024)
    if url_s:
        signatur_pruefen(manifest, holen(url_s, 4096), schluessel)
        stand = 'signiert'
    elif unsigniert:
        stand = 'UNSIGNIERT (nur Prüfsumme aus dem Manifest)'
    else:
        raise Fehler(f'Release {version} ist nicht signiert. Mit --unsigniert-erlauben ausdrücklich zulassen.')
    try:
        m = json.loads(manifest.decode('utf-8'))
        soll, groesse = m['archive']['sha256'], int(m['archive']['size'])
    except (ValueError, KeyError, TypeError):
        raise Fehler('Manifest nicht lesbar.') from None
    archiv = holen(url_a)
    if len(archiv) != groesse or hashlib.sha256(archiv).hexdigest() != soll:
        raise Fehler('Archiv passt nicht zum Manifest (beschädigt oder verändert).')
    auspacken(archiv, ziel)
    return f'Basis {version}, {stand}'


def zweig_holen(zweig: str, ziel: Path) -> str:
    if zweig not in ('main', 'beta'):
        raise Fehler('Zweig muss main oder beta sein.')
    archiv = holen(f'https://github.com/{REPO}/archive/refs/heads/{zweig}.tar.gz')
    auspacken(archiv, ziel, abschneiden=1)
    return f'Zweig {zweig}, UNSIGNIERT (kein Release, keine Prüfsumme)'


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    p.add_argument('--kanal', choices=('stabil', 'beta'), default='stabil')
    p.add_argument('--unsigniert-erlauben', action='store_true')
    p.add_argument('--zweig', choices=('main', 'beta'), default='')
    p.add_argument('--ziel', default='/opt/kienzle-installer')
    p.add_argument('--schluessel', default=str(SCHLUESSEL))
    p.add_argument('--name', default='suite')
    p.add_argument('--praxis', default='')
    p.add_argument('--ohne-installer', action='store_true', help='nur holen (Tests)')
    a = p.parse_args(argv)
    ziel = Path(a.ziel)
    try:
        try:
            info = release_holen(a.kanal, a.unsigniert_erlauben, Path(a.schluessel), ziel)
        except Fehler as e:
            if not (a.unsigniert_erlauben and a.zweig and 'Kein Release' in str(e)):
                raise
            print(f'Hinweis: {e} – hole stattdessen den Zweig.', file=sys.stderr)
            info = zweig_holen(a.zweig, ziel)
    except Fehler as e:
        print(f'FEHLER: {e}', file=sys.stderr)
        return 1
    print('Geholt:', info)
    if a.ohne_installer:
        return 0
    install = ziel / 'installer' / 'install.sh'
    if not install.is_file():
        print('FEHLER: installer/install.sh fehlt im Archiv.', file=sys.stderr)
        return 1
    cmd = ['bash', str(install), '--name', a.name]
    if a.praxis:
        cmd += ['--praxis', a.praxis]
    return subprocess.call(cmd)


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
