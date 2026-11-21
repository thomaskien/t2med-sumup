<?php
declare(strict_types=1);
// Drucker im Browser einrichten (CUPS simuliert) und Arbeitsplatz-Pakete (ZIP).
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, ClientPackage, Config, Database, InvoicePrinter, PrinterAdmin, Problem, Settings, SettingsPage};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}

/** Simuliertes CUPS: merkt sich Warteschlangen und Aufrufe. */
final class FakeCups {
    public array $calls = [];
    public array $queues = ['TMm10' => ['uri' => 'socket://192.168.1.80:9100', 'desc' => 'TMm10']];
    public string $fail = '';
    public function __invoke(array $args, int $timeout): array {
        $this->calls[] = $args;
        $bin = basename($args[0]);
        if ($this->fail !== '' && $bin === 'lpadmin') return ['code' => 1, 'output' => '', 'stderr' => $this->fail];
        if ($bin === 'lpstat' && $args[1] === '-v') {
            if (!$this->queues) return ['code' => 1, 'output' => '', 'stderr' => 'lpstat: No destinations added.'];
            return ['code' => 0, 'output' => implode("\n", array_map(fn($n, $q) => "device for $n: {$q['uri']}", array_keys($this->queues), $this->queues)) . "\n", 'stderr' => ''];
        }
        if ($bin === 'lpstat') {
            $out = '';
            foreach ($this->queues as $n => $q) $out .= "printer $n is idle.  enabled since Fri 20 Nov 2026\n\tForm mounted:\n\tDescription: {$q['desc']}\n\tLocation:\n";
            return ['code' => 0, 'output' => $out, 'stderr' => ''];
        }
        if ($bin === 'lpinfo') return ['code' => 0, 'output' => "network socket\nnetwork ipp\nnetwork dnssd://Beispiel%20A4-Drucker._ipp._tcp.local/?uuid=00000000-0000-4000-8000-000000000001\n"
            . "network dnssd://EPSON%20TM-m30III._pdl-datastream._tcp.local/\ndirect usb://EPSON/TM-m10?serial=X3KM012345\nnetwork socket://192.168.1.77\ndirect hp\n", 'stderr' => ''];
        if ($bin === 'lpadmin' && $args[1] === '-x') { unset($this->queues[$args[2]]); return ['code' => 0, 'output' => '', 'stderr' => '']; }
        if ($bin === 'lpadmin') {
            $o = array_flip($args);
            $this->queues[$args[$o['-p'] + 1]] = ['uri' => $args[$o['-v'] + 1], 'desc' => $args[$o['-D'] + 1]];
            return ['code' => 0, 'output' => '', 'stderr' => ''];
        }
        return ['code' => 127, 'output' => '', 'stderr' => 'unbekannt'];
    }
}
final class TestPrinters extends PrinterAdmin { public function available(): bool { return true; } }

