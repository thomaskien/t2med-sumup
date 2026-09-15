#!/usr/bin/env python3
"""Kurzer TLS-Test auf Loopback: Legacy-Zertifikat, falscher Schlüssel, sichere Vorgabe."""
import http.server
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading

PROJECT = Path(__file__).resolve().parent.parent


def openssl(*args):
    subprocess.run(['openssl', *map(str, args)], check=True, capture_output=True)


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.server.requests_seen += 1
        body = b'{"ok":true}'
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def handle(self):
        try:
            super().handle()
        except ConnectionResetError:
            pass  # Erwarteter Abbruch bei abgelehnter TLS-Identität.

    def log_message(self, *args):
        pass


def server(cert, key):
    httpd = http.server.HTTPServer(('127.0.0.1', 0), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert, key)
    httpd.socket = context.wrap_socket(httpd.socket, server_side=True)
    httpd.requests_seen = 0
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    return httpd


CLIENT = r'''
require $argv[1] . '/src/bootstrap.php';
try {
    $r = (new KienzleSumup\HttpClient())->request('GET', $argv[2], [], null, $argv[3], $argv[4] === '1');
    if ($argv[5] !== 'success' || $r['status'] !== 200 || ($r['body']['ok'] ?? null) !== true) throw new RuntimeException('Unerwarteter TLS-Erfolg');
} catch (KienzleSumup\TransportError $e) {
    if ($argv[5] === 'success' || $e->ambiguous || !str_contains($e->getMessage(), $argv[5])) throw $e;
}
'''


def request(httpd, ca, pin, expected):
    subprocess.run(['php', '-r', CLIENT, str(PROJECT),
                    f'https://127.0.0.1:{httpd.server_port}/', str(ca),
                    '1' if pin else '0', expected], check=True)


with tempfile.TemporaryDirectory(prefix='kienzle-sumup-tls-') as directory:
    d = Path(directory)
    cert, key = d / 'legacy.crt', d / 'legacy.key'
    # V1, keine Extensions/SAN, wie das beobachtete t2med-Zertifikat.
    openssl('req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '2',
            '-config', '/dev/null', '-subj', '/CN=T2med Server', '-keyout', key, '-out', cert)
    # CA-Zertifikat mit demselben Schlüssel für einen gezielten Pin-Negativtest.
    # Die CA-Prüfung allein würde die zweite Identität akzeptieren.
    ca = d / 'ca.crt'
    openssl('req', '-x509', '-key', key, '-sha256', '-days', '2', '-config', '/dev/null',
            '-subj', '/CN=T2med Server', '-addext', 'basicConstraints=critical,CA:TRUE',
            '-addext', 'keyUsage=critical,keyCertSign,digitalSignature', '-out', ca)
    other_cert, other_key, csr = d / 'other.crt', d / 'other.key', d / 'other.csr'
    openssl('req', '-new', '-newkey', 'rsa:2048', '-nodes', '-config', '/dev/null',
            '-subj', '/CN=Other Server', '-keyout', other_key, '-out', csr)
    openssl('x509', '-req', '-in', csr, '-CA', ca, '-CAkey', key, '-CAcreateserial',
            '-days', '1', '-sha256', '-out', other_cert)
    good = server(cert, key)
    wrong = server(other_cert, other_key)
    try:
        request(good, cert, False, 'cURL 60')
        request(good, cert, True, 'success')
        request(wrong, ca, True, 'cURL 90')
        request(good, d / 'missing.pem', True, 'lesbares')
        assert good.requests_seen == 1 and wrong.requests_seen == 0, 'HTTP-Daten trotz abgelehnter TLS-Identität gesendet'
    finally:
        for httpd in (good, wrong):
            httpd.shutdown()
            httpd.server_close()
print('OK: Legacy-Zertifikat mit Pin akzeptiert; falscher Schlüssel, fehlende Datei und normale Hostnamenprüfung sicher abgelehnt.')
