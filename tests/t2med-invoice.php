<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Config, HttpClient, InvoiceExport, Problem, T2medInvoice};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message, int $http = 0): Problem {
    try { $fn(); } catch (Problem $e) { if ($http && $e->http !== $http) throw new RuntimeException($message . ' (HTTP ' . $e->http . ')'); return $e; }
    throw new RuntimeException($message);
}
// Nachbildung des pdftotext-Layouts einer T2med-Liquidation (fiktive Daten).
function invoiceText(string $number = 'RE2026-0042', string $total = '42,50', string $transfer = '42,50', string $patient = 'Erika Musterfrau , 12.04.1980'): string {
    return "Praxis Beispiel | Musterstraße 1 | 12345 Musterstadt\nFrau\nErika Musterfrau\n\n" .
        "Re.-Nr.:      $number\nRe.-Datum:    15.03.2026\nPatient:      $patient\n\nLIQUIDATION\n\n" .
        " Datum            Ziffer   Leistungstext                 Wert   Faktor      Betrag\n" .
        "15.03.2026          1      Beratung auch telefonisch     4,66 € 2,30         10,72 €\n" .
        "                                                     Gesamtsumme:            $total €\n" .
        "                         Seite   1 / 2\n\fRe.-Nr.: $number           Re.-Datum: 15.03.2026        Patient: Erika Musterfrau\n\n" .
        "Bitte überweisen Sie den Betrag von $transfer € ohne Abzug bis zum 14.04.2026.\n";
}
function configuration(bool $live = false): Config {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = sys_get_temp_dir() . '/ks-t2med-invoice-' . bin2hex(random_bytes(6));
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['invoice_inbox'] = ['enabled' => true, 'key' => str_repeat('c', 64), 'max_age_days' => 14];
    if ($live) {
        $v['sumup'] = ['mode' => 'live', 'api_key' => 'sumup-secret', 'affiliate_key' => 'affiliate-secret', 'app_id' => 'test.app', 'merchant_code' => 'MTEST', 'reader_id' => 'reader-test'];
        $v['fhir'] = ['mode' => 'live', 'base_url' => 'https://t2med.test/aps/fhir/api/r4', 'launch_urls' => ['https://t2med.test/aps/fhir/api/r4'], 'api_key' => 'fhir-secret', 'entry_code' => 'ZAHLUNG', 'ca_file' => ''];
    }
    return new Config($v);
}
function visit(App $app, string $context = 'test'): array {
    $launch = $app->launch(['context_id' => $context, 'oauth_token' => 'secret-token', 'fhir_base_url' => 'https://t2med.test/aps/fhir/api/r4']);
    parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $fragment);
    $exchange = $app->exchange($fragment['launch'], null); $browser = $app->browser($exchange['cookie']);
    return [$app->visit($browser, $exchange['visit_id']), $browser];
}
final class InvoiceFakeHttp extends HttpClient {
    public array $checkout = [];
    public string $status = 'SUCCESSFUL';
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array {
        if (str_contains($url, '/Patient?')) return ['status' => 200, 'body' => ['resourceType' => 'Patient', 'id' => 'patient-1', 'birthDate' => '1980-04-12', 'name' => [['given' => ['Erika'], 'family' => 'Musterfrau']]]];
        check(str_starts_with($url, 'https://api.sumup.com/'), 'Unerwarteter Netzwerkaufruf');
        if ($method === 'POST' && str_ends_with($url, '/checkout')) { $this->checkout = $body; return ['status' => 201, 'body' => ['data' => ['checkout_id' => 'checkout-1', 'client_transaction_id' => 'client-1']]]; }
        if (str_contains($url, '/transactions?')) return ['status' => 200, 'body' => ['id' => 'tx-1', 'client_transaction_id' => 'client-1', 'merchant_code' => 'MTEST',
            'currency' => 'EUR', 'amount' => $this->checkout['total_amount']['value'] / 100, 'status' => $this->status, 'timestamp' => '2026-03-15T10:00:00Z']];
        throw new RuntimeException('Unerwarteter API-Pfad ' . $url);
    }
}

