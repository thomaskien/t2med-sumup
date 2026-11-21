<?php
declare(strict_types=1);
// Mehrwertsteuer je Leistung, Kleinunternehmerregelung, Rechnungsausweis und Excel-Export.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, App, Config, InvoiceReceipt, Problem, Settings, SettingsPage, Tax, TaxExport};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}
function config(string $state, array $tax = []): Config {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = $state;
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['sumup'] = ['mode' => 'mock']; $v['fhir'] = ['mode' => 'mock', 'entry_code' => 'ZAHLUNG'];
    $v['practice'] = ['name' => 'Praxis Beispiel', 'street' => 'Musterstraße 1', 'city' => '12345 Musterstadt'];
    if ($tax) $v['tax'] = $tax;
    return new Config($v);
}
/** Eine bezahlte Testrechnung mit den gewählten Leistungen anlegen. */
function pay(App $app, array $ids, string $manual = '', ?int $paidAt = null): array {
    $launch = $app->launch(['context_id' => 'ctx-' . bin2hex(random_bytes(4)), 'oauth_token' => '', 'fhir_base_url' => '']);
    parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $fragment);
    $exchange = $app->exchange($fragment['launch'], null); $browser = $app->browser($exchange['cookie']);
    $visit = $app->visit($browser, $exchange['visit_id']);
    $p = $app->start($visit, ['services' => $ids, 'manual_amount' => $manual, 'request_id' => App::id()]);
    $app->mock($visit, ['payment_id' => $p['id'], 'status' => 'successful']);
    if ($paidAt) $app->db->query('UPDATE payments SET paid_at=? WHERE id=?', [$paidAt, $p['id']]);
    return $app->db->one('SELECT * FROM payments WHERE id=?', [$p['id']]);
}
function svg(array $payment): string {
    $invoice = json_decode($payment['invoice_json'], true);
    $invoice['number'] = $payment['transaction_id']; $invoice['issued_date'] = date('Y-m-d', (int)$payment['paid_at']);
    return (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('</text>', "\n", InvoiceReceipt::source($invoice, ['status' => 'successful', 'paid_date' => 'heute',
        'reference' => $payment['reference'], 'transaction_id' => $payment['transaction_id'], 'mock' => true]))), ENT_XML1, 'UTF-8'));
}

