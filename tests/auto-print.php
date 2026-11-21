<?php
declare(strict_types=1);
// Rechnung automatisch drucken: T2med-Rechnung auf dem Praxisdrucker, App-Rechnung wahlweise
// auf Praxis- oder Bondrucker (TM-m10 per Samba, TM-m30III per CUPS-RAW).
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Config, HttpClient, OfficePrinter, Problem, ReceiptPrinter, T2medInvoice};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message, int $http = 0): Problem {
    try { $fn(); } catch (Problem $e) { if ($http && $e->http !== $http) throw new RuntimeException($message . ' (HTTP ' . $e->http . ')'); return $e; }
    throw new RuntimeException($message);
}
function configError(array $v): string {
    try { new Config($v); } catch (\RuntimeException $e) { return $e->getMessage(); }
    throw new RuntimeException('Ungültige Druckkonfiguration akzeptiert');
}

final class FakeOffice extends OfficePrinter {
    public array $jobs = []; public bool $fail = false;
    protected function run(array $args, string $data): array {
        if ($this->fail) return ['code' => 1, 'output' => '', 'stderr' => 'lp: The printer or class does not exist.'];
        $this->jobs[] = ['args' => $args, 'data' => $data];
        return ['code' => 0, 'output' => 'request id is Praxisdrucker-' . (6 + count($this->jobs)) . " (1 file(s))\n", 'stderr' => ''];
    }
}
final class FakeReceipt extends ReceiptPrinter {
    public array $cupsJobs = [], $sambaJobs = [];
    protected function cups(array $args, string $data): array { $this->cupsJobs[] = ['args' => $args, 'data' => $data]; return ['code' => 0, 'output' => "request id is TMm10-3 (0 file(s))\n", 'stderr' => '']; }
    protected function transfer(string $data): array { $this->sambaJobs[] = $data; return ['code' => 0, 'output' => '', 'stderr' => '']; }
}
final class PrintHttp extends HttpClient {
    public array $checkout = [];
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array {
        if (str_contains($url, '/Patient?')) return ['status' => 200, 'body' => ['resourceType' => 'Patient', 'id' => 'p1', 'birthDate' => '1980-04-12', 'name' => [['given' => ['Erika'], 'family' => 'Musterfrau']]]];
        if ($method === 'POST' && str_ends_with($url, '/checkout')) { $this->checkout = $body; return ['status' => 201, 'body' => ['data' => ['checkout_id' => 'c-' . count($this->checkout), 'client_transaction_id' => 'client-' . md5(json_encode($body))]]]; }
        if (str_contains($url, '/transactions?')) {
            parse_str(parse_url($url, PHP_URL_QUERY), $q);
            return ['status' => 200, 'body' => ['id' => 'TX' . strtoupper(substr(md5($q['client_transaction_id'] ?? 'x'), 0, 8)), 'client_transaction_id' => $q['client_transaction_id'] ?? '',
                'merchant_code' => 'MTEST', 'currency' => 'EUR', 'amount' => $this->checkout['total_amount']['value'] / 100, 'status' => 'SUCCESSFUL', 'timestamp' => '2026-11-19T10:00:00Z']];
        }
        throw new RuntimeException('Unerwarteter Aufruf ' . $url);
    }
}
function values(string $model = 'tm-m10', string $transport = 'samba'): array {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = sys_get_temp_dir() . '/ks-print-' . bin2hex(random_bytes(6));
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['sumup'] = ['mode' => 'live', 'api_key' => 'k', 'affiliate_key' => 'a', 'app_id' => 'test.app', 'merchant_code' => 'MTEST', 'reader_id' => 'reader-test'];
    $v['fhir'] = ['mode' => 'live', 'base_url' => 'https://t2med.test/aps/fhir/api/r4', 'launch_urls' => ['https://t2med.test/aps/fhir/api/r4'], 'api_key' => 'f', 'entry_code' => 'ZAHLUNG', 'ca_file' => ''];
    $v['invoice_inbox'] = ['enabled' => true, 'key' => str_repeat('c', 64), 'max_age_days' => 14];
    $v['practice'] = ['name' => 'Testpraxis', 'street' => 'Praxisstraße 1', 'city' => '12345 Musterstadt'];
    $v['office_printing'] = ['enabled' => true, 'cups_server' => 'kienzlebox:631', 'queue' => 'Praxisdrucker', 'media' => 'A4', 'duplex' => false];
    $v['printing'] = ['enabled' => true, 'model' => $model, 'transport' => $transport, 'share' => '//kienzlebox/TMm10', 'cups_server' => '', 'queue' => 'TMm10', 'cut' => true];
    return $v;
}
function visit(App $app, string $context = 'print'): array {
    $launch = $app->launch(['context_id' => $context, 'oauth_token' => 'token', 'fhir_base_url' => 'https://t2med.test/aps/fhir/api/r4']);
    parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f);
    $ex = $app->exchange($f['launch'], null); $b = $app->browser($ex['cookie']);
    return [$app->visit($b, $ex['visit_id']), $b];
}
function localPayment(App $app, array $v, ?bool $print = null): array {
    $app->saveService($v, ['label' => 'Beratung ' . App::id(), 'price' => '10,72', 'goae_code' => '1', 'factor' => '2,3']);
    $service = $app->db->one('SELECT id FROM services ORDER BY updated_at DESC, rowid DESC LIMIT 1')['id'];
    $input = ['request_id' => App::id(), 'services' => [$service], 'groups' => [], 'reasons' => [], 'manual_amount' => '', 'service_date' => date('Y-m-d')];
    if ($print !== null) $input['auto_print'] = $print;
    $payment = $app->start($v, $input);
    check($app->poll($v, $payment['id'])['payment_status'] === 'successful', 'Zahlung nicht erfolgreich');
    return $app->poll($v, $payment['id']);
}

