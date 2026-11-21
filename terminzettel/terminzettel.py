#!/usr/bin/env python3
from __future__ import annotations

import argparse
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

DEFAULT_CONFIG = "/etc/terminzettel/config.toml"
# Vom Webinterface (Kienzle-SumUp → Einstellungen → Terminzettel) geschrieben; überlagert config.toml.
WEB_OVERLAY = "/etc/terminzettel/web/layout.json"
# Bondrucker-Modelle: Druckbreite in Punkten und Zeichen je Zeile (Font A, 12 Punkte breit).
MODELS = {"tm-m10": (420, 35), "tm-m30iii": (576, 48)}
SIZES = ("1x1", "1x2", "2x1", "2x2", "2x3", "3x3", "4x4")
QR_POSITIONS = ("right", "left", "below")
RUNTIME = Path("/run/terminzettel")
SPOOL = RUNTIME / "spool"
WORK = RUNTIME / "work"
CUPS_SOCKET = "/run/cups/cups.sock"
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
    """Kein stiller Rückfall auf ein Verzeichnis auf der SD-Karte."""
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    try:
        mounted = False
        for line in Path("/proc/self/mountinfo").read_text().splitlines():
            before, after = line.split(" - ", 1)
            fields, fs = before.split(), after.split()
            if fields[4] == str(RUNTIME):
                mounted = fs[0] == "tmpfs"
        if not mounted:
            raise TicketError("RAM-Dateisystem fehlt. Bitte den Installer erneut ausführen.")
        if not WORK.is_dir() or not SPOOL.is_dir():
            raise TicketError("RAM-Verzeichnisse fehlen. Bitte den Installer erneut ausführen.")
    except OSError:
        raise TicketError("RAM-Dateisystem konnte nicht geprüft werden.") from None


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
    return cfg


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


def extract_pdf_text(source: str, cfg: dict[str, Any], *, data: bytes | None = None,
                     temp_dir: str | None = None) -> str:
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
        image = run_checked(["pdftoppm", "-f", "1", "-l", "1", "-singlefile",
                             "-scale-to", "2500", "-gray", "-png", source],
                            input_bytes=data, temp_dir=temp_dir)
        # Die Textschicht erhält Spalten und Einrückungen für mehrzeilige Termintypen.
        # Bild, Textschicht und Ergebnis werden ausschließlich per Pipe übergeben.
        searchable = run_checked(["tesseract", "stdin", "stdout", "-l", "deu", "--psm", "6",
                                  "-c", "tessedit_create_pdf=1", "-c", "textonly_pdf=1"],
                                 input_bytes=image, temp_dir=temp_dir)
        text = run_checked(["pdftotext", "-layout", "-", "-"],
                           input_bytes=searchable, temp_dir=temp_dir).decode("utf-8", "strict")
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
                if data.startswith(b"%!PS"):
                    run_checked(["gs", "-q", "-dSAFER", "-dNOPAUSE", "-dBATCH", "-sDEVICE=pdfwrite",
                                 "-sOutputFile=" + str(pdf), str(source)], temp_dir=td)
                else:
                    run_checked(["gxps2pdf", str(source), str(pdf)], temp_dir=td)
                return extract_pdf_text(str(pdf), cfg, temp_dir=td)
    encoding = str(cfg.get("input", {}).get("text_encoding", "utf-8"))
    try:
        return data.decode(encoding, "strict")
    except (UnicodeError, LookupError):
        raise TicketError("Textkodierung ungültig. Bitte PDF oder PostScript verwenden.") from None


