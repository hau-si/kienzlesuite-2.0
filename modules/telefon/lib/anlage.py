#!/usr/bin/env python3
"""Telefonanlage des Moduls telefon (Kienzle-Suite): Asterisk-Konfiguration erzeugen, neu laden, Zustand lesen.

Übernommen aus Kienzlefax 3.5.0 von Dr. Thomas Kienzle (installer-modular/pjsip-provider.sh, extensions.sh,
telefonie-queue.sh): Leitungsvorlagen der Anbieter, gemeinsame Kanalgrenze (kfx_external_capacity), Faxversand
(fax-out, kfx_sendfax), Faxempfang (fax-in), Telefonieeingang, Warteschlange „praxis“ mit wachsender Rufgruppe,
interne Nebenstellen und Sipgate-Überlauf. Die Namen der Kontexte bleiben gleich, damit die Teile aus Kienzlefon
(Sprachmenü, Vermittlung) später darauf aufsetzen können.

Anders als dort: kein root, keine Dateien unter /etc/asterisk. Asterisk läuft als ks-telefon mit eigener
Konfiguration unter <Datenordner>/asterisk; die Einstellungen kommen als JSON von der Weboberfläche (stdin).

Aufrufe (als ks-telefon):
  anlage.py grundgeruest       Ordner und Grunddateien anlegen (vor dem Start von Asterisk, ohne Zugangsdaten)
  anlage.py pruefen   < json   Einstellungen prüfen, nichts schreiben
  anlage.py anwenden  < json   prüfen, Dateien schreiben, Asterisk neu laden
  anlage.py status             Zustand als JSON (Leitungen, Telefone, Gespräche, Warteschlange)
  anlage.py pflege             Protokolle und alte Empfangsreste aufräumen (täglich)
"""
from __future__ import annotations

import glob
import ipaddress
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import time
from pathlib import Path

E = os.environ.get
DATA = Path(E('KIENZLE_DATEN', '/var/lib/kienzle/telefon'))
AST = DATA / 'asterisk'
ETC = AST / 'etc'
EXCHANGE = Path(E('KIENZLE_AUSTAUSCH', '/var/spool/kienzle/austausch/fax_telefon'))
PROGRAM = Path(E('KIENZLE_PROGRAMM', '/opt/kienzle/telefon/current'))
ASTERISK = E('KIENZLE_ASTERISK', '/usr/sbin/asterisk')
LOG_KEEP_DAYS = 7

PROVIDER_PORT = 5070
PHONE_PORT = 5060
TLS_PORT = 5061
RTP_START, RTP_END = 12000, 12999
AMI_PORT = 5038
AMI_USER = 'kienzle-fax'
CA_LIST = '/etc/ssl/certs/ca-certificates.crt'

# Anbietervorlagen (pjsip-provider.sh und telefonie-queue.sh von Kienzlefax).
ANBIETER = {
    '1und1': {'name': '1&1 (UDP)', 'domain': 'sip.1und1.de', 'identify': '212.227.0.0/16'},
    '1und1-tls': {'name': '1&1 (TLS, verschlüsselt)', 'domain': 'sip.1und1.de', 'identify': '212.227.0.0/16', 'tls': True},
    'telekom': {'name': 'Telekom', 'domain': 'tel.t-online.de'},
    'sipgate': {'name': 'sipgate (Basic/Team)', 'domain': 'sipgate.de'},
    'sipgate-trunk': {'name': 'sipgate trunking (nur Überlauf)', 'domain': 'sipconnect.sipgate.de', 'identify': '217.10.68.150', 'trunk': True},
    'voip2gsm': {'name': 'voip2gsm', 'domain': 'b.vsip.eu', 'identify_domain': True, 'line': True, 'national': True},
    'fonial': {'name': 'Fonial / Plusnet', 'domain': 'sip.plusnet.de', 'proxy': 'sbc.sip.plusnet.de', 'line': True},
    'andere': {'name': 'Anderer Anbieter', 'domain': ''},
}
NOTRUF = {'110', '112', '115', '116116', '116117'}
# Sprachausgabe für die Ansagen von Kienzlefon: Piper (Standard, lokal), Google und Kokoro wahlweise.
TTS = ('piper', 'google', 'kokoro')

ID = re.compile(r'^[a-z0-9]{1,12}$')
NUMMER = re.compile(r'^\+?[0-9]{3,20}$')
NEBENSTELLE = re.compile(r'^[1-8][0-9]{2,3}$')
HOST = re.compile(r'^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*(?::[0-9]{1,5})?$')
SIP_USER = re.compile(r'^[A-Za-z0-9_.+@-]{1,80}$')
URI = re.compile(r'^sip:[A-Za-z0-9_.+@:-]{1,150}$')
CONTROL = re.compile(r'[\x00-\x1f\x7f]')


class Fehler(Exception):
    pass


# ---- Prüfung -----------------------------------------------------------------------------------------------------
def _text(value, name: str, limit: int, required: bool = False) -> str:
    value = '' if value is None else str(value).strip()
    if required and not value:
        raise Fehler(f'{name}: Bitte ausfüllen.')
    if CONTROL.search(value) or len(value) > limit:
        raise Fehler(f'{name}: höchstens {limit} Zeichen, keine Steuerzeichen.')
    return value


def _name(value, name: str, limit: int = 40) -> str:
    """Anzeigename für Asterisk (callerid, Kommentar): keine Zeichen, die die Konfiguration brechen."""
    value = _text(value, name, limit)
    if re.search(r'[;<>"\\\[\]=,()|^$`]', value):
        raise Fehler(f'{name}: Bitte nur Buchstaben, Zahlen, Leerzeichen, Punkt, Bindestrich, & und /.')
    return value


def _ascii(text) -> str:
    text = str(text or '').strip()
    for a, b in (('ä', 'ae'), ('ö', 'oe'), ('ü', 'ue'), ('Ä', 'Ae'), ('Ö', 'Oe'), ('Ü', 'Ue'), ('ß', 'ss')):
        text = text.replace(a, b)
    return re.sub(r' +', ' ', re.sub(r'[^A-Za-z0-9 .&/-]', '', text)).strip()


def _kuerzen(text: str, limit: int) -> str:
    if len(text) > limit:
        cut = text[:limit]
        text = cut.rsplit(' ', 1)[0] if ' ' in cut and text[limit] != ' ' else cut
    return text.strip(' .&/-')


def kennung_kurz(name) -> str:
    """Stationskennung im Faxprotokoll: T.30 erlaubt nur 20 Zeichen (ASCII); an Wortgrenze gekürzt."""
    return _kuerzen(_ascii(name), 20) or 'Fax'


def kennung_aus_praxis(praxis) -> str:
    """Vorgabe der Faxkennung (Name im Faxkopf): Praxisname der Basis ohne Sonderzeichen, höchstens 60 Zeichen; sonst „Fax“."""
    return _kuerzen(_ascii(praxis), 60) or 'Fax'


def _pattern(value, regex: re.Pattern, name: str, example: str, required: bool = False) -> str:
    value = '' if value is None else str(value).strip()
    if not value:
        if required:
            raise Fehler(f'{name}: Bitte ausfüllen (z. B. {example}).')
        return ''
    if not regex.match(value):
        raise Fehler(f'{name}: „{value[:40]}“ ist ungültig (z. B. {example}).')
    return value


def _int(value, name: str, low: int, high: int, default: int) -> int:
    if value in (None, ''):
        return default
    try:
        n = int(value)
    except (TypeError, ValueError):
        raise Fehler(f'{name}: Bitte eine Zahl angeben.') from None
    if not low <= n <= high:
        raise Fehler(f'{name}: zwischen {low} und {high}.')
    return n


def _net(value, name: str) -> str:
    value = _text(value, name, 43)
    if not value:
        return ''
    try:
        net = ipaddress.ip_network(value, strict=False)
    except ValueError:
        raise Fehler(f'{name}: „{value}“ ist kein Netz (z. B. 192.0.2.0/24).') from None
    if net.version != 4:
        raise Fehler(f'{name}: nur IPv4-Netze.')
    return str(net)


def did(value) -> str:
    """Rufnummer, wie sie der Anbieter in der Anfrage schickt: Ziffern, ein führendes + wird entfernt."""
    return str(value or '').strip().lstrip('+')


