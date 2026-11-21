#!/usr/bin/env python3
"""Vorschau des Terminbons: dieselben ESC/POS-Daten wie beim Druck, als PNG-Bild.

Eingabe (stdin, JSON): {"overlay": {...Webeinstellungen...}, "sample": optional}
Ausgabe (stdout): eine JSON-Zeile mit Hinweisen, danach PNG (oder ESC/POS bei "format": "escpos"). Es werden nur Beispieldaten verwendet, keine Patientendaten.
"""
from __future__ import annotations

from datetime import date, timedelta
import io
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
import terminzettel as app  # noqa: E402

try:
    import tomllib
except ModuleNotFoundError:  # pragma: no cover
    import tomli as tomllib  # type: ignore

FONT_DIR = Path("/usr/share/fonts/truetype/dejavu")
LINE_SPACING = 34  # Epson-Standard 1/6 Zoll bei 203 dpi
WEEKDAYS = ("Mo", "Di", "Mi", "Do", "Fr", "Sa", "So")


def sample_text(today: date | None = None) -> str:
    """Künstlicher Terminzettel im T2med-Format mit Terminen in den nächsten Wochen."""
    today = today or date.today()
    first, second = today + timedelta(days=7), today + timedelta(days=21)
    rows = [(first, "09:00", "Kontrolle Blutdruck"), (first, "09:30", "Labor nüchtern"), (second, "14:15", "Lungenfunktion")]
    lines = ["TERMINE", "Erika Musterfrau", "Terminzeitpunkt          Termintyp"]
    for day, time, kind in rows:
        lines.append(f"{WEEKDAYS[day.weekday()]}. {day:%d.%m.%Y}, {time}  {kind}")
    return "\n".join(lines) + "\n"


def build_config(overlay: dict) -> dict:
    cfg = tomllib.loads((Path(__file__).resolve().parent / "config.toml").read_text(encoding="utf-8"))
    return app.apply_overlay(cfg, overlay, printing=False)


