#!/usr/bin/env python3
"""Nachgebautes lpadmin/lpstat/lpinfo/lp für die Tests (Zustand in $FAKE_CUPS). Aufruf über Verweise mit dem Programmnamen."""
import json
import os
import sys
from pathlib import Path

state = Path(os.environ['FAKE_CUPS'])
data = json.loads(state.read_text()) if state.exists() else {'queues': {}, 'jobs': [], 'calls': []}
prog, args = Path(sys.argv[0]).name, sys.argv[1:]
data['calls'].append([prog, *args])


def save():
    state.write_text(json.dumps(data))


def opt(name):
    return args[args.index(name) + 1] if name in args else None


if prog == 'lpadmin':
    if '-x' in args:
        data['queues'].pop(opt('-x'), None)
    else:
        name = opt('-p')
        if os.environ.get('FAKE_CUPS_FAIL') == name:
            save()
            print('lpadmin: Unable to query printer: Connection refused', file=sys.stderr)
            sys.exit(1)
        q = data['queues'].setdefault(name, {'uri': '', 'desc': '', 'options': []})
        if opt('-v'):
            q['uri'] = opt('-v')
        if opt('-D'):
            q['desc'] = opt('-D')
        q['options'] += [args[i + 1] for i, a in enumerate(args) if a == '-o']
    save()
elif prog == 'lpstat':
    save()
    if not data['queues']:
        print('lpstat: No destinations added.', file=sys.stderr)
        sys.exit(1)
    if args == ['-o']:
        for j in data['jobs']:
            if not j.get('fertig'):
                print(f'{j["id"]}  {j["user"]}  {j["bytes"]}  Sun 22 Nov 2026 18:00:00 CET')
        sys.exit(0)
    if args == ['-v']:
        for n, q in data['queues'].items():
            print(f'device for {n}: {q["uri"]}')
    else:
        for n, q in data['queues'].items():
            print(f'printer {n} is idle.  enabled since Sun 22 Nov 2026')
            print(f'\tDescription: {q["desc"]}')
elif prog == 'lpinfo':
    save()
    print('network socket')
    print('network dnssd://EPSON%20TM-m30III._pdl-datastream._tcp.local/?uuid=1')
    print('network ipp://192.0.2.90/ipp/print')
    print('direct usb://EPSON/TM-m10?serial=X1')
    print('network bad uri with spaces')
elif prog == 'lp':
    data['jobs'].append({'args': args, 'bytes': len(sys.stdin.buffer.read()), 'id': f'{opt("-d")}-{len(data["jobs"]) + 1}', 'user': os.environ.get('USER', 'root')})
    save()
    print(f'request id is {opt("-d")}-{len(data["jobs"])} (1 file(s))')
elif prog == 'cancel':
    for j in data['jobs']:
        if j['id'] in args or ('-a' in args and j['id'].rsplit('-', 1)[0] == args[-1]):
            j['fertig'] = True
    save()
elif prog == 'net':
    data.setdefault('net', []).append({'args': args, 'passwd': os.environ.get('PASSWD')})
    save()
    if os.environ.get('PASSWD') != 'Domaenen-Passwort-1':
        print('Failed to join domain: Logon failure', file=sys.stderr)
        sys.exit(255)
elif prog == 'testparm':
    save()
    sys.exit(0)
elif prog == 'systemctl':
    data.setdefault('systemctl', []).append(args)
    save()
