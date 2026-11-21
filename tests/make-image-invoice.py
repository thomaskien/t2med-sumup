#!/usr/bin/env python3
"""Erzeugt ein Bild-PDF (ohne Textebene) einer fiktiven T2med-Rechnung auf stdout. Nur für Tests."""
import io, sys
from PIL import Image, ImageDraw, ImageFont

mismatch = len(sys.argv) > 1 and sys.argv[1] == "mismatch"
lines = [
    "Praxis Beispiel | Musterstraße 1 | 12345 Musterstadt", "Frau", "Erika Musterfrau", "",
    "Re.-Nr.:      RE2026-0042", "Re.-Datum:    15.03.2026", "Patient:      Erika Musterfrau , 12.04.1980", "",
    "LIQUIDATION", "",
    "15.03.2026   1   Beratung auch telefonisch   4,66 €   2,30   10,72 €",
    "Gesamtsumme:   42,50 €", "",
    "Bitte überweisen Sie den Betrag von %s € ohne Abzug bis zum 14.04.2026." % ("41,50" if mismatch else "42,50"),
]
font = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 34)
image = Image.new("L", (2480, 3508), 255)
draw = ImageDraw.Draw(image)
y = 200
for line in lines:
    draw.text((200, y), line, font=font, fill=0)
    y += 60
buffer = io.BytesIO()
image.save(buffer, "PDF", resolution=300.0)
sys.stdout.buffer.write(buffer.getvalue())