class Interpreter:
    """Gerade so viel ESC/POS, wie der Terminzettel erzeugt (Standard- und Seitenmodus)."""

    def __init__(self, page_width: int):
        from PIL import Image, ImageDraw, ImageFont
        self.Image, self.ImageDraw = Image, ImageDraw
        self.fonts = {False: ImageFont.truetype(str(FONT_DIR / "DejaVuSansMono.ttf"), 20),
                      True: ImageFont.truetype(str(FONT_DIR / "DejaVuSansMono-Bold.ttf"), 20)}
        self.width = page_width
        self.rows: list = []          # fertige Bildstreifen
        self.reset()
        self.line: list = []          # (Text, Breite, Höhe, fett) der aktuellen Zeile
        self.page = None              # Seitenmodus: [Bild, x, y]
        self.cut = False

    def reset(self):
        self.align, self.bold, self.size, self.font_b = 0, False, (1, 1), False

    def cell(self):
        return (9, 17) if self.font_b else (12, 24)

    def glyphs(self, text: str, size, bold: bool):
        cw, ch = self.cell()
        base = self.Image.new("L", (max(1, len(text) * cw), ch), 255)
        draw = self.ImageDraw.Draw(base)
        for index, char in enumerate(text):
            draw.text((index * cw + cw / 2, ch / 2 + 1), char, fill=0, font=self.fonts[bold], anchor="mm")
        return base.resize((base.width * size[0], base.height * size[1]), self.Image.NEAREST)

    def flush_line(self):
        if not self.line:
            self.rows.append(self.Image.new("L", (self.width, LINE_SPACING), 255))
            return
        # Wie der Drucker: was über den Papierrand ginge, steht in der nächsten Zeile.
        cw = self.cell()[0]
        rows, row, used = [], [], 0
        for text, size, bold in self.line:
            while text:
                room = max(1, (self.width - used) // (cw * size[0]))
                part, text = text[:room], text[room:]
                row.append((part, size, bold)); used += len(part) * cw * size[0]
                if text:
                    rows.append(row); row, used = [], 0
        rows.append(row)
        if len(rows) > 1:
            align, self.line = self.align, []
            for part in rows:
                self.line = part; self.flush_line()
            self.align = align
            return
        images = [self.glyphs(t, s, b) for t, s, b in self.line]
        height = max(LINE_SPACING, max(i.height for i in images) + 6)
        strip = self.Image.new("L", (self.width, height), 255)
        total = sum(i.width for i in images)
        x = 0 if self.align == 0 else (self.width - total) // 2 if self.align == 1 else self.width - total
        x = max(0, x)
        for image in images:
            strip.paste(image, (x, height - image.height - 3))
            x += image.width
        self.rows.append(strip)
        self.line = []

    def text(self, raw: bytes):
        text = raw.decode("cp858", "replace")
        if self.page is not None:
            image = self.glyphs(text, self.size, self.bold)
            canvas, x, y = self.page
            # Im Seitenmodus bezeichnet y die unterste Punktzeile der Zeichen.
            canvas.paste(image, (x, y - image.height + 1))
            self.page[1] = x + image.width
        else:
            self.line.append((text, self.size, self.bold))

    def bitimage(self, width: int, data: bytes):
        canvas, x, y = self.page or (None, 0, 0)
        strip = self.Image.new("L", (width, 24), 255)
        pixels = strip.load()
        for column in range(width):
            for part in range(3):
                value = data[column * 3 + part]
                for bit in range(8):
                    if value & (0x80 >> bit):
                        pixels[column, part * 8 + bit] = 0
        if canvas is not None:
            canvas.paste(strip, (x, y - 23))

    def run(self, data: bytes):
        i, n, pending = 0, len(data), bytearray()

        def take():
            nonlocal pending
            if pending:
                self.text(bytes(pending))
                pending = bytearray()

        while i < n:
            b = data[i]
            if b >= 0x20 and b != 0x7f:
                pending.append(b); i += 1; continue
            take()
            if b == 0x0a:
                if self.page is None:
                    self.flush_line()
                i += 1
            elif b == 0x0c:  # Seite drucken, zurück in den Standardmodus
                if self.page is not None:
                    canvas = self.page[0]
                    strip = self.Image.new("L", (self.width, canvas.height), 255)
                    strip.paste(canvas, (0, 0))
                    self.rows.append(strip)
                    self.page = None
                i += 1
            elif b == 0x1b and i + 1 < n:
                c = data[i + 1]
                if c == ord("@"):
                    self.reset(); i += 2
                elif c in (ord("t"), ord("M"), ord("a"), ord("E"), ord("T")):
                    arg = data[i + 2]
                    if c == ord("M"): self.font_b = arg == 1
                    if c == ord("a"): self.align = arg
                    if c == ord("E"): self.bold = arg == 1
                    i += 3
                elif c == ord("L"):
                    i += 2
                elif c == ord("W"):
                    dx = int.from_bytes(data[i + 6:i + 8], "little"); dy = int.from_bytes(data[i + 8:i + 10], "little")
                    self.page = [self.Image.new("L", (dx, dy), 255), 0, 0]
                    i += 10
                elif c == ord("$"):
                    if self.page is not None:
                        self.page[1] = int.from_bytes(data[i + 2:i + 4], "little")
                    i += 4
                elif c == ord("*"):
                    width = int.from_bytes(data[i + 3:i + 5], "little")
                    self.bitimage(width, data[i + 5:i + 5 + width * 3])
                    i += 5 + width * 3
                else:
                    i += 2
            elif b == 0x1d and i + 1 < n:
                c = data[i + 1]
                if c == ord("!"):
                    v = data[i + 2]; self.size = ((v >> 4) + 1, (v & 0x0f) + 1); i += 3
                elif c == ord("$"):
                    if self.page is not None:
                        self.page[2] = int.from_bytes(data[i + 2:i + 4], "little")
                    i += 4
                elif c == ord("P"):
                    i += 4
                elif c == ord("V"):
                    self.cut = True; i += 3
                elif c == ord("v") and data[i + 2] == ord("0"):
                    row_bytes = int.from_bytes(data[i + 4:i + 6], "little"); height = int.from_bytes(data[i + 6:i + 8], "little")
                    i += 8 + row_bytes * height
                else:
                    i += 2
            else:
                i += 1
        take()
        if self.line:
            self.flush_line()

    def png(self) -> bytes:
        margin = 18
        height = sum(r.height for r in self.rows) + 2 * margin
        paper = self.Image.new("L", (self.width + 2 * margin, height), 255)
        y = margin
        for row in self.rows:
            paper.paste(row, (margin, y)); y += row.height
        if self.cut:
            draw = self.ImageDraw.Draw(paper)
            for x in range(0, paper.width, 12):
                draw.line([(x, height - 4), (x + 6, height - 4)], fill=120, width=2)
        out = io.BytesIO()
        paper.convert("1", dither=self.Image.NONE).save(out, "PNG", optimize=True)
        return out.getvalue()


def render_preview(overlay: dict, text: str | None = None) -> tuple[bytes, list[str]]:
    cfg = build_config(overlay)
    notes = []
    stderr, sys.stderr = sys.stderr, io.StringIO()
    try:
        payload = app.render_escpos(app.parse_t2med(text or sample_text()), cfg)
        if "calendar QR" in sys.stderr.getvalue() and app.cfg_bool(cfg.get("calendar_qr", {}), "enabled", False):
            notes.append("Kalender-QR konnte nicht erzeugt werden (zu groß für diese Schriftgröße, oder python3-qrcode fehlt).")
    finally:
        sys.stderr = stderr
    interpreter = Interpreter(int(cfg.get("layout", {}).get("page_width_dots", 420)))
    interpreter.run(payload)
    return interpreter.png(), notes


def main() -> int:
    try:
        request = json.loads(sys.stdin.buffer.read(65536).decode("utf-8") or "{}")
        if request.get("format") == "escpos":
            # Testdruck: exakt die Bytes, die auch der Drucker „Terminzettel“ senden würde.
            cfg = build_config(request.get("overlay", {}))
            payload = app.render_escpos(app.parse_t2med(sample_text()), cfg)
            sys.stdout.buffer.write(b"{}\n" + payload)
            return 0
        png, notes = render_preview(request.get("overlay", {}))
        sys.stdout.buffer.write(json.dumps({"notes": notes}).encode() + b"\n" + png)
        return 0
    except app.TicketError as exc:
        print("Vorschau: " + str(exc), file=sys.stderr)
        return 2
    except Exception as exc:  # nur Beispieldaten, daher Fehlertyp unkritisch
        print("Vorschau fehlgeschlagen (" + type(exc).__name__ + ").", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