final class FakeExport extends InvoiceExport {
    public array $commands = [], $files = [], $auth = [];
    public int $failures = 0;
    protected function run(array $credentials, string $command): array {
        $this->commands[] = $command;
        if (($i = array_search('-A', $credentials, true)) !== false) $this->auth[] = file_get_contents($credentials[$i + 1]);
        if (preg_match('/put "([^"]+)" "([^"]+)"/', $command, $m)) {
            if ($this->failures > 0) { $this->failures--; return ['code' => 1, 'output' => 'NT_STATUS_ACCESS_DENIED opening remote file', 'stderr' => '']; }
            $this->files[$m[2]] = file_get_contents($m[1]);
        }
        return ['code' => str_starts_with($command, 'mkdir') ? 1 : 0, 'output' => str_starts_with($command, 'mkdir') ? 'NT_STATUS_OBJECT_NAME_COLLISION' : '', 'stderr' => ''];
    }
}
function exportConfig(string $when, string $user = ''): Config {
    $v = configuration()->values;
    $v['invoice_export'] = ['enabled' => true, 'share' => '//nas/steuerberater', 'directory' => 'Rechnungen/{jahr}/{monat}', 'username' => $user, 'password' => $user ? 'geheim' : '', 'domain' => '', 'when' => $when];
    return new Config($v);
}
$dirs = [];
try {
    // 1. Parser: Werte, Gegenprobe und Eindeutigkeit.
    $parsed = T2medInvoice::parse(invoiceText(), 100000);
    check($parsed === ['number' => 'RE2026-0042', 'invoice_date' => '2026-03-15', 'amount_cents' => 4250, 'patient_name' => 'Erika Musterfrau', 'patient_birthdate' => '1980-04-12'], 'Rechnung falsch gelesen');
    check(T2medInvoice::parse(invoiceText(total: '1.234,50', transfer: '1.234,50'), 1000000)['amount_cents'] === 123450, 'Tausenderpunkt falsch gelesen');
    rejects(fn() => T2medInvoice::parse(invoiceText(transfer: '57,88'), 100000), 'Widersprüchlicher Zahlbetrag akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse(invoiceText() . "Gesamtsumme: 12,00 €\n", 100000), 'Zweite Gesamtsumme akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse(invoiceText() . "Re.-Nr.: RE2026-0043\n", 100000), 'Zweite Rechnungsnummer akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse(invoiceText(patient: 'Erika Musterfrau'), 100000), 'Rechnung ohne Geburtsdatum akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse(invoiceText(total: '-42,50', transfer: '-42,50'), 100000), 'Negative Rechnung (Gutschrift) akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse(invoiceText(total: '2.000,00', transfer: '2.000,00'), 100000), 'Betrag über Maximum akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse('', 100000), 'Leere Rechnung akzeptiert', 422);
    rejects(fn() => T2medInvoice::fromPdf('kein pdf', 100000), 'Nicht-PDF akzeptiert', 415);

    // Bild-PDF ohne Textebene: Texterkennung (nur, wenn pdftoppm und tesseract mit „deu“ vorhanden sind).
    $ocrReady = is_executable('/usr/bin/pdftoppm') || is_executable('/usr/local/bin/pdftoppm');
    $ocrReady = $ocrReady && (is_executable('/usr/bin/tesseract') || is_executable('/usr/local/bin/tesseract'));
    $imagePdf = $ocrReady ? shell_exec('python3 ' . escapeshellarg(__DIR__ . '/make-image-invoice.py') . ' 2>/dev/null') : null;
    if ($ocrReady && is_string($imagePdf) && str_starts_with($imagePdf, '%PDF-')) {
        $read = T2medInvoice::fromPdf($imagePdf, 100000);
        check($read['number'] === 'RE2026-0042' && $read['amount_cents'] === 4250 && $read['invoice_date'] === '2026-03-15'
            && $read['patient_birthdate'] === '1980-04-12', 'Bild-PDF: Texterkennung liefert falsche Werte');
        $broken = shell_exec('python3 ' . escapeshellarg(__DIR__ . '/make-image-invoice.py') . ' mismatch 2>/dev/null');
        rejects(fn() => T2medInvoice::fromPdf((string)$broken, 100000), 'Bild-PDF mit abweichendem Zahlbetrag akzeptiert', 422);
        echo "OCR-Test ausgeführt.\n";
    } else {
        echo "OCR-Test übersprungen (pdftoppm/tesseract fehlen).\n";
    }

    // Eigene Textschnipsel (andere T2med-Vorlage) und mehr eingebaute Varianten.
    $custom = ['label_number' => ['Beleg-Nr.'], 'label_total' => ['Endsumme'], 'label_patient' => ['Behandelte Person']];
    $other = "Beleg-Nr.: B-77/1\nRe.-Datum: 01.02.2026\nBehandelte Person: Max Muster (geb. 03.04.1970)\nEndsumme 12,50 €\nBitte überweisen Sie den Betrag von 12,50 € ohne Abzug.\n";
    $parsedOther = T2medInvoice::parse($other, 100000, false, $custom);
    check($parsedOther['number'] === 'B-77/1' && $parsedOther['amount_cents'] === 1250 && $parsedOther['patient_birthdate'] === '1970-04-03' && $parsedOther['patient_name'] === 'Max Muster', 'Eigene Textschnipsel nicht gelesen');
    $missing = rejects(fn() => T2medInvoice::parse($other, 100000), 'Fremde Vorlage ohne Textschnipsel akzeptiert', 422);
    check(str_contains($missing->getMessage(), 'nicht gefunden'), 'Fehler nennt fehlende Bezeichnung nicht');
    check(T2medInvoice::parse("Rechnungsnummer: A1-9\nRechnungsdatum: 01.02.2026\nPatientin: Eva Muster , 03.04.1970\nGesamtbetrag: 5,00 EUR\n", 100000)['amount_cents'] === 500, 'Eingebaute Varianten nicht gelesen');
    check(T2medInvoice::parse("Re.-Nr.:\nRE2026-0042\nRe.-Datum:\n15.03.2026\nPatient: A B , 12.04.1980\nGesamtsumme:\n42,50 €\n", 100000, false, [], true)['number'] === 'RE2026-0042', 'Wert in Folgezeile nicht gelesen');
    rejects(fn() => T2medInvoice::parse("Re.-Nr.:\nRe.-Datum: 15.03.2026\nPatient: A B , 12.04.1980\nGesamtsumme: 42,50 €\n", 100000, false, [], true), 'Nächste Beschriftung als Rechnungsnummer gelesen', 422);
    $lines = implode("\n", T2medInvoice::overview($other . "Patient: Erika Musterfrau , 12.04.1980\n"));
    check(!str_contains($lines, 'Erika') && !str_contains($lines, 'Muster') && !str_contains($lines, '1980') && str_contains($lines, 'Endsumme 12,50 €'), 'Zeilenübersicht enthält Namen oder Daten');

    // Abgelehnter Druck landet maskiert in der Übersicht; eingegangene Rechnungen zeigen nur Initialen.
    $cfg = configuration(); $dirs[] = $cfg->get('app', 'state_dir'); $app = new App($cfg); $app->db->migrate();
    rejects(fn() => $app->inboxReceive("%PDF-1.4\nkein lesbarer Inhalt"), 'Unlesbares PDF übernommen');
    $app->storeInvoice(T2medInvoice::parse(invoiceText(), 100000));
    $view = KienzleSumup\InboxOverview::build($app->db, $cfg);
    check(count($view['items']) === 1 && $view['items'][0]['status'] === 'offen' && $view['items'][0]['patient'] === 'Erika Musterfrau' && $view['items'][0]['birthdate'] === '1980-04-12' && $view['items'][0]['deletable'], 'Eingangsübersicht falsch');
    check(count($view['rejected']) === 1 && $view['rejected'][0]['reason'] !== '', 'Abgelehnter Druck nicht protokolliert');
    // Löschen: offene Rechnung ja, bezahlte nein.
    $openId = $view['items'][0]['id'];
    rejects(fn() => KienzleSumup\InboxOverview::delete($app->db, 'x'), 'Ungültige ID beim Löschen akzeptiert', 422);
    rejects(fn() => KienzleSumup\InboxOverview::delete($app->db, str_repeat('b', 32)), 'Unbekannte Rechnung gelöscht', 404);
    KienzleSumup\InboxOverview::delete($app->db, $openId);
    check(KienzleSumup\InboxOverview::build($app->db, $cfg)['items'] === [], 'Rechnung nach dem Löschen noch vorhanden');

    // 2. Patientenabgleich.
    check(T2medInvoice::matches($parsed, 'Erika Musterfrau', '1980-04-12'), 'Passender Patient abgelehnt');
    check(T2medInvoice::matches(['patient_name' => 'Dr. Erika  Musterfrau'] + $parsed, 'erika musterfrau', '1980-04-12'), 'Titel/Schreibweise nicht toleriert');
    check(!T2medInvoice::matches($parsed, 'Erika Musterfrau', '1980-04-13'), 'Falsches Geburtsdatum akzeptiert');
    check(!T2medInvoice::matches($parsed, 'Erik Musterfrau', '1980-04-12'), 'Anderer Name akzeptiert');
    check(!T2medInvoice::matches($parsed, 'Erika Musterfrau', ''), 'Fehlendes Geburtsdatum akzeptiert');

    // 3. Ablauf im Testmodus: Eingang, Anzeige, Kassieren, Schutz vor Doppelzahlung.
    $cfg = configuration(); $dirs[] = $cfg->get('app', 'state_dir'); $app = new App($cfg); $app->db->migrate();
    [$v, $b] = visit($app);
    check($app->state($v, $b)['invoices'] === [], 'Rechnung ohne Eingang angezeigt');
    $stored = $app->storeInvoice($parsed);
    check(!str_contains(json_encode($stored, JSON_UNESCAPED_UNICODE), 'Musterfrau'), 'Antwort an Druckserver enthält Patientendaten');
    $app->storeInvoice(['number' => 'RE2026-0999', 'patient_birthdate' => '1990-01-01'] + $parsed);
    $app->storeInvoice(['number' => 'RE2026-0998', 'patient_name' => 'Max Mustermann'] + $parsed);
    $open = $app->state($v, $b)['invoices'];
    check(count($open) === 1 && $open[0]['number'] === 'RE2026-0042' && $open[0]['amount_cents'] === 4250, 'Fremde Rechnung angezeigt oder eigene fehlt');
    // Erneuter Druck derselben Nummer ersetzt die ältere Fassung.
    $app->storeInvoice(['amount_cents' => 4300] + $parsed);
    $open = $app->state($v, $b)['invoices'];
    check(count($open) === 1 && $open[0]['amount_cents'] === 4300, 'Neuere Fassung ersetzt alte nicht');
    rejects(fn() => $app->startInvoice($v, ['request_id' => App::id(), 'invoice_id' => str_repeat('0', 32)]), 'Unbekannte Rechnung gestartet', 409);
    $other = $app->db->one("SELECT id FROM invoice_inbox WHERE invoice_number='RE2026-0999'")['id'];
    rejects(fn() => $app->startInvoice($v, ['request_id' => App::id(), 'invoice_id' => $other]), 'Rechnung eines anderen Patienten gestartet', 409);
    $request = ['request_id' => App::id(), 'invoice_id' => $open[0]['id']];
    $payment = $app->startInvoice($v, $request);
    check($payment['payment_status'] === 'pending' && $payment['amount_cents'] === 4300 && $payment['t2med_invoice'] === 'RE2026-0042', 'Rechnungszahlung nicht gestartet');
    check($app->startInvoice($v, $request)['id'] === $payment['id'], 'Wiederholter Start erzeugt zweite Zahlung');
    rejects(fn() => $app->startInvoice($v, ['request_id' => $request['request_id'], 'invoice_id' => $other]), 'Vorgangsschlüssel für andere Rechnung wiederverwendet', 409);
    check($app->state($v, $b)['invoices'] === [], 'Laufende Rechnung weiter angeboten');
    rejects(fn() => $app->storeInvoice($parsed), 'Laufende Rechnung erneut angenommen', 409);
    $app->mock($v, ['payment_id' => $payment['id'], 'status' => 'failed']);
    check(count($app->state($v, $b)['invoices']) === 1, 'Nach abgelehnter Zahlung nicht erneut kassierbar');
    $retry = $app->startInvoice($v, ['request_id' => App::id(), 'invoice_id' => $open[0]['id']]);
    $app->mock($v, ['payment_id' => $retry['id'], 'status' => 'successful']);
    $e = rejects(fn() => $app->storeInvoice($parsed), 'Bezahlte Rechnung erneut angenommen', 409);
    check(str_contains($e->getMessage(), 'bereits'), 'Unklare Meldung bei bezahlter Rechnung');
    $done = $app->document($v, $retry['id']);
    check($done['doc_status'] === 'written', 'Rechnungszahlung nicht dokumentiert');
    $record = json_decode($app->db->one('SELECT resource_json FROM mock_records WHERE payment_id=?', [$retry['id']])['resource_json'], true);
    check(str_contains($record['valueString'], 'T2med-Rechnung RE2026-0042 vom 15.03.2026 43,00 €'), 'Akteneintrag ohne Rechnungsbezug');
    // Neuer Behandlungskontext desselben Patienten: bezahlte Rechnung nicht erneut anbieten.
    [$v2, $b2] = visit($app, 'test-2');
    check($app->state($v2, $b2)['invoices'] === [], 'Bezahlte Rechnung in neuem Kontext angeboten');
    // Bar bezahlt: sofort erfasst, ohne Terminal, ohne eigenen Beleg, mit Akteneintrag und in der Liste der bezahlten Rechnungen.
    [$v3, $b3] = visit($app, 'test-3');
    $app->storeInvoice(['number' => 'RE2026-0050', 'amount_cents' => 2000] + $parsed);
    $cashOpen = $app->state($v3, $b3)['invoices'];
    check(count($cashOpen) === 1 && $cashOpen[0]['number'] === 'RE2026-0050', 'Rechnung für Barzahlung nicht angeboten');
    rejects(fn() => $app->startInvoice($v3, ['request_id' => App::id(), 'invoice_id' => $cashOpen[0]['id'], 'method' => 'lastschrift']), 'Ungültige Zahlungsart akzeptiert');
    $cashRequest = ['request_id' => App::id(), 'invoice_id' => $cashOpen[0]['id'], 'method' => 'cash'];
    $cash = $app->startInvoice($v3, $cashRequest);
    check($cash['payment_status'] === 'successful' && $cash['method'] === 'cash' && $cash['amount_cents'] === 2000 && $cash['t2med_invoice'] === 'RE2026-0050' && $cash['local_receipt'] === false, 'Barzahlung nicht erfasst');
    check($app->startInvoice($v3, $cashRequest)['id'] === $cash['id'], 'Wiederholte Barzahlung erzeugt zweite Zahlung');
    check($app->state($v3, $b3)['invoices'] === [], 'Bar bezahlte Rechnung weiter angeboten');
    check(str_starts_with($app->receipt($v3, $cash['id']), '%PDF'), 'Quittung als PDF fehlt');
    $svg = (new ReflectionMethod($app, 'receiptSource'))->invoke($app, $app->db->one('SELECT * FROM payments WHERE id=?', [$cash['id']]));
    foreach (['QUITTUNG', 'RE2026-0050', 'Erika Musterfrau', '20,00 €', 'bar erhalten', date('Y')] as $needle) check(str_contains($svg, $needle), 'Quittung ohne ' . $needle);
    rejects(fn() => $app->receiptShare($v3, $cash['id']), 'SumUp-Link für Quittung', 409);
    check(!isset($cash['quittung']), 'Quittung gedruckt, obwohl die Einstellung aus ist');
    check($app->document($v3, $cash['id'])['doc_status'] === 'written', 'Barzahlung nicht dokumentiert');
    $cashRecord = json_decode($app->db->one('SELECT resource_json FROM mock_records WHERE payment_id=?', [$cash['id']])['resource_json'], true);
    check(str_starts_with($cashRecord['valueString'], 'Barzahlung – 20,00 €') && str_contains($cashRecord['valueString'], 'RE2026-0050'), 'Akteneintrag der Barzahlung falsch');
    $paidList = array_values(array_filter((new KienzleSumup\TaxExport($app->db, $cfg))->t2medPaid('all', 0, 0, true), fn($r) => $r['number'] === 'RE2026-0050'));
    check(count($paidList) === 1 && $paidList[0]['method'] === 'cash' && $paidList[0]['amount_cents'] === 2000, 'Barzahlung fehlt in der Liste der bezahlten Rechnungen');
    // Quittung automatisch auf dem Bondrucker (Einstellung im Adminpanel), Testmodus: nur simuliert.
    $autoValues = $cfg->values; $autoValues['printing'] = ['enabled' => true, 'model' => 'tm-m10', 'transport' => 'samba', 'share' => '//kienzlebox/TMm10', 'cups_server' => '', 'queue' => 'TMm10', 'cut' => true, 'cash_receipt_auto' => true];
    $autoValues['app']['state_dir'] = sys_get_temp_dir() . '/ks-t2med-quittung-' . bin2hex(random_bytes(6)); $dirs[] = $autoValues['app']['state_dir'];
    $autoApp = new App(new Config($autoValues)); $autoApp->db->migrate();
    [$v4, $b4] = visit($autoApp);
    $autoApp->storeInvoice(['number' => 'RE2026-0060', 'amount_cents' => 1550] + $parsed);
    $quittung = $autoApp->startInvoice($v4, ['request_id' => App::id(), 'invoice_id' => $autoApp->state($v4, $b4)['invoices'][0]['id'], 'method' => 'cash']);
    check(($quittung['quittung'] ?? '') === 'simulated' && str_contains($quittung['quittung_message'] ?? '', 'Testdruck'), 'Quittung bei Barzahlung nicht automatisch gedruckt');
    check($autoApp->db->one("SELECT status FROM receipt_prints WHERE payment_id=?", [$quittung['id']])['status'] === 'simulated', 'Quittungsdruck nicht protokolliert');
    // Kartenzahlung druckt keine Quittung, auch bei eingeschalteter Einstellung.
    $autoApp->storeInvoice(['number' => 'RE2026-0061'] + $parsed);
    $cardPay = $autoApp->startInvoice($v4, ['request_id' => App::id(), 'invoice_id' => $autoApp->state($v4, $b4)['invoices'][0]['id']]);
    check(!isset($cardPay['quittung']), 'Quittung bei Kartenzahlung gedruckt');
    $badValues = $autoValues; $badValues['printing']['cash_receipt_auto'] = 'ja';
    try { new Config($badValues); throw new RuntimeException('Ungültiger Wert akzeptiert'); } catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'cash_receipt_auto'), 'Falsche Ablehnung: ' . $e->getMessage()); }
    // Abgelaufene, nie kassierte Rechnungen werden nicht angeboten und später entfernt.
    $app->db->query("UPDATE invoice_inbox SET received_at=? WHERE invoice_number='RE2026-0998'", [time() - 15 * 86400]);
    $app->storeInvoice(['number' => 'RE2026-0997'] + $parsed);
    check(!$app->db->one("SELECT id FROM invoice_inbox WHERE invoice_number='RE2026-0998'"), 'Abgelaufene Rechnung nicht entfernt');
    check($app->db->one('SELECT id FROM invoice_inbox WHERE id=?', [$open[0]['id']]) !== null, 'Kassierte Rechnung entfernt');
    // Deaktivierter Eingang.
    $off = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $off['app']['state_dir'] = sys_get_temp_dir() . '/ks-t2med-invoice-off-' . bin2hex(random_bytes(6)); $dirs[] = $off['app']['state_dir'];
    $off['app']['launcher_key'] = str_repeat('a', 64); $off['app']['encryption_key'] = str_repeat('b', 64);
    $offApp = new App(new Config($off)); $offApp->db->migrate();
    rejects(fn() => $offApp->storeInvoice($parsed), 'Deaktivierter Eingang nimmt Rechnung an', 404);

    // 4. Live-Daten an SumUp: nur Betrag, Rechnungsnummer und neutrale Referenz.
    $cfg = configuration(true); $dirs[] = $cfg->get('app', 'state_dir'); $http = new InvoiceFakeHttp(); $app = new App($cfg, $http); $app->db->migrate();
    [$v, $b] = visit($app);
    $app->storeInvoice($parsed);
    $id = $app->state($v, $b)['invoices'][0]['id'];
    $payment = $app->startInvoice($v, ['request_id' => App::id(), 'invoice_id' => $id]);
    check($http->checkout['total_amount'] === ['currency' => 'EUR', 'minor_unit' => 2, 'value' => 4250], 'Falscher Betrag an SumUp');
    check($http->checkout['description'] === 'Rechnung RE2026-0042', 'Rechnungsnummer nicht als SumUp-Beschreibung');
    check($http->checkout['affiliate']['foreign_transaction_id'] === $payment['reference'], 'Neutrale Referenz fehlt');
    check(!str_contains(json_encode($http->checkout, JSON_UNESCAPED_UNICODE), 'Musterfrau') && !str_contains(json_encode($http->checkout), '1980'), 'Patientendaten an SumUp gesendet');
    check($app->poll($v, $payment['id'])['payment_status'] === 'successful', 'Erfolg nicht übernommen');

    // 5. Original-PDF in den Netzwerkordner (Steuerberatung).
    $pdf = "%PDF-1.6\nTestrechnung\n";
    check(InvoiceExport::filename(['invoice_number' => 'RE2026/0042', 'invoice_date' => '2026-03-15', 'amount_cents' => 123450], true) === '2026-03-15_RE2026_0042_1234,50EUR_Karte.pdf', 'Dateiname falsch');
    $bad = configuration()->values; $bad['invoice_export'] = ['enabled' => true, 'share' => '//nas/steuer', 'directory' => '../etc', 'when' => 'paid'];
    try { new Config($bad); throw new RuntimeException('Pfad mit .. akzeptiert'); } catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'directory'), 'Falsche Ablehnung: ' . $e->getMessage()); }
    $bad['invoice_export']['directory'] = ''; $bad['invoice_inbox']['enabled'] = false;
    try { new Config($bad); throw new RuntimeException('Ablage ohne Rechnungseingang akzeptiert'); } catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'Rechnungseingang'), 'Falsche Ablehnung: ' . $e->getMessage()); }
    // a) Nur bezahlte Rechnungen, mit Anmeldung; erster Versuch scheitert.
    $cfg = exportConfig('paid', 'praxis'); $dirs[] = $cfg->get('app', 'state_dir'); $export = new FakeExport($cfg);
    $app = new App($cfg, null, null, null, $export); $app->db->migrate();
    [$v, $b] = visit($app);
    $app->storeInvoice($parsed, $pdf);
    check($export->commands === [], 'Unbezahlte Rechnung im Modus paid abgelegt');
    check($app->db->one('SELECT pdf FROM invoice_inbox')['pdf'] === $pdf, 'Original-PDF nicht gespeichert');
    $payment = $app->startInvoice($v, ['request_id' => App::id(), 'invoice_id' => $app->state($v, $b)['invoices'][0]['id']]);
    rejects(fn() => $app->invoiceExport($v, $payment['id']), 'Ablage vor erfolgreicher Zahlung', 409);
    $app->mock($v, ['payment_id' => $payment['id'], 'status' => 'successful']);
    $export->failures = 1;
    $state = $app->invoiceExport($v, $payment['id'])['invoice_export'];
    check($state['status'] === 'failed' && str_contains($state['error'], 'NT_STATUS_ACCESS_DENIED'), 'Fehlgeschlagene Ablage nicht gemeldet');
    check($app->db->one('SELECT pdf FROM invoice_inbox')['pdf'] === $pdf, 'PDF nach Fehler verworfen');
    $done = $app->document($v, $payment['id']);
    check($done['doc_status'] === 'written' && $done['invoice_export']['status'] === 'done', 'Ablage beim Abschluss nicht nachgeholt');
    check($done['invoice_export']['path'] === 'Rechnungen/2026/03/TEST_2026-03-15_RE2026-0042_42,50EUR_Karte.pdf', 'Falscher Ablagepfad');
    check(($export->files['TEST_2026-03-15_RE2026-0042_42,50EUR_Karte.pdf'] ?? null) === $pdf, 'PDF-Inhalt nicht unverändert abgelegt');
    check(in_array('mkdir "Rechnungen\\2026\\03"', $export->commands, true), 'Unterordner nicht angelegt');
    check(str_contains($export->auth[0], "username = praxis\npassword = geheim"), 'Anmeldedaten nicht übergeben');
    check($app->db->one('SELECT pdf FROM invoice_inbox')['pdf'] === null, 'PDF nach Ablage weiter gespeichert');
    $puts = count(array_filter($export->commands, fn($c) => str_contains($c, 'put ')));
    $app->invoiceExport($v, $payment['id']);
    check(count(array_filter($export->commands, fn($c) => str_contains($c, 'put '))) === $puts, 'Rechnung doppelt abgelegt');
    check(!str_contains(implode("\n", $export->commands), 'Musterfrau'), 'Patientendaten im Dateinamen');
    // Bar bezahlte T2med-Rechnung: dieselbe Ablage, Dateiname mit „_Bar“.
    [$vc, $bc] = visit($app, 'test-bar');
    $app->storeInvoice(['number' => 'RE2026-0077', 'amount_cents' => 1500] + $parsed, $pdf);
    $cashPay = $app->startInvoice($vc, ['request_id' => App::id(), 'invoice_id' => $app->state($vc, $bc)['invoices'][0]['id'], 'method' => 'cash']);
    $cashDone = $app->document($vc, $cashPay['id']);
    check($cashDone['invoice_export']['status'] === 'done' && $cashDone['invoice_export']['path'] === 'Rechnungen/2026/03/TEST_2026-03-15_RE2026-0077_15,00EUR_Bar.pdf', 'Barzahlung nicht oder falsch benannt abgelegt: ' . json_encode($cashDone['invoice_export']));
    check(InvoiceExport::filename(['invoice_number' => 'X1', 'invoice_date' => '2026-03-15', 'amount_cents' => 1000], 'Bar') === '2026-03-15_X1_10,00EUR_Bar.pdf', 'Dateiname Bar falsch');
    // b) Jede gedruckte Rechnung, Gastzugriff.
    $cfg = exportConfig('received'); $dirs[] = $cfg->get('app', 'state_dir'); $export = new FakeExport($cfg);
    $app = new App($cfg, null, null, null, $export); $app->db->migrate();
    $result = $app->storeInvoice($parsed, $pdf);
    check(str_contains($result['message'], 'im Netzwerkordner abgelegt') && isset($export->files['TEST_2026-03-15_RE2026-0042_42,50EUR.pdf']) && $export->auth === [], 'Eingang nicht sofort abgelegt');
    // c) Ablage aus: keine PDF aufbewahren.
    $cfg = configuration(); $dirs[] = $cfg->get('app', 'state_dir'); $app = new App($cfg); $app->db->migrate();
    $app->storeInvoice($parsed, $pdf);
    check($app->db->one('SELECT pdf FROM invoice_inbox')['pdf'] === null, 'PDF ohne aktivierte Ablage gespeichert');

    // 6. In der App erzeugte Rechnungen (GOÄ/Leistungsbeleg) ablegen, auch ohne Rechnungseingang.
    $appValues = function (bool $appInvoices, bool $practice = true, bool $inbox = false): Config {
        $v = configuration()->values;
        $v['invoice_inbox']['enabled'] = $inbox;
        $v['invoice_export'] = ['enabled' => true, 'share' => '//nas/steuerberater', 'directory' => 'Rechnungen/{jahr}', 'username' => '', 'password' => '',
            'domain' => '', 'when' => 'paid', 'app_invoices' => $appInvoices];
        $v['practice'] = $practice ? ['name' => 'Testpraxis', 'street' => 'Praxisstraße 1', 'city' => '12345 Musterstadt'] : [];
        return new Config($v);
    };
    $bad = configuration()->values; $bad['invoice_inbox']['enabled'] = false;
    $bad['invoice_export'] = ['enabled' => true, 'share' => '//nas/steuer', 'directory' => '', 'when' => 'paid', 'app_invoices' => false];
    try { new Config($bad); throw new RuntimeException('Ablage ohne Quelle akzeptiert'); } catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'app_invoices'), 'Falsche Ablehnung: ' . $e->getMessage()); }
    $paidLocal = function (App $app) {
        [$v, $b] = visit($app);
        $app->saveService($v, ['label' => 'Beratung', 'price' => '10,72', 'goae_code' => '1', 'factor' => '2,3']);
        $service = $app->db->one("SELECT id FROM services WHERE label='Beratung'")['id'];
        $payment = $app->start($v, ['request_id' => App::id(), 'services' => [$service], 'groups' => [], 'reasons' => [], 'manual_amount' => '', 'service_date' => date('Y-m-d')]);
        $app->mock($v, ['payment_id' => $payment['id'], 'status' => 'successful']);
        return [$v, $payment['id']];
    };
    $cfg = $appValues(true); $dirs[] = $cfg->get('app', 'state_dir'); $export = new FakeExport($cfg);
    $app = new App($cfg, null, null, null, $export); $app->db->migrate();
    [$v, $id] = $paidLocal($app);
    $export->failures = 1;
    $state = $app->invoiceExport($v, $id)['invoice_export'];
    check($state['status'] === 'failed', 'Fehlgeschlagene Ablage der App-Rechnung nicht gemeldet');
    // Fenster geschlossen, NAS wieder da: der stündliche Timer holt die Ablage nach.
    $pending = $app->exportPending();
    check($pending['done'] === 1 && $pending['failed'] === 0, 'Nachholung der App-Rechnung fehlgeschlagen');
    $tx = $app->db->one('SELECT transaction_id FROM payments WHERE id=?', [$id])['transaction_id'];
    $name = 'TEST_' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d') . '_' . $tx . '_10,72EUR_Karte.pdf';
    check(isset($export->files[$name]) && str_starts_with($export->files[$name], '%PDF-'), 'App-Rechnung nicht als PDF abgelegt: ' . implode(', ', array_keys($export->files)));
    check(in_array('mkdir "Rechnungen\\' . date('Y') . '"', $export->commands, true), 'Jahresordner nicht angelegt');
    $puts = count(array_filter($export->commands, fn($c) => str_contains($c, 'put ')));
    $app->exportPending(); $done = $app->document($v, $id);
    check($done['invoice_export']['status'] === 'done' && count(array_filter($export->commands, fn($c) => str_contains($c, 'put '))) === $puts, 'App-Rechnung doppelt abgelegt');
    // Abschluss ohne vorherige Ablage legt ebenfalls ab.
    [$v, $id] = $paidLocal($app);
    check($app->document($v, $id)['invoice_export']['status'] === 'done', 'App-Rechnung beim Abschluss nicht abgelegt');
    // Abfrage verneint: App-Rechnungen bleiben unberührt.
    $cfg = $appValues(false, true, true); $dirs[] = $cfg->get('app', 'state_dir');
    $export = new FakeExport($cfg); $app = new App($cfg, null, null, null, $export); $app->db->migrate();
    [$v, $id] = $paidLocal($app);
    check($app->document($v, $id)['invoice_export'] === null && $export->commands === [], 'App-Rechnung trotz app_invoices = false abgelegt');
    rejects(fn() => $app->invoiceExport($v, $id), 'Ablage trotz app_invoices = false', 409);
    // Ohne Praxisdaten entsteht keine App-Rechnung (SumUp-Beleg) und es wird nichts abgelegt.
    $cfg = $appValues(true, false); $dirs[] = $cfg->get('app', 'state_dir'); $export = new FakeExport($cfg);
    $app = new App($cfg, null, null, null, $export); $app->db->migrate();
    [$v, $b] = visit($app);
    $payment = $app->start($v, ['request_id' => App::id(), 'services' => [], 'groups' => [], 'reasons' => [], 'manual_amount' => '12,00', 'service_date' => date('Y-m-d')]);
    $app->mock($v, ['payment_id' => $payment['id'], 'status' => 'successful']);
    check($app->document($v, $payment['id'])['invoice_export'] === null && $export->commands === [], 'Zahlung ohne App-Rechnung abgelegt');

    echo "OK: T2med-Rechnung lesen, Patientenabgleich, Ersetzen, Doppelzahlungsschutz, Akteneintrag, neutrale SumUp-Daten und Ablage von T2med- und App-Rechnungen im Netzwerkordner.\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) { array_map('unlink', glob($dir . '/*') ?: []); @rmdir($dir); }
}
