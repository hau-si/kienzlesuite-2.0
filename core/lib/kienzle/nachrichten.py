"""Nachrichten-Ausgang der Basis: SMS und E-Mail für Module, ohne dass ein Modul die Zugangsdaten kennt.

Läuft als Dienstbenutzer ks-nachrichten (kienzle-nachrichten.service, alle 20 Sekunden über kienzle-nachrichten.timer).
Die Zugänge (SMS über seven.io, E-Mail über SMTP oder Gmail) bekommt nur er: /etc/kienzle/verbindungen/nachrichten.json
(root:ks-nachrichten, 0640), dazu die Liste der Module, die die Verbindung „nachrichten“ angemeldet haben.

Ein Modul legt in seinen Ausgang /var/spool/kienzle/nachrichten/<modul>/ (Besitzer ks-<modul>, Gruppe ks-nachrichten, 2770):
  <auftrag>.json   {"art": "sms", "an": "+491701234567", "text": "…"}
                   {"art": "mail", "an": ["a@example.org"], "betreff": "…", "text": "…"}
Der Dienst verschickt, löscht den Auftrag und schreibt <auftrag>.ergebnis ({"stand": "gesendet"|"fehler", "meldung", "zeit"}, 0644).
Fehlschläge beim Versand werden 30 Minuten lang erneut versucht. Je Modul höchstens GRENZE Nachrichten je Stunde und Art.
Ins Protokoll kommen nie Empfänger oder Inhalte, nur Modul, Art und Ergebnis.
"""
from __future__ import annotations

import json
import os
import re
import time
from pathlib import Path

from . import verbindungen

E = os.environ.get
CONFIG = Path(E('KIENZLE_NACHRICHTEN_KONFIG', '/etc/kienzle/verbindungen/nachrichten.json'))
SPOOL = Path(E('KIENZLE_NACHRICHTEN_SPOOL', '/var/spool/kienzle/nachrichten'))
STATE = Path(E('KIENZLE_NACHRICHTEN_STATE', '/var/lib/kienzle/nachrichten'))
JOB = re.compile(r'^[A-Za-z0-9_-]{8,64}$')
MID = re.compile(r'^[a-z][a-z0-9-]{1,30}$')
GRENZE = {'sms': 60, 'mail': 120}       # je Modul und Stunde
WIEDERHOLEN = 30 * 60                   # so lange werden Fehlschläge erneut versucht
ERGEBNIS_TAGE = 7
MAX_AUFTRAG = 64 * 1024
SMS_TEXT = 320                          # wie send_sms der Basis (zwei SMS-Teile)
MAIL_TEXT = 20000


class Fehler(Exception):
    pass


def log(text: str) -> None:
    print(f'nachrichten: {text}', flush=True)


def pruefen(job: dict) -> dict:
    """Auftrag prüfen und vereinheitlichen; wirft Fehler mit Meldung für das Modul."""
    if not isinstance(job, dict):
        raise Fehler('Auftrag ungültig.')
    art = job.get('art')
    text = job.get('text')
    if not isinstance(text, str) or not text.strip() or verbindungen.CONTROL.search(text.replace('\n', ' ').replace('\t', ' ')):
        raise Fehler('Text fehlt oder enthält Steuerzeichen.')
    if art == 'sms':
        an = job.get('an')
        if not isinstance(an, str) or not verbindungen.SMS_NUMBER.match(an):
            raise Fehler('Handynummer bitte im Format +491701234567.')
        if len(text) > SMS_TEXT:
            raise Fehler(f'SMS-Text höchstens {SMS_TEXT} Zeichen.')
        return {'art': 'sms', 'an': an, 'text': text}
    if art == 'mail':
        an = job.get('an')
        an = [an] if isinstance(an, str) else an
        if not isinstance(an, list) or not 1 <= len(an) <= 5 or not all(isinstance(a, str) and verbindungen.EMAIL.match(a) for a in an):
            raise Fehler('Ein bis fünf gültige E-Mail-Adressen.')
        betreff = job.get('betreff')
        if not isinstance(betreff, str) or not betreff.strip() or len(betreff) > 150 or verbindungen.CONTROL.search(betreff):
            raise Fehler('Betreff fehlt oder ist ungültig (höchstens 150 Zeichen).')
        if len(text) > MAIL_TEXT:
            raise Fehler(f'E-Mail-Text höchstens {MAIL_TEXT} Zeichen.')
        return {'art': 'mail', 'an': an, 'betreff': betreff, 'text': text}
    raise Fehler('Art muss „sms“ oder „mail“ sein.')