$dirs = [];
try {
    // Rechenregeln: enthaltene Steuer aus dem Bruttopreis, kaufmännisch gerundet, je Satz summiert.
    check(Tax::included(11900, 1900) === 1900 && Tax::included(1000, 1900) === 160 && Tax::included(1000, 700) === 65 && Tax::included(999, 0) === 0, 'Steuerberechnung falsch');
    check(Tax::totals([['price_cents' => 1000, 'vat_rate' => 1900], ['price_cents' => 1000, 'vat_rate' => 1900], ['price_cents' => 500]]) ===
        [0 => ['gross' => 500, 'net' => 500, 'vat' => 0], 1900 => ['gross' => 2000, 'net' => 1681, 'vat' => 319]], 'Summen je Satz falsch');
    check(Tax::percent(1900) === '19 %' && Tax::percent(750) === '7,5 %', 'Prozentanzeige falsch');

    // Rechnungstool mit MwSt.: Satz je Position wird bei der Zahlung festgehalten.
    $state = sys_get_temp_dir() . '/ks-tax-' . bin2hex(random_bytes(6)); $dirs[] = $state; mkdir($state, 0700);
    $app = new App(config($state, ['rate' => 1900, 'tax_id' => '12/345/67890', 'exempt_note' => 'Umsatzsteuerfreie Heilbehandlung.']));
    $app->db->migrate();
    check((int)$app->db->query('PRAGMA user_version')->fetchColumn() === 19, 'Datenbank nicht auf Version 19');
    [$v] = (function () use ($app) {
        $launch = $app->launch(['context_id' => 'setup', 'oauth_token' => '', 'fhir_base_url' => '']);
        parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f); $e = $app->exchange($f['launch'], null);
        return [$app->visit($app->browser($e['cookie']), $e['visit_id'])];
    })();
    $app->saveService($v, ['label' => 'Reiseimpfberatung', 'price' => '59,50', 'vat' => true]);
    $app->saveService($v, ['label' => 'Untersuchung', 'price' => '20,00', 'goae_code' => '5', 'factor' => '2,3']);
    problem(fn() => $app->saveService($v, ['label' => 'X', 'price' => '1', 'vat' => 'ja']), 'Ungültige MwSt.-Angabe akzeptiert', 400);
    $ids = array_column($app->db->query('SELECT id,label FROM services ORDER BY label')->fetchAll(), 'id', 'label');
    $browserState = $app->state($v, ['csrf' => 'x']);
    check(array_column($browserState['services'], 'vat', 'label') === ['Reiseimpfberatung' => true, 'Untersuchung' => false] && $browserState['tax']['rate'] === 1900, 'MwSt. nicht im Zustand der Seite');

    $january = (new DateTimeImmutable('2026-01-15 10:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $p1 = pay($app, [$ids['Reiseimpfberatung'], $ids['Untersuchung']], '5,00', $january);
    $items = array_column(json_decode($p1['invoice_json'], true)['items'], 'vat_rate', 'label');
    check($items === ['Untersuchung' => 0, 'Reiseimpfberatung' => 1900, 'Sonstiger Betrag' => 0], 'Steuersätze der Positionen falsch: ' . json_encode($items));
    $text = svg($p1);
    foreach (['St.-Nr./USt-IdNr.: 12/345/67890', 'inkl. 19 % MwSt.', 'Netto 19 %', '50,00', 'MwSt. 19 %', '9,50', 'Ohne MwSt.', '25,00', 'Umsatzsteuerfreie Heilbehandlung.'] as $part) {
        check(str_contains($text, $part), 'Rechnung ohne „' . $part . '“');
    }

    // Satz ändern: alte Rechnung behält 19 %, neue bekommt 7 %.
    $app = new App(config($state, ['rate' => 700]));
    $march = (new DateTimeImmutable('2026-03-02 09:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $p2 = pay($app, [$ids['Reiseimpfberatung']], '', $march);
    check(array_column(json_decode($p2['invoice_json'], true)['items'], 'vat_rate')[0] === 700 && json_decode($p1['invoice_json'], true)['items'][1]['vat_rate'] === 1900, 'Satzänderung wirkt rückwirkend');

    // Kleinunternehmerregelung: keine MwSt., Hinweis auf der Rechnung.
    $app = new App(config($state, ['rate' => 1900, 'small_business' => true]));
    $p3 = pay($app, [$ids['Reiseimpfberatung']], '', $march + 3600);
    $text = svg($p3);
    check(!str_contains($text, 'MwSt.') && str_contains($text, 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.'), 'Kleinunternehmer-Rechnung weist MwSt. aus');
    // Alte Zahlungen ohne Steuerangaben sehen unverändert aus.
    $old = json_decode($p1['invoice_json'], true); unset($old['tax']); foreach ($old['items'] as &$i) unset($i['vat_rate']); unset($i);
    $legacy = $p1; $legacy['invoice_json'] = json_encode($old);
    check(!str_contains(svg($legacy), 'MwSt') && !str_contains(svg($legacy), 'St.-Nr.'), 'Alte Rechnung verändert');

    // Excel-Export: Jahr und Monat, Testzahlungen nur auf Wunsch, Namen nur auf Wunsch.
    $export = new TaxExport($app->db, $app->config);
    check($export->invoices(2026, 0) === [], 'Testzahlungen ohne Wunsch exportiert');
    check(count($export->invoices(2026, 0, true)) === 3 && count($export->invoices(2026, 3, true)) === 2 && count($export->invoices(2026, 1, true)) === 1 && $export->invoices(2025, 0, true) === [], 'Zeitraumfilter falsch');
    problem(fn() => $export->invoices(2026, 13), 'Monat 13 akzeptiert', 422);
    check(TaxExport::filename(2026, 3) === 'Rechnungen-2026-03.xlsx' && TaxExport::filename(2026, 0) === 'Rechnungen-2026.xlsx', 'Dateiname falsch');
    // Als echte Zahlungen werten (Testkennzeichen entfernen) und mit zwei unabhängigen Lesern prüfen.
    foreach ($app->db->query('SELECT id,invoice_json FROM payments')->fetchAll() as $row) {
        $j = json_decode($row['invoice_json'], true); $j['mock'] = false; $j['patient']['name'] = 'Erika Musterfrau';
        $app->db->query('UPDATE payments SET invoice_json=? WHERE id=?', [json_encode($j, JSON_UNESCAPED_UNICODE), $row['id']]);
    }
    file_put_contents($state . '/jahr.xlsx', $export->workbook(2026, 0));
    file_put_contents($state . '/namen.xlsx', $export->workbook(2026, 3, true));
    $out = shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/tax_xlsx_check.py') . ' ' . escapeshellarg($state . '/jahr.xlsx') . ' ' . escapeshellarg($state . '/namen.xlsx') . ' 2>&1');
    check(trim((string)$out) === 'ok', 'Excel-Datei fehlerhaft: ' . $out);
    if (getenv('KS_KEEP_XLSX')) copy($state . '/jahr.xlsx', getenv('KS_KEEP_XLSX'));

    // Download nur nach Admin-Anmeldung.
    $page = new SettingsPage($app->config);
    problem(fn() => $page->taxExport(null, ['year' => '2026'], fn() => null, fn() => null), 'Export ohne Anmeldung', 401);
    $admin = new Admin($app->db, $state);
    $page->handle('setup', ['token' => $admin->createSetupToken(), 'password' => 'beispiel-passwort'], null, null);
    preg_match('/^ks_admin=([^;]+)/', (string)$page->cookie(), $m);
    $headers = []; $data = '';
    $page->taxExport($m[1], ['year' => '2026', 'month' => '1'], function ($h) use (&$headers) { $headers[] = $h; }, function ($b) use (&$data) { $data .= $b; });
    check(str_starts_with($data, "PK\x03\x04") && in_array('Content-Disposition: attachment; filename="Rechnungen-2026-01.xlsx"', $headers, true), 'Download falsch');
    problem(fn() => $page->taxExport($m[1], ['year' => 'x'], fn() => null, fn() => null), 'Ungültiges Jahr akzeptiert', 422);

    // Einstellungen: Prozentsatz mit Komma, Grenzen.
    $base = config($state);
    Settings::save($app->db, $base, 'tax', ['rate' => '7,5', 'small_business' => false]);
    check(Settings::effective($base)->get('tax', 'rate') === 750, 'Prozentsatz nicht gespeichert');
    check(array_column(array_column(Settings::view(Settings::effective($base)), null, 'id')['tax']['fields'], 'value', 'key')['rate'] === '7,5', 'Prozentsatz falsch angezeigt');
    problem(fn() => Settings::save($app->db, $base, 'tax', ['rate' => '45']), 'Zu hoher Satz akzeptiert', 422);
    problem(fn() => Settings::save($app->db, $base, 'tax', ['rate' => 'neunzehn']), 'Text als Satz akzeptiert', 422);
    echo "Mehrwertsteuer und Excel-Export ok\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
}