def check(cfg: dict) -> dict:
    """Einstellungen prüfen und vereinheitlichen. Wirft Fehler mit einer verständlichen Meldung."""
    if not isinstance(cfg, dict):
        raise Fehler('Einstellungen fehlen.')
    out: dict = {'leitungen': [], 'nebenstellen': []}
    ids = set()
    for n, raw in enumerate(cfg.get('leitungen') or [], 1):
        if not isinstance(raw, dict):
            raise Fehler('Leitung ungültig.')
        lid = _pattern(raw.get('id'), ID, f'Leitung {n}', 'l1', required=True)
        if lid in ids:
            raise Fehler(f'Leitung {n}: Kennung doppelt.')
        ids.add(lid)
        anbieter = str(raw.get('anbieter') or '')
        if anbieter not in ANBIETER:
            raise Fehler(f'Leitung {n}: Bitte einen Anbieter wählen.')
        vorlage = ANBIETER[anbieter]
        name = _name(raw.get('name'), f'Leitung {n}: Name', 40) or vorlage['name']
        line = {
            'id': lid, 'name': name, 'anbieter': anbieter,
            'benutzer': _pattern(raw.get('benutzer'), SIP_USER, f'{name}: Benutzername', '4930123456', required=True),
            'passwort': _text(raw.get('passwort'), f'{name}: Passwort', 128, required=True),
            'domain': _pattern(raw.get('domain'), HOST, f'{name}: Server', 'sip.example.org') or vorlage['domain'],
            'nummer': did(_pattern(raw.get('nummer'), NUMMER, f'{name}: Rufnummer', '4930123456')),
            'proxy': _pattern(raw.get('proxy'), HOST, f'{name}: Proxy', 'sbc.example.org') or vorlage.get('proxy', ''),
            'identify': _text(raw.get('identify'), f'{name}: Absender-Netz', 200),
            'client_uri': _pattern(raw.get('client_uri'), URI, f'{name}: Client-URI', 'sip:4930123456@sip.example.org'),
            'from_user': _pattern(raw.get('from_user'), SIP_USER, f'{name}: From-User', '4930123456'),
            'ablauf': _int(raw.get('ablauf'), f'{name}: Registrierungsdauer', 60, 3600, 600 if vorlage.get('trunk') else 300),
        }
        if not line['domain']:
            raise Fehler(f'{name}: Bitte den SIP-Server des Anbieters angeben.')
        if any(c in line['passwort'] for c in '\r\n'):
            raise Fehler(f'{name}: Passwort ohne Zeilenumbruch.')
        if line['identify'] == '-':
            line['identify'] = ''
        elif not line['identify']:
            line['identify'] = line['domain'].split(':')[0] if vorlage.get('identify_domain') else vorlage.get('identify', '')
        for part in [p for p in line['identify'].split(',') if p.strip()]:
            part = part.strip()
            try:
                ipaddress.ip_network(part, strict=False)
            except ValueError:
                if not HOST.match(part):
                    raise Fehler(f'{name}: Absender-Netz „{part[:40]}“ ist weder Netz noch Servername.') from None
        if vorlage.get('line') and not line['nummer']:
            raise Fehler(f'{name}: {vorlage["name"]} braucht die Rufnummer der Leitung.')
        out['leitungen'].append(line)
    by_id = {x['id']: x for x in out['leitungen']}

    def pick(value, name: str, required: bool) -> str:
        value = str(value or '')
        if not value:
            if required:
                raise Fehler(f'{name}: Bitte eine Leitung wählen.')
            return ''
        if value not in by_id:
            raise Fehler(f'{name}: Leitung unbekannt.')
        return value

    f = cfg.get('fax') or {}
    fax = {'leitung': pick(f.get('leitung'), 'Fax', False)}
    if fax['leitung']:
        if ANBIETER[by_id[fax['leitung']]['anbieter']].get('trunk'):
            raise Fehler('Fax: sipgate trunking ist nur für den Telefonie-Überlauf vorgesehen.')
        fax['nummer'] = did(_pattern(f.get('nummer'), NUMMER, 'Faxnummer', '4930123459', required=True))
        fax['kennung'] = _pattern(f.get('kennung'), NUMMER, 'Absendernummer für Faxe', '4930123459') or fax['nummer']
        fax['name'] = _name(f.get('name'), 'Faxkennung', 60) or kennung_aus_praxis(cfg.get('praxis'))
    out['fax'] = fax

    t = cfg.get('telefon') or {}
    tel = {'leitung_ein': pick(t.get('leitung_ein'), 'Telefon (eingehend)', False)}
    if tel['leitung_ein']:
        if ANBIETER[by_id[tel['leitung_ein']]['anbieter']].get('trunk'):
            raise Fehler('Telefon: sipgate trunking ist nur für den Überlauf vorgesehen.')
        tel['nummer_ein'] = did(_pattern(t.get('nummer_ein'), NUMMER, 'Telefonnummer der Praxis', '4930123456', required=True))
        if fax.get('nummer') and fax['nummer'] == tel['nummer_ein']:
            raise Fehler('Telefonnummer und Faxnummer müssen verschieden sein.')
        tel['leitung_aus'] = pick(t.get('leitung_aus'), 'Telefon (ausgehend)', False) or tel['leitung_ein']
        if ANBIETER[by_id[tel['leitung_aus']]['anbieter']].get('trunk'):
            raise Fehler('Telefon (ausgehend): sipgate trunking ist nur für den Überlauf vorgesehen.')
        tel['kennung_aus'] = _pattern(t.get('kennung_aus'), NUMMER, 'Absendernummer beim Anrufen', '4930123456') or tel['nummer_ein']
        tel['aus_zaehlt'] = t.get('aus_zaehlt', True) is not False
        tel['ausland'] = t.get('ausland') is True
        tel['sonderrufnummern'] = t.get('sonderrufnummern') is True
    out['telefon'] = tel

    u = cfg.get('ueberlauf') or {}
    ueb = {'leitung': pick(u.get('leitung'), 'Überlauf', False)}
    if ueb['leitung']:
        if not tel['leitung_ein']:
            raise Fehler('Überlauf: zuerst die eingehende Telefonleitung einrichten.')
        if not ANBIETER[by_id[ueb['leitung']]['anbieter']].get('trunk'):
            raise Fehler('Überlauf: nur mit einer Leitung „sipgate trunking“.')
        ueb['nummer'] = did(_pattern(u.get('nummer'), NUMMER, 'Überlauf-Rufnummer', '4930123457', required=True))
    out['ueberlauf'] = ueb

    k = cfg.get('kanaele') or {}
    kan = {'gesamt': _int(k.get('gesamt'), 'Kanäle insgesamt', 1, 60, 4)}
    kan['telefon'] = _int(k.get('telefon'), 'Kanäle für Telefon', 1, 60, min(3, kan['gesamt']))
    kan['fax'] = _int(k.get('fax'), 'Kanäle für Fax', 1, 60, min(3, kan['gesamt']))
    kan['ueberlauf'] = _int(k.get('ueberlauf'), 'Kanäle des Überlaufs', 1, 30, 2)
    if kan['telefon'] > kan['gesamt'] or kan['fax'] > kan['gesamt']:
        raise Fehler('Kanäle: Telefon und Fax dürfen die Gesamtzahl nicht überschreiten.')
    out['kanaele'] = kan
    out['warteschlange'] = {'max_wartend': _int((cfg.get('warteschlange') or {}).get('max_wartend'), 'Wartende Anrufer', 1, 50, 5)}

    seen = set()
    for n, raw in enumerate(cfg.get('nebenstellen') or [], 1):
        if not isinstance(raw, dict):
            raise Fehler('Nebenstelle ungültig.')
        num = _pattern(raw.get('nummer'), NEBENSTELLE, f'Nebenstelle {n}', '201', required=True)
        if num in seen:
            raise Fehler(f'Nebenstelle {num} ist doppelt.')
        if num in NOTRUF or num.startswith('11'):
            raise Fehler(f'Nebenstelle {num}: 11x ist für Notrufe und Sonderdienste reserviert.')
        seen.add(num)
        pw = _text(raw.get('passwort'), f'Nebenstelle {num}: Passwort', 128, required=True)
        if len(pw) < 16 or not re.fullmatch(r'[A-Za-z0-9]+', pw):
            raise Fehler(f'Nebenstelle {num}: Passwort zu schwach (mindestens 16 Buchstaben und Ziffern).')
        out['nebenstellen'].append({'nummer': num, 'name': _name(raw.get('name'), f'Nebenstelle {num}: Name', 30) or f'Telefon {num}',
                                    'passwort': pw, 'warteschlange': raw.get('warteschlange', True) is not False})
    if len(out['nebenstellen']) > 100:
        raise Fehler('Höchstens 100 Nebenstellen.')

    kz = cfg.get('kienzlefon') or {}
    tts = str(kz.get('tts') or 'piper')
    if tts not in TTS:
        raise Fehler('Sprachausgabe: Piper, Google oder Kokoro wählen.')
    g = kz.get('google') if isinstance(kz.get('google'), dict) else {}
    google = {'schluessel': _text(g.get('schluessel'), 'Google-Schlüssel', 200), 'stimme': _text(g.get('stimme'), 'Google-Stimme', 60) or 'de-DE-Chirp3-HD-Enceladus',
              'tempo': g.get('tempo') if g.get('tempo') not in (None, '') else 0.93}
    if google['schluessel'] and not re.fullmatch(r'[A-Za-z0-9_-]{20,200}', google['schluessel']):
        raise Fehler('Google-Schlüssel: nur Buchstaben, Ziffern, - und _ (so wie in der Google Cloud Console angezeigt).')
    if not re.fullmatch(r'[a-z]{2}-[A-Z]{2}-[A-Za-z0-9-]{1,50}', google['stimme']):
        raise Fehler('Google-Stimme: z. B. de-DE-Chirp3-HD-Enceladus.')
    try:
        google['tempo'] = round(float(str(google['tempo']).replace(',', '.')), 2)
    except ValueError:
        raise Fehler('Google-Sprechtempo: Zahl zwischen 0,25 und 2.') from None
    if not 0.25 <= google['tempo'] <= 2.0:
        raise Fehler('Google-Sprechtempo: Zahl zwischen 0,25 und 2.')
    if tts == 'google' and not google['schluessel']:
        raise Fehler('Sprachausgabe Google: Bitte den Google-Schlüssel eintragen (Text-to-Speech API).')
    wartemusik = str(kz.get('wartemusik') or 'klingelzeichen')
    if wartemusik not in ('klingelzeichen', 'musik'):
        raise Fehler('Wartemusik: Klingelzeichen oder Musik wählen.')
    out['kienzlefon'] = {'aktiv': kz.get('aktiv') is True, 'tts': tts, 'google': google, 'wartemusik': wartemusik,
                         'whisper_genau': kz.get('whisper_genau') is True, 'rufgruppen': kz.get('rufgruppen') is True}
    if out['kienzlefon']['rufgruppen'] and not out['kienzlefon']['aktiv']:
        raise Fehler('Rufgruppen brauchen das eingeschaltete Sprachmenü.')
    if out['kienzlefon']['aktiv'] and not tel.get('leitung_ein'):
        raise Fehler('Sprachmenü: zuerst die eingehende Telefonleitung unter „Rufnummern“ wählen.')
    if out['kienzlefon']['aktiv']:
        # Kienzlefon erwartet die Telefone der Warteschlange als lückenlose Reihe (wie Kienzlefax: erste Nummer und Anzahl).
        reihe = sorted(int(x['nummer']) for x in out['nebenstellen'] if x['warteschlange'])
        if not reihe:
            raise Fehler('Sprachmenü: Bitte zuerst mindestens ein Telefon anlegen, das in der Warteschlange klingelt.')
        if reihe != list(range(reihe[0], reihe[0] + len(reihe))):
            raise Fehler(f'Sprachmenü: Die Telefone der Warteschlange müssen fortlaufend nummeriert sein (z. B. 201, 202, 203); jetzt: {", ".join(map(str, reihe))}.')
    nz = cfg.get('netz') or {}
    netz = {'lokal': _net(nz.get('lokal'), 'Netz der Telefone'), 'erlaubt': _net(nz.get('erlaubt'), 'Erlaubtes Netz für Telefone'),
            'extern': _pattern(nz.get('extern'), HOST, 'Öffentliche Adresse', 'praxis.example.org')}
    if (out['nebenstellen'] or out['kienzlefon']['aktiv']) and not netz['lokal']:
        raise Fehler('Netz der Telefone: Bitte angeben (z. B. 192.0.2.0/24), sonst darf sich kein Telefon anmelden.')
    if not netz['erlaubt']:
        netz['erlaubt'] = netz['lokal']
    out['netz'] = netz
    out['praxis'] = _name(cfg.get('praxis'), 'Praxisname', 60)   # leer: bisherigen Namen behalten (Aufruf aus einem Haken)
    return out


# ---- Erzeugen ----------------------------------------------------------------------------------------------------
def cfg_value(value: str) -> str:
    """Wert für eine Asterisk-Konfigurationszeile (wie ast_cfg_value in Kienzlefax)."""
    return value.replace('\\', '\\\\').replace(';', '\\;')


def endpoint(lid: str) -> str:
    return f'kfx-leitung-{lid}'


def module_dir() -> str:
    for d in sorted(glob.glob('/usr/lib/*/asterisk/modules')) + ['/usr/lib/asterisk/modules']:
        if Path(d).is_dir():
            return d
    return '/usr/lib/x86_64-linux-gnu/asterisk/modules'


def roles(c: dict) -> dict[str, list[tuple[str, str]]]:
    """Je Leitung die eingehenden Ziele: (Rufnummer, Dialplan-Ziel)."""
    out: dict[str, list[tuple[str, str]]] = {x['id']: [] for x in c['leitungen']}
    if c['fax'].get('leitung'):
        out[c['fax']['leitung']].append((c['fax']['nummer'], 'fax-in,s,1'))
    if c['telefon'].get('leitung_ein'):
        out[c['telefon']['leitung_ein']].append((c['telefon']['nummer_ein'], 'kfx-phone-in,s,1'))
    if c['ueberlauf'].get('leitung'):
        out[c['ueberlauf']['leitung']].append((c['ueberlauf']['nummer'], 'kfx-phone-overflow-in,s,1'))
    return out


def fax_line(c: dict) -> set[str]:
    return {c['fax']['leitung']} if c['fax'].get('leitung') else set()