$dirs = [];
try {
    // 1. Konfiguration: Modelle, Breiten, Transport, Praxisdrucker.
    $v = values('tm-m30iii', 'cups'); $cfg = new Config($v);
    check((new ReceiptPrinter($cfg))->width() === 576, 'TM-m30III nicht mit 576 Punkten (72 mm)');
    check((new ReceiptPrinter(new Config(values())))->width() === 420, 'TM-m10 nicht mit 420 Punkten');
    $bad = values('tm-m30iii'); $bad['printing']['width_dots'] = 600; check(str_contains(configError($bad), '576'), 'Zu breiter Druck akzeptiert');
    $bad = values('tm-m10'); $bad['printing']['width_dots'] = 576; check(str_contains(configError($bad), '420'), 'TM-m10 mit 80-mm-Breite akzeptiert');
    $bad = values('tm-x'); check(str_contains(configError($bad), 'model'), 'Unbekanntes Modell akzeptiert');
    $bad = values('tm-m30iii', 'cups'); $bad['printing']['queue'] = 'TM m10; rm'; check(str_contains(configError($bad), 'queue'), 'Ungültige Warteschlange akzeptiert');
    $bad = values(); $bad['office_printing']['cups_server'] = 'http://x'; check(str_contains(configError($bad), 'cups_server'), 'Ungültiger CUPS-Server akzeptiert');

    // 2. Einstellung: standardmäßig aus, App-Rechnungen auf dem Bondrucker.
    $office = new FakeOffice($cfg); $receipt = new FakeReceipt($cfg); $http = new PrintHttp();
    $dirs[] = $cfg->get('app', 'state_dir');
    $app = new App($cfg, $http, null, $receipt, null, $office); $app->db->migrate();
    [$v1, $b1] = visit($app);
    $s = $app->state($v1, $b1)['print'];
    check($s['auto_print'] === false && $s['app_target'] === 'receipt' && $s['office'] && $s['receipt'], 'Falsche Standard-Druckeinstellung');
    check($s['receipt_label'] === 'Epson TM-m30III (80 mm)', 'Bondrucker-Modell nicht angezeigt');
    rejects(fn() => $app->savePrintSettings($v1, ['auto_print' => 'ja', 'app_target' => 'office']), 'Ungültige Einstellung akzeptiert');
    $s = $app->savePrintSettings($v1, ['auto_print' => true, 'app_target' => 'receipt']);
    check($s['auto_print'] === true, 'Einstellung nicht gespeichert');

    // 3. T2med-Rechnung: immer Praxisdrucker, Original-PDF unverändert.
    $pdf = "%PDF-1.6\nOriginal T2med\n";
    $parsed = ['number' => 'RE2026-0042', 'invoice_date' => '2026-03-15', 'amount_cents' => 4250, 'patient_name' => 'Erika Musterfrau', 'patient_birthdate' => '1980-04-12'];
    $app->storeInvoice($parsed, $pdf);
    check($app->db->one('SELECT pdf FROM invoice_inbox')['pdf'] === $pdf, 'PDF trotz Praxisdrucker nicht aufbewahrt');
    $invoiceId = $app->state($v1, $b1)['invoices'][0]['id'];
    $payment = $app->startInvoice($v1, ['request_id' => App::id(), 'invoice_id' => $invoiceId]);   // ohne Häkchen-Angabe: Einstellung (an)
    $payment = $app->poll($v1, $payment['id']);
    check($payment['invoice_print']['status'] === 'pending' && $payment['invoice_print']['target'] === 'office', 'T2med-Rechnung nicht zum Drucken vorgemerkt');
    $printed = $app->invoicePrint($v1, ['payment_id' => $payment['id']]);
    check($printed["invoice_print"]["status"] === "done" && str_contains($printed['invoice_print']['info'], 'Praxisdrucker-7'), 'T2med-Rechnung nicht gedruckt');
    $job = $office->jobs[0];
    check($job['data'] === $pdf, 'Original-PDF nicht unverändert gedruckt');
    check(array_slice($job['args'], 0, 7) === ['/usr/bin/lp', '-h', 'kienzlebox:631', '-d', 'Praxisdrucker', '-t', 'Rechnung RE2026-0042'], 'Falscher lp-Aufruf: ' . implode(' ', $job['args']));
    check(in_array('media=A4', $job['args'], true) && !str_contains(implode(' ', $job['args']), 'Musterfrau'), 'Druckoptionen/Titel falsch');
    check($receipt->cupsJobs === [] && $receipt->sambaJobs === [], 'T2med-Rechnung auf dem Bondrucker gedruckt');
    $app->invoicePrint($v1, ['payment_id' => $payment['id']]);
    check(count($office->jobs) === 1, 'Automatischer Druck doppelt ausgeführt');
    $app->invoicePrint($v1, ['payment_id' => $payment['id'], 'again' => true]);
    check(count($office->jobs) === 2, 'Nachdruck auf Wunsch nicht ausgeführt');
    check($app->db->one('SELECT pdf FROM invoice_inbox WHERE id=?', [$invoiceId])['pdf'] === $pdf, 'PDF vor dem Abschluss gelöscht (Nachdruck unmöglich)');

    // 4. T2med-Rechnungen drucken immer (Einstellung der Praxis); das Häkchen der Zahlungsseite gilt nur für App-Rechnungen.
    [$v2, $b2] = visit($app, 'print-2');
    $app->storeInvoice(['number' => 'RE2026-0043'] + $parsed, $pdf);
    $id = $app->state($v2, $b2)['invoices'][0]['id'];
    $p = $app->poll($v2, $app->startInvoice($v2, ['request_id' => App::id(), 'invoice_id' => $id, 'auto_print' => false])['id']);
    check(($p['invoice_print']['status'] ?? '') === 'pending', 'T2med-Rechnung trotz abgewähltem Häkchen nicht zum Drucken vorgemerkt');
    $app->invoicePrint($v2, ['payment_id' => $p['id']]);
    check(count($office->jobs) === 3, 'T2med-Rechnung nicht gedruckt');
    check($app->db->one('SELECT pdf FROM invoice_inbox WHERE id=?', [$id])['pdf'] === $pdf, 'PDF zu früh gelöscht (Nachdruck noch möglich)');
    $manual = $app->invoicePrint($v2, ['payment_id' => $p['id'], 'again' => true]);
    check($manual['invoice_print']['status'] === 'done' && count($office->jobs) === 4, 'Nachdruck nicht möglich');

    // 4b. „Später (Überweisung)“ druckt einmal; die spätere Zahlung druckt nicht noch einmal.
    [$v4, $b4] = visit($app, 'print-later');
    $app->storeInvoice(['number' => 'RE2026-0044'] + $parsed, $pdf);
    $lid = $app->state($v4, $b4)['invoices'][0]['id'];
    $later = $app->invoiceLater($v4, ['invoice_id' => $lid]);
    check($later['printed'] === true && count($office->jobs) === 5 && $app->state($v4, $b4)['invoices'][0]['printed_at'] > 0, 'Später-Druck nicht ausgeführt');
    $app->invoiceLater($v4, ['invoice_id' => $lid]);
    check(count($office->jobs) === 5, 'Später-Druck doppelt ausgeführt');
    $lp = $app->poll($v4, $app->startInvoice($v4, ['request_id' => App::id(), 'invoice_id' => $lid])['id']);
    $app->invoicePrint($v4, ['payment_id' => $lp['id']]);
    check(count($office->jobs) === 5 && str_contains((string)($app->db->one('SELECT print_info FROM payments WHERE id=?', [$lp['id']])['print_info']), 'Schon vor der Zahlung'), 'Nach Später-Druck erneut gedruckt');
    // Einstellung aus: weder automatisch noch bei „Später“.
    $off = values(); $off['office_printing']['invoice_auto'] = false; $offCfg = new Config($off); $dirs[] = $offCfg->get('app', 'state_dir');
    $offOffice = new FakeOffice($offCfg); $offApp = new App($offCfg, new PrintHttp(), null, new FakeReceipt($offCfg), null, $offOffice); $offApp->db->migrate();
    [$ov, $ob] = visit($offApp, 'print-off');
    $offApp->storeInvoice(['number' => 'RE2026-0045'] + $parsed, $pdf);
    $oid = $offApp->state($ov, $ob)['invoices'][0]['id'];
    check($offApp->invoiceLater($ov, ['invoice_id' => $oid])['printed'] === false && $offOffice->jobs === [], 'Später-Druck trotz ausgeschalteter Einstellung');
    $op = $offApp->poll($ov, $offApp->startInvoice($ov, ['request_id' => App::id(), 'invoice_id' => $oid])['id']);
    check($op['invoice_print'] === null && $offOffice->jobs === [], 'Automatischer Druck trotz ausgeschalteter Einstellung');

    // 5. App-Rechnung auf dem Bondrucker (TM-m30III per CUPS-RAW).
    [$v3] = visit($app, 'print-3');
    $p = localPayment($app, $v3);
    check($p['invoice_print']['target'] === 'receipt', 'App-Rechnung nicht für den Bondrucker vorgemerkt');
    check($app->invoicePrint($v3, ['payment_id' => $p['id']])['invoice_print']['status'] === 'done', 'App-Rechnung nicht gedruckt');
    $raw = $receipt->cupsJobs[0];
    check(array_slice($raw['args'], 0, 3) === ['/usr/bin/lp', '-d', 'TMm10'] && in_array('raw', $raw['args'], true), 'Bon nicht als RAW an CUPS: ' . implode(' ', $raw['args']));
    check(str_starts_with($raw['data'], "\x1b\x40") && str_contains($raw['data'], "\x30\x70\x30\x01\x01\x31" . pack('v', 576)) && str_ends_with($raw['data'], "\x1d\x56\x42\x00"), 'ESC/POS nicht in TM-m30III-Breite');

    // 5b. Derselbe Ablauf mit dem TM-m10 über die Samba-Freigabe (bisherige Einrichtung).
    $cfg10 = new Config(values('tm-m10', 'samba')); $dirs[] = $cfg10->get('app', 'state_dir');
    $r10 = new FakeReceipt($cfg10); $app10 = new App($cfg10, new PrintHttp(), null, $r10, null, new FakeOffice($cfg10)); $app10->db->migrate();
    [$v10] = visit($app10, 'tm-m10');
    $p10 = localPayment($app10, $v10, true);
    check($app10->invoicePrint($v10, ['payment_id' => $p10['id']])['invoice_print']['status'] === 'done', 'App-Rechnung auf TM-m10 nicht gedruckt');
    check(count($r10->sambaJobs) === 1 && $r10->cupsJobs === [], 'TM-m10 nicht über Samba angesprochen');
    check(str_contains($r10->sambaJobs[0], "\x30\x70\x30\x01\x01\x31" . pack('v', 420)), 'ESC/POS nicht in TM-m10-Breite (420)');
    check($app10->state($v10, visit($app10, 'tm-m10-b')[1])['print']['receipt_label'] === 'Epson TM-m10 (58 mm)', 'TM-m10 nicht als Bondrucker angezeigt');

    // 6. App-Rechnung auf dem Praxisdrucker: A4-Seite mit vergrößertem Beleg.
    $app->savePrintSettings($v3, ['auto_print' => true, 'app_target' => 'office']);
    [$v4] = visit($app, 'print-4');
    $p = localPayment($app, $v4);
    check($p['invoice_print']['target'] === 'office', 'App-Rechnung nicht für den Praxisdrucker vorgemerkt');
    $app->invoicePrint($v4, ['payment_id' => $p['id']]);
    $a4 = end($office->jobs)['data'];
    check(str_starts_with($a4, '%PDF-'), 'App-Rechnung nicht als PDF');
    if (is_executable('/usr/bin/pdfinfo')) {   // Seitengröße prüfen (poppler-utils)
        $info = KienzleSumup\Command::run(['/usr/bin/pdfinfo', '-'], $a4);
        check(str_contains($info['output'], '(A4)'), 'App-Rechnung nicht auf A4: ' . $info['output']);
    }

    // 7. Druckerfehler: gespeichert, beim Abschluss erneut versucht, danach manuell.
    [$v5] = visit($app, 'print-5');
    $office->fail = true;
    $p = localPayment($app, $v5);
    $failed = $app->invoicePrint($v5, ['payment_id' => $p['id']])['invoice_print'];
    check($failed['status'] === 'failed' && str_contains($failed['error'], 'does not exist'), 'Druckerfehler nicht gemeldet');
    $office->fail = false;
    check($app->invoicePrint($v5, ['payment_id' => $p['id']])['invoice_print']['status'] === 'done', 'Erneuter Druck nach Fehler fehlgeschlagen');

    // 8. Testmodus: aufbereiten, nichts senden.
    $mv = values(); $mv['sumup'] = ['mode' => 'mock']; $mv['fhir'] = ['mode' => 'mock', 'entry_code' => 'ZAHLUNG']; $mcfg = new Config($mv); $dirs[] = $mcfg->get('app', 'state_dir');
    $mo = new FakeOffice($mcfg); $mr = new FakeReceipt($mcfg);
    $mapp = new App($mcfg, null, null, $mr, null, $mo); $mapp->db->migrate();
    [$mvisit] = visit($mapp, 'mock');
    $mapp->saveService($mvisit, ['label' => 'Beratung', 'price' => '10,72', 'goae_code' => '1', 'factor' => '2,3']);
    $sid = $mapp->db->one("SELECT id FROM services WHERE label='Beratung'")['id'];
    $mp = $mapp->start($mvisit, ['request_id' => App::id(), 'services' => [$sid], 'groups' => [], 'reasons' => [], 'manual_amount' => '', 'service_date' => date('Y-m-d'), 'auto_print' => true]);
    $mapp->mock($mvisit, ['payment_id' => $mp['id'], 'status' => 'successful']);
    $done = $mapp->document($mvisit, $mp['id']);
    check($done['invoice_print']['status'] === 'simulated' && $mo->jobs === [] && $mr->sambaJobs === [], 'Testmodus hat gedruckt oder nicht aufbereitet');
    // T2med-Rechnung im Testmodus: nach dem Abschluss ist die Original-PDF gelöscht.
    [$mv2, $mb2] = visit($mapp, 'mock-2');
    $mapp->storeInvoice($parsed, $pdf);
    $mid = $mapp->state($mv2, $mb2)['invoices'][0]['id'];
    $mp = $mapp->startInvoice($mv2, ['request_id' => App::id(), 'invoice_id' => $mid, 'auto_print' => true]);
    $mapp->mock($mv2, ['payment_id' => $mp['id'], 'status' => 'successful']);
    check($mapp->db->one('SELECT pdf FROM invoice_inbox WHERE id=?', [$mid])['pdf'] === $pdf, 'PDF vor dem Abschluss gelöscht');
    check($mapp->document($mv2, $mp['id'])['invoice_print']['status'] === 'simulated', 'T2med-Rechnung beim Abschluss nicht gedruckt');
    check($mapp->db->one('SELECT pdf FROM invoice_inbox WHERE id=?', [$mid])['pdf'] === null, 'PDF nach dem Abschluss nicht gelöscht');

    echo "OK: Druckeinstellung, Häkchen pro Zahlung, T2med-Rechnung auf A4, App-Rechnung auf Bon (TM-m10/TM-m30III) oder A4, Fehler und Testmodus.\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) { array_map('unlink', glob($dir . '/*') ?: []); @rmdir($dir); }
}
