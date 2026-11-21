<?php
declare(strict_types=1);
// Barzahlung im Rechnungstool: ohne Terminal, eigene Rechnungsnummer, Kennzeichnung in Beleg, Akte und Export.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Config, FhirClient, HttpClient, InvoiceReceipt, Problem, TaxExport};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}
final class NoNetwork extends HttpClient {
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array { throw new RuntimeException('Barzahlung darf nichts an SumUp senden: ' . $url); }
}
function config(string $state, bool $practice = true): Config {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = $state; $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['sumup'] = ['mode' => 'mock']; $v['fhir'] = ['mode' => 'mock', 'entry_code' => 'ZAHLUNG'];
    if ($practice) $v['practice'] = ['name' => 'Praxis Beispiel', 'street' => 'Musterstraße 1', 'city' => '12345 Musterstadt'];
    return new Config($v);
}
function text(string $svg): string { return (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('</text>', "\n", $svg)), ENT_XML1, 'UTF-8')); }

$dirs = [];
try {
    $state = sys_get_temp_dir() . '/ks-cash-' . bin2hex(random_bytes(6)); $dirs[] = $state; mkdir($state, 0700);
    $app = new App(config($state), new NoNetwork());
    $app->db->migrate();
    check((int)$app->db->query('PRAGMA user_version')->fetchColumn() === 19, 'Datenbank nicht auf Version 19');
    $visit = function () use ($app): array {
        $launch = $app->launch(['context_id' => 'ctx-' . bin2hex(random_bytes(4)), 'oauth_token' => '', 'fhir_base_url' => '']);
        parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f); $e = $app->exchange($f['launch'], null);
        return $app->visit($app->browser($e['cookie']), $e['visit_id']);
    };
    $v1 = $visit();
    $app->saveService($v1, ['label' => 'Attest', 'price' => '15,00']);
    $ids = $app->db->query('SELECT id FROM services')->fetchAll(PDO::FETCH_COLUMN);

    // Terminal belegt (Kartenzahlung läuft in einem anderen Vorgang): Bar geht trotzdem, Karte nicht.
    $card = $app->start($v1, ['services' => $ids, 'request_id' => App::id()]);
    check($card['method'] === 'card' && $card['payment_status'] !== 'successful', 'Kartenzahlung falsch gestartet');
    $v2 = $visit();
    problem(fn() => $app->start($v2, ['services' => $ids, 'request_id' => App::id()]), 'Zweite Kartenzahlung trotz belegtem Terminal', 409);
    $request = App::id();
    $cash = $app->start($v2, ['services' => $ids, 'request_id' => $request, 'method' => 'cash']);
    check($cash['method'] === 'cash' && $cash['payment_status'] === 'successful' && $cash['invoice_number'] === 'TEST-BAR-' . date('Y') . '-0001', 'Barzahlung falsch: ' . json_encode($cash));
    check($app->start($v2, ['services' => $ids, 'request_id' => $request, 'method' => 'cash'])['id'] === $cash['id'], 'Wiederholte Anfrage nicht idempotent');
    problem(fn() => $app->start($v2, ['services' => $ids, 'request_id' => $request]), 'Gleicher Schlüssel mit anderer Zahlungsart akzeptiert', 409);
    problem(fn() => $app->start($visit(), ['services' => $ids, 'request_id' => App::id(), 'method' => 'scheck']), 'Unbekannte Zahlungsart akzeptiert', 400);
    $cash2 = $app->start($visit(), ['services' => $ids, 'request_id' => App::id(), 'method' => 'cash']);
    check($cash2['invoice_number'] === 'TEST-BAR-' . date('Y') . '-0002', 'Nummern nicht fortlaufend');

    // Beleg, Akteneintrag und Abschluss.
    $row = $app->db->one('SELECT * FROM payments WHERE id=?', [$cash['id']]);
    $invoice = json_decode($row['invoice_json'], true); $invoice['number'] = $row['transaction_id']; $invoice['issued_date'] = date('Y-m-d');
    $t = text(InvoiceReceipt::source($invoice, ['status' => 'successful', 'paid_date' => 'heute', 'reference' => $row['reference'], 'transaction_id' => $row['transaction_id'], 'mock' => true, 'method' => 'cash']));
    check(str_contains($t, 'Bar bezahlt') && str_contains($t, 'Betrag bar erhalten') && str_contains($t, 'Rechnungsnummer: TEST-BAR-') && !str_contains($t, 'SumUp') && !str_contains($t, 'Karte'), 'Barbeleg falsch: ' . $t);
    check(str_starts_with($app->receipt($v2, $cash['id']), '%PDF'), 'Kein PDF für Barzahlung');
    $fhir = (new FhirClient($app->config, new NoNetwork()))->resource($v2, $row)['valueString'];
    check(str_starts_with($fhir, 'Barzahlung – 15,00 €') && str_contains($fhir, 'bar erhalten') && !str_contains($fhir, 'SumUp'), 'Akteneintrag falsch: ' . $fhir);
    check($app->document($v2, $cash['id'])['doc_status'] === 'written', 'Barzahlung nicht dokumentierbar');

    // Kartenzahlung abschließen, dann Export: Bar und Kartenzahlung gekennzeichnet.
    $app->mock($v1, ['payment_id' => $card['id'], 'status' => 'successful']);
    $export = new TaxExport($app->db, $app->config);
    $rows = $export->invoices((int)date('Y'), 0, true);
    check(array_column($rows, 'cash') === [false, true, true], 'Zahlungsart im Export falsch: ' . json_encode(array_column($rows, 'cash')));
    file_put_contents($state . '/x.xlsx', $export->workbook((int)date('Y'), (int)date('n'), false, true));
    $out = shell_exec('python3 -I -c ' . escapeshellarg('import openpyxl,sys
wb=openpyxl.load_workbook(sys.argv[1]); ws=wb["Rechnungen"]; h=[c.value for c in ws[1]]
art=[r[h.index("Zahlungsart")] for r in ws.iter_rows(min_row=2,values_only=True)][:-1]
assert art==["Kartenzahlung","Bar","Bar"], art
s=wb["Summe"]; sh=[c.value for c in s[1]]; r=list(s.iter_rows(min_row=2,max_row=2,values_only=True))[0]
assert r[sh.index("davon bar")]==30 and r[sh.index("davon Kartenzahlung")]==15, (sh,r)
print("ok")') . ' ' . escapeshellarg($state . '/x.xlsx') . ' 2>&1');
    check(trim((string)$out) === 'ok', 'Excel-Kennzeichnung fehlerhaft: ' . $out);

    // Ohne Praxisdaten keine Barzahlung (es muss eine Rechnung entstehen).
    $state2 = sys_get_temp_dir() . '/ks-cash2-' . bin2hex(random_bytes(6)); $dirs[] = $state2; mkdir($state2, 0700);
    $plain = new App(config($state2, false), new NoNetwork()); $plain->db->migrate();
    $launch = $plain->launch(['context_id' => 'x', 'oauth_token' => '', 'fhir_base_url' => '']);
    parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f); $e = $plain->exchange($f['launch'], null);
    $pv = $plain->visit($plain->browser($e['cookie']), $e['visit_id']);
    $plain->saveService($pv, ['label' => 'Attest', 'price' => '15,00']);
    problem(fn() => $plain->start($pv, ['services' => $plain->db->query('SELECT id FROM services')->fetchAll(PDO::FETCH_COLUMN), 'request_id' => App::id(), 'method' => 'cash']), 'Barzahlung ohne Praxisdaten akzeptiert', 409);
    echo "Barzahlung ok\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
}