def render_line(line: dict, carries_fax: bool) -> str:
    v = ANBIETER[line['anbieter']]
    name = endpoint(line['id'])
    transport = 'transport-1und1-tls' if v.get('tls') else 'transport-udp'
    if v.get('tls'):
        server_uri = aor_contact = 'sip:tls-sip.1und1.de:5061\\;transport=tls'
    else:
        server_uri = aor_contact = f'sip:{line["domain"]}'
    client_uri = line['client_uri'] or f'sip:{line["benutzer"]}@{line["domain"]}'
    proxy = ''
    if line['proxy']:
        uri = line['proxy'].removeprefix('sip:')
        proxy = f'outbound_proxy=sip:{cfg_value(uri if ";lr" in uri else uri + ";lr")}'
    contact_user = line['benutzer']
    line_cfg = ''
    if v.get('line'):
        contact_user = line['nummer']
        line_cfg = f'line=yes\nendpoint={name}\n'
    if v.get('trunk'):
        server_uri = f'sip:{line["benutzer"]}@{line["domain"]}'
        contact_user = 'inbound-calls'
        line_cfg = f'line=yes\nendpoint={name}\n'
    # G.722 stört Fax; Leitungen mit Fax und die Vorlagen von voip2gsm/Fonial nur mit alaw/ulaw.
    codecs = 'alaw,ulaw' if carries_fax or v.get('line') else 'g722,alaw,ulaw'
    media = 'media_encryption=sdes\nmedia_encryption_optimistic=no\n' if v.get('tls') else ''
    trust = 'trust_id_inbound=yes\n' if v.get('trunk') else 'trust_id_outbound=yes\n'
    out = (f'\n; Leitung {line["id"]}: {line["name"]} ({v["name"]})\n'
           f'[{name}-registration]\ntype=registration\ntransport={transport}\noutbound_auth={name}-auth\nserver_uri={server_uri}\n'
           f'client_uri={cfg_value(client_uri)}\ncontact_user={contact_user}\n{line_cfg}retry_interval=60\nforbidden_retry_interval=600\n'
           f'expiration={line["ablauf"]}\n' + (proxy + '\n' if proxy else '') +
           f'\n[{name}-auth]\ntype=auth\nauth_type=userpass\nusername={cfg_value(line["benutzer"])}\npassword={cfg_value(line["passwort"])}\n'
           f'\n[{name}-aor]\ntype=aor\ncontact={aor_contact}\n' + (proxy + '\n' if proxy else '') +
           f'\n[{name}]\ntype=endpoint\ntransport={transport}\ncontext={name}-in\ndisallow=all\nallow={codecs}\n{media}'
           f'outbound_auth={name}-auth\naors={name}-aor\ndirect_media=no\nrewrite_contact=yes\nrtp_symmetric=yes\nforce_rport=yes\n'
           f't38_udptl=no\nt38_udptl_nat=no\nfrom_user={cfg_value(line["from_user"] or line["benutzer"])}\nfrom_domain={line["domain"].split(":")[0]}\n'
           f'send_pai=yes\nsend_rpid=yes\n{trust}' + (proxy + '\n' if proxy else ''))
    matches = [p.strip() for p in line['identify'].split(',') if p.strip()]
    if matches:
        out += f'\n[{name}-identify]\ntype=identify\nendpoint={name}\n' + ''.join(f'match={m}\n' for m in matches)
    return out


def render_pjsip(c: dict) -> tuple[str, str]:
    """pjsip.conf (Transporte, Leitungen) und pjsip-kfx-telefonie.conf (Telefone) wie Kienzlefax."""
    tls = any(ANBIETER[x['anbieter']].get('tls') for x in c['leitungen'])
    nets = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']
    if c['netz']['lokal'] and not any(ipaddress.ip_network(c['netz']['lokal']).subnet_of(ipaddress.ip_network(n)) for n in nets):
        nets.append(c['netz']['lokal'])
    ext = c['netz']['extern']
    external = f'external_signaling_address={ext}\nexternal_media_address={ext}\n' if ext else ''
    local = ''.join(f'local_net={n}\n' for n in nets)
    out = ('; Erzeugt vom Modul telefon der Kienzle-Suite (Vorlagen aus Kienzlefax). Änderungen gehen verloren.\n\n'
           '[system]\ntype=system\ncompact_headers=yes\n\n[global]\ntype=global\nuser_agent=KienzleSuite\n\n'
           f'[transport-udp]\ntype=transport\nprotocol=udp\nbind=0.0.0.0:{PROVIDER_PORT}\n{external}{local}')
    if tls:
        out += (f'\n[transport-1und1-tls]\ntype=transport\nprotocol=tls\nbind=0.0.0.0:{TLS_PORT}\nmethod=tlsv1_2\nverify_server=yes\n'
                f'verify_client=no\nrequire_client_cert=no\nca_list_file={CA_LIST}\nallow_reload=no\n{external}{local}')
    phone = '; Erzeugt vom Modul telefon (Telefone wie Kienzlefax telefonie-queue.sh).\n'
    if c['nebenstellen'] or c['kienzlefon']['aktiv']:
        phone += f'\n[transport-kfx-phone]\ntype=transport\nprotocol=udp\nbind=0.0.0.0:{PHONE_PORT}\nlocal_net={c["netz"]["lokal"]}\n'
    carries = fax_line(c)
    for line in c['leitungen']:
        out += render_line(line, line['id'] in carries)
    out += '\n#tryinclude "/etc/asterisk/pjsip-kfx-telefonie.conf"\n'
    allowed = c['netz']['erlaubt']
    for x in c['nebenstellen']:
        n = x['nummer']
        acl = '' if allowed == '0.0.0.0/0' else (f'deny=0.0.0.0/0.0.0.0\npermit={allowed}\ncontact_deny=0.0.0.0/0.0.0.0\ncontact_permit={allowed}\n')
        phone += (f'\n[{n}-auth]\ntype=auth\nauth_type=userpass\nusername={n}\npassword={cfg_value(x["passwort"])}\n'
                f'\n[{n}]\ntype=aor\nmax_contacts=1\nremove_existing=yes\nqualify_frequency=30\n'
                f'\n[{n}]\ntype=endpoint\ntransport=transport-kfx-phone\ncontext=kfx-phone-local\ndisallow=all\nallow=g722,alaw,ulaw\n'
                f'auth={n}-auth\naors={n}\ncallerid={x["name"]} <{n}>\ndirect_media=no\nrewrite_contact=yes\nrtp_symmetric=yes\n'
                f'force_rport=yes\ndevice_state_busy_at=1\n{acl}')
    return out, phone


CAPACITY = r"""
; =============================================================================
; GEMEINSAME KANALGRENZE (aus Kienzlefax)
; - Eine Sperre macht Prüfen und Reservieren für Telefon und Fax atomar.
; - Gruppen hängen an genau einem Kanal und verschwinden beim Auflegen.
; =============================================================================
[kfx_external_capacity]
exten => primary-phone,1,NoOp(KFX CAPACITY request=primary-phone)
 same => n,Set(KFX_CAPACITY_OK=0)
 same => n,GotoIf($["${GROUP(kfx_primary_total)}"="active" & "${GROUP(kfx_primary_phone)}"="active"]?already_reserved)
 same => n,Set(KFX_CAPACITY_LOCK=${LOCK(kfx_external_capacity_lock)})
 same => n,GotoIf($["${KFX_CAPACITY_LOCK}"="1"]?locked:lock_failed)
 same => n(locked),Set(KFX_PRIMARY_TOTAL_COUNT=${GROUP_COUNT(active@kfx_primary_total)})
 same => n,Set(KFX_PRIMARY_PHONE_COUNT=${GROUP_COUNT(active@kfx_primary_phone)})
 same => n,GotoIf($[${KFX_PRIMARY_TOTAL_COUNT} >= @GESAMT@]?full)
 same => n,GotoIf($[${KFX_PRIMARY_PHONE_COUNT} >= @TELEFON@]?full)
 same => n,Set(GROUP(kfx_primary_total)=active)
 same => n,Set(GROUP(kfx_primary_phone)=active)
 same => n,Set(KFX_CAPACITY_OK=1)
 same => n,Set(KFX_CAPACITY_UNLOCK=${UNLOCK(kfx_external_capacity_lock)})
 same => n,Return()
 same => n(full),Set(KFX_CAPACITY_UNLOCK=${UNLOCK(kfx_external_capacity_lock)})
 same => n,NoOp(KFX CAPACITY full primary-phone)
 same => n,Return()
 same => n(already_reserved),Set(KFX_CAPACITY_OK=1)
 same => n,Return()
 same => n(lock_failed),NoOp(KFX CAPACITY lock failed primary-phone - fail closed)
 same => n,Return()

exten => primary-fax,1,NoOp(KFX CAPACITY request=primary-fax)
 same => n,Set(KFX_CAPACITY_OK=0)
 same => n,GotoIf($["${GROUP(kfx_primary_total)}"="active" & "${GROUP(kfx_primary_fax)}"="active"]?already_reserved)
 same => n,Set(KFX_CAPACITY_LOCK=${LOCK(kfx_external_capacity_lock)})
 same => n,GotoIf($["${KFX_CAPACITY_LOCK}"="1"]?locked:lock_failed)
 same => n(locked),Set(KFX_PRIMARY_TOTAL_COUNT=${GROUP_COUNT(active@kfx_primary_total)})
 same => n,Set(KFX_PRIMARY_FAX_COUNT=${GROUP_COUNT(active@kfx_primary_fax)})
 same => n,GotoIf($[${KFX_PRIMARY_TOTAL_COUNT} >= @GESAMT@]?full)
 same => n,GotoIf($[${KFX_PRIMARY_FAX_COUNT} >= @FAX@]?full)
 same => n,Set(GROUP(kfx_primary_total)=active)
 same => n,Set(GROUP(kfx_primary_fax)=active)
 same => n,Set(KFX_CAPACITY_OK=1)
 same => n,Set(KFX_CAPACITY_UNLOCK=${UNLOCK(kfx_external_capacity_lock)})
 same => n,Return()
 same => n(full),Set(KFX_CAPACITY_UNLOCK=${UNLOCK(kfx_external_capacity_lock)})
 same => n,NoOp(KFX CAPACITY full primary-fax)
 same => n,Return()
 same => n(already_reserved),Set(KFX_CAPACITY_OK=1)
 same => n,Return()
 same => n(lock_failed),NoOp(KFX CAPACITY lock failed primary-fax - fail closed)
 same => n,Return()

exten => sipgate-phone,1,NoOp(KFX CAPACITY request=sipgate-phone)
 same => n,Set(KFX_CAPACITY_OK=0)
 same => n,GotoIf($["${GROUP(kfx_sipgate_overflow)}"="active"]?already_reserved)
 same => n,Set(KFX_CAPACITY_LOCK=${LOCK(kfx_external_capacity_lock)})
 same => n,GotoIf($["${KFX_CAPACITY_LOCK}"="1"]?locked:lock_failed)
 same => n(locked),Set(KFX_SIPGATE_COUNT=${GROUP_COUNT(active@kfx_sipgate_overflow)})
 same => n,GotoIf($[${KFX_SIPGATE_COUNT} >= @UEBERLAUF@]?full)
 same => n,Set(GROUP(kfx_sipgate_overflow)=active)
 same => n,Set(KFX_CAPACITY_OK=1)
 same => n,Set(KFX_CAPACITY_UNLOCK=${UNLOCK(kfx_external_capacity_lock)})
 same => n,Return()
 same => n(full),Set(KFX_CAPACITY_UNLOCK=${UNLOCK(kfx_external_capacity_lock)})
 same => n,NoOp(KFX CAPACITY full sipgate-phone)
 same => n,Return()
 same => n(already_reserved),Set(KFX_CAPACITY_OK=1)
 same => n,Return()
 same => n(lock_failed),NoOp(KFX CAPACITY lock failed sipgate-phone - fail closed)
 same => n,Return()
"""

