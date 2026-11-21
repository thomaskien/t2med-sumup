<?php
declare(strict_types=1);
// Letterxpress: Einstellungen, Versand der T2med-Rechnung per Post (API-Nachbildung, keine echte Verbindung).
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Config, HttpClient, Letterxpress, Problem, TransportError};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message, int $http = 0): Problem {
    try { $fn(); } catch (Problem $e) { if ($http && $e->http !== $http) throw new RuntimeException($message . ' (HTTP ' . $e->http . ')'); return $e; }
    throw new RuntimeException($message);
}
final class LxpFakeHttp extends HttpClient {
    public array $calls = [];
    public int $status = 200;
    public ?TransportError $fail = null;
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array {
        if (str_contains($url, '/Patient?')) return ['status' => 200, 'body' => ['resourceType' => 'Patient', 'id' => 'patient-1', 'birthDate' => '1980-04-12', 'name' => [['given' => ['Erika'], 'family' => 'Musterfrau']]]];
        check(str_starts_with($url, 'https://api.letterxpress.de/v3/'), 'Unerwarteter Netzwerkaufruf: ' . $url);
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
        if ($this->fail) throw $this->fail;
        if ($this->status !== 200) return ['status' => $this->status, 'body' => ['message' => 'Unauthorized.']];
        if (str_ends_with($url, '/balance')) return ['status' => 200, 'body' => ['status' => 200, 'message' => 'OK', 'data' => ['balance' => 12.5, 'currency' => 'EUR']]];
        if (str_ends_with($url, '/printjobs')) return ['status' => 200, 'body' => ['status' => 200, 'message' => 'OK', 'data' => ['id' => 4711]]];
        throw new RuntimeException('Unerwarteter API-Pfad ' . $url);
    }
}
function values(array $lxp = []): array {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = sys_get_temp_dir() . '/ks-lxp-' . bin2hex(random_bytes(6));
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['invoice_inbox'] = ['enabled' => true, 'key' => str_repeat('c', 64), 'max_age_days' => 14];
    if ($lxp) $v['letterxpress'] = $lxp + ['enabled' => true, 'username' => 'praxis-test', 'api_key' => 'lxp-secret-key', 'mode' => 'test'];
    return $v;
}
function visit(App $app, string $context = 'test'): array {
    $launch = $app->launch(['context_id' => $context, 'oauth_token' => 'secret-token', 'fhir_base_url' => 'https://t2med.test/aps/fhir/api/r4']);
    parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $fragment);
    $exchange = $app->exchange($fragment['launch'], null); $browser = $app->browser($exchange['cookie']);
    return [$app->visit($browser, $exchange['visit_id']), $browser];
}
$dirs = [];
try {
    $parsed = ['number' => 'RE2026-0042', 'invoice_date' => '2026-03-15', 'amount_cents' => 4250, 'patient_name' => 'Erika Musterfrau', 'patient_birthdate' => '1980-04-12'];
    $pdf = "%PDF-1.6\nTestrechnung\n";

    // 1. Einstellungen werden geprüft.
    foreach ([['username' => ''], ['api_key' => ''], ['mode' => 'dev'], ['color' => '2'], ['registered' => 'r3'], ['shipping' => 'international', 'registered' => 'r1']] as $bad) {
        try { new Config(values($bad)); throw new RuntimeException('Ungültige Einstellung akzeptiert: ' . json_encode($bad)); }
        catch (\RuntimeException $e) { check(!str_starts_with($e->getMessage(), 'Ungültige Einstellung akzeptiert'), $e->getMessage()); }
    }
    $v = values(['color' => '4', 'duplex' => true, 'shipping' => 'national', 'registered' => 'r2']); $dirs[] = $v['app']['state_dir'];
    $http = new LxpFakeHttp(); $app = new App(new Config($v), $http); $app->db->migrate();
    [$vi, $br] = visit($app);
    check($app->state($vi, $br)['letter'] === ['enabled' => true, 'mode' => 'test'], 'Letterxpress-Status fehlt im Kassenstatus');

    // 2. Ohne Original-PDF kein Versand; mit PDF ein Auftrag mit den eingestellten Optionen.
    $app->storeInvoice($parsed);
    $id = $app->state($vi, $br)['invoices'][0]['id'];
    check($app->state($vi, $br)['invoices'][0]['letter']['postable'] === false, 'Postversand ohne PDF angeboten');
    rejects(fn() => $app->invoiceLetter($vi, ['invoice_id' => $id]), 'Versand ohne Original-PDF', 409);
    $app->storeInvoice($parsed, $pdf);
    $id = $app->state($vi, $br)['invoices'][0]['id'];
    check($app->db->one('SELECT pdf FROM invoice_inbox WHERE id=?', [$id])['pdf'] === $pdf && $app->state($vi, $br)['invoices'][0]['letter']['postable'] === true, 'PDF für den Postversand nicht aufbewahrt');
    rejects(fn() => $app->invoiceLetter($vi, ['invoice_id' => str_repeat('0', 32)]), 'Unbekannte Rechnung gesendet', 409);
    $result = $app->invoiceLetter($vi, ['invoice_id' => $id]);
    check($result['status'] === 'sent' && $result['job_id'] === '4711' && $result['mode'] === 'test' && str_contains($result['message'], 'Testmodus'), 'Versand nicht bestätigt');
    $call = $http->calls[0];
    check($call['method'] === 'POST' && $call['url'] === 'https://api.letterxpress.de/v3/printjobs', 'Falscher Aufruf');
    check($call['body']['auth'] === ['username' => 'praxis-test', 'apikey' => 'lxp-secret-key', 'mode' => 'test'], 'Zugangsdaten falsch übergeben');
    $letter = $call['body']['letter'];
    check(base64_decode($letter['base64_file'], true) === $pdf && $letter['base64_file_checksum'] === md5($letter['base64_file']), 'PDF oder Prüfsumme falsch');
    check($letter['specification'] === ['color' => '4', 'mode' => 'duplex', 'shipping' => 'national'] && $letter['registered'] === 'r2', 'Druckoptionen falsch');
    check($letter['notice'] === 'Rechnung RE2026-0042' && $letter['filename_original'] === 'Rechnung-RE2026-0042.pdf', 'Notiz oder Dateiname falsch');
    check(!str_contains(json_encode($call['body']['letter'] + ['x' => 1], JSON_UNESCAPED_UNICODE), 'Musterfrau'), 'Patientenname an Letterxpress gesendet');
    $state = $app->state($vi, $br)['invoices'][0]['letter'];
    check($state['status'] === 'sent' && $state['mode'] === 'test' && $state['job_id'] === '4711' && $state['sent_at'] > 0, 'Versandstatus nicht gespeichert');

    // 3. Testbriefe sperren nicht; live gesendete Rechnungen nie ein zweites Mal.
    check($app->invoiceLetter($vi, ['invoice_id' => $id])['status'] === 'sent' && count($http->calls) === 2, 'Zweiter Testbrief nicht möglich');
    $app->db->query("UPDATE invoice_inbox SET lxp_mode='live' WHERE id=?", [$id]);
    $locked = $app->state($vi, $br)['invoices'][0]['letter'];
    check($locked['locked'] === true, 'Live gesendete Rechnung nicht gesperrt');
    rejects(fn() => $app->invoiceLetter($vi, ['invoice_id' => $id]), 'Doppelter Versand', 409);
    rejects(fn() => $app->invoiceLetter($vi, ['invoice_id' => $id, 'again' => true]), 'Doppelter Versand trotz again', 409);
    check(count($http->calls) === 2, 'Doppelter Versand erreichte Letterxpress');
    $app->db->query("UPDATE invoice_inbox SET lxp_mode='test' WHERE id=?", [$id]);

    // 4. Fremder Patient und bezahlte Rechnung.
    [$other, $otherBrowser] = visit($app, 'test-2');
    $app->db->query("UPDATE visits SET patient_name='Max Mustermann',patient_birthdate='1975-01-01' WHERE id=?", [$other['id']]);
    $other = $app->visit($otherBrowser, $other['id']);
    rejects(fn() => $app->invoiceLetter($other, ['invoice_id' => $id]), 'Rechnung eines anderen Patienten gesendet', 409);
    $pay = $app->startInvoice($vi, ['request_id' => App::id(), 'invoice_id' => $id, 'method' => 'cash']);
    check($pay['payment_status'] === 'successful', 'Barzahlung nicht erfasst');
    rejects(fn() => $app->invoiceLetter($vi, ['invoice_id' => $id, 'again' => true]), 'Bezahlte Rechnung gesendet', 409);

    // 5. Fehler der API und unklarer Ausgang.
    $v2 = values(); $v2['letterxpress'] = ['mode' => 'live'] + $v['letterxpress']; $v2['app']['state_dir'] = sys_get_temp_dir() . '/ks-lxp-' . bin2hex(random_bytes(6)); $dirs[] = $v2['app']['state_dir'];
    $http2 = new LxpFakeHttp(); $app2 = new App(new Config($v2), $http2); $app2->db->migrate();
    [$w, $wb] = visit($app2);
    $app2->storeInvoice($parsed, $pdf); $wid = $app2->state($w, $wb)['invoices'][0]['id'];
    $http2->status = 401;
    $e = rejects(fn() => $app2->invoiceLetter($w, ['invoice_id' => $wid]), 'API-Fehler nicht gemeldet', 502);
    check(str_contains($e->getMessage(), 'Benutzername oder API-Key') && !str_contains($e->getMessage(), 'lxp-secret-key'), 'Fehlermeldung unpassend: ' . $e->getMessage());
    check($app2->state($w, $wb)['invoices'][0]['letter']['status'] === '', 'Fehlversuch als gesendet gespeichert');
    $http2->status = 200; $http2->fail = new TransportError('Zeitüberschreitung', true);
    rejects(fn() => $app2->invoiceLetter($w, ['invoice_id' => $wid]), 'Unklarer Ausgang nicht gemeldet', 502);
    check($app2->state($w, $wb)['invoices'][0]['letter']['status'] === 'unknown', 'Unklarer Ausgang nicht gespeichert');
    $http2->fail = null; $before = count($http2->calls);
    $e = rejects(fn() => $app2->invoiceLetter($w, ['invoice_id' => $wid]), 'Wiederholung bei unklarem Ausgang', 409);
    check(str_contains($e->getMessage(), 'Postausgang') && count($http2->calls) === $before, 'Bei unklarem Ausgang erneut gesendet');

    // 6. Verbindungstest und ausgeschalteter Versand.
    check(str_contains((new Letterxpress(new Config($v2), $http2, $app2->db))->check(), 'Guthaben: 12,50 €'), 'Guthaben nicht angezeigt');
    check(end($http2->calls)['url'] === 'https://api.letterxpress.de/v3/balance', 'Falscher Aufruf beim Verbindungstest');
    $v3 = values(); $dirs[] = $v3['app']['state_dir'];
    $app3 = new App(new Config($v3), new LxpFakeHttp()); $app3->db->migrate();
    [$x, $xb] = visit($app3);
    check($app3->state($x, $xb)['letter']['enabled'] === false, 'Versand ohne Einstellung angeboten');
    rejects(fn() => $app3->invoiceLetter($x, ['invoice_id' => str_repeat('a', 32)]), 'Versand bei ausgeschalteter Funktion', 409);
    echo "OK: Letterxpress-Einstellungen, Versand der T2med-Rechnung per Post, Schutz vor Doppelversand, Fehler und Verbindungstest.\n";
} finally {
    foreach ($dirs as $dir) { if (is_dir($dir)) { foreach (glob($dir . '/*') as $f) @unlink($f); @rmdir($dir); } }
}// Rechte-Verschlüsselung (ohne Kennwort) wird vor dem Senden entfernt.
if (is_executable('/usr/bin/qpdf')) {
    $plain = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R/Size 4>>\n%%EOF\n";
    $in = tempnam(sys_get_temp_dir(), 'lx'); $out = $in . '.enc'; file_put_contents($in, $plain);
    exec('qpdf --encrypt "" owner 256 --print=none -- ' . escapeshellarg($in) . ' ' . escapeshellarg($out) . ' 2>/dev/null');
    $method = new ReflectionMethod(KienzleSumup\Letterxpress::class, 'unprotected');
    $result = $method->invoke(null, (string)file_get_contents($out));
    check(str_starts_with($result, '%PDF-') && !str_contains($result, '/Encrypt'), 'Verschlüsselung des PDFs nicht entfernt');
    @unlink($in); @unlink($out);
}