def read_spool(path: str) -> bytes:
    """Nur eigene Samba-Spooldateien übernehmen und sofort aus dem Verzeichnis entfernen."""
    source = Path(os.path.abspath(path))
    if source.parent != SPOOL:
        raise TicketError("Nur Dateien aus dem Terminzettel-Spoolverzeichnis sind zulässig.")
    fd = os.open(source, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as fh:
        metadata = os.fstat(fh.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid() or metadata.st_nlink != 1:
            raise TicketError("Ungültige Spooldatei.")
        if source.lstat().st_ino != metadata.st_ino:
            raise TicketError("Spooldatei wurde während der Verarbeitung geändert.")
        source.unlink()
        ensure_runtime()
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


def render_escpos(ticket: ParsedTicket, cfg: dict[str, Any]) -> bytes:
    r = EscPosRenderer(cfg)
    layout = cfg.get("layout", {})
    r.init()

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
        ticket = parse_t2med(text)
    except TicketError:
        raise
    except ValueError:
        raise TicketError("Ungültiger Beleg: eine Seite, ein Patient und vollständige Termine erforderlich.") from None
    return render(ticket, cfg)


def cups_connection():
    import cups
    cups.setUser("terminzettel")
    return cups, cups.Connection(host=CUPS_SOCKET)


def send_cups(payload: bytes, cfg: dict[str, Any], *, copies: int = 1) -> None:
    queue = str(cfg.get("output", {}).get("queue", "TMm10"))
    if not re.fullmatch(r"[A-Za-z0-9_.-]+", queue) or queue.casefold() == "terminzettel":
        raise TicketError("Ungültige Zielwarteschlange.")
    cups, conn = cups_connection()
    job = None
    try:
        # Weder Name noch Dateiname des Patienten gehen in die Druckauftrags-Metadaten.
        options = {"job-cancel-after": str(JOB_TIMEOUT)}
        if copies != 1:
            if type(copies) is not int or not 1 <= copies <= 99:
                raise TicketError("Ungültige Anzahl Druckexemplare.")
            options["copies"] = str(copies)
        job = conn.createJob(queue, "Terminzettel", options)
        if conn.startDocument(queue, job, "Terminzettel", "application/vnd.cups-raw", 1) != 100:
            raise TicketError("CUPS hat den Druckauftrag abgelehnt.")
        if conn.writeRequestData(payload, len(payload)) != 100:
            raise TicketError("CUPS konnte den Druckauftrag nicht übernehmen.")
        if conn.finishDocument(queue) >= 0x400:
            raise TicketError("CUPS konnte den Druckauftrag nicht abschließen.")
        deadline = time.monotonic() + JOB_TIMEOUT
        while time.monotonic() < deadline:
            try:
                attrs = conn.getJobAttributes(job, requested_attributes=["job-state"])
            except cups.IPPError as exc:
                if exc.args and exc.args[0] == cups.IPP_NOT_FOUND:
                    return  # CUPS hat den beendeten Auftrag bereits entfernt.
                raise
            state = attrs.get("job-state")
            if state == 9:
                return
            if state in (7, 8):
                raise TicketError("Der Druckauftrag wurde abgebrochen. Bitte den Drucker prüfen.")
            time.sleep(1)
        raise TicketError("Druckzeit überschritten. Auftrag wurde abgebrochen; bitte den Drucker prüfen.")
    finally:
        if job is not None:
            try:
                _, cleanup = cups_connection()
                cleanup.cancelJob(job, purge_job=True)
            except Exception:
                # Der Bereinigungsdienst versucht verwaiste Aufträge erneut; alles bleibt im RAM.
                pass


def cleanup() -> None:
    ensure_runtime()
    now = time.time()
    for source in SPOOL.iterdir():
        try:
            info = source.lstat()
            if stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid() and info.st_nlink == 1 and now - info.st_mtime > 120:
                source.unlink()
        except FileNotFoundError:
            pass
    for directory in WORK.glob("job-*"):
        if directory.is_symlink() or not directory.is_dir() or now - directory.stat().st_mtime < 120:
            continue
        try:
            with open(directory / ".lock", "a") as lock:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                shutil.rmtree(directory)
        except (BlockingIOError, FileNotFoundError):
            pass
    _, conn = cups_connection()
    for job, attrs in conn.getJobs(my_jobs=True, which_jobs="all",
            requested_attributes=["job-name", "time-at-creation"]).items():
        if attrs.get("job-name") == "Terminzettel" and now - attrs.get("time-at-creation", now) > 120:
            conn.cancelJob(job, purge_job=True)


def main() -> int:
    parser = argparse.ArgumentParser(description="Einseitiger T2med-Terminbeleg auf 58-mm-Bondrucker")
    parser.add_argument("input", nargs="?", help="Von Samba übergebene Spooldatei")
    parser.add_argument("--config", default=DEFAULT_CONFIG)
    parser.add_argument("--version", action="version", version="Terminzettel 1.3")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--check", action="store_true", help="Beleg prüfen, ohne Druck oder Datenexport")
    mode.add_argument("--self-test", action="store_true", help="Selbsttest mit künstlichen Daten, ohne Druck")
    mode.add_argument("--cleanup", action="store_true", help=argparse.SUPPRESS)
    args = parser.parse_args()
    try:
        if args.self_test:
            ticket = parse_t2med("TERMINE\nTestperson\nTerminzeitpunkt   Termintyp\nDo. 17.09.2026, 09:00 Kontrolle\n")
            render(ticket, {})
            print("Selbsttest erfolgreich.")
            return 0
        if args.cleanup:
            cleanup()
            return 0
        if not args.input:
            raise TicketError("Es fehlt eine Eingabedatei.")
        if args.check:
            ensure_runtime()
            with open(args.input, "rb") as fh:
                data = fh.read(MAX_INPUT + 1)
        else:
            data = read_spool(args.input)
        cfg = load_config(args.config)
        payload = make_payload(data, cfg)
        if args.check:
            print("Belegprüfung erfolgreich.")
        else:
            send_cups(payload, cfg)
        return 0
    except TicketError as exc:
        eprint("terminzettel: " + str(exc))
        return 1
    except (Exception, KeyboardInterrupt):
        # Fremde Fehlertexte können Beleginhalte enthalten. Keine Tracebacks oder Rohmeldungen.
        eprint("terminzettel: Verarbeitung fehlgeschlagen. Bitte Konfiguration und Drucker prüfen.")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