FAX_OUT = r"""
; =============================================================================
; FAX-AUSGANG (aus Kienzlefax)
; - Der Fax-Dienst ruft per AMI Local/<nummer>@fax-out/n an und hält den Kanal mit Wait.
; - Dial() + SendFAX() im Gosub des angerufenen Kanals; Ergebnis über kfx_update_status.agi.
; =============================================================================
[fax-out]
@NATIONAL@
exten => kfx_missing_file,1,NoOp(FAX OUT ERROR: missing KFX_FILE | jobid=${KFX_JOBID})
 same => n,AGI(@AGI@,${KFX_JOBID},dial_end,CHANUNAVAIL,0)
 same => n,Hangup()

exten => h,1,NoOp(fax-out h-extension | jobid=${KFX_JOBID} DIALSTATUS=${DIALSTATUS} HANGUPCAUSE=${HANGUPCAUSE})
 same => n,GotoIf($["${KFX_CAPACITY_DEFERRED}"="1"]?done)
 same => n,AGI(@AGI@,${KFX_JOBID},dial_end,${DIALSTATUS},${HANGUPCAUSE})
 same => n(done),Hangup()

exten => _49X.,1,NoOp(FAX OUT normalize 49... -> national | jobid=${KFX_JOBID})
 same => n,Goto(0${EXTEN:2},1)

exten => _0X.,1,NoOp(FAX OUT | jobid=${KFX_JOBID})
 same => n,Set(JITTERBUFFER(adaptive)=default)
 same => n,GotoIf($[ "${KFX_FILE}" = "" ]?kfx_missing_file,1)
 same => n,Gosub(kfx_external_capacity,primary-fax,1)
 same => n,GotoIf($["${KFX_CAPACITY_OK}"="1"]?kfx_capacity_ok)
 same => n,Set(KFX_CAPACITY_DEFERRED=1)
 same => n,NoOp(FAX OUT deferred: provider or fax capacity full)
 same => n,AGI(@AGI@,${KFX_JOBID},capacity_deferred)
 same => n,Hangup(17)
 same => n(kfx_capacity_ok),AGI(@AGI@,${KFX_JOBID},dial_start)
 same => n,Set(CHANNEL(accountcode)=${KFX_JOBID})
 same => n,Set(FAXOPT(ecm)=yes)
 same => n,Set(FAXOPT(maxrate)=9600)
 same => n,Set(CALLERID(num)=@KENNUNG@)
 same => n,Set(CALLERID(name)=@NAME@)
 same => n,Set(FAXOPT(headerinfo)=@NAME@)
 same => n,Set(FAXOPT(localstationid)=@KURZ@)
 same => n,Dial(PJSIP/${EXTEN}@@ENDPOINT@,60,gU(kfx_sendfax^${KFX_JOBID}^${KFX_FILE}))
 same => n,Hangup()

[kfx_sendfax]
exten => s,1,NoOp(kfx_sendfax | jobid=${ARG1})
 same => n,Set(CHANNEL(accountcode)=${ARG1})
 same => n,Set(AGISIGHUP=no)
 same => n,Set(AGIEXITONHANGUP=no)
 same => n,Set(CHANNEL(hangup_handler_push)=kfx_sendfax_hangup,s,1(${ARG1}))
 same => n,AGI(@AGI@,${ARG1},send_start)
 same => n,Set(FAXOPT(ecm)=yes)
 same => n,Set(FAXOPT(maxrate)=9600)
 same => n,Set(JITTERBUFFER(adaptive)=default)
 same => n,TryExec(SendFAX(${ARG2}))
 same => n,NoOp(FAX done | FAXSTATUS=${FAXSTATUS} FAXERROR=${FAXERROR} FAXPAGES=${FAXPAGES})
 same => n,AGI(@AGI@,${ARG1},send_end,${FAXSTATUS},${FAXERROR},${FAXPAGES},${FAXBITRATE},${FAXECM},${DIALSTATUS},${HANGUPCAUSE})
 same => n,Return()

[kfx_sendfax_hangup]
exten => s,1,NoOp(kfx_sendfax_hangup | jobid=${ARG1} FAXSTATUS=${FAXSTATUS})
 same => n,Set(AGISIGHUP=no)
 same => n,Set(AGIEXITONHANGUP=no)
 same => n,AGI(@AGI@,${ARG1},send_end,${FAXSTATUS},${FAXERROR},${FAXPAGES},${FAXBITRATE},${FAXECM},${DIALSTATUS},${HANGUPCAUSE})
 same => n,Return()
"""

FAX_IN = r"""
; =============================================================================
; FAX-EINGANG (aus Kienzlefax): TIFF empfangen, als PDF in den Austauschordner (empfang/) legen.
; =============================================================================
[fax-in]
exten => s,1,NoOp(Inbound Fax)
 same => n,Gosub(kfx_external_capacity,primary-fax,1)
 same => n,GotoIf($["${KFX_CAPACITY_OK}"="1"]?kfx_capacity_ok)
 same => n,NoOp(Inbound Fax rejected: provider or fax capacity full)
 same => n,Hangup(17)
 same => n(kfx_capacity_ok),Answer()
 same => n,Set(FAXOPT(ecm)=yes)
 same => n,Set(FAXOPT(maxrate)=9600)
 same => n,Set(JITTERBUFFER(adaptive)=default)
 same => n,Set(FAXSTAMP=${STRFTIME(${EPOCH},,%Y%m%d-%H%M%S)})
 same => n,Set(FROM=${FILTER(0-9,${CALLERID(num)})})
 same => n,ExecIf($["${FROM}"=""]?Set(FROM=unbekannt))
 same => n,Set(UID=${FILTER(0-9,${UNIQUEID})})
 same => n,Set(FAXBASE=${FAXSTAMP}_${FROM}_${UID})
 same => n,Set(TIFF=@SPOOL@/${FAXBASE}.tif)
 same => n,ReceiveFAX(${TIFF})
 same => n,NoOp(FAXSTATUS=${FAXSTATUS} FAXERROR=${FAXERROR} PAGES=${FAXPAGES})
 same => n,System(@EMPFANG@ ${FAXBASE} ${FILTER(A-Z,${FAXSTATUS})} ${FILTER(0-9,${FAXPAGES})})
 same => n,Hangup()
"""

PHONE = r"""
; =============================================================================
; TELEFONIE (aus Kienzlefax telefonie-queue.sh)
; =============================================================================
[kfx-phone-in]
exten => s,1,NoOp(Telefonieeingang Hauptanschluss)
 same => n,Gosub(kfx_external_capacity,primary-phone,1)
 same => n,GotoIf($["${KFX_CAPACITY_OK}"="1"]?accepted)
 same => n,NoOp(KFX PHONE rejected before answer: primary provider capacity full)
 same => n,Hangup(17)
 same => n(accepted),Goto(kfx-phone-queue,s,1)

[kfx-phone-overflow-in]
exten => s,1,NoOp(Telefonieeingang Sipgate-Ueberlauf)
 same => n,Gosub(kfx_external_capacity,sipgate-phone,1)
 same => n,GotoIf($["${KFX_CAPACITY_OK}"="1"]?accepted)
 same => n,NoOp(KFX PHONE rejected before answer: sipgate overflow capacity full)
 same => n,Hangup(17)
 same => n(accepted),Goto(kfx-phone-queue,s,1)

[kfx-phone-queue]
exten => s,1,NoOp(KienzleFax Telefoniewarteschlange)
 same => n,Set(CHANNEL(language)=de)
 same => n,Set(KFX_QUEUE_WAITING=${QUEUE_WAITING_COUNT(praxis)})
 same => n,GotoIf($[${KFX_QUEUE_WAITING} >= @MAX_WARTEND@]?full)
 same => n,Answer()
 same => n,Set(QUEUE_RAISE_PENALTY=0)
 same => n,Queue(praxis,r)
 same => n,GotoIf($["${QUEUESTATUS}"="FULL"]?full)
 same => n,Hangup()
 same => n(full),NoOp(KFX PHONE rejected before answer: queue maxlen reached)
 same => n,Hangup(17)
"""


def render_extensions(c: dict) -> tuple[str, str]:
    """Hauptdatei (Kanalgrenze, Leitungen, Fax) und Telefonie-Datei (extensions-kfx-telefonie.conf) wie Kienzlefax."""
    agi = str(PROGRAM / 'lib' / 'kfx' / 'kfx_update_status.agi')
    out = ('; Erzeugt vom Modul telefon der Kienzle-Suite (Vorlagen aus Kienzlefax). Änderungen gehen verloren.\n'
           '[general]\nstatic=yes\nwriteprotect=yes\nclearglobalvars=no\n')
    k = c['kanaele']
    out += (CAPACITY.replace('@GESAMT@', str(k['gesamt'])).replace('@TELEFON@', str(k['telefon']))
            .replace('@FAX@', str(k['fax'])).replace('@UEBERLAUF@', str(k['ueberlauf'])))
    by_id = {x['id']: x for x in c['leitungen']}
    # Eingang je Leitung: Rufnummern verteilen; hat eine Leitung nur ein Ziel, landet jede Nummer dort.
    for lid, targets in roles(c).items():
        out += f'\n[{endpoint(lid)}-in]\nexten => _+X.,1,Goto({endpoint(lid)}-in,${{EXTEN:1}},1)\n'
        for number, target in targets:
            out += f'exten => {number},1,Goto({target})\n'
        if len(targets) == 1:
            t = targets[0][1]
            out += f'exten => _X.,1,Goto({t})\nexten => s,1,Goto({t})\nexten => inbound-calls,1,Goto({t})\n'
        elif not targets:
            out += 'exten => _X.,1,NoOp(Leitung ohne Ziel)\n same => n,Hangup(21)\n'
    if c['fax'].get('leitung'):
        line = by_id[c['fax']['leitung']]
        national = ('; voip2gsm: internationale Ziele ohne + bzw. 00.\nexten => _+X.,1,Goto(00${EXTEN:1},1)\nexten => _0049X.,1,Goto(0${EXTEN:4},1)\n'
                    'exten => _[1-9]X.,1,Goto(00${EXTEN},1)\n') if ANBIETER[line['anbieter']].get('national') else (
            '; Ziele national (0…), international (00…) oder ohne 00 gespeichert (wie das Modul fax: 49…, 33…).\nexten => _[1-9]X.,1,Goto(00${EXTEN},1)\n')
        out += (FAX_OUT.replace('@NATIONAL@', national).replace('@AGI@', agi).replace('@ENDPOINT@', endpoint(line['id']))
                .replace('@KENNUNG@', c['fax']['kennung']).replace('@NAME@', c['fax']['name']).replace('@KURZ@', kennung_kurz(c['fax']['name'])))
        out += FAX_IN.replace('@SPOOL@', str(AST / 'spool' / 'fax-empfang')).replace('@EMPFANG@', str(PROGRAM / 'lib' / 'kfx' / 'fax-empfangen'))
    out += '\n#tryinclude "/etc/asterisk/extensions-kfx-telefonie.conf"\n'
    main, out = out, '; Erzeugt vom Modul telefon (Telefonie wie Kienzlefax telefonie-queue.sh). Änderungen gehen verloren.\n'
    out += PHONE.replace('@MAX_WARTEND@', str(c['warteschlange']['max_wartend']))
    # Interne Nebenstellen: klingeln nur, wenn frei (kein Anklopfen).
    out += '\n[kfx-phone-local]\n'
    for x in c['nebenstellen']:
        n = x['nummer']
        out += (f'exten => {n},1,NoOp(Interner Anruf zu {n})\n same => n,Set(KFX_INTERNAL_TARGET_STATE=${{DEVICE_STATE(PJSIP/{n})}})\n'
                f' same => n,GotoIf($["${{KFX_INTERNAL_TARGET_STATE}}"="NOT_INUSE"]?dial:busy)\n same => n(dial),Dial(PJSIP/{n},120)\n'
                f' same => n,Hangup()\n same => n(busy),NoOp(Interner Anruf zu {n} abgewiesen: ${{KFX_INTERNAL_TARGET_STATE}})\n same => n,Hangup(17)\n\n')
    tel = c['telefon']
    if tel.get('leitung_aus') and c['kienzlefon']['aktiv']:
        # Mit Kienzlefon: Ausgang genau wie Kienzlefax; Kienzlefon ersetzt ihn durch seine Wahlregeln (kienzlefon-outbound).
        line = by_id[tel['leitung_aus']]
        if ANBIETER[line['anbieter']].get('national'):
            out += '; BEGIN voip2gsm\nexten => _+X.,1,Goto(00${EXTEN:1},1)\nexten => _0049X.,1,Goto(0${EXTEN:4},1)\nexten => _49X.,1,Goto(0${EXTEN:2},1)\n; END voip2gsm\n\n'
        out += ('exten => _X.,1,NoOp(KienzleFax Telefonie ausgehend)\n'
                f' same => n,GotoIf($["{"y" if tel["aus_zaehlt"] else "n"}"!="y"]?dial)\n'
                ' same => n,Gosub(kfx_external_capacity,primary-phone,1)\n same => n,GotoIf($["${KFX_CAPACITY_OK}"="1"]?dial)\n'
                ' same => n,NoOp(KFX PHONE outgoing rejected: primary provider capacity full)\n same => n,Hangup(17)\n'
                f' same => n(dial),Set(CALLERID(num)={tel["kennung_aus"]})\n same => n,Dial(PJSIP/${{EXTEN}}@{endpoint(line["id"])},120)\n same => n,Hangup()\n')
    elif tel.get('leitung_aus'):
        line = by_id[tel['leitung_aus']]
        if ANBIETER[line['anbieter']].get('national'):
            out += 'exten => _+X.,1,Goto(00${EXTEN:1},1)\nexten => _0049X.,1,Goto(0${EXTEN:4},1)\nexten => _49X.,1,Goto(0${EXTEN:2},1)\n'
        for n in sorted(NOTRUF):
            out += f'exten => {n},1,Goto(kfx-phone-out,${{EXTEN}},1)\n'
        if not tel['ausland']:
            out += 'exten => _00X.,1,NoOp(Auslandsgespraech gesperrt)\n same => n,Playback(ss-noservice)\n same => n,Hangup(21)\n'
        if not tel['sonderrufnummern']:
            for p in ('_0900X.', '_0137X.', '_0138X.', '_118XX', '_118XXX'):
                out += f'exten => {p},1,NoOp(Sonderrufnummer gesperrt)\n same => n,Playback(ss-noservice)\n same => n,Hangup(21)\n'
        out += 'exten => _X.,1,Goto(kfx-phone-out,${EXTEN},1)\n'
        gate = '' if tel['aus_zaehlt'] else 'dial'
        out += ('\n[kfx-phone-out]\nexten => _X.,1,NoOp(Telefonie ausgehend)\n'
                + (f' same => n,Goto({gate})\n' if gate else '') +
                ' same => n,Gosub(kfx_external_capacity,primary-phone,1)\n same => n,GotoIf($["${KFX_CAPACITY_OK}"="1"]?dial)\n'
                ' same => n,NoOp(KFX PHONE outgoing rejected: primary provider capacity full)\n same => n,Hangup(17)\n'
                f' same => n(dial),Set(CALLERID(num)={tel["kennung_aus"]})\n same => n,Dial(PJSIP/${{EXTEN}}@{endpoint(line["id"])},120)\n same => n,Hangup()\n')
    else:
        out += 'exten => _X.,1,NoOp(Keine ausgehende Leitung eingerichtet)\n same => n,Hangup(21)\n'
    return main, out


