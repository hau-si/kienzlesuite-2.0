#!/usr/bin/env python3
from __future__ import annotations

import argparse
import contextlib
import json
import os
import re
import shutil
import resource
import stat
import time
import fcntl
import subprocess
import sys
import tempfile
import textwrap
from collections import OrderedDict
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any

try:
    import tomllib  # Python 3.11+
except ModuleNotFoundError:  # pragma: no cover
    import tomli as tomllib  # type: ignore

DATA = Path(os.environ.get("KIENZLE_DATEN") or "/var/lib/kienzle/terminzettel")
DEFAULT_CONFIG = str(Path(__file__).resolve().parent / "config.toml")
# Von der Einstellungsseite des Moduls geschrieben (Layout) bzw. von der Basis übernommen (Drucker); überlagert config.toml.
WEB_OVERLAY = str(DATA / "layout.json")
BASIS = str(DATA / "basis.json")
# Praxislogo (Graustufen-PNG), von der Einstellungsseite abgelegt.
LOGO = str(DATA / "logo.png")
# Bondrucker-Modelle: Druckbreite in Punkten und Zeichen je Zeile (Font A, 12 Punkte breit).
MODELS = {"tm-m10": (420, 35), "tm-m30iii": (576, 48)}
SIZES = ("1x1", "1x2", "2x1", "2x2", "2x3", "3x3", "4x4")
QR_POSITIONS = ("right", "left", "below")
WORK = Path(tempfile.gettempdir())
LP = "/usr/bin/lp"
MAX_INPUT = 8 * 1024 * 1024
JOB_TIMEOUT = 60


class TicketError(ValueError):
    """Nur feste, vom Programm vorgegebene Meldungen; niemals Beleginhalte."""


def safe_text(text: str) -> str:
    if any((ord(char) < 32 and char not in "\n\r\t\f") or 127 <= ord(char) < 160
           for char in text):
        raise TicketError("Der Beleg enthält unzulässige Steuerzeichen.")
    return text


def ensure_runtime() -> None:
    """Keine Speicherauszüge mit Patientendaten."""
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))


WEEKDAYS = {
    "Mo": "Mo.", "Di": "Di.", "Mi": "Mi.", "Do": "Do.",
    "Fr": "Fr.", "Sa": "Sa.", "So": "So.",
}

APPOINTMENT_RE = re.compile(
    r"^\s*(?P<weekday>Mo|Di|Mi|Do|Fr|Sa|So)\.?\s+"
    r"(?P<date>\d{1,2}\.\d{1,2}\.\d{4}),\s*"
    r"(?P<time>\d{1,2}:\d{2})\s+"
    r"(?P<type>\S.*)\s*$"
)


@dataclass
class Appointment:
    weekday: str
    date: str
    time: str
    kind: str

    @property
    def dt(self) -> datetime:
        return datetime.strptime(f"{self.date} {self.time}", "%d.%m.%Y %H:%M")


@dataclass
class ParsedTicket:
    patient: str
    appointments: list[Appointment]


def eprint(*args: object) -> None:
    print(*args, file=sys.stderr)


LOG_FILE = "druckprotokoll.txt"


_START = time.monotonic()
_ZEITEN: dict[str, float] = {}


@contextlib.contextmanager
def zeit(name: str):
    """Dauer eines Schritts messen (für das Druckprotokoll: Wo bleibt die Zeit?)."""
    begin = time.monotonic()
    try:
        yield
    finally:
        _ZEITEN[name] = _ZEITEN.get(name, 0.0) + time.monotonic() - begin


def sekunden(value: float) -> str:
    return (f"{value:.1f}" if value >= 0.1 else f"{value:.2f}").replace(".", ",") + " s"


def zeiten_text() -> str:
    """z. B. „Umwandlung 0,8 s · Texterkennung 6,2 s · … · gesamt 7,5 s“ (nur Schritte, die gelaufen sind)."""
    teile = [f"{name} {sekunden(value)}" for name, value in _ZEITEN.items()]
    return " · ".join(teile + [f"gesamt {sekunden(time.monotonic() - _START)}"])


def job_log(text: str) -> None:
    """Kurzes Protokoll der letzten Aufträge für die Einstellungsseite (feste Texte, keine Patientendaten)."""
    try:
        path = DATA / LOG_FILE
        try:
            old = path.read_text(encoding="utf-8").splitlines()[-39:]
        except OSError:
            old = []
        old.append(datetime.now().strftime("%d.%m.%Y %H:%M:%S") + "  " + text)
        tmp = path.with_suffix(".neu")
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write("\n".join(old) + "\n")
        os.replace(tmp, path)
    except Exception:  # Protokoll ist nur Hilfe, nie Grund für einen Fehler
        pass


def job_kind(data: bytes) -> str:
    if data.startswith(b"%PDF-"):
        return "PDF"
    if data.startswith(b"%!PS"):
        return "PostScript"
    if data.startswith(b"PK\x03\x04"):
        return "XPS"
    return "Text"


def load_config(path: str, overlay: str | None = WEB_OVERLAY) -> dict[str, Any]:
    with open(path, "rb") as fh:
        cfg = tomllib.load(fh)
    if cfg.get("debug") or cfg.get("output", {}).get("transport", "cups") != "cups":
        raise TicketError("Alte Konfiguration: Bitte die neue Beispieldatei verwenden.")
    if overlay:
        try:
            with open(overlay, "rb") as fh:
                data = fh.read(65537)
        except FileNotFoundError:
            return cfg  # Eigenständiger Betrieb ohne Webinterface.
        except OSError:
            raise TicketError("Einstellungen aus dem Webinterface nicht lesbar. Bitte den Server-Installer erneut ausführen.") from None
        try:
            settings = json.loads(data.decode("utf-8"))
        except (UnicodeError, ValueError):
            raise TicketError("Einstellungen aus dem Webinterface sind beschädigt. Bitte dort erneut speichern.") from None
        apply_overlay(cfg, settings, printing=True)
    apply_basis(cfg)
    return cfg


