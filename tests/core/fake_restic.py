#!/usr/bin/env python3
"""Nachgebautes restic für die Tests (nur die Befehle, die Praxisherz nutzt). Speichert Schnappschüsse als Ordner."""
import json
import os
import secrets
import shutil
import sys
import time
from pathlib import Path

repo = Path(os.environ['RESTIC_REPOSITORY'])
pw = Path(os.environ['RESTIC_PASSWORD_FILE']).read_text().strip()
args = sys.argv[1:]
while args and args[0] == '-o':
    args = args[2:]
cmd, rest = args[0], args[1:]
snaps = repo / 'snapshots'


def opt(name, default=None, many=False):
    vals = [rest[i + 1] for i, a in enumerate(rest) if a == name]
    return vals if many else (vals[0] if vals else default)


def fail(msg, code=1):
    print('Fatal: ' + msg, file=sys.stderr)
    sys.exit(code)


def under(path, prefixes):
    return any(path == p or path.startswith(p.rstrip('/') + '/') for p in prefixes)


def all_snapshots():
    return sorted([json.loads((d / 'meta.json').read_text()) for d in snaps.iterdir()], key=lambda m: m['time'])


def init():
    if (repo / 'config').exists():
        fail('create repository: config file already exists')
    repo.mkdir(parents=True, exist_ok=True)
    (repo / 'config').write_text(json.dumps({'id': secrets.token_hex(32), 'pw': pw}))
    snaps.mkdir()


def backup():
    excl = set(Path(opt('--exclude-file')).read_text().split()) if opt('--exclude-file') else set()
    paths = [a for i, a in enumerate(rest) if not a.startswith('--') and (i == 0 or rest[i - 1] not in ('--host', '--tag', '--exclude-file'))]
    sid = secrets.token_hex(32)
    dest = snaps / sid / 'daten'
    dest.mkdir(parents=True)
    total = 0
    for p in map(Path, paths):
        for f in [p] if p.is_file() else [x for x in p.rglob('*') if x.is_file()]:
            if under(str(f), excl):
                continue
            t = dest / str(f).lstrip('/')
            t.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(f, t)
            total += f.stat().st_size
    meta = {'id': sid, 'short_id': sid[:8], 'time': time.strftime('%Y-%m-%dT%H:%M:%S'), 'host': opt('--host'), 'tags': opt('--tag', many=True)}
    (snaps / sid / 'meta.json').write_text(json.dumps(meta))
    print(json.dumps({'message_type': 'summary', 'snapshot_id': sid, 'total_bytes_processed': total, 'data_added': total}))


def log(name):
    with open(repo / name, 'a') as f:
        f.write(' '.join(rest) + '\n')


def restore():
    sid, found = rest[0], all_snapshots()
    meta = found[-1] if sid == 'latest' and found else next((m for m in found if m['id'].startswith(sid)), None)
    if not meta:
        fail('no matching ID found')
    src = snaps / meta['id'] / 'daten'
    target, includes = Path(opt('--target')), opt('--include', many=True)
    for f in [x for x in src.rglob('*') if x.is_file()]:
        if includes and not under('/' + str(f.relative_to(src)), includes):
            continue
        t = target / str(f.relative_to(src))
        t.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(f, t)


if cmd == 'init':
    init()
    sys.exit(0)
if not (repo / 'config').exists():
    fail('repository does not exist', 10)
cfg = json.loads((repo / 'config').read_text())
if cfg['pw'] != pw:
    fail('wrong password or no key found', 12)
if cmd == 'cat':
    print(json.dumps({'id': cfg['id'], 'version': 2}))
elif cmd == 'backup':
    backup()
elif cmd == 'snapshots':
    print(json.dumps(all_snapshots()))
elif cmd == 'forget':
    log('forget.log')
elif cmd == 'check':
    if (repo / 'kaputt').exists():
        fail('check: pack 1234 contains errors')
    log('check.log')
    print('no errors were found')
elif cmd == 'restore':
    restore()
else:
    fail('unknown command ' + cmd)