def render_queues(c: dict) -> tuple[str, str]:
    members = [x for x in c['nebenstellen'] if x['warteschlange']]
    queues = ('; Erzeugt vom Modul telefon (Warteschlange aus Kienzlefax).\n\n[praxis]\nstrategy=ringall\n'
              'autofill=yes\nringinuse=no\njoinempty=yes\nleavewhenempty=no\n'
              '; 19 Sekunden Rufversuch + 1 Sekunde = nächste Stufe nach 20 Sekunden\ntimeout=19\nretry=1\ndefaultrule=kfx-phone-progressive\n'
              'announce-position=yes\nannounce-frequency=60\nmin-announce-frequency=60\nannounce-to-first-user=yes\nannounce-holdtime=no\n'
              f'maxlen={c["warteschlange"]["max_wartend"]}\n')
    for i, x in enumerate(members):
        queues += f'member => PJSIP/{x["nummer"]},{i},{x["name"]},PJSIP/{x["nummer"]},no\n'
    rules = '; Erzeugt vom Modul telefon (Kienzlefax).\n\n[kfx-phone-progressive]\n' + ''.join(f'penaltychange => {i * 20},,,{i}\n' for i in range(1, len(members)))
    return queues, rules


def ami_secret() -> str:
    path = DATA / 'ami.json'
    try:
        value = json.loads(path.read_text(encoding='utf-8'))['secret']
        if re.fullmatch(r'[A-Za-z0-9]{32,64}', value):
            return value
    except (OSError, ValueError, KeyError, TypeError):
        pass
    value = secrets.token_hex(24)
    write(path, json.dumps({'user': AMI_USER, 'secret': value, 'port': AMI_PORT}) + '\n', 0o600)
    return value


# Module für SIP, Faxe, Warteschlange, AGI und Ansagen; Kienzlefon (Stufe 2) ergänzt hier, was es braucht.
MODULES = (
    'res_sorcery_config', 'res_sorcery_memory', 'res_sorcery_astdb', 'res_pjproject', 'res_timing_timerfd', 'res_rtp_asterisk', 'res_srtp', 'res_crypto',
    'res_pjsip', 'res_pjsip_session', 'res_pjsip_pubsub', 'res_pjsip_authenticator_digest', 'res_pjsip_outbound_authenticator_digest',
    'res_pjsip_endpoint_identifier_ip', 'res_pjsip_endpoint_identifier_user', 'res_pjsip_registrar', 'res_pjsip_outbound_registration',
    'res_pjsip_nat', 'res_pjsip_caller_id', 'res_pjsip_sdp_rtp', 'res_pjsip_dtmf_info', 'res_pjsip_rfc3326', 'res_pjsip_dlg_options',
    'res_pjsip_header_funcs', 'res_pjsip_path', 'res_pjsip_acl', 'res_pjsip_empty_info', 'res_pjsip_refer', 'res_pjsip_diversion',
    'res_pjsip_logger', 'chan_pjsip',
    'codec_alaw', 'codec_ulaw', 'codec_a_mu', 'codec_g722', 'codec_gsm', 'codec_resample',
    'format_gsm', 'format_wav', 'format_wav_gsm', 'format_pcm', 'format_sln',
    'bridge_simple', 'bridge_native_rtp', 'bridge_holding', 'bridge_builtin_features', 'bridge_builtin_interval_features',
    'pbx_config', 'app_dial', 'app_queue', 'app_playback', 'app_stack', 'app_system', 'app_exec', 'app_senddtmf', 'app_read',
    'app_record', 'app_echo', 'app_transfer', 'app_verbose', 'res_speech', 'res_agi', 'res_fax', 'res_fax_spandsp', 'res_musiconhold', 'res_clioriginate',
    'func_groupcount', 'func_lock', 'func_callerid', 'func_channel', 'func_strings', 'func_logic', 'func_math', 'func_devstate',
    'func_jitterbuffer', 'func_global', 'func_env', 'func_timeout', 'func_pjsip_endpoint', 'func_pjsip_contact', 'func_pjsip_aor',
)

INDICATIONS = """[general]
country=de

[de]
description = Germany
ringcadence = 1000,4000
dial = 425
busy = 425/480,0/480
ring = 425/1000,0/4000
congestion = 425/240,0/240
callwaiting = !425/200,!0/200,!425/200,!0/5000,!425/200,!0/200,!425/200,!0/5000,!425/200,!0/200,!425/200
dialrecall = !425/100,!0/100,!425/100,!0/100,!425/100,!0/100,425
record = 1400/80,0/15000
info = 950/330,1400/330,1800/330,0/1000
stutter = 425+400
"""


def base_files() -> dict[str, str]:
    """Grunddateien ohne Zugangsdaten der Leitungen (asterisk.conf, Module, Protokoll, AMI, RTP)."""
    run = AST / 'run'
    files = {
        'asterisk.conf': ('; Erzeugt vom Modul telefon der Kienzle-Suite: eigene Asterisk-Instanz als ks-telefon.\n[directories]\n'
                          f'astetcdir => {ETC}\nastmoddir => {module_dir()}\nastvarlibdir => {AST / "lib"}\nastdbdir => {AST / "lib"}\n'
                          f'astkeydir => {AST / "lib"}\nastdatadir => /usr/share/asterisk\nastagidir => {PROGRAM / "lib" / "kfx"}\n'
                          f'astspooldir => {AST / "spool"}\nastrundir => {run}\nastlogdir => {AST / "log"}\nastcachedir => {AST / "cache"}\nastsbindir => /usr/sbin\n\n'
                          '[options]\ndefaultlanguage = de\nhideconnect = yes\nastctlpermissions = 0600\nlive_dangerously = no\n'),
        'modules.conf': ('; Nur die Module, die die Anlage braucht (keine automatische Ladung: weniger Angriffsfläche, keine Fehlermeldungen).\n'
                         '[modules]\nautoload=no\n' + ''.join(f'load => {m}.so\n' for m in MODULES)),
        'logger.conf': ('; Nur Warnungen und Fehler (Rufnummern sind personenbezogen); Aufräumen nach 7 Tagen (anlage.py pflege).\n'
                        '[general]\ndateformat=%F %T\nrotatestrategy=timestamp\nqueue_log=no\n\n[logfiles]\nmessages.log => warning,error\n'),
        'cdr.conf': '[general]\nenable=no\n',
        'cel.conf': '[general]\nenable=no\n',
        'http.conf': '[general]\nenabled=no\n',
        'rtp.conf': f'[general]\nrtpstart={RTP_START}\nrtpend={RTP_END}\nicesupport=no\n',
        'manager.conf': ('; AMI nur auf localhost, nur für den Fax-Dienst des Moduls.\n[general]\nenabled=yes\nwebenabled=no\n'
                         f'port={AMI_PORT}\nbindaddr=127.0.0.1\ndisplayconnects=no\nauthtimeout=10\nauthlimit=20\n\n'
                         f'[{AMI_USER}]\nsecret={ami_secret()}\ndeny=0.0.0.0/0.0.0.0\npermit=127.0.0.1/255.255.255.255\n'
                         'read=system,call,reporting\nwrite=system,call,originate\n\n'
                         f'; Zugang des Steuerungsdienstes für die Rufgruppen (nur wenn eingeschaltet, sonst leer)\n#tryinclude "{CONTROL_CONF}"\n'),
        'musiconhold.conf': '; Wartemusik folgt mit Kienzlefon (Stufe 2); die Warteschlange klingelt.\n[general]\n',
        'indications.conf': INDICATIONS,
        'udptl.conf': '[general]\nudptlstart=4000\nudptlend=4999\n',
        'features.conf': '[general]\n',
        'ccss.conf': '[general]\n',
        'acl.conf': '',
        'pjproject.conf': '',
    }
    return files


def placeholder_files() -> dict[str, str]:
    """Leere Anlage (noch nichts eingerichtet): Asterisk startet ohne Leitungen und Telefone."""
    return {'pjsip.conf': '; Noch keine Leitung eingerichtet.\n', 'extensions.conf': '[general]\nstatic=yes\nwriteprotect=yes\n',
            'queues.conf': '[general]\npersistentmembers=no\n', 'queuerules.conf': ''}


def render(c: dict) -> dict[str, str]:
    """Alle erzeugten Dateien der Anlage in der Aufteilung von Kienzlefax (darauf setzt Kienzlefon auf)."""
    queues, rules = render_queues(c)
    pjsip, pjsip_phone = render_pjsip(c)
    ext, ext_phone = render_extensions(c)
    return {'pjsip.conf': pjsip, 'pjsip-kfx-telefonie.conf': pjsip_phone, 'extensions.conf': ext, 'extensions-kfx-telefonie.conf': ext_phone,
            'queues.conf': '[general]\npersistentmembers=no\n\n#include "/etc/asterisk/queues-kfx.conf"\n', 'queues-kfx.conf': queues,
            'queuerules.conf': '; Erzeugt vom Modul telefon.\n#include "/etc/asterisk/queuerules-kfx.conf"\n', 'queuerules-kfx.conf': rules}



# ---- Kienzlefon (Sprachmenü, Zeiten, Ansagen) ------------------------------------------------------------------
KZF_SOURCE = PROGRAM / 'kienzlefon'          # unverändert übernommenes Kienzlefon 2.7 (kienzlefon/HERKUNFT.md)
KZF_DATA = DATA / 'kienzlefon'
ERLEDIGT_TAGE = 7                             # erledigte Vorgänge werden danach gelöscht
MOH = KZF_DATA / 'wartemusik'                 # Wiedergabeformate der Wartemusik (statt /var/lib/asterisk/moh/kienzlefon-wartemusik)
PLACHETKA = PROGRAM / 'medien' / 'johannes-plachetka-warteschlange.mp3'
KZF_CONFIG = KZF_DATA / 'kienzlefon.toml'
KZF_BIN = PROGRAM / 'lib' / 'kzf-bin'         # Startskripte für die Programme, die der Dialplan von Kienzlefon aufruft
# Dateien, die Kienzlefon schreibt bzw. ändert (install_asterisk_config), zusätzlich zu den Dateien der Anlage.
KZF_FILES = ('features.conf', 'musiconhold.conf', 'pjsip-kienzlefon.conf', 'extensions-kienzlefon.conf', 'queues-kienzlefon.conf',
             'features-kienzlefon.conf', 'musiconhold-kienzlefon.conf', 'queuerules-kienzlefon.conf')
