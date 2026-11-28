#!/usr/bin/env python3
"""Erzeugt user-data und meta-data für die Start-ISO: Vorlage seed/autoinstall.yaml mit eingebettetem holen.py und den Release-Schlüsseln.

  erzeugen_seed.py AUSGABEORDNER
"""
from __future__ import annotations

import base64
import io
import sys
import tarfile
from pathlib import Path

HIER = Path(__file__).resolve().parent


def schluessel_tgz() -> bytes:
    pems = sorted((HIER.parent / 'installer' / 'release-keys').glob('*.pem'))
    if not pems:
        raise SystemExit('Keine Release-Schlüssel in installer/release-keys.')
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode='w:gz') as t:
        for p in pems:
            info = tarfile.TarInfo(p.name)
            data = p.read_bytes()
            info.size, info.mode, info.mtime = len(data), 0o644, 0
            t.addfile(info, io.BytesIO(data))
    return buf.getvalue()


def erzeugen(ziel: Path) -> None:
    text = (HIER / 'seed' / 'autoinstall.yaml').read_text(encoding='utf-8')
    b64 = lambda b: base64.b64encode(b).decode('ascii')  # noqa: E731
    text = text.replace('@@HOLEN_B64@@', b64((HIER / 'holen.py').read_bytes())).replace('@@SCHLUESSEL_B64@@', b64(schluessel_tgz()))
    if '@@' in text:
        raise SystemExit('Platzhalter in der Vorlage nicht ersetzt.')
    ziel.mkdir(parents=True, exist_ok=True)
    (ziel / 'user-data').write_text(text, encoding='utf-8')
    (ziel / 'meta-data').write_text('instance-id: praxisherz-installer\n', encoding='utf-8')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    erzeugen(Path(sys.argv[1]))
