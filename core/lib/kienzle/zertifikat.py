"""Lokale Zertifizierungsstelle der Praxis (root-Werkzeug, Aufruf über core/bin/kienzle-zertifikat).

- Stamm-CA: ECDSA P-256, 10 Jahre, nur zum Ausstellen von Server-Zertifikaten (pathlen 0).
- Namensbeschränkung (nameConstraints, kritisch): nur die Servernamen der Praxis und private IP-Bereiche. Selbst mit
  gestohlenem CA-Schlüssel lassen sich damit keine gültigen Zertifikate für fremde Seiten (z. B. Banken) ausstellen.
- Server-Zertifikat: ECDSA P-256, höchstens 825 Tage (Vorgabe von macOS/iOS), wird 60 Tage vor Ablauf erneuert.
- Alle Aufrufe von openssl ohne Shell, Eingaben vorher geprüft; private Schlüssel nur für root (0600).
"""
from __future__ import annotations

import datetime as dt
import hashlib
import ipaddress
import os
import re
import secrets
import subprocess
import tempfile
from pathlib import Path

OPENSSL = os.environ.get('KIENZLE_OPENSSL', '/usr/bin/openssl')
CA_DAYS = 3650
SERVER_DAYS = 825
RENEW_DAYS = 60
PRIVATE_V4 = ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10')
PRIVATE_V6 = ('fc00::/7',)
HOST = re.compile(r'^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$')
PRACTICE = re.compile(r'^[\w .,&()/+-]{1,60}$')


class Fehler(Exception):
    pass


def split_names(names: list[str]) -> tuple[list[str], list[str]]:
    """Servernamen in DNS-Namen und IP-Adressen trennen; nur private Adressen, keine Platzhalter."""
    dns, ips = [], []
    for raw in names:
        name = raw.strip().lower()
        try:
            ip = ipaddress.ip_address(name)
        except ValueError:
            if not HOST.match(name):
                raise Fehler(f'Ungültiger Servername: {name[:60]}') from None
            if name not in dns:
                dns.append(name)
            continue
        if not any(ip in ipaddress.ip_network(n) for n in (PRIVATE_V4 if ip.version == 4 else PRIVATE_V6)):
            raise Fehler(f'{ip} ist keine private Adresse; die Suite ist nur im Praxisnetz erreichbar.')
        if str(ip) not in ips:
            ips.append(str(ip))
    if not dns and not ips:
        raise Fehler('Mindestens ein Servername oder eine IP-Adresse nötig.')
    return dns, ips


def _openssl(*args: str, stdin: bytes | None = None) -> bytes:
    r = subprocess.run([OPENSSL, *args], input=stdin, capture_output=True, timeout=60)
    if r.returncode != 0:
        raise Fehler('openssl: ' + r.stderr.decode('utf-8', 'replace').strip().splitlines()[-1][:200] if r.stderr else 'openssl fehlgeschlagen')
    return r.stdout


def _write_private(path: Path, data: bytes) -> None:
    tmp = path.with_name(path.name + '.neu')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as f:
        f.write(data)
    os.replace(tmp, path)


def _write_public(path: Path, data: bytes) -> None:
    tmp = path.with_name(path.name + '.neu')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o644)
    with os.fdopen(fd, 'wb') as f:
        f.write(data)
    os.chmod(tmp, 0o644)
    os.replace(tmp, path)


def _mask(net: str) -> str:
    n = ipaddress.ip_network(net)
    return f'{n.network_address}/{n.netmask}' if n.version == 4 else f'{n.network_address.exploded}/{n.netmask.exploded}'


def ca_extensions(dns: list[str]) -> str:
    permitted = [f'permitted;DNS:{d}' for d in dns] + [f'permitted;IP:{_mask(n)}' for n in PRIVATE_V4 + PRIVATE_V6]
    return ('[v3]\nbasicConstraints = critical, CA:TRUE, pathlen:0\nkeyUsage = critical, keyCertSign, cRLSign\n'
            'subjectKeyIdentifier = hash\nnameConstraints = critical, ' + ', '.join(permitted) + '\n')


def server_extensions(dns: list[str], ips: list[str]) -> str:
    alt = [f'DNS:{d}' for d in dns] + [f'IP:{i}' for i in ips]
    return ('[v3]\nbasicConstraints = critical, CA:FALSE\nkeyUsage = critical, digitalSignature\nextendedKeyUsage = serverAuth\n'
            'subjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid\nsubjectAltName = ' + ', '.join(alt) + '\n')


