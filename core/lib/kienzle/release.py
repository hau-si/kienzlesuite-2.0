"""Signierte Releases: Manifest bauen und prüfen, Signatur prüfen, Archiv sicher auspacken.

Ein Release eines Teils (Basis „core“ oder Modul) besteht aus drei Dateien:
  <teil>-<version>.tar.gz              Archiv (Ordner core/ und installer/ bzw. modules/<id>/)
  <teil>-<version>.manifest.json       Teil, Version, Kanal, Schnittstelle, SHA-256 von Archiv und jeder Datei
  <teil>-<version>.manifest.json.sig   Ed25519-Signatur über die Bytes des Manifests (OpenSSL, ohne externe Stelle)

Vertraut wird nur Schlüsseln in /etc/kienzle/release-keys/*.pem (bringt der Installer mit). Geprüft wird vor dem
Auspacken: Signatur, Format, Teil, Version, Prüfsumme des Archivs; nach dem Auspacken: genau die Dateien des Manifests
mit Größe, Prüfsumme und Rechten. Das Archiv darf nur normale Dateien und Ordner enthalten.
"""
from __future__ import annotations

import gzip
import hashlib
import io
import json
import os
import re
import subprocess
import tarfile
import tempfile
from pathlib import Path

OPENSSL = os.environ.get('KIENZLE_OPENSSL', '/usr/bin/openssl')
FORMAT = 'kienzle-release-1'
PART = re.compile(r'^(core|[a-z][a-z0-9]*(?:-[a-z0-9]+)*)$')
VERSION = re.compile(r'^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})(?:-([0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*))?$')
PATH = re.compile(r'^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$')
CHANNELS = ('stabil', 'beta')
MAX_ARCHIVE = 100 * 1024 * 1024
MAX_FILES = 5000
MAX_TOTAL = 300 * 1024 * 1024


class Fehler(Exception):
    pass


# ---- Versionen ---------------------------------------------------------------------------------------------------
def parse_version(v: str) -> tuple:
    m = VERSION.match(v) if isinstance(v, str) else None
    if not m:
        raise Fehler(f'Ungültige Version: {str(v)[:40]}')
    pre = m.group(4)
    # Vorabversion < endgültige Version (SemVer 11); Teile numerisch bzw. als Text vergleichen.
    pre_key = (1,) if pre is None else (0, *[(0, int(p), '') if p.isdigit() else (1, 0, p) for p in pre.split('.')])
    return (int(m.group(1)), int(m.group(2)), int(m.group(3)), pre_key)


def newer(a: str, b: str) -> bool:
    return parse_version(a) > parse_version(b)


def names(part: str, version: str) -> dict:
    base = f'{part}-{version}'
    return {'archive': base + '.tar.gz', 'manifest': base + '.manifest.json', 'signature': base + '.manifest.json.sig'}


# ---- Bauen (Herausgeber) -----------------------------------------------------------------------------------------
def _tar(files: dict[str, bytes], modes: dict[str, int]) -> bytes:
    """Reproduzierbares Archiv: sortiert, Zeit 0, Eigentümer root, nur Dateien (Ordner entstehen beim Auspacken)."""
    raw = io.BytesIO()
    with tarfile.open(fileobj=raw, mode='w', format=tarfile.PAX_FORMAT) as tar:
        for name in sorted(files):
            info = tarfile.TarInfo(name)
            info.size = len(files[name])
            info.mode = modes[name]
            info.mtime = 0
            info.uid = info.gid = 0
            info.uname = info.gname = 'root'
            tar.addfile(info, io.BytesIO(files[name]))
    out = io.BytesIO()
    with gzip.GzipFile(fileobj=out, mode='wb', mtime=0) as gz:
        gz.write(raw.getvalue())
    return out.getvalue()


def build(part: str, version: str, channel: str, sources: dict[str, Path], extra: dict) -> tuple[bytes, bytes]:
    """Archiv und Manifest aus Dateien bauen. sources: Pfad im Archiv → Datei auf der Platte."""
    if not PART.match(part) or channel not in CHANNELS:
        raise Fehler('Teil oder Kanal ungültig.')
    parse_version(version)
    files, modes, listing = {}, {}, {}
    for name, src in sorted(sources.items()):
        if not PATH.match(name) or '..' in name.split('/'):
            raise Fehler(f'Ungültiger Pfad im Release: {name}')
        data = Path(src).read_bytes()
        mode = 0o755 if os.stat(src).st_mode & 0o100 else 0o644
        files[name], modes[name] = data, mode
        listing[name] = {'sha256': hashlib.sha256(data).hexdigest(), 'size': len(data), 'mode': oct(mode)}
    archive = _tar(files, modes)
    manifest = {'format': FORMAT, 'part': part, 'version': version, 'channel': channel, **extra,
                'archive': {'name': names(part, version)['archive'], 'sha256': hashlib.sha256(archive).hexdigest(), 'size': len(archive)},
                'files': listing}
    return archive, (json.dumps(manifest, ensure_ascii=False, indent=1, sort_keys=True) + '\n').encode()


