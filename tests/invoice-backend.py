"""CUPS-Backend „SumUp-Rechnung“: Konfiguration aus dem Webinterface oder vom Einrichtungsskript."""
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile

spec = importlib.util.spec_from_file_location("backend", Path(__file__).resolve().parents[1] / "scripts/invoice-printer/kienzle-sumup-backend.py")
backend = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backend)
CERT = "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----\n"

with tempfile.TemporaryDirectory() as d:
    d = Path(d); web = d / "web"; web.mkdir()
    (d / "printer.json").write_text(json.dumps({"server_url": "https://alt:7868", "inbox_key": "a" * 64}))
    (d / "server.crt").write_text(CERT)
    paths = dict(config=str(d / "printer.json"), cert_path=str(d / "server.crt"), web_dir=str(web))
    # Ohne Webdaten: bisherige Einrichtung per Skript.
    assert backend.load_config(**paths)[0] == "https://alt:7868/invoice-inbox.php"
    # Webdaten haben Vorrang.
    (web / "printer.json").write_text(json.dumps({"server_url": "https://praxisserver:7868/", "inbox_key": "b" * 64}))
    (web / "server.crt").write_text(CERT)
    url, key, cert = backend.load_config(**paths)
    assert url == "https://praxisserver:7868/invoice-inbox.php" and key == "b" * 64 and cert == CERT
    # Symbolische Links (z. B. auf Systemdateien) werden nicht gelesen.
    (web / "printer.json").unlink(); os.symlink(d / "printer.json", web / "printer.json")
    try:
        backend.load_config(**paths); raise AssertionError("Symlink gelesen")
    except backend.Failure as e:
        assert "Einstellungen" in str(e)
    # Ungültiger Schlüssel wird abgelehnt.
    (web / "printer.json").unlink(); (web / "printer.json").write_text(json.dumps({"server_url": "https://x:1", "inbox_key": "kurz"}))
    try:
        backend.load_config(**paths); raise AssertionError("Ungültiger Schlüssel akzeptiert")
    except backend.Failure:
        pass
print("Rechnungsdrucker-Backend ok")
