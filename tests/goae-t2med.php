<?php
declare(strict_types=1);
// GOÄ-Rechner (Einfachsatz + Endbetrag → Faktor) und Liste bezahlter T2med-Rechnungen zum Abhaken.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, App, Config, Problem, ServiceCatalog, SettingsPage, TaxExport};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}

$dirs = [];
try {
    // Rechner: Faktor so genau wie nötig (2, sonst 3, sonst 4 Nachkommastellen), damit der Endbetrag exakt stimmt.
    $r = ServiceCatalog::bestFactor(1072, 2500, 'standard');      // 25,00 / 10,72 = 2,3321…
    check($r['factor'] === '2.332' && $r['price_cents'] === 2500 && $r['exact'] && $r['over_threshold'] && !$r['over_max'], 'Faktor falsch: ' . json_encode($r));
    $r = ServiceCatalog::bestFactor(466, 1072, 'standard');       // GOÄ 1 (4,66 €) × 2,3 = 10,72
    check($r['factor'] === '2.3' && $r['price_cents'] === 1072 && $r['exact'] && !$r['over_threshold'], 'Regelfall falsch: ' . json_encode($r));
    $r = ServiceCatalog::bestFactor(466, 2000, 'standard');
    check($r['over_max'] && $r['max_price_cents'] === 1631, 'Höchstsatz nicht erkannt: ' . json_encode($r));
    check(ServiceCatalog::bestFactor(1000, 500, 'standard')['factor'] === '1', 'Faktor unter 1,0 zugelassen');
    check(ServiceCatalog::bestFactor(1000, 1800, 'technical')['factor'] === '1.8' && ServiceCatalog::bestFactor(1000, 1300, 'lab')['factor'] === '1.3', 'Gebührenrahmen falsch');
    // Faktor mit bis zu 4 Nachkommastellen wird gespeichert und geprüft; 5 Stellen nicht.
    check(ServiceCatalog::factor('2,332', 'standard') === '2.332' && ServiceCatalog::factor('2.3321', 'standard') === '2.3321' && ServiceCatalog::factor('2,30', 'standard') === '2.3', 'Faktor-Schreibweise');
    foreach (['2,33211', '0,5', '3,5001', '2,', 'abc'] as $bad) { try { ServiceCatalog::factor($bad, 'standard'); throw new RuntimeException("Faktor $bad akzeptiert"); } catch (Problem) {} }
    check(ServiceCatalog::needsReason(['goae_code' => '1', 'factor' => '2.301', 'fee_type' => 'standard']) && !ServiceCatalog::needsReason(['goae_code' => '1', 'factor' => '2.3', 'fee_type' => 'standard']), 'Begründungspflicht bei 2,301 nicht erkannt');
    // Gegenprobe über viele Werte: bis 100 € Einfachsatz ist jeder Endbetrag exakt möglich, mit möglichst wenigen Nachkommastellen.
    $cases = [];
    for ($i = 0; $i < 400; $i++) {
        $base = random_int(100, 10000); $target = random_int($base, (int)($base * 3.5));
        $best = ServiceCatalog::bestFactor($base, $target, 'standard');
        check($best['exact'] && $best['price_cents'] === $target, "Endbetrag $target bei Einfachsatz $base nicht exakt: " . json_encode($best));
        $units = ServiceCatalog::units($best['factor']);
        check(ServiceCatalog::feeUnits($base, $units) === $best['price_cents'], 'Preis passt nicht zum Faktor');
        $decimals = strlen(rtrim(substr(sprintf('%04d', $units % 10000), 0, 4), '0'));
        foreach ([2 => 100, 3 => 10] as $d => $step) {
            if ($decimals <= $d) continue;
            for ($u = 10000; $u <= 35000; $u += $step) check(ServiceCatalog::feeUnits($base, $u) !== $target, "Mit $d Stellen wäre $target bei $base exakt möglich (Faktor " . ServiceCatalog::text($u) . "), gefunden: {$best['factor']}");
        }
        $cases[] = [$base, $target, $best['factor']];
    }
    // Sehr hoher Einfachsatz: nicht jeder Cent erreichbar, dann nächstliegender Betrag
    $r = ServiceCatalog::bestFactor(25000, 61234, 'standard');
    check(!$r['exact'] ? abs($r['price_cents'] - 61234) <= 1 : $r['price_cents'] === 61234, 'hoher Einfachsatz: ' . json_encode($r));
    // Der Rechner im Browser (goae-calc.js) muss dieselben Faktoren liefern
    if (trim((string)shell_exec('command -v node'))) {
        $js = file_get_contents(dirname(__DIR__) . '/public/assets/goae-calc.js');
        $script = "const euro=x=>x;\n" . $js . "\nconst cases=" . json_encode($cases) . ";\nconst bad=cases.filter(([b,t,f])=>commaFactor(bestFactor(b,t)[0]).replace(',','.')!==f);\nconsole.log(bad.length?JSON.stringify(bad.slice(0,3)):'ok');";
        $out = trim((string)shell_exec('node -e ' . escapeshellarg($script) . ' 2>&1'));
        check($out === 'ok', 'Browser-Rechner weicht ab: ' . $out);
    }

    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = $state = sys_get_temp_dir() . '/ks-goae-' . bin2hex(random_bytes(6)); $dirs[] = $state; mkdir($state, 0700);
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['sumup'] = ['mode' => 'mock']; $v['fhir'] = ['mode' => 'mock', 'entry_code' => 'ZAHLUNG'];
    $v['practice'] = ['name' => 'Praxis Beispiel', 'street' => 'Musterstraße 1', 'city' => '12345 Musterstadt'];
    $app = new App(new Config($v)); $app->db->migrate();
    check((int)$app->db->query('PRAGMA user_version')->fetchColumn() === 19, 'Datenbank nicht auf Version 19');
    $visit = function () use ($app): array {
        $launch = $app->launch(['context_id' => 'ctx-' . bin2hex(random_bytes(4)), 'oauth_token' => '', 'fhir_base_url' => '']);
        parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f); $e = $app->exchange($f['launch'], null);
        return $app->visit($app->browser($e['cookie']), $e['visit_id']);
    };
    $vis = $visit();
    // Mit Einfachsatz: Preis muss Einfachsatz × Faktor sein (±1 Cent).
    $app->saveService($vis, ['label' => 'Beratung', 'price' => '24,98', 'goae_code' => '3', 'factor' => '2,33', 'base' => '10,72']);
    check((int)$app->db->one("SELECT base_cents FROM services WHERE label='Beratung'")['base_cents'] === 1072, 'Einfachsatz nicht gespeichert');
    $e = problem(fn() => $app->saveService($vis, ['label' => 'Falsch', 'price' => '25,00', 'goae_code' => '3', 'factor' => '2,3', 'base' => '10,72']), 'Unpassender Preis akzeptiert', 400);
    check(str_contains($e->getMessage(), '24,66 €'), 'Fehlermeldung ohne richtigen Betrag: ' . $e->getMessage());
    // Faktor mit 3 Nachkommastellen: genau 25,00 €; gespeichert und angezeigt mit allen Stellen
    $app->saveService($vis, ['label' => 'Genau 25', 'price' => '25,00', 'goae_code' => '3', 'factor' => '2,332', 'base' => '10,72']);
    check($app->db->one("SELECT factor FROM services WHERE label='Genau 25'")['factor'] === '2.332', 'Faktor 2,332 nicht gespeichert');
    problem(fn() => $app->saveService($vis, ['label' => 'Zu genau', 'price' => '25,00', 'goae_code' => '3', 'factor' => '2,33211', 'base' => '10,72']), 'Faktor mit 5 Nachkommastellen akzeptiert', 400);
    $app->saveService($vis, ['label' => 'Ohne Rechner', 'price' => '25,00', 'goae_code' => '3', 'factor' => '2,3']);   // wie bisher ohne Einfachsatz
    check(in_array(1072, array_column($app->state($vis, ['csrf' => 'x'])['services'], 'base_cents'), true), 'Einfachsatz nicht an die Seite übergeben');

    // Bezahlte T2med-Rechnungen: Liste nach Rechnungsnummer, Abhaken, Export.
    $inbox = function (string $number, int $cents, string $patient) use ($app): string {
        $id = App::id();
        $app->db->query('INSERT INTO invoice_inbox(id,invoice_number,invoice_date,amount_cents,patient_name,patient_birthdate,received_at) VALUES(?,?,?,?,?,?,?)',
            [$id, $number, '2026-11-02', $cents, $patient, '1980-04-12', time()]);
        return $id;
    };
    $pay = function (string $inboxId, string $number, int $cents, string $reader, ?string $status = 'successful') use ($app, $visit): string {
        $id = App::id(); $vis = $visit();
        $app->db->query('INSERT INTO payments(id,visit_id,request_id,request_hash,reference,reader_id,amount_cents,services_json,payment_status,created_at,paid_at,transaction_id,inbox_id,t2med_invoice) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $vis['id'], App::id(), 'h', 'POS-' . strtoupper($id), $reader, $cents, '[]', $status, time(), time(), 'TX-' . $number, $inboxId, $number]);
        return $id;
    };
    $a = $pay($inbox('RE2026-0010', 4250, 'Erika Musterfrau'), 'RE2026-0010', 4250, 'rdr-1');
    $b = $pay($inbox('RE2026-0002', 1990, 'Max Mustermann'), 'RE2026-0002', 1990, 'rdr-1');
    $pay($inbox('RE2026-0003', 500, 'Test Person'), 'RE2026-0003', 500, 'mock-reader');
    $pay($inbox('RE2026-0004', 700, 'Abgebrochen'), 'RE2026-0004', 700, 'rdr-1', 'failed');
    $export = new TaxExport($app->db, $app->config);
    $open = $export->t2medPaid();
    check(array_column($open, 'number') === ['RE2026-0002', 'RE2026-0010'], 'Liste falsch sortiert oder gefiltert: ' . json_encode(array_column($open, 'number')));
    check($open[1]['patient'] === 'Erika Musterfrau' && $open[1]['amount_cents'] === 4250 && $open[1]['invoice_date'] === '2026-11-02', 'Rechnungsangaben fehlen');
    check(count($export->t2medPaid('open', 0, 0, true)) === 3, 'Testzahlung nicht auf Wunsch gezeigt');
    check($export->markT2medDone([$b], true) === 1 && array_column($export->t2medPaid(), 'number') === ['RE2026-0010'], 'Abhaken wirkt nicht');
    check(count($export->t2medPaid('all')) === 2 && $export->t2medPaid('all')[0]['done_date'] !== '', 'Alle-Ansicht falsch');
    check($export->markT2medDone([$b], false) === 1 && count($export->t2medPaid()) === 2, 'Zurücknehmen wirkt nicht');
    // Bezahlte Rechnungen lassen sich nicht aus dem Eingang löschen; abgebrochene schon.
    $paidInbox = $app->db->one("SELECT inbox_id FROM payments WHERE id=?", [$a])['inbox_id'];
    problem(fn() => KienzleSumup\InboxOverview::delete($app->db, $paidInbox), 'Bezahlte Rechnung gelöscht', 409);
    $failedInbox = $app->db->one("SELECT id FROM invoice_inbox WHERE invoice_number='RE2026-0004'")['id'];
    KienzleSumup\InboxOverview::delete($app->db, $failedInbox);
    check($app->db->one('SELECT id FROM invoice_inbox WHERE id=?', [$failedInbox]) === false || $app->db->one('SELECT id FROM invoice_inbox WHERE id=?', [$failedInbox]) === null, 'Abgebrochene Rechnung nicht gelöscht');
    // Nachträgliche Korrektur: Betrag ändern, stornieren, zurücksetzen.
    $before = $export->t2medPaid('all');
    $first = $before[0]['id']; $orig = $before[0]['amount_cents'];
    $export->correctT2medPayment($first, ['action' => 'amount', 'amount_cents' => $orig + 500, 'note' => 'Betrag zu niedrig erfasst'], 100000);
    $row = array_values(array_filter($export->t2medPaid('all'), fn($r) => $r['id'] === $first))[0];
    check($row['amount_cents'] === $orig + 500 && $row['original_cents'] === $orig && $row['corrected'] && !$row['voided'] && $row['note'] === 'Betrag zu niedrig erfasst', 'Betragskorrektur falsch');
    $export->correctT2medPayment($first, ['action' => 'void', 'note' => 'Erstattet'], 100000);
    $row = array_values(array_filter($export->t2medPaid('all'), fn($r) => $r['id'] === $first))[0];
    check($row['voided'] && $row['amount_cents'] === 0 && $row['original_cents'] === $orig, 'Stornierung falsch');
    check(!in_array($first, array_column($export->t2medPaid('open'), 'id'), true), 'Stornierte Zahlung weiter in der offenen Liste');
    $export->correctT2medPayment($first, ['action' => 'restore'], 100000);
    $row = array_values(array_filter($export->t2medPaid('all'), fn($r) => $r['id'] === $first))[0];
    check(!$row['voided'] && !$row['corrected'] && $row['amount_cents'] === $orig && $row['note'] === '', 'Zurücknehmen falsch');
    $export->correctT2medPayment($first, ['action' => 'amount', 'amount_cents' => $orig], 100000);
    check(!array_values(array_filter($export->t2medPaid('all'), fn($r) => $r['id'] === $first))[0]['corrected'], 'Gleicher Betrag als Korrektur gespeichert');
    problem(fn() => $export->correctT2medPayment($first, ['action' => 'amount', 'amount_cents' => 0], 100000), 'Betrag 0 akzeptiert', 422);
    problem(fn() => $export->correctT2medPayment($first, ['action' => 'amount', 'amount_cents' => 100001], 100000), 'Betrag über Maximum akzeptiert', 422);
    problem(fn() => $export->correctT2medPayment($first, ['action' => 'void', 'note' => str_repeat('x', 201)], 100000), 'Zu lange Notiz akzeptiert', 422);
    problem(fn() => $export->correctT2medPayment(str_repeat('a', 32), ['action' => 'void'], 100000), 'Unbekannte Zahlung korrigiert', 404);
    problem(fn() => $export->correctT2medPayment('x', ['action' => 'void'], 100000), 'Ungültige ID akzeptiert', 422);
    problem(fn() => $export->correctT2medPayment($first, ['action' => 'nope'], 100000), 'Unbekannte Korrektur akzeptiert', 422);
    $export->correctT2medPayment($first, ['action' => 'void', 'note' => 'Test'], 100000);
    check(str_contains((string)(new \ReflectionClass($export))->getName(), 'TaxExport') && strlen($export->t2medWorkbook('all', 0, 0)) > 1000, 'Excel mit stornierter Zahlung nicht erstellt');
    $export->correctT2medPayment($first, ['action' => 'restore'], 100000);

    problem(fn() => $export->markT2medDone(['x'], true), 'Ungültige ID akzeptiert', 422);
    problem(fn() => $export->t2medPaid('open', 0, 5), 'Monat ohne Jahr akzeptiert', 422);
    $year = (int)date('Y');
    check(count($export->t2medPaid('all', $year)) === 2 && $export->t2medPaid('all', $year - 1) === [], 'Jahresfilter falsch');

    // Über die Einstellungsseite (Admin) und als Excel.
    $page = new SettingsPage($app->config);
    problem(fn() => $page->handle('t2med_paid', [], null, null), 'Liste ohne Anmeldung', 401);
    $admin = new Admin($app->db, $state);
    $login = $page->handle('setup', ['token' => $admin->createSetupToken(), 'password' => 'beispiel-passwort'], null, null);
    preg_match('/^ks_admin=([^;]+)/', (string)$page->cookie(), $m);
    check(count($page->handle('t2med_paid', ['status' => 'open'], $m[1], null)['rows']) === 2, 'Liste über die Seite falsch');
    problem(fn() => $page->handle('t2med_done', ['ids' => [$a]], $m[1], 'falsch'), 'Abhaken ohne CSRF', 403);
    $result = $page->handle('t2med_done', ['ids' => [$a], 'done' => true, 'filter' => ['status' => 'open']], $m[1], $login['csrf']);
    check(str_contains($result['message'], '1 Rechnung als in T2med abgehakt') && count($result['rows']) === 1 && $result['open_total'] === 1, 'Abhaken über die Seite falsch');
    $data = ''; $headers = [];
    $page->taxExport($m[1], ['kind' => 't2med', 'status' => 'all', 'year' => (string)$year], function ($h) use (&$headers) { $headers[] = $h; }, function ($x) use (&$data) { $data .= $x; });
    check(in_array('Content-Disposition: attachment; filename="T2med-Rechnungen-bezahlt-' . $year . '.xlsx"', $headers, true), 'Dateiname falsch: ' . json_encode($headers));
    file_put_contents($state . '/t2med.xlsx', $data);
    $out = shell_exec('python3 -I -c ' . escapeshellarg('import openpyxl,sys
ws=openpyxl.load_workbook(sys.argv[1])["T2med bezahlt"]
rows=list(ws.iter_rows(values_only=True))
assert rows[0][:5]==("Rechnungsnummer","Rechnungsdatum","Patient","Geburtsdatum","Betrag"), rows[0]
assert [r[0] for r in rows[1:-1]]==["RE2026-0002","RE2026-0010"], rows
assert rows[2][4]==42.5 and rows[2][9] is not None and rows[1][9] is None and rows[-1][0]=="Summe", rows
print("ok")') . ' ' . escapeshellarg($state . '/t2med.xlsx') . ' 2>&1');
    check(trim((string)$out) === 'ok', 'Excel-Liste fehlerhaft: ' . $out);
    echo "GOÄ-Rechner und T2med-Abhakliste ok\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
}