def sign(manifest: Path, key: Path) -> bytes:
    r = subprocess.run([OPENSSL, 'pkeyutl', '-sign', '-inkey', str(key), '-rawin', '-in', str(manifest)], capture_output=True, timeout=120)
    if r.returncode != 0:
        raise Fehler('Signieren fehlgeschlagen: ' + r.stderr.decode(errors='replace').strip()[:200])
    return r.stdout


# ---- Prüfen (Server) ---------------------------------------------------------------------------------------------
def trusted_keys(folder: Path) -> list[Path]:
    keys = sorted(p for p in Path(folder).glob('*.pem') if p.is_file() and not p.is_symlink())
    if not keys:
        raise Fehler('Kein Release-Schlüssel eingerichtet; Updates sind gesperrt (siehe docs/RELEASE-ABLAUF.md).')
    return keys


def verify_signature(manifest: bytes, signature: bytes, keys: list[Path]) -> Path:
    """Gültig, wenn einer der vertrauten Schlüssel passt. Liefert den Schlüssel."""
    if len(signature) != 64:
        raise Fehler('Signatur hat die falsche Länge.')
    with tempfile.TemporaryDirectory() as tmp:
        m, s = Path(tmp) / 'manifest', Path(tmp) / 'sig'
        m.write_bytes(manifest)
        s.write_bytes(signature)
        for key in keys:
            r = subprocess.run([OPENSSL, 'pkeyutl', '-verify', '-pubin', '-inkey', str(key), '-rawin', '-in', str(m), '-sigfile', str(s)],
                               capture_output=True, timeout=30)
            if r.returncode == 0:
                return key
    raise Fehler('Signatur ungültig: Das Release stammt nicht vom Herausgeber oder wurde verändert.')


def parse_manifest(raw: bytes, part: str) -> dict:
    try:
        m = json.loads(raw.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        raise Fehler('Manifest ist kein gültiges JSON.') from None
    if not isinstance(m, dict) or m.get('format') != FORMAT:
        raise Fehler('Unbekanntes Release-Format.')
    if m.get('part') != part:
        raise Fehler(f'Release gehört zu „{str(m.get("part"))[:30]}“, nicht zu „{part}“.')
    parse_version(m.get('version'))
    if m.get('channel') not in CHANNELS:
        raise Fehler('Unbekannter Kanal im Manifest.')
    a = m.get('archive')
    if not isinstance(a, dict) or not re.fullmatch(r'[0-9a-f]{64}', str(a.get('sha256'))) or not isinstance(a.get('size'), int) \
            or a.get('name') != names(part, m['version'])['archive'] or not 0 < a['size'] <= MAX_ARCHIVE:
        raise Fehler('Manifest: Angaben zum Archiv fehlen oder sind ungültig.')
    files = m.get('files')
    if not isinstance(files, dict) or not 0 < len(files) <= MAX_FILES:
        raise Fehler('Manifest: Dateiliste fehlt.')
    total = 0
    for name, f in files.items():
        if not PATH.match(name) or '..' in name.split('/') or not isinstance(f, dict) or f.get('mode') not in ('0o644', '0o755') \
                or not re.fullmatch(r'[0-9a-f]{64}', str(f.get('sha256'))) or not isinstance(f.get('size'), int) or f['size'] < 0:
            raise Fehler('Manifest: ungültiger Dateieintrag.')
        total += f['size']
    if total > MAX_TOTAL:
        raise Fehler('Release zu groß.')
    return m


def check_archive(archive: bytes, manifest: dict) -> None:
    if len(archive) != manifest['archive']['size'] or hashlib.sha256(archive).hexdigest() != manifest['archive']['sha256']:
        raise Fehler('Archiv passt nicht zum Manifest (beschädigt oder verändert).')


def extract(archive: bytes, manifest: dict, target: Path) -> None:
    """Nur die Dateien des Manifests, nur normale Dateien, keine Pfade nach außen; danach jede Datei prüfen."""
    files = manifest['files']
    target.mkdir(mode=0o755, parents=True, exist_ok=False)
    seen = set()
    with tarfile.open(fileobj=io.BytesIO(archive), mode='r:gz') as tar:
        for member in tar:
            if len(seen) > MAX_FILES:
                raise Fehler('Archiv enthält zu viele Einträge.')
            if member.isdir():
                continue
            if not member.isreg() or member.name not in files or member.name in seen:
                raise Fehler(f'Unerwarteter Eintrag im Archiv: {member.name[:80]}')
            f = files[member.name]
            if member.size != f['size']:
                raise Fehler(f'Größe stimmt nicht: {member.name[:80]}')
            data = tar.extractfile(member).read(f['size'] + 1)
            if len(data) != f['size'] or hashlib.sha256(data).hexdigest() != f['sha256']:
                raise Fehler(f'Prüfsumme stimmt nicht: {member.name[:80]}')
            dest = target / member.name
            dest.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
            fd = os.open(dest, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, int(f['mode'], 8))
            with os.fdopen(fd, 'wb') as out:
                out.write(data)
            os.chmod(dest, int(f['mode'], 8))
            seen.add(member.name)
    missing = set(files) - seen
    if missing:
        raise Fehler(f'Im Archiv fehlen Dateien: {sorted(missing)[0][:80]}')