# Rufgruppen (Anlagensteuerung von Kienzlefon): AMI-Zugang nur für den Steuerungsdienst, Auftragsjournal im Datenordner.
CONTROL_CONF = 'manager_kienzlefon_control.conf'
CONTROL_USER = 'kienzlefon-control'
CONTROL_DIR = DATA / 'kienzlefon' / 'control'
CONTROL_AMI = DATA / 'kienzlefon' / 'control-ami.json'
PBX_ID = 'kienzle-praxis'


def toml_set(text: str, section: str, key: str, value) -> str:
    """Einen Wert in einer TOML-Datei setzen und Kommentare behalten (wie _set_toml_value aus kienzlefon/webadmin.py, auch für Zahlen)."""
    literal = ('true' if value else 'false') if isinstance(value, bool) else str(value) if isinstance(value, (int, float)) else json.dumps(value, ensure_ascii=False)
    header = re.search(rf'^\[{re.escape(section)}\]\s*$', text, re.M)
    if header is None:
        return text.rstrip() + f'\n\n[{section}]\n{key} = {literal}\n'
    nxt = re.search(r'^\[[^]]+\]\s*$', text[header.end():], re.M)
    end = header.end() + (nxt.start() if nxt else len(text) - header.end())
    body, n = re.subn(rf'^(?P<p>{re.escape(key)}\s*=\s*).*$', lambda m: m['p'] + literal, text[header.end():end], count=1, flags=re.M)
    if n == 0:
        body = body.rstrip() + f'\n{key} = {literal}\n'
    return text[:header.end()] + body + text[end:]


def kienzlefon_toml(c: dict) -> str:
    """kienzlefon.toml: die gespeicherte Fassung (später aus den Seiten des Moduls) bzw. die Vorlage von Kienzlefon, mit den Ordnern
    des Moduls und den Angaben der Anlage. Geheimnisse (rotes Telefon) werden einmal erzeugt und bleiben."""
    try:
        text = KZF_CONFIG.read_text(encoding='utf-8')
    except OSError:
        text = (KZF_SOURCE / 'config' / 'kienzlefon.toml.example').read_text(encoding='utf-8')
    d = KZF_DATA
    by_id = {x['id']: x for x in c['leitungen']}
    tel = c['telefon']
    members = sorted((x['nummer'] for x in c['nebenstellen'] if x['warteschlange']), key=int)
    werte = [
        ('pfade', 'spool', str(d / 'spool')), ('pfade', 'runtime', str(d / 'run')), ('pfade', 'ansagen', str(d / 'ansagen')),
        ('pfade', 'ansagen_master', str(d / 'ansagen-master')), ('whisper', 'modellverzeichnis', str(d / 'modelle')),
        ('telepraxis', 'ausgabeverzeichnis', str(d / 'vorgaenge')), ('telepraxis', 'public_key', str(d / 'telepraxis-public.pem')),
        # Vorgänge verschlüsselt für die Seite „Vorgänge“ (Schlüsselpaar des Moduls, geheimer Teil nur mit dem Modulschlüssel lesbar),
        # ohne Tonaufnahmen (die löscht der Worker nach dem Umwandeln).
        ('telepraxis', 'demo', False), ('telepraxis', 'audio_beilegen', False), ('telepraxis', 'anrufernummern_anonymisieren', False),
        # Whisper: large-v3-turbo für alles; „genauer“ nimmt für Vor- und Nachnamen large-v3 (zusätzlich etwa 3 GB).
        ('whisper', 'modell_standard', 'large-v3-turbo'), ('whisper', 'modell_medikamente', 'large-v3-turbo'),
        ('whisper', 'modell_namen', 'large-v3' if c['kienzlefon'].get('whisper_genau') else 'large-v3-turbo'),
        ('whisper', 'geraet', 'cpu'), ('whisper', 'compute_type', 'int8'),
        ('tts', 'engine', c['kienzlefon']['tts']), ('tts', 'kokoro_generator', str(d / 'kokoro' / 'kienzlefon-kokoro-tts-generate')),
        ('tts', 'kokoro_modellverzeichnis', str(d / 'kokoro-thorsten')), ('tts', 'qwen_generator', str(KZF_BIN / 'kienzlefon-qwen3-tts-generate')),
        ('tts', 'google_generator', str(KZF_BIN / 'kienzlefon-google-tts-generate')), ('tts', 'google_konfig', str(d / 'google-tts.conf')),
        ('tts', 'stimmenverzeichnis', str(d / 'piper-voices')), ('tts', 'uploadverzeichnis', str(d / 'upload')),
        ('asterisk', 'telefon_transport', 'transport-kfx-phone'),
        # Rufgruppen: Steuerungsdienst nur auf diesem Rechner; Änderungen von außen (Middleware, App) bleiben aus.
        ('control_api', 'enabled', bool(c['kienzlefon'].get('rufgruppen'))), ('control_api', 'bind', '127.0.0.1'), ('control_api', 'port', 8767),
        ('control_api', 'allowed_networks', ['127.0.0.1/32']), ('control_api', 'credentials_file', str(d / 'control-identities.json')),
        ('control_api', 'pbx_id', PBX_ID), ('routing_policy', 'remote_enabled', False),
        ('asterisk', 'hauptausgang_endpoint', endpoint(tel['leitung_aus']) if tel.get('leitung_aus') in by_id else 'kfx-phone-in-endpoint'),
        ('asterisk', 'hauptausgang_nummer', tel.get('kennung_aus') or '0'), ('asterisk', 'ausgehend_zaehlt_kanalgrenze', bool(tel.get('aus_zaehlt', True))),
        ('asterisk', 'erste_queue_nebenstelle', int(members[0]) if members else 201), ('asterisk', 'queue_nebenstellen_anzahl', max(1, len(members))),
        ('ansagen_ivr', 'audit_log', str(d / 'ansagen-ivr-audit.jsonl')), ('wahlregeln', 'audit_log', str(d / 'telefonie-audit.jsonl')),
        ('control_api', 'credentials_file', str(d / 'control-identities.json')),
        # Wartemusik: installiert, sobald das Modul die Wiedergabeformate erzeugt hat; Musik nur dann, sonst Klingelzeichen.
        ('wartemusik', 'installiert', wartemusik_bereit()),
        ('wartemusik', 'modus', 'musik' if wartemusik_bereit() and c['kienzlefon'].get('wartemusik') == 'musik' else 'klingelzeichen'),
    ]
    if c.get('praxis'):
        werte.append(('praxis', 'name', c['praxis']))
    for section, key, value in werte:
        text = toml_set(text, section, key, value)
    if 'CHANGE_ME_RED_PHONE_SECRET' in text:
        text = toml_set(text, 'asterisk', 'rotes_telefon_passwort', secrets.token_hex(16))
    return text


def _anpassen() -> None:
    """Kienzlefon an das Modul anpassen (Journal im Datenordner, Prozesskennung der eigenen Asterisk-Instanz): lib/kzf_anpassung.py."""
    import importlib.util
    spec = importlib.util.spec_from_file_location('kzf_anpassung', Path(__file__).resolve().parent / 'kzf_anpassung.py')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    mod.DATA = DATA
    mod.anpassen()


def steuerung_einrichten(config) -> str:
    """Rufgruppen ein/aus (wie control_setup.provision von Kienzlefon, aber ohne manager.conf umzuschreiben, die das Modul selbst
    erzeugt): eigener AMI-Zugang (Geheimnis bleibt), lokale Identität, Rufgruppen-Richtlinie für die vorhandenen Telefone.
    Liefert den Inhalt von manager_kienzlefon_control.conf."""
    if not config.control_api.enabled:
        CONTROL_AMI.unlink(missing_ok=True)
        return '; Rufgruppen sind ausgeschaltet.\n'
    _anpassen()
    from kienzlefon.control_setup import ensure_credentials, write_policy
    from kienzlefon.telephony_policy import RoutingPolicy, managed_phones, policy_for
    try:
        secret = json.loads(CONTROL_AMI.read_text(encoding='utf-8'))['secret']
        if not re.fullmatch(r'[0-9a-f]{64}', secret):
            raise ValueError
    except (OSError, ValueError, KeyError, TypeError):
        secret = secrets.token_hex(32)
    write(CONTROL_AMI, json.dumps({'host': '127.0.0.1', 'port': AMI_PORT, 'user': CONTROL_USER, 'secret': secret}) + '\n', 0o600)
    ensure_credentials(config)
    # Richtlinie: beim ersten Einschalten aus den Telefonen der Warteschlange; gelöschte Telefone fallen heraus, neue kommen hinzu.
    policy = config.routing_policy
    phones = managed_phones(config)
    if not policy.members or policy.members.keys() - phones.keys() or phones.keys() - policy.members.keys():
        kept = RoutingPolicy(policy.remote_enabled, {k: v for k, v in policy.members.items() if k in phones}, policy.workplace_mode, policy.workplace_ids)
        from dataclasses import replace
        try:
            write_policy(config, policy_for(replace(config, routing_policy=kept)), CONTROL_DIR)
        except ValueError as e:
            raise Fehler(f'Rufgruppen: {e}') from None
    return (f'; Erzeugt vom Modul telefon: Zugang des Steuerungsdienstes (Rufgruppen), nur von diesem Rechner.\n[{CONTROL_USER}]\nsecret={secret}\n'
            'deny=0.0.0.0/0.0.0.0\npermit=127.0.0.1/255.255.255.255\nread=system,call,agent,dialplan,reporting\nwrite=call,agent,reporting\n')


def kienzlefon_anwenden(files: dict[str, str], c: dict) -> dict[str, str]:
    """Kienzlefon auf die Dateien der Anlage setzen: install_asterisk_config aus Kienzlefon 2.7 in einem Arbeitsordner, danach die
    festen Pfade von Kienzlefon durch die Ordner des Moduls ersetzen. Liefert alle Dateien (die der Anlage und die von Kienzlefon)."""
    import sys
    import tempfile
    if str(KZF_SOURCE) not in sys.path:
        sys.path.insert(0, str(KZF_SOURCE))
    from kienzlefon.asterisk import AsteriskConfigError, install_asterisk_config
    from kienzlefon.config import load_config
    toml = kienzlefon_toml(c)
    for sub in ('', 'spool', 'run', 'ansagen', 'ansagen-master', 'upload', 'vorgaenge', 'piper-voices'):
        (KZF_DATA / sub).mkdir(mode=0o700, parents=True, exist_ok=True)
    write(KZF_CONFIG, toml, 0o600)
    # Google: Schlüssel nur in dieser Datei (0600) für den Generator von Kienzlefon; leer, solange keiner eingetragen ist.
    g = c['kienzlefon']['google']
    if g['schluessel']:
        write(KZF_DATA / 'google-tts.conf', f'# Erzeugt vom Modul telefon.\nAPI_KEY={g["schluessel"]}\nVOICE={g["stimme"]}\nLANG=de-DE\nRATE={g["tempo"]}\n', 0o600)
    else:
        (KZF_DATA / 'google-tts.conf').unlink(missing_ok=True)
    try:
        config = load_config(KZF_CONFIG)
        files = {**files, CONTROL_CONF: steuerung_einrichten(config)}
        config = load_config(KZF_CONFIG)
    except Fehler:
        raise
    except Exception as e:  # noqa: BLE001 - Kienzlefon meldet Konfigurationsfehler mit eigenen Ausnahmen
        raise Fehler(f'Kienzlefon: {str(e)[:200]}') from None
    with tempfile.TemporaryDirectory(prefix='telefon-kienzlefon-') as tmp:
        staging = Path(tmp)
        for name, text in files.items():
            (staging / name).write_text(text, encoding='utf-8')
        try:
            install_asterisk_config(config, staging)
        except (AsteriskConfigError, ValueError) as e:
            raise Fehler(f'Kienzlefon: {e}') from None
        out = {}
        for name in (*files, *KZF_FILES):
            path = staging / name
            if path.is_file():
                # Einbindungen mit dem Pfad des Arbeitsordners (z. B. features-kienzlefon.conf) auf /etc/asterisk zurückführen.
                out[name] = path.read_text(encoding='utf-8').replace(f'{staging}/', '/etc/asterisk/').replace(f'{staging.resolve()}/', '/etc/asterisk/')
    return out