class Zertifikate:
    """Dateien im Ordner (Vorgabe /etc/kienzle/ca): ca.key, ca.crt, server.key, server.crt."""

    def __init__(self, folder: str | os.PathLike):
        self.dir = Path(folder)

    @property
    def ca_crt(self) -> Path: return self.dir / 'ca.crt'
    @property
    def ca_key(self) -> Path: return self.dir / 'ca.key'
    @property
    def server_crt(self) -> Path: return self.dir / 'server.crt'
    @property
    def server_key(self) -> Path: return self.dir / 'server.key'

    def exists(self) -> bool:
        return self.ca_crt.is_file() and self.ca_key.is_file()

    def create_ca(self, practice: str, names: list[str]) -> None:
        if self.exists():
            raise Fehler('Es gibt schon eine Zertifizierungsstelle. Eine neue müssten alle Arbeitsplätze neu einspielen.')
        if not PRACTICE.match(practice):
            raise Fehler('Praxisname für das Zertifikat: 1 bis 60 Zeichen, nur Buchstaben, Ziffern und einfache Satzzeichen.')
        dns, _ = split_names(names)
        self.dir.mkdir(mode=0o755, parents=True, exist_ok=True)
        key = _openssl('genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:P-256')
        with tempfile.TemporaryDirectory() as tmp:
            ext = Path(tmp) / 'ext.cnf'
            ext.write_text(ca_extensions(dns))
            keyfile = Path(tmp) / 'ca.key'
            _write_private(keyfile, key)
            crt = _openssl('req', '-x509', '-new', '-key', str(keyfile), '-sha256', '-days', str(CA_DAYS),
                           '-subj', f'/O=Kienzle-Suite/CN=Kienzle-Suite {practice}', '-config', str(ext), '-extensions', 'v3',
                           '-set_serial', '0x' + secrets.token_hex(16))
        _write_private(self.ca_key, key)
        _write_public(self.ca_crt, crt)

    def issue(self, names: list[str], days: int = SERVER_DAYS) -> None:
        """Neues Server-Zertifikat mit neuem Schlüssel für die angegebenen Namen."""
        if not self.exists():
            raise Fehler('Keine Zertifizierungsstelle vorhanden.')
        dns, ips = split_names(names)
        key = _openssl('genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:P-256')
        self._sign(key, dns, ips, days, (dns or ips)[0])
        _write_private(self.server_key, key)

    def _sign(self, key: bytes, dns: list[str], ips: list[str], days: int, cn: str, out: Path | None = None) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            keyfile = Path(tmp) / 'server.key'
            _write_private(keyfile, key)
            csr = Path(tmp) / 'server.csr'
            csr.write_bytes(_openssl('req', '-new', '-key', str(keyfile), '-subj', f'/O=Kienzle-Suite/CN={cn}'))
            ext = Path(tmp) / 'ext.cnf'
            ext.write_text(server_extensions(dns, ips))
            crt = _openssl('x509', '-req', '-in', str(csr), '-CA', str(self.ca_crt), '-CAkey', str(self.ca_key), '-sha256',
                           '-days', str(days), '-extfile', str(ext), '-extensions', 'v3', '-set_serial', '0x' + secrets.token_hex(16))
        _write_public(out or self.server_crt, crt)

    def fingerprint(self, path: Path | None = None) -> str:
        """SHA-256-Fingerabdruck (wie Browser und Windows ihn anzeigen), Bytes mit Doppelpunkt getrennt."""
        der = _openssl('x509', '-in', str(path or self.ca_crt), '-outform', 'DER')
        return ':'.join(f'{b:02X}' for b in hashlib.sha256(der).digest())

    def expires(self, path: Path | None = None) -> dt.datetime:
        out = _openssl('x509', '-in', str(path or self.server_crt), '-noout', '-enddate').decode()
        return dt.datetime.strptime(out.strip().split('=', 1)[1], '%b %d %H:%M:%S %Y %Z').replace(tzinfo=dt.timezone.utc)

    def names(self) -> list[str]:
        out = _openssl('x509', '-in', str(self.server_crt), '-noout', '-ext', 'subjectAltName').decode()
        found = re.findall(r'(?:DNS:|IP Address:)([^,\s]+)', out)
        return [n.lower() for n in found]

    def needs_renewal(self, names: list[str], now: dt.datetime | None = None) -> bool:
        if not self.server_crt.is_file() or not self.server_key.is_file():
            return True
        now = now or dt.datetime.now(dt.timezone.utc)
        dns, ips = split_names(names)
        current = self.names()
        wanted = dns + [str(ipaddress.ip_address(i)) for i in ips]
        return self.expires() - now < dt.timedelta(days=RENEW_DAYS) or sorted(current) != sorted(wanted)

    def verify(self, path: Path | None = None) -> bool:
        r = subprocess.run([OPENSSL, 'verify', '-CAfile', str(self.ca_crt), '-x509_strict', '-purpose', 'sslserver', str(path or self.server_crt)],
                           capture_output=True, timeout=30)
        return r.returncode == 0