$dirs = [];
try {
    $cups = new FakeCups();
    $reachable = ['192.168.1.81:9100', '192.168.1.90:631'];
    $printers = new TestPrinters($cups, fn(string $h, int $p) => in_array("$h:$p", $reachable, true));

    // Suche: verwertbare Geräte mit Art, ohne leere Backends.
    $found = $printers->discover();
    $byName = array_column($found, null, 'name');
    check(count($found) === 4, 'Unerwartete Anzahl gefundener Geräte: ' . count($found));
    check(($byName['Beispiel A4-Drucker']['kind'] ?? '') === 'office', 'A4-Drucker nicht erkannt');
    check(($byName['EPSON TM-m30III']['kind'] ?? '') === 'tm-m30iii', 'TM-m30III nicht erkannt');
    check(($byName['EPSON TM-m10']['connection'] ?? '') === 'USB' && $byName['EPSON TM-m10']['kind'] === 'tm-m10', 'TM-m10 per USB nicht erkannt');

    // Anlegen per IP: RAW für Bondrucker, IPP Everywhere für A4, nicht freigegeben, aktiviert.
    $added = $printers->add('tm-m30iii', '', '192.168.1.81');
    check($added['name'] === 'TM-m30III' && $added['uri'] === 'socket://192.168.1.81:9100', 'Bondrucker falsch angelegt');
    $call = end($cups->calls);
    check(in_array('raw', $call, true) && in_array('printer-is-shared=false', $call, true) && end($call) === '-E', 'lpadmin-Aufruf für Bondrucker falsch: ' . implode(' ', $call));
    $printers->add('office', 'Praxisdrucker', '192.168.1.90');
    $call = end($cups->calls);
    check(in_array('everywhere', $call, true) && in_array('ipp://192.168.1.90/ipp/print', $call, true), 'A4-Drucker nicht über IPP Everywhere angelegt');
    $usb = $printers->add('tm-m10', 'TM-m10', 'usb://EPSON/TM-m10?serial=X3KM012345');
    check($usb['address'] === 'USB', 'USB-Adresse falsch angezeigt');

    // Klartextfehler statt Terminal-Meldungen; nichts angelegt.
    $before = count($cups->queues);
    check(str_contains(problem(fn() => $printers->add('tm-m10', '', '192.168.1.99'), 'Nicht erreichbarer Drucker akzeptiert', 422)->getMessage(), 'eingeschaltet'), 'Fehlertext ohne Hinweis');
    problem(fn() => $printers->add('tm-m10', 'Mein Drucker', '192.168.1.81'), 'Name mit Leerzeichen akzeptiert', 422);
    problem(fn() => $printers->add('tm-m10', '', '192.168.1.81; rm -rf /'), 'Ungültige Adresse akzeptiert', 422);
    problem(fn() => $printers->add('tm-m10', '', 'http://evil/'), 'Fremdes Schema akzeptiert', 422);
    problem(fn() => $printers->add('tm-m10', 'TMm10', '192.168.1.81'), 'Fremde Warteschlange überschrieben', 409);
    problem(fn() => $printers->remove('TMm10'), 'Fremde Warteschlange entfernt', 409);
    check(count($cups->queues) === $before, 'Trotz Fehler Warteschlange angelegt');
    $cups->fail = 'lpadmin: Forbidden';
    check(str_contains(problem(fn() => $printers->add('tm-m30iii', 'Neu', '192.168.1.81'), 'Fehlende Berechtigung nicht gemeldet', 502)->getMessage(), 'install-server.sh'), 'Berechtigungsfehler unverständlich');
    $cups->fail = 'lpadmin: Unable to query printer: Not Found';
    check(str_contains(problem(fn() => $printers->add('office', 'Neu', '192.168.1.90'), 'IPP-Fehler nicht gemeldet', 502)->getMessage(), 'AirPrint'), 'IPP-Fehler unverständlich');
    $cups->fail = '';

    $queues = array_column($printers->queues(), null, 'name');
    check($queues['TM-m30III']['managed'] && !$queues['TMm10']['managed'] && $queues['TM-m30III']['state'] === 'bereit', 'Warteschlangenliste falsch');
    $printers->remove('TM-m10');
    check(!isset($cups->queues['TM-m10']), 'Eigene Warteschlange nicht entfernt');

    // Über die Einstellungsseite: Anlegen stellt die Einstellungen automatisch um.
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = $state = sys_get_temp_dir() . '/ks-printers-' . bin2hex(random_bytes(6)); $dirs[] = $state; mkdir($state, 0700);
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $base = new Config($v);
    $page = new SettingsPage($base, null, $printers);
    $admin = new Admin(new Database($state), $state);
    $login = $page->handle('setup', ['token' => $admin->createSetupToken(), 'password' => 'beispiel-passwort'], null, null);
    preg_match('/^ks_admin=([^;]+)/', (string)$page->cookie(), $m); $session = $m[1];
    problem(fn() => $page->handle('printer_add', ['kind' => 'tm-m30iii', 'address' => '192.168.1.81'], $session, 'falsch'), 'Druckereinrichtung ohne CSRF', 403);
    $r = $page->handle('printer_add', ['kind' => 'tm-m30iii', 'name' => '', 'address' => '192.168.1.81'], $session, $login['csrf']);
    $eff = Settings::effective($base);
    check($eff->get('printing', 'enabled') === true && $eff->get('printing', 'transport') === 'cups' && $eff->get('printing', 'queue') === 'TM-m30III'
        && $eff->get('printing', 'model') === 'tm-m30iii' && $eff->get('printing', 'width_dots') === 576, 'Bondrucker-Einstellungen nicht übernommen');
    check(array_column($r['printers']['queues'], 'role', 'name')['TM-m30III'] === 'receipt', 'Rolle nicht angezeigt');
    $page->handle('printer_use', ['name' => 'Praxisdrucker', 'kind' => 'office'], $session, $login['csrf']);
    check(Settings::effective($base)->get('office_printing', 'queue') === 'Praxisdrucker', 'Praxisdrucker nicht übernommen');
    // Vorhandene (nicht hier angelegte) Warteschlange als TM-m10 verwenden: Breite passt sich an.
    $page->handle('printer_use', ['name' => 'TMm10', 'kind' => 'tm-m10'], $session, $login['csrf']);
    check(Settings::effective($base)->get('printing', 'width_dots') === 420, 'Breite beim Modellwechsel nicht angepasst');

    // Drucker „SumUp-Rechnung“ ohne Terminal: Einschalten legt ihn an, Ausschalten entfernt ihn.
    $files = $state . '/invoice-printer'; mkdir($files . '/web', 0700, true);
    InvoicePrinter::$dir = $files . '/web'; InvoicePrinter::$backend = $files . '/backend'; InvoicePrinter::$ppd = $files . '/sumup-rechnung.ppd';
    InvoicePrinter::$certificate = $files . '/server.crt';
    file_put_contents(InvoicePrinter::$backend, '#!/bin/sh'); file_put_contents(InvoicePrinter::$ppd, '*PPD-Adobe: "4.3"');
    file_put_contents(InvoicePrinter::$certificate, "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----\n");
    $r = $page->handle('save', ['section' => 'invoice_inbox', 'values' => ['enabled' => true, 'max_age_days' => '14']], $session, $login['csrf']);
    check(str_contains($r['message'], 'Drucker „SumUp-Rechnung“ ist eingerichtet'), 'Rechnungsdrucker nicht angelegt: ' . $r['message']);
    $call = array_values(array_filter($cups->calls, fn($c) => basename($c[0]) === 'lpadmin' && in_array('SumUp-Rechnung', $c, true)))[0] ?? [];
    check(($cups->queues['SumUp-Rechnung']['uri'] ?? '') === 'kienzle-sumup:/' && in_array('printer-is-shared=true', $call, true) && in_array(InvoicePrinter::$ppd, $call, true), 'lpadmin für SumUp-Rechnung falsch: ' . implode(' ', $call));
    $cfg = json_decode((string)file_get_contents($files . '/web/printer.json'), true);
    check($cfg['inbox_key'] === Settings::effective($base)->get('invoice_inbox', 'key') && str_starts_with($cfg['server_url'], 'https://'), 'Verbindungsdaten falsch');
    check((fileperms($files . '/web/printer.json') & 0777) === 0600 && is_file($files . '/web/server.crt'), 'Verbindungsdaten nicht geschützt');
    check($r['invoice_printer']['queue']['ours'] === true, 'Status zeigt den Drucker nicht');
    $before = count($cups->calls);
    $page->handle('save', ['section' => 'invoice_inbox', 'values' => ['enabled' => true]], $session, $login['csrf']);
    check(!array_filter(array_slice($cups->calls, $before), fn($c) => basename($c[0]) === 'lpadmin'), 'Vorhandener Drucker erneut angelegt');
    $r = $page->handle('save', ['section' => 'invoice_inbox', 'values' => ['enabled' => false]], $session, $login['csrf']);
    check(!isset($cups->queues['SumUp-Rechnung']) && !is_file($files . '/web/printer.json') && str_contains($r['message'], 'entfernt'), 'Ausschalten entfernt den Drucker nicht');
    $cups->queues['SumUp-Rechnung'] = ['uri' => 'ipp://fremd/', 'desc' => 'Fremd'];
    $r = $page->handle('save', ['section' => 'invoice_inbox', 'values' => ['enabled' => true]], $session, $login['csrf']);
    check(str_contains($r['message'], 'anderen Drucker') && $cups->queues['SumUp-Rechnung']['uri'] === 'ipp://fremd/', 'Fremder Drucker überschrieben');
    unset($cups->queues['SumUp-Rechnung']);
    unlink(InvoicePrinter::$backend);
    check(str_contains($page->handle('save', ['section' => 'invoice_inbox', 'values' => ['enabled' => true]], $session, $login['csrf'])['message'], 'Server-Installer'), 'Fehlendes Backend nicht gemeldet');

    // Arbeitsplatz-Pakete: echtes ZIP mit Konfiguration, Zertifikat, Skript und Rechten.
    $cert = $state . '/server.crt';
    exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg($state . '/k.pem') . ' -out ' . escapeshellarg($cert) . ' -days 1 -subj /CN=praxisserver 2>/dev/null', $o, $code);
    check($code === 0, 'Testzertifikat nicht erzeugt');
    problem(fn() => $page->clientDownload(null, 'windows', fn() => null, fn() => null, $cert), 'Paket ohne Anmeldung', 401);
    problem(fn() => $page->clientDownload($session, 'amiga', fn() => null, fn() => null, $cert), 'Unbekanntes System akzeptiert', 404);
    foreach (array_keys(ClientPackage::SYSTEMS) as $system) {
        $headers = []; $zip = '';
        $page->clientDownload($session, $system, function (string $h) use (&$headers) { $headers[] = $h; }, function (string $b) use (&$zip) { $zip .= $b; }, $cert);
        check(in_array('Content-Length: ' . strlen($zip), $headers, true), "Content-Length falsch ($system)");
        file_put_contents($path = "$state/$system.zip", $zip);
        $check = shell_exec('python3 -I -c ' . escapeshellarg('import json,sys,zipfile
z=zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None
c=json.loads(z.read("client.json")); print(c["server_url"], c["launcher_key"][:4], len(c["certificate_sha256"]))
for i in z.infolist(): print(i.filename, oct(i.external_attr >> 16 & 0o777))') . ' ' . escapeshellarg($path) . ' 2>&1');
        check(str_contains((string)$check, 'aaaa 64'), "client.json fehlt oder falsch ($system): $check");
        $script = ClientPackage::SYSTEMS[$system]['scripts'][0];
        check(str_contains((string)$check, "$script 0o755") && str_contains((string)$check, 'client.json 0o600') && str_contains((string)$check, 'LIESMICH.txt'), "Dateien oder Rechte falsch ($system): $check");
    }
    $der = shell_exec('openssl x509 -in ' . escapeshellarg($cert) . ' -outform DER');
    $files = (new ClientPackage($base, dirname(__DIR__), $cert))->files('linux');
    check(json_decode($files['client.json']['data'], true)['certificate_sha256'] === hash('sha256', (string)$der), 'Zertifikatsfingerabdruck falsch');
    echo "Druckereinrichtung und Arbeitsplatz-Pakete ok\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
}