def pfade_ersetzen(files: dict[str, str]) -> dict[str, str]:
    """Feste Pfade von Kienzlefax/Kienzlefon (Einbindungen, Programmaufrufe) durch die Ordner des Moduls ersetzen."""
    replace = {'/var/lib/asterisk/moh/kienzlefon-wartemusik': str(MOH),
               '/opt/kienzlefon/venv/bin/': f'{KZF_BIN}/', '/etc/kienzlefon/kienzlefon.toml': str(KZF_CONFIG), '/etc/asterisk/': f'{ETC}/'}
    out = {}
    for name, text in files.items():
        for old, new in replace.items():
            text = text.replace(old, new)
        out[name] = text
    return out

# ---- Dateien und Asterisk ------------------------------------------------------------------------------------------
def write(path: Path, text: str, mode: int = 0o600) -> bool:
    """Atomar schreiben; liefert True, wenn sich etwas geändert hat."""
    try:
        if path.read_text(encoding='utf-8') == text:
            return False
    except OSError:
        pass
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(f'.{path.name}.{os.getpid()}.neu')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        f.write(text)
    os.chmod(tmp, mode)
    os.replace(tmp, path)
    return True


def grundgeruest() -> None:
    for d in (ETC, AST / 'lib', AST / 'lib' / 'keys', AST / 'spool' / 'fax-empfang', AST / 'run', AST / 'log', AST / 'cache'):
        d.mkdir(mode=0o700, parents=True, exist_ok=True)
    for name, text in base_files().items():
        # features.conf und musiconhold.conf ergänzt Kienzlefon um Einbindungen; die schreibt nur „anwenden“ neu.
        if name not in ('features.conf', 'musiconhold.conf') or not (ETC / name).exists():
            write(ETC / name, text)
    for name, text in placeholder_files().items():
        if not (ETC / name).exists():
            write(ETC / name, text)


def rx(command: str, timeout: int = 8) -> tuple[int, str]:
    try:
        r = subprocess.run([ASTERISK, '-C', str(ETC / 'asterisk.conf'), '-rx', command], capture_output=True, text=True, timeout=timeout)
        return r.returncode, r.stdout
    except (OSError, subprocess.SubprocessError):
        return 127, ''


def running() -> bool:
    code, out = rx('core show version', 5)
    return code == 0 and 'Asterisk' in out


def transports(text: str) -> str:
    """Abschnitte der Transporte: ändern sie sich, muss Asterisk neu starten (PJSIP lädt Transporte nicht neu)."""
    return '\n'.join(re.findall(r'^\[transport-[^\]]+\][^\[]*', text, flags=re.M))


def anwenden(cfg: dict) -> dict:
    c = check(cfg)
    grundgeruest()
    files = render(c)
    if c['kienzlefon']['aktiv']:
        files = kienzlefon_anwenden({**files, **{n: t for n, t in base_files().items() if n in ('features.conf', 'musiconhold.conf')}}, c)
    else:
        files[CONTROL_CONF] = '; Rufgruppen sind ausgeschaltet.\n'
        CONTROL_AMI.unlink(missing_ok=True)
    files = pfade_ersetzen(files)
    old = ''
    for name in ('pjsip.conf', 'pjsip-kfx-telefonie.conf'):
        try:
            old += (ETC / name).read_text(encoding='utf-8')
        except OSError:
            pass
    changed = [name for name, text in files.items() if write(ETC / name, text)]
    if c['praxis'] or not (DATA / 'praxis.txt').exists():
        write(DATA / 'praxis.txt', (c['praxis'] or 'Praxis') + '\n', 0o600)
    write(DATA / 'fax.json', json.dumps({'aktiv': bool(c['fax'].get('leitung')), 'nummer': c['fax'].get('nummer', ''),
                                         'kennung': c['fax'].get('kennung', '')}) + '\n', 0o600)
    result = {'ok': True, 'geaendert': changed, 'neustart': False, 'laeuft': running()}
    if result['laeuft'] and changed:
        if transports(old) != transports(files['pjsip.conf'] + files.get('pjsip-kfx-telefonie.conf', '')):
            # Wie Kienzlefax: Transportänderung braucht einen Neustart; erst wenn kein Gespräch mehr läuft.
            rx('core restart when convenient')
            result['neustart'] = True
        else:
            if any(n.startswith('pjsip') for n in changed):
                rx('module reload res_pjsip.so')
            if any(n.startswith('extensions') for n in changed):
                rx('dialplan reload')
            if any(n.startswith('queue') for n in changed):
                rx('module reload app_queue.so')
            if any(n.startswith('manager') for n in changed):
                rx('manager reload')
            if any(n.startswith(('features', 'musiconhold')) for n in changed):
                rx('module reload features')
                rx('moh reload')
            rx('pjsip send register *all')
    return result


def parse_registrations(text: str) -> dict[str, str]:
    out = {}
    for line in text.splitlines():
        parts = line.split()
        if len(parts) >= 3 and parts[0].startswith('kfx-leitung-') and '-registration/' in parts[0]:
            lid = parts[0].split('-registration/')[0].removeprefix('kfx-leitung-')
            out[lid] = parts[2]
    return out


def parse_contacts(text: str) -> dict[str, str]:
    out = {}
    for line in text.splitlines():
        parts = line.split()
        if len(parts) >= 4 and parts[0] == 'Contact:' and '/' in parts[1]:
            aor = parts[1].split('/')[0]
            if NEBENSTELLE.match(aor):
                out[aor] = parts[3]
    return out


def status() -> dict:
    if not running():
        return {'laeuft': False}
    _, reg = rx('pjsip show registrations')
    _, con = rx('pjsip show contacts')
    _, chans = rx('core show channels count')
    _, queue = rx('queue show praxis')
    m = re.search(r'(\d+) active call', chans)
    q = re.search(r'has (\d+) calls?', queue)
    return {'laeuft': True, 'leitungen': parse_registrations(reg), 'telefone': parse_contacts(con),
            'gespraeche': int(m.group(1)) if m else 0, 'wartend': int(q.group(1)) if q else 0}


def pflege(now: float | None = None) -> int:
    """Protokolle älter als 7 Tage und liegengebliebene Empfangsdateien löschen."""
    now = now or time.time()
    removed = 0
    # Datensparsamkeit: erledigte Vorgänge nach 7 Tagen; fertig verarbeitete Anrufe im Spool von Kienzlefon (Klartext der Anliegen)
    # nach 2 Tagen, fehlgeschlagene nach 7 Tagen.
    for folder in (KZF_DATA / 'spool' / 'ready', KZF_DATA / 'spool' / 'error'):
        for p in folder.glob('*') if folder.is_dir() else []:
            try:
                if p.is_dir() and not p.is_symlink() and p.stat().st_mtime < now - (2 if folder.name == 'ready' else 7) * 86400:
                    shutil.rmtree(p)
                    removed += 1
            except OSError:
                pass
    for folder, days in ((AST / 'log', LOG_KEEP_DAYS), (AST / 'spool' / 'fax-empfang', 2), (KZF_DATA / 'vorgaenge' / 'erledigt', ERLEDIGT_TAGE)):
        for p in folder.glob('*') if folder.is_dir() else []:
            try:
                if p.is_file() and not p.is_symlink() and p.stat().st_mtime < now - days * 86400:
                    p.unlink()
                    removed += 1
            except OSError:
                pass
    if running():
        rx('logger rotate')
    return removed


# ---- Wartemusik (Johannes Plachetka oder eigene Datei) ----------------------------------------------------------------
WARTEMUSIK_MAX_SEKUNDEN = 1800


def wartemusik_bereit() -> bool:
    return all((MOH / f'wartemusik.{s}').is_file() for s in ('wav16', 'sln16', 'g722', 'alaw', 'ulaw'))


def wartemusik_stand() -> dict:
    try:
        info = json.loads((MOH / 'quelle.json').read_text(encoding='utf-8'))
    except (OSError, ValueError):
        info = {}
    return {'ok': True, 'bereit': wartemusik_bereit(), 'quelle': info.get('quelle', ''), 'dauer': info.get('dauer', 0)}


def _dauer(path: Path) -> float:
    try:
        r = subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', str(path)],
                           capture_output=True, text=True, timeout=30, check=False)
        return float(r.stdout.strip())
    except (OSError, ValueError, subprocess.SubprocessError):
        return 0.0


def wartemusik_einrichten(job: dict) -> dict:
    """Musik von Johannes Plachetka oder die hochgeladene Datei (kienzlefon/wartemusik-upload) mit hold_music.install_media aus
    Kienzlefon umwandeln (Lautheit -23 LUFS, alle Asterisk-Formate). Die hochgeladene Datei wird danach gelöscht."""
    quelle = job.get('quelle') if isinstance(job, dict) else None
    if quelle not in ('plachetka', 'eigene'):
        raise Fehler('Unbekannte Wartemusik.')
    source = PLACHETKA if quelle == 'plachetka' else KZF_DATA / 'wartemusik-upload'
    try:
        if not source.is_file() or source.is_symlink():
            raise Fehler('Die Datei fehlt. Bitte noch einmal hochladen.')
        dauer = _dauer(source)
        if not 3 <= dauer <= WARTEMUSIK_MAX_SEKUNDEN:
            raise Fehler('Die Datei ist keine lesbare Musikdatei oder kürzer als 3 Sekunden bzw. länger als 30 Minuten.')
        if str(KZF_SOURCE) not in sys.path:
            sys.path.insert(0, str(KZF_SOURCE))
        from kienzlefon.hold_music import HoldMusicError, install_media
        KZF_DATA.mkdir(mode=0o700, parents=True, exist_ok=True)
        try:
            install_media(source, MOH)
        except HoldMusicError as e:
            raise Fehler(f'Die Musik ließ sich nicht umwandeln: {str(e)[:200]}') from None
        write(MOH / 'quelle.json', json.dumps({'quelle': quelle, 'dauer': round(dauer)}) + '\n', 0o644)
    finally:
        if quelle == 'eigene':
            source.unlink(missing_ok=True)
    return wartemusik_stand()


def wartemusik_entfernen() -> dict:
    if MOH.is_dir():
        for f in MOH.iterdir():
            if f.is_file() or f.is_symlink():
                f.unlink()
        MOH.rmdir()
    return wartemusik_stand()


# ---- Rufgruppen Normal und ROT (Seite /telefon/rufgruppen) --------------------------------------------------------------
def _steuerung():
    """Kienzlefon mit dem Auftragsjournal des Moduls laden; liefert (Konfiguration, Prüfsumme von kienzlefon.toml)."""
    import hashlib
    if str(KZF_SOURCE) not in sys.path:
        sys.path.insert(0, str(KZF_SOURCE))
    _anpassen()
    from kienzlefon.config import load_config
    if not KZF_CONFIG.is_file():
        return None, None
    config = load_config(KZF_CONFIG)
    return config, hashlib.sha256(KZF_CONFIG.read_bytes()).hexdigest()


def rufgruppen_lesen() -> dict:
    """Standards je Telefon (aus kienzlefon.toml) und die zuletzt von Asterisk bestätigte Teilnahme (members-confirmed.json)."""
    config, stand = _steuerung()
    if config is None or not config.control_api.enabled:
        return {'ok': True, 'bereit': False}
    from kienzlefon.telephony_policy import managed_phones, policy_for
    policy = policy_for(config)
    try:
        bestaetigt = json.loads((CONTROL_DIR / 'members-confirmed.json').read_text(encoding='utf-8'))
        alter = time.time() - (CONTROL_DIR / 'members-confirmed.json').stat().st_mtime
    except (OSError, ValueError):
        bestaetigt, alter = {}, None
    live = bestaetigt.get('members') or {}
    telefone = []
    for nummer, name in sorted(managed_phones(config).items(), key=lambda x: int(x[0])):
        m = policy.members[nummer]
        telefone.append({'nummer': nummer, 'name': name, **{route: {
            'zugelassen': m[route].eligible, 'standard': m[route].default_member, 'stufe': m[route].penalty,
            'jetzt': nummer in (live.get(route) or [])} for route in ('normal', 'specialist')}})
    return {'ok': True, 'bereit': True, 'stand': stand, 'revision': str(bestaetigt.get('revision', '')), 'bestaetigt': bool(bestaetigt),
            'alter': alter, 'telefone': telefone}


