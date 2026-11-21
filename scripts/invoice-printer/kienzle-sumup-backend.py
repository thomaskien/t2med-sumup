#!/usr/bin/env python3
"""CUPS-Backend „kienzle-sumup:/“ für den virtuellen Drucker SumUp-Rechnung.

Nimmt die in T2med gedruckte Rechnung (PDF oder PostScript) entgegen und übergibt
sie per HTTPS an den Rechnungseingang von kienzle-sumup. Die PDF wird hier nicht
ausgewertet; Lesen, Prüfen und Speichern erfolgen auf dem Anwendungsserver.
Ausgaben enthalten keine Patientendaten.
"""
import json
import os
import pwd
import signal
import ssl
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request

CONFIG = "/etc/kienzle-sumup-printer/printer.json"
CERT = "/etc/kienzle-sumup-printer/server.crt"
# Vom Webinterface auf demselben Server geschrieben (Einstellungen → T2med-Rechnungen übernehmen).
WEB_DIR = "/etc/kienzle-sumup-printer/web"
MAX_INPUT = 5 * 1024 * 1024
LABEL = "SumUp-Rechnung"


class Failure(Exception):
    """Feste, vom Programm vorgegebene Fehlertexte."""


def read_plain(path, limit=65536):
    """Nur eine echte Datei lesen (kein symbolischer Link, keine Gerätedatei), höchstens limit Bytes.

    Das Backend läuft hier noch als root; der Web-Ordner gehört dem Dienstbenutzer der Anwendung.
    """
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as stream:
        info = os.fstat(stream.fileno())
        if not (info.st_mode & 0o170000 == 0o100000) or info.st_size > limit:
            raise OSError("not a small regular file")
        return stream.read(limit + 1).decode("utf-8")


def load_config(config=CONFIG, cert_path=CERT, web_dir=WEB_DIR):
    # Webinterface hat Vorrang (aktueller Schlüssel); sonst die per Skript eingerichtete Datei.
    web = (os.path.join(web_dir, "printer.json"), os.path.join(web_dir, "server.crt"))
    paths = web if os.path.lexists(web[0]) else (config, cert_path)
    try:
        cfg = json.loads(read_plain(paths[0]))
        cert = read_plain(paths[1])
        if not isinstance(cfg, dict):
            raise ValueError
    except (OSError, ValueError, UnicodeError):
        raise Failure("Konfiguration fehlt. Bitte in den Einstellungen „T2med-Rechnungen übernehmen“ einschalten.")
    url = cfg.get("server_url", "")
    key = cfg.get("inbox_key", "")
    if not isinstance(url, str) or not url.startswith("https://") or any(c in url for c in "?#@ \t\r\n"):
        raise Failure("Ungültige Serveradresse in printer.json.")
    if not isinstance(key, str) or len(key) != 64 or any(c not in "0123456789abcdef" for c in key):
        raise Failure("Ungültiger Rechnungseingangs-Schlüssel in printer.json.")
    if "PRIVATE KEY" in cert or "BEGIN CERTIFICATE" not in cert:
        raise Failure("Ungültiges Serverzertifikat.")
    return url.rstrip("/") + "/invoice-inbox.php", key, cert


def drop_privileges():
    if os.geteuid() != 0:
        return
    account = pwd.getpwnam("lp")
    os.setgroups([])
    os.setgid(account.pw_gid)
    os.setuid(account.pw_uid)
    os.umask(0o077)


