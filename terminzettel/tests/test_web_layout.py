"""Einstellungen aus dem Kienzle-SumUp-Webinterface: Schriftgrößen, QR-Position, Modell, Vorschau."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
spec = importlib.util.spec_from_file_location("ticket_web_layout_test", ROOT / "terminzettel.py")
app = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = app
spec.loader.exec_module(app)
import preview  # noqa: E402

TEXT = ("TERMINE\nTestperson Alpha\nTerminzeitpunkt              Termintyp\n"
        "Do. 17.09.2026, 09:00 Kontrolle\nFr. 18.09.2026, 10:30 Lungenfunktion\n")
try:
    import qrcode  # noqa: F401
    HAS_QR = hasattr(qrcode, "QRCode")
except ImportError:
    HAS_QR = False


def render(web, base=None):
    cfg = app.apply_overlay(base or {"calendar_qr": {"enabled": False}}, web)
    with contextlib.redirect_stderr(io.StringIO()):
        return app.render_escpos(app.parse_t2med(TEXT), cfg)


class OverlayTests(unittest.TestCase):
    def test_sizes_per_block_use_gs_exclamation(self):
        raw = render({"header": {"enabled": True, "text": "Praxis Beispiel", "size": "1x1"},
                      "heading": {"size": "3x3"}, "appointments": {"size": "1x2"},
                      "footer": {"enabled": True, "text": "Bitte absagen.", "size": "2x1"}})
        for part, command in ((b"Praxis Beispiel", b"\x1d!\x00"), (b"IHRE TERMINE", b"\x1d!\x22"),
                              (b"09:00", b"\x1d!\x01"), (b"Bitte absagen.", b"\x1d!\x10")):
            position = raw.index(part)
            self.assertEqual(raw.rfind(b"\x1d!", 0, position), raw.rfind(command, 0, position), part)

    def test_wrap_width_follows_font_width_and_model(self):
        long = "Sehr langer Hinweistext für die Breite"
        narrow = render({"footer": {"enabled": True, "text": long, "size": "2x2"}})
        wide = render({"model": "tm-m30iii", "footer": {"enabled": True, "text": long, "size": "1x1"}})
        self.assertIn(long.encode("cp858"), wide)          # 48 Zeichen passen auf den TM-m30III
        self.assertNotIn(long.encode("cp858"), narrow)     # 17 Zeichen beim TM-m10 in 2x2

    def test_default_without_overlay_is_unchanged(self):
        self.assertEqual(app.render_escpos(app.parse_t2med(TEXT), {"calendar_qr": {"enabled": False}}),
                         render({}))

    def test_disabled_and_missing_printer_only_block_printing(self):
        with self.assertRaisesRegex(app.TicketError, "ausgeschaltet"):
            app.apply_overlay({}, {"enabled": False}, printing=True)
        with self.assertRaisesRegex(app.TicketError, "Kein Bondrucker"):
            app.apply_overlay({}, {"queue": None}, printing=True)
        self.assertEqual(app.apply_overlay({}, {"queue": None, "enabled": False}), {"output": {}, "layout": {}, "escpos": {}})

    def test_invalid_values_rejected(self):
        for web in ({"appointments": {"size": "9x9"}}, {"qr": {"position": "oben"}}, {"queue": "x;rm"},
                    {"queue": "Terminzettel"}, {"footer": {"text": "a\x1b@b"}}, {"model": "tm-t88"},
                    {"qr": {"width_dots": 1000}}, {"header": {"bold": "ja"}}):
            with self.assertRaises(app.TicketError, msg=str(web)):
                app.apply_overlay({}, web)

    def test_load_config_reads_web_overlay(self):
        with tempfile.TemporaryDirectory() as directory:
            toml = Path(directory, "config.toml"); toml.write_text('[output]\nqueue = "TMm10"\n')
            web = Path(directory, "layout.json")
            web.write_text(json.dumps({"enabled": True, "queue": "TM-m30III", "model": "tm-m30iii", "cut": False}))
            cfg = app.load_config(str(toml), str(web))
            self.assertEqual(cfg["output"]["queue"], "TM-m30III")
            self.assertEqual(cfg["layout"]["page_width_dots"], 576)
            self.assertEqual(cfg["escpos"]["cut"], "none")
            self.assertEqual(app.load_config(str(toml), str(Path(directory, "fehlt.json")))["output"]["queue"], "TMm10")
            web.write_text("{kaputt")
            with self.assertRaisesRegex(app.TicketError, "beschädigt"):
                app.load_config(str(toml), str(web))


@unittest.skipUnless(HAS_QR, "qrcode nicht installiert")
class QrPositionTests(unittest.TestCase):
    def page(self, raw):
        start = raw.index(b"\x1bW")
        width = int.from_bytes(raw[start + 6:start + 8], "little")
        x_text = int.from_bytes(raw[raw.index(b"\x1b$", start) + 2:raw.index(b"\x1b$", start) + 4], "little")
        image = raw.index(b"\x1b*", start)
        x_image = int.from_bytes(raw[raw.rindex(b"\x1b$", 0, image) + 2:raw.rindex(b"\x1b$", 0, image) + 4], "little")
        return width, x_text, x_image

    def test_positions_and_tm_m30iii_width(self):
        base = {"calendar_qr": {"enabled": True}}
        width, text_x, qr_x = self.page(render({"model": "tm-m30iii", "qr": {"position": "right"}}, dict(base)))
        self.assertEqual((width, text_x), (576, 0)); self.assertGreater(qr_x, 300)
        _, text_x, qr_x = self.page(render({"qr": {"position": "left"}}, {"calendar_qr": {"enabled": True}}))
        self.assertEqual(qr_x, 0); self.assertGreater(text_x, 64)
        _, text_x, qr_x = self.page(render({"qr": {"position": "below", "width_dots": 200}, "appointments": {"size": "3x3"}},
                                           {"calendar_qr": {"enabled": True}}))
        self.assertEqual(text_x, 0); self.assertGreater(qr_x, 0)


class PreviewTests(unittest.TestCase):
    def test_preview_png_has_model_width_and_cut(self):
        png, _ = preview.render_preview({"model": "tm-m30iii", "qr": {"enabled": False}}, TEXT)
        self.assertTrue(png.startswith(b"\x89PNG"))
        from PIL import Image
        image = Image.open(io.BytesIO(png))
        self.assertEqual(image.width, 576 + 36)

    def test_bigger_font_makes_longer_receipt(self):
        from PIL import Image
        small = Image.open(io.BytesIO(preview.render_preview({"appointments": {"size": "1x1"}, "qr": {"enabled": False}}, TEXT)[0]))
        big = Image.open(io.BytesIO(preview.render_preview({"appointments": {"size": "3x3"}, "qr": {"enabled": False}}, TEXT)[0]))
        self.assertGreater(big.height, small.height)

    def test_sample_text_parses_and_contains_no_real_data(self):
        ticket = app.parse_t2med(preview.sample_text())
        self.assertEqual(ticket.patient, "Erika Musterfrau")
        self.assertEqual(len(ticket.appointments), 3)


if __name__ == "__main__":
    unittest.main()