def rufgruppen_schalten(job: dict) -> dict:
    """Ein Telefon jetzt an- oder abmelden (gilt bis zum nächsten Neustart der Anlage; danach wieder die Standards)."""
    config, _ = _steuerung()
    if config is None or not config.control_api.enabled:
        raise Fehler('Rufgruppen sind ausgeschaltet.')
    from kienzlefon.control_api import read_ami
    from kienzlefon.telephony_ami import AsteriskPlant
    from kienzlefon.telephony_control import Controller, Journal, SourceError
    route, nummer, an = job.get('gruppe'), job.get('nummer'), job.get('an')
    if route not in ('normal', 'specialist') or not isinstance(nummer, str) or not re.fullmatch(r'[0-9]{2,8}', nummer) or type(an) is not bool:
        raise Fehler('Ungültige Angaben.')
    plant = AsteriskPlant(read_ami(CONTROL_AMI))
    try:
        plant.connect()
        Controller(Journal(CONTROL_DIR), plant).set_local_member(config, str(job.get('revision', '')), route, nummer, an)
    except (SourceError, ValueError, OSError) as e:
        raise Fehler(str(e)[:300] or 'Die Anlage hat nicht geantwortet.') from None
    finally:
        if plant.connection:
            plant.connection.close()
    return {'ok': True}


def rufgruppen_speichern(job: dict) -> dict:
    """Standards (wer nach einem Neustart klingelt), Zulassung und Rufstufe je Telefon; Freigaben für Middleware/App bleiben unverändert."""
    config, stand = _steuerung()
    if config is None or not config.control_api.enabled:
        raise Fehler('Rufgruppen sind ausgeschaltet.')
    if job.get('stand') != stand:
        raise Fehler('Die Einstellungen wurden inzwischen geändert. Bitte die Seite neu laden.')
    from dataclasses import replace
    from kienzlefon.control_setup import write_policy
    from kienzlefon.telephony_policy import RoutingPolicy, policy_for
    policy = policy_for(config)
    neu = {}
    eingabe = job.get('telefone') if isinstance(job.get('telefone'), dict) else {}
    for nummer, routes in policy.members.items():
        e = eingabe.get(nummer) if isinstance(eingabe.get(nummer), dict) else {}
        neu[nummer] = {}
        for route, m in routes.items():
            r = e.get(route) if isinstance(e.get(route), dict) else {}
            zugelassen = r.get('zugelassen', m.eligible) is True
            stufe = r.get('stufe', m.penalty)
            if route == 'specialist':
                stufe = 0
            if type(stufe) is not int or not 0 <= stufe <= 1000:
                raise Fehler(f'Rufstufe von {nummer}: 0 bis 1000.')
            neu[nummer][route] = replace(m, eligible=zugelassen, default_member=zugelassen and r.get('standard', m.default_member) is True,
                                         can_enable=zugelassen and m.can_enable, can_disable=zugelassen and m.can_disable, penalty=stufe)
    try:
        write_policy(config, RoutingPolicy(policy.remote_enabled, neu, policy.workplace_mode, policy.workplace_ids), CONTROL_DIR, expected_hash=stand)
    except ValueError as e:
        raise Fehler(str(e)[:300]) from None
    return {'ok': True}


# ---- Sprachmenü: Zeiten, Ansagetexte, Menütasten (Seite /telefon/sprachmenue) ---------------------------------------
ZEITEN = {'oeffnungszeiten': 'Öffnungszeiten', 'telefonzeiten': 'Telefonzeiten', 'apothekenzeiten': 'Apotheken und Pflege',
          'fachstellenzeiten': 'Krankenhaus, Notarzt und Kollegen'}


def _kienzlefon():
    import sys
    if str(KZF_SOURCE) not in sys.path:
        sys.path.insert(0, str(KZF_SOURCE))
    from kienzlefon import ivr_keys, webadmin
    from kienzlefon.config import CONFIGURABLE_PROMPT_MODES, WEEKDAYS, load_config
    from kienzlefon.prompts import rendered_prompts
    return webadmin, ivr_keys, load_config, rendered_prompts, CONFIGURABLE_PROMPT_MODES, WEEKDAYS


def _zeitliste(text: str) -> list[str]:
    return [t.strip().replace(' ', '') for t in re.split(r'[,;\n]+', str(text)) if t.strip()]


def sprachmenue_lesen() -> dict:
    """Werte der Seite aus kienzlefon.toml (Vorgaben aus der Vorlage von Kienzlefon). Liefert bereit=false ohne Sprachmenü."""
    import hashlib
    import tomllib
    if not KZF_CONFIG.is_file():
        return {'ok': True, 'bereit': False}
    webadmin, ivr_keys, load_config, rendered_prompts, modes, days = _kienzlefon()
    try:
        config = load_config(KZF_CONFIG)
    except Exception as e:  # noqa: BLE001
        raise Fehler(f'Kienzlefon: {str(e)[:200]}') from None
    raw = tomllib.loads(KZF_CONFIG.read_text(encoding='utf-8'))
    vorgabe = tomllib.loads((KZF_SOURCE / 'config' / 'kienzlefon.toml.example').read_text(encoding='utf-8'))
    rendered = set(rendered_prompts(config))
    erzeugt = set(ivr_keys.GENERATED_PROMPTS) if config.ivr_keys != ivr_keys.DEFAULTS else set()
    ansagen = []
    for name, text in raw.get('ansagen', {}).items():
        if name not in vorgabe.get('ansagen', {}):
            continue
        modus_name = 'opening_hours' if name == 'opening_hours_prefix' else name
        ansagen.append({'name': name, 'label': webadmin._prompt_label(name), 'gruppe': webadmin._prompt_group(name), 'text': str(text),
                        'standard': str(vorgabe['ansagen'][name]), 'erzeugt': name in erzeugt,
                        'modus': config.prompt_mode(modus_name) if modus_name in modes and modus_name in rendered else None,
                        'modus_name': modus_name})
    menues = [('Hauptmenü', ivr_keys.MAIN), ('Untermenü Rückruf/Sonstiges', ivr_keys.SUBMENU),
              ('Nach einem Medikament', ivr_keys.MEDICATION), ('Nach einem Anliegen', ivr_keys.AFTER_CONCERN)]
    return {'ok': True, 'bereit': True, 'stand': hashlib.sha256(KZF_CONFIG.read_bytes()).hexdigest(),
            'zeiten': {s: {'titel': t, 'tage': {d: ', '.join(raw.get(s, {}).get(d, [])) for d in days}} for s, t in ZEITEN.items()},
            'ansagen': ansagen,
            'tasten': [{'titel': t, 'tasten': [{'id': i, 'label': lab, 'taste': config.ivr_keys.get(i, ''), 'standard': std}
                                                for i, (std, lab) in m.items()]} for t, m in menues]}


def sprachmenue_schreiben(job: dict) -> dict:
    """Geänderte Werte prüfen (Prüfung der Weboberfläche von Kienzlefon) und in kienzlefon.toml schreiben."""
    import hashlib
    import tomllib
    if not isinstance(job, dict) or set(job) - {'stand', 'zeiten', 'ansagen', 'modi', 'tasten'}:
        raise Fehler('Ungültige Angaben.')
    if not KZF_CONFIG.is_file():
        raise Fehler('Das Sprachmenü ist noch nicht eingeschaltet.')
    webadmin, _, load_config, _, _, days = _kienzlefon()
    text = KZF_CONFIG.read_text(encoding='utf-8')
    stand = hashlib.sha256(text.encode('utf-8')).hexdigest()
    if job.get('stand') != stand:
        raise Fehler('Die Einstellungen wurden inzwischen geändert. Bitte die Seite neu laden.')
    config = load_config(KZF_CONFIG)
    zeiten = job.get('zeiten') or {}
    alt = tomllib.loads(text)
    if not isinstance(zeiten, dict) or any(not isinstance(v, dict) for v in zeiten.values()):
        raise Fehler('Ungültige Zeiten.')
    auftrag = {'config_hash': stand,
               'schedules': {s: {d: _zeitliste(tage[d]) if d in tage else list(alt.get(s, {}).get(d, [])) for d in days} for s, tage in zeiten.items()},
               'prompts': {str(k): str(v) for k, v in (job.get('ansagen') or {}).items()},
               'prompt_modes': {str(k): str(v) for k, v in (job.get('modi') or {}).items()}}
    if job.get('tasten'):
        auftrag['ivr_keys'] = {str(k): str(v).strip() for k, v in job['tasten'].items()}
    try:
        values = webadmin._validate_save_request(auftrag, config)
    except (webadmin.WebAdminError, ValueError, TypeError) as e:
        raise Fehler(str(e)[:300]) from None
    neu = webadmin._render_updated_config(text, values)
    if neu == text:
        return {'ok': True, 'geaendert': False}
    tmp = KZF_DATA / '.kienzlefon.toml.neu'
    write(tmp, neu, 0o600)
    try:
        load_config(tmp)
    except Exception as e:  # noqa: BLE001 - Konfigurationsfehler von Kienzlefon
        tmp.unlink(missing_ok=True)
        raise Fehler(f'Kienzlefon: {str(e)[:200]}') from None
    os.replace(tmp, KZF_CONFIG)
    return {'ok': True, 'geaendert': True}


def main(argv: list[str]) -> int:
    action = argv[1] if len(argv) > 1 else ''
    try:
        if action == 'grundgeruest':
            grundgeruest()
            return 0
        if action in ('pruefen', 'anwenden'):
            raw = sys.stdin.read(2_000_000)
            cfg = json.loads(raw) if raw.strip() else {}
            if action == 'pruefen':
                check(cfg)
                print(json.dumps({'ok': True}))
            else:
                print(json.dumps(anwenden(cfg), ensure_ascii=False))
            return 0
        if action == 'sprachmenue-lesen':
            print(json.dumps(sprachmenue_lesen(), ensure_ascii=False))
            return 0
        if action == 'sprachmenue-schreiben':
            raw = sys.stdin.read(1_000_000)
            print(json.dumps(sprachmenue_schreiben(json.loads(raw) if raw.strip() else {}), ensure_ascii=False))
            return 0
        if action in ('rufgruppen-lesen', 'rufgruppen-schalten', 'rufgruppen-speichern'):
            raw = sys.stdin.read(200_000)
            job = json.loads(raw) if raw.strip() else {}
            r = rufgruppen_lesen() if action == 'rufgruppen-lesen' else rufgruppen_schalten(job) if action == 'rufgruppen-schalten' else rufgruppen_speichern(job)
            print(json.dumps(r, ensure_ascii=False))
            return 0
        if action in ('wartemusik', 'wartemusik-entfernen', 'wartemusik-stand'):
            raw = sys.stdin.read(10_000)
            job = json.loads(raw) if raw.strip() else {}
            r = wartemusik_einrichten(job) if action == 'wartemusik' else wartemusik_entfernen() if action == 'wartemusik-entfernen' else wartemusik_stand()
            print(json.dumps(r, ensure_ascii=False))
            return 0
        if action == 'status':
            print(json.dumps(status(), ensure_ascii=False))
            return 0
        if action == 'pflege':
            print(json.dumps({'entfernt': pflege()}))
            return 0
    except Fehler as e:
        print(json.dumps({'ok': False, 'fehler': str(e)}, ensure_ascii=False))
        return 1
    except ValueError:
        print(json.dumps({'ok': False, 'fehler': 'Einstellungen ungültig.'}))
        return 1
    print('Aufruf: anlage.py grundgeruest|pruefen|anwenden|status|pflege|sprachmenue-lesen|sprachmenue-schreiben', file=sys.stderr)
    return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv))