def to_pdf(data):
    if data.startswith(b"%PDF-"):
        return data
    if data.startswith(b"%!PS"):
        gs = next((p for p in ("/usr/bin/gs", "/usr/local/bin/gs") if os.access(p, os.X_OK)), None)
        if not gs:
            raise Failure("PostScript erhalten, aber Ghostscript fehlt. Bitte als PDF drucken oder ghostscript installieren.")
        with tempfile.TemporaryDirectory(prefix="kienzle-sumup-") as directory:
            source = os.path.join(directory, "in.ps")
            target = os.path.join(directory, "out.pdf")
            with open(source, "wb") as stream:
                stream.write(data)
            try:
                subprocess.run([gs, "-q", "-dSAFER", "-dNOPAUSE", "-dBATCH", "-sDEVICE=pdfwrite",
                                "-sOutputFile=" + target, source], check=True, timeout=60,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                with open(target, "rb") as stream:
                    return stream.read(MAX_INPUT + 1)
            except (OSError, subprocess.SubprocessError):
                raise Failure("PostScript konnte nicht in PDF umgewandelt werden.")
    raise Failure("Unbekanntes Druckformat. Bitte die Rechnung als PDF drucken.")


def post(url, key, cert, body, content_type="application/pdf"):
    context = ssl.create_default_context(cadata=cert)
    request = urllib.request.Request(url, data=body, method="POST", headers={
        "Authorization": "Bearer " + key, "Content-Type": content_type,
        "User-Agent": "kienzle-sumup-printer"})
    try:
        with urllib.request.urlopen(request, timeout=40, context=context) as response:
            return response.status, json.loads(response.read(65536) or b"{}")
    except urllib.error.HTTPError as error:
        try:
            payload = json.loads(error.read(65536) or b"{}")
        except ValueError:
            payload = {}
        return error.code, payload
    except (urllib.error.URLError, OSError, ValueError):
        raise Failure("kienzle-sumup-Server nicht erreichbar oder Zertifikat passt nicht.")


def clean(text):
    return "".join(c if c.isprintable() else " " for c in str(text))[:300]


def check():
    """Verbindung, Zertifikat und Schlüssel prüfen, ohne eine Rechnung zu senden."""
    url, key, cert = load_config()
    status, payload = post(url, key, cert, b"check", "text/plain")
    if status == 415:
        print("Verbindung, Zertifikat und Schlüssel in Ordnung.")
        return 0
    if status == 404:
        raise Failure("Rechnungseingang ist auf dem Server nicht aktiviert. Server-Installer ausführen.")
    if status == 401:
        raise Failure("Schlüssel abgelehnt. printer.json mit dem Server abgleichen.")
    raise Failure("Unerwartete Antwort vom Server (HTTP %d)." % status)


def cancel(signum, frame):
    raise KeyboardInterrupt


def main(args):
    if not args:
        print('direct kienzle-sumup:/ "Kienzle-SumUp Rechnungseingang" "SumUp-Rechnung"')
        return 0
    if args == ["--check"]:
        return check()
    if len(args) not in (5, 6):
        raise Failure("Ungültige CUPS-Argumente.")
    signal.signal(signal.SIGTERM, cancel)
    url, key, cert = load_config()
    # Root nur zum Lesen von Konfiguration und Spooldatei; Verarbeitung als lp.
    source = open(args[5], "rb") if len(args) == 6 else sys.stdin.buffer
    with source:
        drop_privileges()
        data = source.read(MAX_INPUT + 1)
    if len(data) > MAX_INPUT:
        raise Failure("Die Rechnung ist zu groß.")
    pdf = to_pdf(data)
    if len(pdf) > MAX_INPUT:
        raise Failure("Die Rechnung ist zu groß.")
    status, payload = post(url, key, cert, pdf)
    if 200 <= status < 300 and payload.get("ok"):
        print("INFO: %s: %s" % (LABEL, clean(payload.get("message", "Rechnung übernommen."))), file=sys.stderr)
        return 0
    raise Failure(clean(payload.get("error") or "Server hat die Rechnung abgelehnt (HTTP %d)." % status))


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except Failure as exc:
        print("ERROR: %s: %s" % (LABEL, exc), file=sys.stderr)
        raise SystemExit(1)
    except KeyboardInterrupt:
        print("ERROR: %s: Druckauftrag abgebrochen." % LABEL, file=sys.stderr)
        raise SystemExit(1)
    except Exception:
        # Keine Tracebacks: sie könnten Inhalte des Druckauftrags enthalten.
        print("ERROR: %s: Unerwarteter Fehler im Backend." % LABEL, file=sys.stderr)
        raise SystemExit(1)