def apply_basis(cfg: dict[str, Any], path: str | None = None) -> None:
    """Bondrucker laut Verwaltung → Drucker (Zuordnung „Bondrucker“), von der Einstellungsseite in basis.json abgelegt."""
    try:
        with open(path or BASIS, "rb") as fh:
            data = json.loads(fh.read(65537).decode("utf-8"))
        queue = data["drucker"]["bon"]
    except (OSError, ValueError, KeyError, TypeError):
        queue = ""
    if not isinstance(queue, str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{0,39}", queue) or queue.casefold() == "terminzettel":
        return
    cfg.setdefault("output", {})["queue"] = queue


def _text(value: Any, limit: int = 1000) -> str:
    if not isinstance(value, str) or len(value) > limit:
        raise TicketError("Ungültiger Text in den Terminzettel-Einstellungen.")
    return safe_text(value.replace("\r\n", "\n").replace("\r", "\n")).replace("\t", " ").replace("\f", " ")


def _choice(value: Any, allowed: tuple[str, ...]) -> str:
    if value not in allowed:
        raise TicketError("Ungültige Auswahl in den Terminzettel-Einstellungen.")
    return value


def apply_overlay(cfg: dict[str, Any], web: Any, *, printing: bool = False) -> dict[str, Any]:
    """Webeinstellungen übernehmen: Drucker, Texte, Schriftgrößen, Kalender-QR."""
    if not isinstance(web, dict):
        raise TicketError("Einstellungen aus dem Webinterface sind beschädigt. Bitte dort erneut speichern.")
    if printing and web.get("enabled") is False:
        raise TicketError("Der Terminzettel ist in den Einstellungen ausgeschaltet.")
    output, layout, escpos = cfg.setdefault("output", {}), cfg.setdefault("layout", {}), cfg.setdefault("escpos", {})
    if "queue" in web:
        queue = web["queue"]
        if queue is None:
            if printing:
                raise TicketError("Kein Bondrucker eingerichtet. Bitte in den Einstellungen unter „Drucker einrichten“ einen Bondrucker anlegen.")
        elif not isinstance(queue, str) or not re.fullmatch(r"[A-Za-z0-9_.-]{1,64}", queue) or queue.casefold() == "terminzettel":
            raise TicketError("Ungültige Zielwarteschlange.")
        else:
            output["queue"] = queue
    if "model" in web:
        layout["page_width_dots"], layout["columns"] = MODELS[_choice(web["model"], tuple(MODELS))]
    if "cut" in web:
        if type(web["cut"]) is not bool:
            raise TicketError("Ungültige Auswahl in den Terminzettel-Einstellungen.")
        escpos["cut"] = "partial" if web["cut"] else "none"
    for name in ("header", "footer"):
        block = web.get(name)
        if block is None:
            continue
        if not isinstance(block, dict):
            raise TicketError("Einstellungen aus dem Webinterface sind beschädigt.")
        section = cfg.setdefault(name, {})
        if "enabled" in block:
            if type(block["enabled"]) is not bool:
                raise TicketError("Ungültige Auswahl in den Terminzettel-Einstellungen.")
            section["enabled"] = block["enabled"]
        if "text" in block:
            section["text"] = _text(block["text"])
        if "align" in block:
            section["align"] = _choice(block["align"], ("left", "center", "right"))
        if "bold" in block:
            if type(block["bold"]) is not bool:
                raise TicketError("Ungültige Auswahl in den Terminzettel-Einstellungen.")
            section["bold"] = block["bold"]
        if "size" in block:
            layout[name + "_size"] = _choice(block["size"], SIZES)
        # Erste Zeile des Textes abweichend gestalten (nur Praxiskopf): Schriftgröße und fett.
        if name == "header":
            for key in ("first_size", "first_bold"):
                layout.pop("header_" + key, None)
            if block.get("first_size") not in (None, ""):
                layout["header_first_size"] = _choice(block["first_size"], SIZES)
            if block.get("first_bold") not in (None, ""):
                if type(block["first_bold"]) is not bool:
                    raise TicketError("Ungültige Auswahl in den Terminzettel-Einstellungen.")
                layout["header_first_bold"] = block["first_bold"]
    logo = web.get("logo")
    if isinstance(logo, dict):
        if type(logo.get("enabled")) is not bool:
            raise TicketError("Ungültige Auswahl in den Terminzettel-Einstellungen.")
        width = logo.get("width_dots", 288)
        if type(width) is not int or not 32 <= width <= 384:
            raise TicketError("Logo-Breite muss zwischen 32 und 384 Punkten liegen.")
        cfg["logo"] = {"enabled": logo["enabled"], "width_dots": width, "align": _choice(logo.get("align", "center"), ("left", "center", "right"))}
    heading = web.get("heading")
    if isinstance(heading, dict):
        if "text" in heading:
            layout["heading"] = _text(heading["text"], 60)
        if "size" in heading:
            layout["heading_size"] = _choice(heading["size"], SIZES)
    appointments = web.get("appointments")
    if isinstance(appointments, dict):
        if "size" in appointments:
            layout["appointment_size"] = _choice(appointments["size"], SIZES)
        if "show_patient" in appointments:
            if type(appointments["show_patient"]) is not bool:
                raise TicketError("Ungültige Auswahl in den Terminzettel-Einstellungen.")
            layout["show_patient"] = appointments["show_patient"]
    cancel = web.get("cancel")
    if isinstance(cancel, dict):
        if type(cancel.get("enabled")) is not bool:
            raise TicketError("Ungültige Auswahl in den Terminzettel-Einstellungen.")
        width = cancel.get("width_dots", 292)
        if type(width) is not int or not 64 <= width <= 384:
            raise TicketError("QR-Größe muss zwischen 64 und 384 Punkten liegen.")
        to = _text(cancel.get("to", ""), 100).strip()
        if cancel["enabled"] and not re.fullmatch(r"[^\s@<>,;]{1,64}@[^\s@<>,;]{1,60}\.[^\s@<>,;]{2,20}", to):
            raise TicketError("Absage-QR: Bitte eine gültige E-Mail-Adresse als Empfänger eintragen.")
        cfg["cancel_qr"] = {"enabled": cancel["enabled"], "to": to, "subject": _text(cancel.get("subject", ""), 100),
                            "body": _text(cancel.get("body", ""), 400), "caption": _text(cancel.get("caption", ""), 80), "width_dots": width}
    qr = web.get("qr")
    if isinstance(qr, dict):
        calendar = cfg.setdefault("calendar_qr", {})
        if "enabled" in qr:
            if type(qr["enabled"]) is not bool:
                raise TicketError("Ungültige Auswahl in den Terminzettel-Einstellungen.")
            calendar["enabled"] = qr["enabled"]
        if "position" in qr:
            calendar["position"] = _choice(qr["position"], QR_POSITIONS)
        if "width_dots" in qr:
            if type(qr["width_dots"]) is not int or not 64 <= qr["width_dots"] <= 384:
                raise TicketError("QR-Größe muss zwischen 64 und 384 Punkten liegen.")
            calendar["max_width_dots"] = qr["width_dots"]
        if "summary" in qr:
            calendar["summary"] = _text(qr["summary"], 80)
    return cfg


def parse_size(value: Any, default: tuple[int, int]) -> tuple[int, int]:
    """Schriftgröße „BreitexHöhe“ als Vielfaches der Druckerschrift (GS !)."""
    if value is None:
        return default
    if value not in SIZES:
        raise TicketError("Ungültige Schriftgröße.")
    width, height = (int(part) for part in value.split("x"))
    return width, height


def run_checked(args: list[str], *, input_bytes: bytes | None = None,
                temp_dir: str | None = None) -> bytes:
    env = {"PATH": "/usr/bin:/bin", "LC_ALL": "C.UTF-8",
           "HOME": "/nonexistent", "TMPDIR": temp_dir or str(WORK)}
    try:
        proc = subprocess.run(args, input=input_bytes, stdout=subprocess.PIPE,
                              stderr=subprocess.DEVNULL, check=False, timeout=30, env=env)
    except (OSError, subprocess.TimeoutExpired):
        raise TicketError("Die Dateiumwandlung konnte nicht abgeschlossen werden.") from None
    if proc.returncode != 0:
        raise TicketError("Die Dateiumwandlung ist fehlgeschlagen.")
    if len(proc.stdout) > MAX_INPUT:
        raise TicketError("Der umgewandelte Beleg ist zu groß.")
    return proc.stdout


def _ocr_text(source: str, data: bytes | None, temp_dir: str | None) -> str:
    """Seite als Bild lesen (Tesseract) und die Textschicht mit Spalten zurückgeben; Bild und Ergebnis laufen nur per Pipe."""
    image = run_checked(["pdftoppm", "-f", "1", "-l", "1", "-singlefile",
                         "-scale-to", "2500", "-gray", "-png", source],
                        input_bytes=data, temp_dir=temp_dir)
    # Die Textschicht erhält Spalten und Einrückungen für mehrzeilige Termintypen.
    searchable = run_checked(["tesseract", "stdin", "stdout", "-l", "deu", "--psm", "6",
                              "-c", "tessedit_create_pdf=1", "-c", "textonly_pdf=1"],
                             input_bytes=image, temp_dir=temp_dir)
    return run_checked(["pdftotext", "-layout", "-", "-"],
                       input_bytes=searchable, temp_dir=temp_dir).decode("utf-8", "strict")


def extract_pdf_text(source: str, cfg: dict[str, Any], *, data: bytes | None = None,
                     temp_dir: str | None = None) -> str:
    with zeit("Textlesen"):
        text = run_checked(["pdftotext", "-layout", source, "-"],
                           input_bytes=data, temp_dir=temp_dir).decode("utf-8", "strict")
    if text.strip():
        return text
    if not cfg_bool(cfg.get("input", {}), "ocr_if_needed", True):
        raise TicketError("Der Beleg enthält keinen auslesbaren Text; Texterkennung ist deaktiviert.")
    # Nicht stillschweigend nur die erste Seite eines mehrseitigen Bild-PDF lesen.
    info = run_checked(["pdfinfo", source], input_bytes=data, temp_dir=temp_dir)
    if not re.search(rb"^Pages:\s+1\s*$", info, re.MULTILINE):
        raise TicketError("Für die Texterkennung ist genau eine PDF-Seite erforderlich.")
    try:
        with zeit("Texterkennung"):
            text = _ocr_text(source, data, temp_dir)
    except TicketError:
        raise TicketError("Texterkennung fehlgeschlagen. Bitte Tesseract und das deutsche Sprachpaket prüfen.") from None
    if not text.strip():
        raise TicketError("Die Texterkennung hat keinen lesbaren Text gefunden.")
    ticket = parse_t2med(text)
    weekdays = tuple(WEEKDAYS.values())
    if any(item.weekday != weekdays[item.dt.weekday()] for item in ticket.appointments):
        raise TicketError("Texterkennung unplausibel: Wochentag und Datum stimmen nicht überein.")
    return text


def extract_text(data: bytes, cfg: dict[str, Any]) -> str:
    if len(data) > MAX_INPUT:
        raise TicketError("Der Beleg ist zu groß (höchstens 8 MiB).")
    if data.startswith(b"%PDF-"):
        return extract_pdf_text("-", cfg, data=data)
    if data.startswith((b"%!PS", b"PK\x03\x04")):
        with tempfile.TemporaryDirectory(prefix="job-", dir=WORK) as td:
            # Der Bereinigungsdienst überspringt noch aktive Aufträge.
            with open(Path(td) / ".lock", "w") as lock:
                fcntl.flock(lock, fcntl.LOCK_EX)
                source, pdf = Path(td) / "input", Path(td) / "input.pdf"
                source.write_bytes(data)
                with zeit("Umwandlung"):
                    if data.startswith(b"%!PS"):
                        run_checked(["gs", "-q", "-dSAFER", "-dNOPAUSE", "-dBATCH", "-sDEVICE=pdfwrite",
                                     "-sOutputFile=" + str(pdf), str(source)], temp_dir=td)
                    else:
                        # Ubuntu liefert das Werkzeug aus libgxps-utils als „xpstopdf“; „gxps2pdf“ nur ältere Pakete.
                        tool = "xpstopdf" if shutil.which("xpstopdf", path="/usr/bin:/bin") else "gxps2pdf"
                        run_checked([tool, str(source), str(pdf)], temp_dir=td)
                return extract_pdf_text(str(pdf), cfg, temp_dir=td)
    encoding = str(cfg.get("input", {}).get("text_encoding", "utf-8"))
    # Der Windows-Treiber „Generic / Text Only“ liefert ANSI (Windows-1252): Umlaute sind dann kein gültiges UTF-8.
    for candidate in dict.fromkeys((encoding, "cp1252")):
        try:
            return data.decode(candidate, "strict")
        except (UnicodeError, LookupError):
            continue
    raise TicketError("Textkodierung ungültig. Bitte PDF oder PostScript verwenden.")


def read_spool(path: str) -> bytes:
    """Auftragsdatei des Druckservers lesen und sofort entfernen (nur eigene, einfache Dateien)."""
    ensure_runtime()
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as fh:
        metadata = os.fstat(fh.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid() or metadata.st_nlink != 1:
            raise TicketError("Ungültige Auftragsdatei.")
        try:
            os.unlink(path)
        except OSError:
            pass
        data = fh.read(MAX_INPUT + 1)
    if len(data) > MAX_INPUT:
        raise TicketError("Der Beleg ist zu groß (höchstens 8 MiB).")
    return data


def parse_t2med(text: str) -> ParsedTicket:
    text = safe_text(text).replace("\u00a0", " ").replace("\r\n", "\n").replace("\r", "\n")
    pages = [page for page in text.split("\f") if page.strip()]
    if len(pages) != 1:
        raise TicketError("Genau eine nichtleere Seite erforderlich.")
    lines = pages[0].expandtabs(8).splitlines()
    headers = [i for i, line in enumerate(lines) if "Terminzeitpunkt" in line and "Termintyp" in line]
    if len(headers) != 1:
        raise TicketError("Tabellenkopf Terminzeitpunkt / Termintyp fehlt oder kommt mehrfach vor.")
    header = headers[0]
    names = [line.strip() for line in lines[:header] if line.strip() and not line.strip().upper().startswith("TERMINE")]
    if not names:
        raise TicketError("Patientenname fehlt.")
    appointments: list[Appointment] = []
    current = None
    type_column = 0
    for line in lines[header + 1:]:
        if not line.strip():
            current = None  # Abgesetzte Praxisfußzeilen gehören nicht zum letzten Termin.
            continue
        match = APPOINTMENT_RE.fullmatch(line)
        if match:
            try:
                date_time = datetime.strptime(match.group("date") + " " + match.group("time"), "%d.%m.%Y %H:%M")
            except ValueError:
                raise TicketError("Ungültiges Datum oder ungültige Uhrzeit.") from None
            current = Appointment(
                weekday=WEEKDAYS[match.group("weekday")],
                date=date_time.strftime("%d.%m.%Y"),
                time=date_time.strftime("%H:%M"),
                kind=" ".join(match.group("type").split()),
            )
            appointments.append(current)
            type_column = match.start("type")
            continue
        # Auch beschädigte Terminzeilen dürfen nicht als Beschreibung verschwinden.
        if re.match(r"^\s*(?:[^\W\d_]+\.?\s+)?\d{1,2}[./-]\d{1,2}[./-]\d{2,4}\b", line):
            raise TicketError("Unvollständige oder ungültige Terminzeile.")
        indent = len(line) - len(line.lstrip())
        if current is not None and indent >= type_column:
            current.kind += " " + " ".join(line.split())
        else:
            current = None  # Nicht eingerückter Fußtext beendet die Fortsetzung.
    if not appointments:
        raise TicketError("Keine T2med-Termine gefunden.")
    seen = set()
    unique = []
    for appointment in appointments:
        key = (appointment.date, appointment.time, appointment.kind)
        if key not in seen:
            seen.add(key)
            unique.append(appointment)
    unique.sort(key=lambda appointment: appointment.dt)
    return ParsedTicket(patient=names[-1], appointments=unique)


def cfg_bool(section: dict[str, Any], key: str, default: bool) -> bool:
    val = section.get(key, default)
    if not isinstance(val, bool):
        raise TicketError("Ein Konfigurationsschalter muss true oder false sein.")
    return val


def align_text(line: str, width: int, align: str) -> str:
    line = line.rstrip()
    if len(line) >= width:
        return line
    if align == "center":
        return line.center(width)
    if align == "right":
        return line.rjust(width)
    return line


class EscPosRenderer:
    ESC = b"\x1b"
    GS = b"\x1d"

    def __init__(self, cfg: dict[str, Any]):
        self.cfg = cfg
        esc = cfg.get("escpos", {})
        layout = cfg.get("layout", {})
        self.encoding = str(esc.get("encoding", "cp858"))
        self.codepage = int(esc.get("codepage", 19))
        self.columns = int(layout.get("columns", 35))
        if self.encoding not in ("cp858", "cp850", "cp437", "latin-1", "ascii"):
            raise TicketError("Für ESC/POS eine unterstützte Einbyte-Kodierung verwenden.")
        if not 8 <= self.columns <= 64 or not 0 <= self.codepage <= 255:
            raise TicketError("Ungültige Druckbreite oder Codepage.")
        self.base_columns = self.columns
        self.page_width = int(layout.get("page_width_dots", 420))
        if self.page_width not in (420, 576):
            raise TicketError("Ungültige Druckbreite.")
        double_width = cfg_bool(layout, "double_width", True)
        double_height = cfg_bool(layout, "double_height", True)
        self.default_size = (2 if double_width else 1, 2 if double_height else 1)
        heading_default = (self.default_size[0], 2 if cfg_bool(layout, "heading_double_height", True) else 1)
        self.sizes = {
            "header": parse_size(layout.get("header_size"), self.default_size),
            "heading": parse_size(layout.get("heading_size"), heading_default),
            "appointment": parse_size(layout.get("appointment_size"), self.default_size),
            "footer": parse_size(layout.get("footer_size"), self.default_size),
        }
        self.columns = self.base_columns // self.default_size[0]
        self.buf = bytearray()
        self._align = "left"
        self._bold = False
        self._size = (1, 1)

    def raw(self, data: bytes) -> None:
        self.buf.extend(data)

    def init(self) -> None:
        esc = self.cfg.get("escpos", {})
        if cfg_bool(esc, "initialize", True):
            self.raw(self.ESC + b"@")
        self.raw(self.ESC + b"t" + bytes([self.codepage & 0xFF]))
        font = str(esc.get("font", "A")).upper()
        self.raw(self.ESC + b"M" + (b"\x01" if font == "B" else b"\x00"))

    def set_align(self, align: str) -> None:
        align = align.lower()
        n = {"left": 0, "center": 1, "right": 2}.get(align, 0)
        if align != self._align:
            self.raw(self.ESC + b"a" + bytes([n]))
            self._align = align

    def set_bold(self, enabled: bool) -> None:
        if enabled != self._bold:
            self.raw(self.ESC + b"E" + (b"\x01" if enabled else b"\x00"))
            self._bold = enabled

    def set_double_height(self, enabled: bool) -> None:
        self.set_scale(self._size[0], 2 if enabled else 1)

    def set_size(self, double_height: bool, double_width: bool) -> None:
        self.set_scale(2 if double_width else 1, 2 if double_height else 1)

    def set_scale(self, width: int, height: int) -> None:
        if (width, height) != self._size:
            self.raw(self.GS + b"!" + bytes([((width - 1) << 4) | (height - 1)]))
            self._size = (width, height)

    def cols(self, size: tuple[int, int]) -> int:
        """Zeichen je Zeile bei dieser Schriftbreite."""
        return max(4, self.base_columns // size[0])

    def reset_style(self) -> None:
        self.set_size(False, False)
        self.set_bold(False)
        self.set_align("left")

    def encode(self, text: str) -> bytes:
        replacement = str(self.cfg.get("escpos", {}).get("unencodable", "?"))[:1] or "?"
        try:
            return text.encode(self.encoding)
        except UnicodeEncodeError:
            safe = text.encode(self.encoding, "replace").decode(self.encoding, "replace")
            if replacement != "?":
                safe = safe.replace("?", replacement)
            return safe.encode(self.encoding, "replace")

    def line(self, text: str = "", *, align: str | None = None,
             bold: bool | None = None, double_height: bool | None = None,
             size: tuple[int, int] | None = None) -> None:
        if align is not None:
            self.set_align(align)
        if bold is not None:
            self.set_bold(bold)
        if size is None:
            if double_height is None:
                size = self.default_size
            else:
                size = (self.default_size[0], 2 if double_height else 1)
        self.set_scale(*size)
        self.raw(self.encode(safe_text(text)) + b"\n")

    def wrapped(self, text: str, *, align: str = "left", bold: bool = False,
                initial_indent: str = "", subsequent_indent: str = "",
                size: tuple[int, int] | None = None) -> None:
        size = size or self.default_size
        columns = self.cols(size)
        width = max(4, columns - len(initial_indent))
        chunks = textwrap.wrap(text.strip(), width=width, break_long_words=True,
                               break_on_hyphens=False) or [""]
        for i, chunk in enumerate(chunks):
            indent = initial_indent if i == 0 else subsequent_indent
            line = indent + chunk
            if align == "center":
                line = align_text(line, columns, "center")
            elif align == "right":
                line = align_text(line, columns, "right")
            self.line(line.rstrip(), align=align, bold=bold, size=size)

    def block(self, section_name: str, size: tuple[int, int] | None = None,
              first_size: tuple[int, int] | None = None, first_bold: bool | None = None) -> None:
        sec = self.cfg.get(section_name, {})
        if not cfg_bool(sec, "enabled", True):
            return
        text = str(sec.get("text", "")).strip("\n")
        if not text:
            return
        align = str(sec.get("align", "center"))
        bold = cfg_bool(sec, "bold", False)
        size = size or self.default_size
        first_pending = first_size is not None or first_bold is not None
        for logical in text.splitlines():
            if not logical.strip():
                self.line("", align=align, bold=bold, size=size)
                continue
            line_size, line_bold = size, bold
            if first_pending:   # erste Textzeile (samt Umbruch) abweichend gestaltet
                line_size = first_size or size
                line_bold = bold if first_bold is None else first_bold
            for wrapped in textwrap.wrap(logical.strip(), width=self.cols(line_size),
                                         break_long_words=True, break_on_hyphens=False) or [""]:
                self.line(wrapped, align=align, bold=line_bold, size=line_size)
            first_pending = False

    def finish(self) -> bytes:
        esc = self.cfg.get("escpos", {})
        self.reset_style()
        feed_lines = int(esc.get("feed_lines", 4))
        if not 0 <= feed_lines <= 20:
            raise TicketError("Papiervorschub muss zwischen 0 und 20 liegen.")
        if feed_lines > 0:
            self.raw(b"\n" * feed_lines)
        cut = str(esc.get("cut", "partial")).lower()
        if cut == "partial":
            self.raw(self.GS + b"V\x01")
        elif cut == "full":
            self.raw(self.GS + b"V\x00")
        elif cut in ("none", "off", "false", ""):
            pass
        else:
            raise ValueError("escpos.cut muss 'partial', 'full' oder 'none' sein")
        return bytes(self.buf)


def append_appointment_row(r: EscPosRenderer, appointment: Appointment, cfg: dict[str, Any]) -> bool:
    """Links große Druckerschrift, rechts genau der zugehörige Offline-QR."""
    try:
        calendar = cfg.get("calendar_qr", {})
        if not isinstance(calendar, dict):
            raise ValueError("invalid QR configuration")
        if not cfg_bool(calendar, "enabled", False):
            return False
        from calendar_qr import CalendarAppointment, appointment_summary, build_icalendar, build_qr_image
        from escpos import page_image

        font_width = 10 if str(cfg.get("escpos", {}).get("font", "A")).upper() == "B" else 12
        width_scale, height_scale = r.sizes["appointment"]
        char_width, char_height = font_width * width_scale, 24 * height_scale
        # TM-m10: 420, TM-m30III: 576 Druckpunkte; Font A 12 x 24 Punkte.
        page_width = r.page_width
        position = calendar.get("position", "right")
        if position not in QR_POSITIONS:
            raise ValueError("invalid QR configuration")
        qr_config = dict(calendar)
        qr_config.pop("position", None)
        qr_config["summary"] = appointment_summary(calendar.get("summary", "Termin Arztpraxis"), appointment.kind)
        payload = build_icalendar([CalendarAppointment(start=appointment.dt)], qr_config)
        requested = calendar.get("max_width_dots", 256)
        if type(requested) is not int or not 64 <= requested <= 384:
            raise ValueError("invalid QR configuration")
        if position == "below":
            qr_config["max_width_dots"] = min(requested, 384, page_width)
        else:
            # Mindestens die Uhrzeit muss vollständig neben den QR passen.
            qr_config["max_width_dots"] = min(requested, page_width - 5 * char_width - 12)
        if qr_config["max_width_dots"] < 64:
            raise ValueError("appointment font too large for QR beside text")
        bitmap = build_qr_image(payload, qr_config)
        line_height = char_height + 6
        if position == "below":
            text_x, columns = 0, r.cols(r.sizes["appointment"])
        elif position == "left":
            text_x = bitmap.width + 12
            columns = min(r.cols(r.sizes["appointment"]), (page_width - text_x) // char_width)
        else:
            text_x = 0
            columns = min(r.cols(r.sizes["appointment"]), (page_width - bitmap.width - 12) // char_width)
        lines = [appointment.time] + (textwrap.wrap(appointment.kind, width=max(1, columns),
                 break_long_words=True, break_on_hyphens=False) or [""])
        text_height = len(lines) * line_height
        if position == "below":
            qr_x, qr_y = (page_width - bitmap.width) // 2, text_height + 4
            page_height = ((qr_y + bitmap.height + 23) // 24) * 24 + 8
        else:
            qr_x, qr_y = (page_width - bitmap.width if position == "right" else 0), 0
            page_height = max(((bitmap.height + 23) // 24) * 24, text_height) + 8
        if page_height > 2400:
            raise ValueError("appointment row too high")

        # Zuerst vollständig bauen: bei jedem Fehler bleibt der normale Textbon erhalten.
        addition = EscPosRenderer(cfg)
        addition.raw(b"\x1dP\xcb\xcb")  # 203 dpi, Koordinaten in Druckpunkten.
        addition.raw(b"\x1bL\x1bT\x00")
        addition.raw(b"\x1bW\x00\x00\x00\x00" + page_width.to_bytes(2, "little")
                     + page_height.to_bytes(2, "little"))
        addition.set_scale(width_scale, height_scale)
        for index, line in enumerate(lines):
            addition.raw(b"\x1b$" + text_x.to_bytes(2, "little") + b"\x1d$"
                         + (index * line_height + char_height - 1).to_bytes(2, "little"))
            addition.raw(addition.encode(safe_text(line)))
        addition.raw(page_image(bitmap, qr_x, qr_y, page_width=page_width))
        addition.raw(b"\x0c")  # Den Block drucken und in den Standardmodus zurückkehren.
        addition.reset_style()
        addition.raw(b"\x1dP\x00\x00")
        r.reset_style()
        r.raw(bytes(addition.buf))
        return True
    except Exception as exc:
        # Nur feste technische Meldungen; niemals Payload oder Fremdfehlermeldungen.
        message = "calendar QR payload too large" if type(exc).__name__ == "PayloadTooLarge" else "calendar QR generation failed"
        eprint(message)
        return False


def append_logo(r: EscPosRenderer, cfg: dict[str, Any]) -> None:
    """Praxislogo oben, mittig, als Raster (GS v 0). Ohne lesbare Logodatei bleibt der Bon wie bisher."""
    section = cfg.get("logo", {})
    if not cfg_bool(section, "enabled", False):
        return
    try:
        from escpos import raster_image
        from logo import bitmap
        image = bitmap(LOGO, int(section.get("width_dots", 288)), r.page_width)
        if image is None:
            return
        align = section.get("align", "center")
        r.set_align(align if align in ("left", "center", "right") else "center")
        r.raw(raster_image(image))
    except Exception:  # Logo ist Zierde: ein Fehler darf den Ausdruck nie verhindern
        eprint("Logo konnte nicht gedruckt werden.")


def cancel_fill(text: str, ticket: "ParsedTicket | None", stufe: int = 0) -> str:
    """Platzhalter im Betreff/Text der Absage-Mail: {{patient}}, {{termine}} (alle Termine mit Art), {{termine_kurz}} (nur Tag und Uhrzeit),
    {{erster_termin}}. Stufe 0 = volle Liste, 1 = kurze Liste, 2 = ohne Termine (wenn der QR-Code sonst zu dicht würde)."""
    patient = ticket.patient if ticket else ""
    termine = ticket.appointments if ticket else []
    voll = "\n".join(f"{a.weekday} {a.date} {a.time}" + (f" {a.kind}" if a.kind else "") for a in termine)
    kurz = "\n".join(f"{a.weekday} {a.date} {a.time}" for a in termine)
    erster = f"{termine[0].weekday} {termine[0].date} {termine[0].time}" if termine else ""
    werte = {"patient": patient, "termine": (voll, kurz, "")[stufe], "termine_kurz": (kurz, kurz, "")[stufe], "erster_termin": erster}
    return re.sub(r"\{\{\s*([a-z_]+)\s*\}\}", lambda m: werte.get(m.group(1), m.group(0)), text)


def cancel_mailto(cancel: dict[str, Any], ticket: "ParsedTicket | None" = None, stufe: int = 0) -> bytes:
    """mailto:-Adresse für den Absage-QR: Empfänger, Betreff und Nachrichtentext (UTF-8, prozentkodiert), Platzhalter ersetzt."""
    from urllib.parse import quote
    body = cancel_fill(str(cancel.get("body", "")), ticket, stufe).replace("\n", "\r\n")
    subject = cancel_fill(str(cancel.get("subject", "")), ticket, 2).replace("\n", " ").strip()
    return ("mailto:" + quote(str(cancel.get("to", "")), safe="@.+-_") + "?subject=" + quote(subject, safe="")
            + "&body=" + quote(body, safe="")).encode("ascii")


FONT_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"


def _bar_lines(caption: str) -> list[str]:
    """Aufforderung für den Balken in höchstens zwei Zeilen: nach dem ersten Fragezeichen, sonst nach der Wortmitte."""
    text = " ".join(caption.split())
    head, sep, rest = text.partition("?")
    if sep and rest.strip():
        return [head.strip() + "?", rest.strip()]
    words = text.split()
    if len(words) < 3:
        return [text]
    half = len(words) // 2
    return [" ".join(words[:half]), " ".join(words[half:])]


def cancel_block_image(qr_image, caption: str):
    """Absage-Block als 1-Bit-Bild: oben ein runder schwarzer Balken mit Kalender-Symbol und der Aufforderung (zwei Zeilen),
    darunter der QR-Code in dickem, rundem Rahmen. Balken und Rahmen sind gleich breit."""
    from PIL import Image, ImageDraw, ImageFont
    frame, pad, gap = 6, 10, 10
    qw, qh = qr_image.size
    width = qw + 2 * (frame + pad)
    top = 0
    bar = None
    if caption:
        lines = _bar_lines(caption)
        icon = 38
        room = width - 2 * 14 - icon - 12
        size = 30
        while size > 13:
            font = ImageFont.truetype(FONT_BOLD, size)
            if max(font.getlength(line) for line in lines) <= room:
                break
            size -= 1
        font = ImageFont.truetype(FONT_BOLD, size)
        line_height = int(size * 1.25)
        bar_h = max(icon + 20, line_height * len(lines) + 20)
        bar = (lines, font, line_height, bar_h, icon)
        top = bar_h + gap
    height = top + qh + 2 * (frame + pad)
    img = Image.new("L", (width, height), 255)
    d = ImageDraw.Draw(img)
    if bar:
        lines, font, line_height, bar_h, icon = bar
        d.rounded_rectangle((0, 0, width - 1, bar_h - 1), radius=18, fill=0)
        ix, iy = 14, (bar_h - icon) // 2
        d.rounded_rectangle((ix, iy + 5, ix + icon - 1, iy + icon - 1), radius=5, outline=255, width=3)
        d.line((ix, iy + 15, ix + icon - 1, iy + 15), fill=255, width=3)
        for px in (ix + 10, ix + icon - 11):
            d.line((px, iy, px, iy + 9), fill=255, width=3)
        cx, cy, k = ix + icon // 2, iy + 26, 5
        d.line((cx - k, cy - k, cx + k, cy + k), fill=255, width=3)
        d.line((cx - k, cy + k, cx + k, cy - k), fill=255, width=3)
        tx = ix + icon + 12
        y = (bar_h - line_height * len(lines)) // 2
        for line in lines:
            d.text((tx, y + (line_height - font.size) // 2 - 1), line, font=font, fill=255)
            y += line_height
    d.rounded_rectangle((0, top, width - 1, height - 1), radius=24, outline=0, width=frame)
    img.paste(qr_image.convert("L"), (frame + pad, top + frame + pad))
    return img.convert("1", dither=Image.NONE)


def append_cancel_qr(r: EscPosRenderer, cfg: dict[str, Any], ticket: "ParsedTicket | None" = None) -> None:
    """Absage-QR auf dem Bon: Balken mit der Aufforderung oben, darunter der QR-Code im runden Rahmen; er öffnet eine vorbereitete
    E-Mail zum Absagen. Ein Fehler darf den Ausdruck nie verhindern (ohne Schrift oder Bildbibliothek gibt es Text und schlichten QR)."""
    cancel = cfg.get("cancel_qr", {})
    if not isinstance(cancel, dict) or not cfg_bool(cancel, "enabled", False):
        return
    try:
        from calendar_qr import PayloadTooLarge, build_qr_image
        from escpos import raster_image
        caption = str(cancel.get("caption", "")).strip()
        # 32 Punkte für Rahmen und Abstand (2 × (6 + 10)): Der ganze Block darf höchstens 384 Punkte breit sein (Rastergrenze).
        room = min(int(cancel.get("width_dots", 292)), r.page_width - 32, 352)
        qr = None
        for stufe in (0, 1, 2):   # passt die Terminliste nicht mehr in den QR-Code, erst kürzer, dann ohne Termine
            try:
                qr = build_qr_image(cancel_mailto(cancel, ticket, stufe), {"max_width_dots": max(64, room)})
                break
            except PayloadTooLarge:
                if stufe == 2:
                    raise
        r.line("")
        try:
            image = cancel_block_image(qr, caption)
        except Exception:
            image = qr
            if caption:
                r.wrapped(caption, align="center", bold=True, size=r.sizes["footer"])
        r.set_align("center")
        r.raw(raster_image(image))
    except Exception:
        eprint("Absage-QR konnte nicht gedruckt werden (Text zu lang?).")


def render_escpos(ticket: ParsedTicket, cfg: dict[str, Any]) -> bytes:
    r = EscPosRenderer(cfg)
    layout = cfg.get("layout", {})
    r.init()

    append_logo(r, cfg)
    first_bold = layout.get("header_first_bold")
    r.block("header", r.sizes["header"],
            first_size=parse_size(layout.get("header_first_size"), r.sizes["header"]) if layout.get("header_first_size") else None,
            first_bold=first_bold if isinstance(first_bold, bool) else None)
    if cfg_bool(cfg.get("header", {}), "enabled", True) and str(cfg.get("header", {}).get("text", "")).strip():
        r.line("")

    heading = str(layout.get("heading", "IHRE TERMINE"))
    if heading:
        r.line(heading, align="center", bold=True, size=r.sizes["heading"])
        r.set_double_height(False)
        r.line("")

    if cfg_bool(layout, "show_patient", True):
        r.wrapped(ticket.patient, align="left", bold=True, size=r.sizes["appointment"])
        r.line("")

    groups: OrderedDict[str, list[Appointment]] = OrderedDict()
    for appt in ticket.appointments:
        groups.setdefault(appt.date, []).append(appt)

    for group_idx, (date, appts) in enumerate(groups.items()):
        first = appts[0]
        date_line = f"{first.weekday} {date}"
        # Bei sehr großer Schrift umbrechen statt am Papierrand abzuschneiden.
        r.wrapped(date_line, align="left", bold=True, size=r.sizes["appointment"])

        for appt in appts:
            if append_appointment_row(r, appt, cfg):
                continue
            prefix = f"{appt.time}  "
            size = r.sizes["appointment"]
            available = max(4, r.cols(size) - len(prefix))
            chunks = textwrap.wrap(appt.kind, width=available, break_long_words=True,
                                   break_on_hyphens=False) or [""]
            r.line(prefix + chunks[0], align="left", bold=False, size=size)
            for chunk in chunks[1:]:
                r.line(" " * len(prefix) + chunk, align="left", bold=False, size=size)

        if group_idx < len(groups) - 1:
            r.line("", size=r.sizes["appointment"])

    footer_text = str(cfg.get("footer", {}).get("text", "")).strip()
    if cfg_bool(cfg.get("footer", {}), "enabled", True) and footer_text:
        r.line("")
        if cfg_bool(layout, "footer_separator", True):
            separator = str(layout.get("separator_char", "-"))[:1] or "-"
            r.line(separator * r.cols(r.sizes["footer"]), align="left", bold=False, size=r.sizes["footer"])
        r.block("footer", r.sizes["footer"])

    append_cancel_qr(r, cfg, ticket)
    return r.finish()


def render_text(ticket: ParsedTicket, cfg: dict[str, Any]) -> bytes:
    layout = cfg.get("layout", {})
    output = cfg.get("output", {})
    columns = int(layout.get("columns", 35))
    if not 8 <= columns <= 64:
        raise TicketError("Druckbreite muss zwischen 8 und 64 liegen.")
    lines: list[str] = []

    header = str(cfg.get("header", {}).get("text", "")).strip("\n")
    if cfg_bool(cfg.get("header", {}), "enabled", True) and header:
        lines.extend(header.splitlines())
        lines.append("")
    heading = str(layout.get("heading", "IHRE TERMINE"))
    if heading:
        lines.append(heading)
        lines.append("")
    if cfg_bool(layout, "show_patient", True):
        lines.extend(textwrap.wrap(ticket.patient, columns) or [ticket.patient])
        lines.append("")

    groups: OrderedDict[str, list[Appointment]] = OrderedDict()
    for appt in ticket.appointments:
        groups.setdefault(appt.date, []).append(appt)
    for idx, (date, appts) in enumerate(groups.items()):
        lines.append(f"{appts[0].weekday} {date}")
        for appt in appts:
            prefix = f"{appt.time}  "
            chunks = textwrap.wrap(appt.kind, max(8, columns - len(prefix)),
                                   break_long_words=True, break_on_hyphens=False) or [""]
            lines.append(prefix + chunks[0])
            lines.extend(" " * len(prefix) + c for c in chunks[1:])
        if idx < len(groups) - 1:
            lines.append("")

    footer = str(cfg.get("footer", {}).get("text", "")).strip("\n")
    if cfg_bool(cfg.get("footer", {}), "enabled", True) and footer:
        lines.append("")
        lines.extend(footer.splitlines())
    lines.append("")
    return safe_text("\n".join(lines)).encode(str(output.get("text_encoding", "utf-8")), "replace")


def render(ticket: ParsedTicket, cfg: dict[str, Any]) -> bytes:
    fmt = str(cfg.get("output", {}).get("format", "escpos")).lower()
    if fmt == "escpos":
        return render_escpos(ticket, cfg)
    if fmt in ("text", "plain", "plaintext"):
        return render_text(ticket, cfg)
    raise ValueError(f"Unbekanntes output.format: {fmt}")


def make_payload(data: bytes, cfg: dict[str, Any]) -> bytes:
    text = safe_text(extract_text(data, cfg))
    try:
        with zeit("Auswertung"):
            ticket = parse_t2med(text)
    except TicketError:
        raise
    except ValueError:
        raise TicketError("Ungültiger Beleg: eine Seite, ein Patient und vollständige Termine erforderlich.") from None
    with zeit("Bon aufbauen (mit Logo und QR)"):
        return render(ticket, cfg)


def send_cups(payload: bytes, cfg: dict[str, Any], *, copies: int = 1) -> None:
    """ESC/POS-Daten roh an die Warteschlange des Bondruckers (lp, als Modul-Benutzer)."""
    queue = str(cfg.get("output", {}).get("queue", ""))
    if not queue:
        raise TicketError("Kein Bondrucker zugeordnet. Bitte unter Verwaltung → Drucker bei „Wofür welcher Drucker?“ den Bondrucker wählen.")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{0,39}", queue) or queue.casefold() == "terminzettel":
        raise TicketError("Ungültige Zielwarteschlange.")
    if type(copies) is not int or not 1 <= copies <= 99:
        raise TicketError("Ungültige Anzahl Druckexemplare.")
    env = {"PATH": "/usr/bin:/bin", "LC_ALL": "C.UTF-8", "HOME": "/nonexistent"}
    # Weder Name noch Dateiname des Patienten gehen in die Druckauftrags-Metadaten.
    args = [LP, "-d", queue, "-t", "Terminzettel", "-n", str(copies), "-o", "raw", "-o", f"job-cancel-after={JOB_TIMEOUT}"]
    try:
        proc = subprocess.run(args, input=payload, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False, timeout=JOB_TIMEOUT, env=env)
    except (OSError, subprocess.TimeoutExpired):
        raise TicketError("Der Druckauftrag konnte nicht abgegeben werden. Bitte den Drucker prüfen.") from None
    if proc.returncode != 0:
        raise TicketError("Der Bondrucker hat den Druckauftrag abgelehnt. Bitte unter Verwaltung → Drucker prüfen.")


def main() -> int:
    parser = argparse.ArgumentParser(description="Einseitiger T2med-Terminbeleg auf 58-mm-Bondrucker")
    parser.add_argument("input", nargs="?", help="Auftragsdatei vom Druckserver")
    parser.add_argument("--config", default=DEFAULT_CONFIG)
    parser.add_argument("--version", action="version", version="Terminzettel (Modul 1.0.0, Programm 1.3.1)")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--check", action="store_true", help="Beleg prüfen, ohne Druck oder Datenexport")
    mode.add_argument("--self-test", action="store_true", help="Selbsttest mit künstlichen Daten, ohne Druck")
    args = parser.parse_args()
    try:
        if args.self_test:
            ticket = parse_t2med("TERMINE\nTestperson\nTerminzeitpunkt   Termintyp\nDo. 17.09.2026, 09:00 Kontrolle\n")
            render(ticket, {})
            print("Selbsttest erfolgreich.")
            return 0
        if not args.input:
            raise TicketError("Es fehlt eine Eingabedatei.")
        if args.check:
            ensure_runtime()
            with open(args.input, "rb") as fh:
                data = fh.read(MAX_INPUT + 1)
        else:
            data = read_spool(args.input)
            job_log(f"Auftrag erhalten: {job_kind(data)}, {len(data)} Bytes")
        cfg = load_config(args.config)
        payload = make_payload(data, cfg)
        if args.check:
            print("Belegprüfung erfolgreich.")
        else:
            with zeit("Übergabe an den Drucker"):
                send_cups(payload, cfg)
            job_log("Bon an die Warteschlange „" + str(cfg.get("output", {}).get("queue", "")) + "“ übergeben")
            job_log("Zeiten: " + zeiten_text())
        return 0
    except TicketError as exc:
        eprint("terminzettel: " + str(exc))
        if not args.check:
            job_log("Fehler: " + str(exc) + " (nach " + sekunden(time.monotonic() - _START) + ")")
        return 1
    except (Exception, KeyboardInterrupt):
        # Fremde Fehlertexte können Beleginhalte enthalten. Keine Tracebacks oder Rohmeldungen.
        eprint("terminzettel: Verarbeitung fehlgeschlagen. Bitte Konfiguration und Drucker prüfen.")
        if not args.check:
            job_log("Fehler: Verarbeitung fehlgeschlagen")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