def senden(cfg: dict, job: dict) -> None:
    try:
        if job['art'] == 'sms':
            verbindungen.send_sms(cfg.get('sms'), job['an'], job['text'])
        else:
            verbindungen.send_mail(cfg.get('mail'), job['an'], job['betreff'], job['text'])
    except verbindungen.Fehler as e:
        raise Fehler(str(e)) from None


def _zaehler() -> dict:
    try:
        d = json.loads((STATE / 'zaehler.json').read_text(encoding='utf-8'))
    except (OSError, ValueError):
        d = {}
    stunde = int(time.time() // 3600)
    return d if d.get('stunde') == stunde else {'stunde': stunde, 'anzahl': {}}


def _schreiben(path: Path, data: dict, mode: int) -> None:
    tmp = path.with_name('.' + path.name + '.neu')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        f.write(json.dumps(data, ensure_ascii=False) + '\n')
    os.chmod(tmp, mode)
    os.replace(tmp, path)


def _ergebnis(ordner: Path, name: str, stand: str, meldung: str) -> None:
    _schreiben(ordner / f'{name}.ergebnis', {'stand': stand, 'meldung': meldung[:300], 'zeit': int(time.time())}, 0o644)


def _lesen(path: Path) -> dict:
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as f:
        raw = f.read(MAX_AUFTRAG + 1)
    if len(raw) > MAX_AUFTRAG:
        raise Fehler('Auftrag zu groß.')
    try:
        return json.loads(raw.decode('utf-8'))
    except ValueError:
        raise Fehler('Auftrag ist kein gültiges JSON.') from None


def abarbeiten(now: float | None = None, send=senden) -> dict:
    """Alle Ausgänge der angemeldeten Module abarbeiten. Liefert Zähler {gesendet, fehler, wartet}."""
    now = now or time.time()
    out = {'gesendet': 0, 'fehler': 0, 'wartet': 0}
    try:
        cfg = json.loads(CONFIG.read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return out
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    zaehler = _zaehler()
    for mid in cfg.get('module', []):
        ordner = SPOOL / str(mid)
        if not MID.match(str(mid)) or not ordner.is_dir() or ordner.is_symlink():
            continue
        for p in sorted(ordner.glob('*.json')):
            name = p.name[:-5]
            if not JOB.match(name) or p.is_symlink():
                continue
            try:
                alter = now - p.stat().st_mtime
                job = pruefen(_lesen(p))
            except (Fehler, OSError) as e:
                _ergebnis(ordner, name, 'fehler', str(e))
                p.unlink(missing_ok=True)
                out['fehler'] += 1
                log(f'{mid}: Auftrag abgelehnt.')
                continue
            if not cfg.get(job['art']):
                meldung = 'SMS ist in der Basis nicht eingerichtet (Verwaltung → Verbindungen).' if job['art'] == 'sms' else \
                    'E-Mail ist in der Basis nicht eingerichtet (Verwaltung → Verbindungen).'
                _ergebnis(ordner, name, 'fehler', meldung)
                p.unlink(missing_ok=True)
                out['fehler'] += 1
                continue
            schluessel = f'{mid}/{job["art"]}'
            if zaehler['anzahl'].get(schluessel, 0) >= GRENZE[job['art']]:
                if alter > WIEDERHOLEN:
                    _ergebnis(ordner, name, 'fehler', 'Zu viele Nachrichten in kurzer Zeit; nicht verschickt.')
                    p.unlink(missing_ok=True)
                    out['fehler'] += 1
                else:
                    out['wartet'] += 1
                continue
            try:
                send(cfg, job)
            except Fehler as e:
                if alter > WIEDERHOLEN:
                    _ergebnis(ordner, name, 'fehler', str(e))
                    p.unlink(missing_ok=True)
                    out['fehler'] += 1
                    log(f'{mid}: {job["art"]} nicht verschickt (aufgegeben).')
                else:
                    out['wartet'] += 1
                    log(f'{mid}: {job["art"]} noch nicht verschickt, neuer Versuch folgt.')
                continue
            zaehler['anzahl'][schluessel] = zaehler['anzahl'].get(schluessel, 0) + 1
            _schreiben(STATE / 'zaehler.json', zaehler, 0o600)
            _ergebnis(ordner, name, 'gesendet', 'SMS verschickt.' if job['art'] == 'sms' else 'E-Mail verschickt.')
            p.unlink(missing_ok=True)
            out['gesendet'] += 1
            log(f'{mid}: {job["art"]} verschickt.')
        for r in ordner.glob('*.ergebnis'):
            try:
                if not r.is_symlink() and r.stat().st_mtime < now - ERGEBNIS_TAGE * 86400:
                    r.unlink()
            except OSError:
                pass
    return out
