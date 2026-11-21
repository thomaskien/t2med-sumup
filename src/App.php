<?php
declare(strict_types=1);
namespace KienzleSumup;

final class App {
    public readonly Database $db;
    private SumUpClient $sumup;
    private FhirClient $fhir;
    private ReceiptMailer $mailer;
    private ReceiptPrinter $printer;
    private InvoiceExport $export;
    private OfficePrinter $office;
    private Letterxpress $letter;
    public function __construct(public readonly Config $config, ?HttpClient $http = null, ?ReceiptMailer $mailer = null, ?ReceiptPrinter $printer = null, ?InvoiceExport $export = null, ?OfficePrinter $office = null) {
        umask(0077);
        $this->db = new Database($config->get('app', 'state_dir'));
        $http ??= new HttpClient();
        $this->sumup = new SumUpClient($config, $http);
        $this->fhir = new FhirClient($config, $http);
        $this->mailer = $mailer ?? new ReceiptMailer($config);
        $this->printer = $printer ?? new ReceiptPrinter($config);
        $this->export = $export ?? new InvoiceExport($config);
        $this->office = $office ?? new OfficePrinter($config);
        $this->letter = new Letterxpress($config, $http, $this->db);
    }
    public static function id(): string { return bin2hex(random_bytes(16)); }
    public static function string(array $input, string $key, int $max = 200, bool $required = true): string {
        $value = $input[$key] ?? '';
        if (!is_string($value) || strlen($value) > $max || ($required && trim($value) === '') || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new Problem('Ungültige Eingabe: ' . $key);
        return trim($value);
    }
    private function encrypt(string $token): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($token, $nonce, hex2bin($this->config->get('app', 'encryption_key'))));
    }
    private function decrypt(string $cipher): string {
        $raw = base64_decode($cipher, true);
        if (!$raw || strlen($raw) < 40) throw new Problem('Sitzung abgelaufen. Bitte erneut aus t2med öffnen.', 401);
        $token = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), hex2bin($this->config->get('app', 'encryption_key')));
        if ($token === false) throw new Problem('Sitzung konnte nicht gelesen werden. Bitte erneut aus t2med öffnen.', 401);
        return $token;
    }
    public function launch(array $input): array {
        $context = self::string($input, 'context_id', 128);
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $context)) throw new Problem('Ungültiger t2med-Kontext.');
        $token = self::string($input, 'oauth_token', 16000, $this->config->get('fhir', 'mode') === 'live');
        $url = self::string($input, 'fhir_base_url', 1000, false);
        if ($this->config->get('fhir', 'mode') === 'live') {
            $allowed = array_map(static fn($u) => rtrim($u, '/'), $this->config->get('fhir', 'launch_urls', []));
            if (!in_array(rtrim($url, '/'), $allowed, true)) {
                $shown = array_map(self::diagnosticAddress(...), array_slice($allowed, 0, 5));
                throw new Problem("Die von t2med übergebene FHIR-Adresse ist nicht freigegeben.\n" .
                    'Von t2med (fhirBasisUrl): ' . self::diagnosticAddress($url) . "\n" .
                    'Im Server erlaubt (launch_urls): ' . implode(', ', $shown) . "\n" .
                    'Bitte [fhir].launch_urls in /etc/kienzle-sumup/kienzle-sumup.toml prüfen. Gemeint ist die FHIR-Adresse, nicht die Adresse der Zahlungswebseite.');
            }
        }
        $patient = $this->fhir->patient($context, $token);
        $expires = time() + $this->config->get('app', 'session_hours') * 3600;
        return $this->db->transaction(function () use ($context, $token, $patient, $expires): array {
            $this->db->query('DELETE FROM launch_tickets WHERE expires_at < ?', [time()]);
            $this->db->query('DELETE FROM browsers WHERE expires_at < ?', [time()]);
            $this->db->query("UPDATE visits SET oauth_cipher='' WHERE expires_at < ?", [time()]);
            // Gleicher Patient/Behandlungskontext: offene Zahlung beim erneuten Start fortsetzen.
            $visit = $this->db->one('SELECT * FROM visits WHERE context_id=? AND patient_id=? AND completed_at IS NULL ORDER BY created_at DESC LIMIT 1', [$context, $patient['id']]);
            $id = $visit['id'] ?? self::id();
            if ($visit) {
                $this->db->query('UPDATE visits SET oauth_cipher=?, expires_at=?, patient_name=?, patient_birthdate=?, patient_address=? WHERE id=?', [$this->encrypt($token), $expires, $patient['name'], $patient['birthdate'], json_encode($patient['address'] ?? [], JSON_THROW_ON_ERROR), $id]);
            } else {
                $this->db->query('INSERT INTO visits(id,context_id,patient_id,patient_name,patient_birthdate,patient_address,oauth_cipher,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?)', [$id, $context, $patient['id'], $patient['name'], $patient['birthdate'], json_encode($patient['address'] ?? [], JSON_THROW_ON_ERROR), $this->encrypt($token), time(), $expires]);
            }
            $ticket = bin2hex(random_bytes(32));
            $this->db->query('INSERT INTO launch_tickets(hash,visit_id,expires_at) VALUES(?,?,?)', [hash('sha256', $ticket), $id, time() + 90]);
            // Fragment wird weder an Apache übertragen noch in Zugriffslogs gespeichert.
            return ['url' => rtrim($this->config->get('app', 'base_url'), '/') . '/#launch=' . $ticket];
        });
    }
    private static function diagnosticAddress(string $url): string {
        // Nur reine HTTP(S)-Basisadressen zurückgeben; keine Zugangsdaten,
        // Queryparameter, Fragmente oder unkontrollierte Texte im Starterdialog.
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment']) || strlen($url) > 240 ||
            !preg_match('~^[A-Za-z0-9.\\[\\]:/_-]+$~D', $url)) {
            return '[keine reine HTTP(S)-Basisadresse; Inhalt ausgeblendet]';
        }
        return $url;
    }
    public function exchange(string $ticket, ?string $cookie): array {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) throw new Problem('Ungültiger Startlink.', 401);
        return $this->db->transaction(function () use ($ticket, $cookie): array {
            $row = $this->db->one('SELECT * FROM launch_tickets WHERE hash=? AND expires_at>=?', [hash('sha256', $ticket), time()]);
            if (!$row) throw new Problem('Startlink abgelaufen oder bereits verwendet. Bitte erneut aus t2med öffnen.', 401);
            $this->db->query('DELETE FROM launch_tickets WHERE hash=?', [$row['hash']]);
            $browser = $cookie ? $this->db->one('SELECT * FROM browsers WHERE hash=? AND expires_at>=?', [hash('sha256', $cookie), time()]) : null;
            if (!$browser) {
                $cookie = bin2hex(random_bytes(32));
                $browser = ['hash' => hash('sha256', $cookie), 'csrf' => bin2hex(random_bytes(32))];
                $this->db->query('INSERT INTO browsers(hash,csrf,expires_at) VALUES(?,?,?)', [$browser['hash'], $browser['csrf'], time() + $this->config->get('app', 'session_hours') * 3600]);
            }
            $this->db->query('INSERT OR IGNORE INTO visit_access(browser_hash,visit_id) VALUES(?,?)', [$browser['hash'], $row['visit_id']]);
            return ['cookie' => $cookie, 'csrf' => $browser['csrf'], 'visit_id' => $row['visit_id']];
        });
    }
    public function browser(?string $cookie): array {
        if (!$cookie || !preg_match('/^[a-f0-9]{64}$/D', $cookie)) throw new Problem('Bitte aus t2med öffnen.', 401);
        $browser = $this->db->one('SELECT * FROM browsers WHERE hash=? AND expires_at>=?', [hash('sha256', $cookie), time()]);
        if (!$browser) throw new Problem('Sitzung abgelaufen. Bitte erneut aus t2med öffnen.', 401);
        return $browser;
    }
    public function visit(array $browser, string $id): array {
        $visit = $this->db->one('SELECT v.* FROM visits v JOIN visit_access a ON a.visit_id=v.id WHERE a.browser_hash=? AND v.id=? AND v.expires_at>=?', [$browser['hash'], $id, time()]);
        if (!$visit) throw new Problem('Vorgang nicht verfügbar. Bitte erneut aus t2med öffnen.', 401);
        return $visit;
    }
    public function state(array $visit, array $browser): array {
        $payment = $this->db->one('SELECT * FROM payments WHERE visit_id=? ORDER BY rowid DESC LIMIT 1', [$visit['id']]);
        return ['visit_id' => $visit['id'], 'csrf' => $browser['csrf'],
            'patient' => ['name' => $visit['patient_name'], 'birthdate' => $visit['patient_birthdate']],
            'completed' => $visit['completed_at'] !== null,
            'services' => array_map(static fn($s) => ['vat' => (bool)$s['vat']] + $s, $this->db->query('SELECT id,label,price_cents,goae_code,factor,fee_type,on_request,vat,base_cents,default_reason FROM services WHERE active=1 ORDER BY label COLLATE NOCASE')->fetchAll()),
            'tax' => array_intersect_key(Tax::settings($this->config), ['small_business' => 1, 'rate' => 1, 'manual_vat' => 1]),
            'groups' => array_map(static fn($g) => ['id' => $g['id'], 'label' => $g['label'], 'services' => json_decode($g['service_ids_json'], true, 32, JSON_THROW_ON_ERROR)], $this->db->query('SELECT * FROM service_groups WHERE active=1 ORDER BY label COLLATE NOCASE')->fetchAll()),
            'local_receipts' => $this->config->practiceReady(),
            'fee_types' => ServiceCatalog::FEES,
            'today' => (new \DateTimeImmutable('now', new \DateTimeZone($this->config->get('app', 'timezone'))))->format('Y-m-d'),
            'payment' => $payment ? $this->publicPayment($payment) : null,
            'reader_payment' => $this->readerPayment($visit),
            'mock' => $this->config->get('sumup', 'mode') === 'mock',
            'fhir_mock' => $this->config->get('fhir', 'mode') === 'mock',
            'mail_enabled' => $this->config->get('mail', 'enabled', false),
            'printing_enabled' => $this->config->get('printing', 'enabled', false),
            'mock_document_fail' => (bool)$visit['mock_document_fail'],
            'invoices' => $this->openInvoices($visit),
            'letter' => ['enabled' => $this->letter->enabled(), 'mode' => $this->letter->mode()],
            'print' => $this->printSettings(),
            'max_amount_cents' => $this->config->get('app', 'max_amount_cents')];
    }
    private function editable(array $visit): void {
        if ($visit['completed_at'] !== null) throw new Problem('Dieser Vorgang ist bereits abgeschlossen.', 409);
    }
    public function saveService(array $visit, array $input): void { $this->editable($visit); (new Catalog($this->db, $this->config))->saveService($input); }
    public function deleteService(array $visit, string $id): void { $this->editable($visit); (new Catalog($this->db, $this->config))->deleteService($id); }
    public function saveGroup(array $visit, array $input): void { $this->editable($visit); (new Catalog($this->db, $this->config))->saveGroup($input); }
    public function deleteGroup(array $visit, string $id): void { $this->editable($visit); (new Catalog($this->db, $this->config))->deleteGroup($id); }
    public function start(array $visit, array $input): array {
        $request = self::string($input, 'request_id', 64);
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/D', $request)) throw new Problem('Ungültiger Vorgangsschlüssel.');
        $ids = ServiceCatalog::ids($input['services'] ?? []);
        $groups = ServiceCatalog::ids($input['groups'] ?? []);
        $reasons = $input['reasons'] ?? [];
        if (!is_array($reasons) || count($reasons) > 100) throw new Problem('Ungültige Begründungen.');
        foreach ($reasons as $key => $reason) {
            if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) throw new Problem('Ungültige Begründungszuordnung.');
            $reasons[$key] = self::string(['reason' => $reason], 'reason', 800, false);
        }
        ksort($reasons);
        $dateInput = self::string($input, 'service_date', 10, false);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($this->config->get('app', 'timezone'))))->format('Y-m-d');
        $date = $dateInput ?: $today;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > $today) throw new Problem('Bitte ein gültiges Leistungsdatum bis einschließlich heute angeben.');
        $manual = self::string($input, 'manual_amount', 16, false);
        $method = self::string($input, 'method', 8, false) ?: 'card';
        if (!in_array($method, ['card', 'cash'], true)) throw new Problem('Ungültige Zahlungsart.');
        // Alte gespeicherte Startanfragen behalten ihren bisherigen Idempotenzschlüssel.
        $payload = [$ids, $manual];
        if (array_key_exists('groups', $input) || array_key_exists('reasons', $input) || array_key_exists('service_date', $input)) $payload = [...$payload, $groups, $reasons, $dateInput];
        if ($method === 'cash') $payload[] = 'cash';
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return $this->db->locked('reader', function () use ($visit, $request, $hash, $ids, $manual, $groups, $reasons, $date, $input, $method): array {
            $existing = $this->db->one('SELECT * FROM payments WHERE request_id=?', [$request]);
            if ($existing) {
                if ($existing['visit_id'] !== $visit['id'] || $existing['request_hash'] !== $hash) throw new Problem('Der Vorgangsschlüssel gehört zu einer anderen Zahlung.', 409);
                return $this->publicPayment($existing);
            }
            $fresh = $this->db->one('SELECT * FROM visits WHERE id=?', [$visit['id']]);
            $this->editable($fresh);
            $pending = $this->db->one("SELECT * FROM payments WHERE visit_id=? AND payment_status IN ('starting','pending','unknown','cancel_requested','successful')", [$visit['id']]);
            if ($pending) return $this->publicPayment($pending);
            // Barzahlung braucht kein Terminal.
            if ($method === 'card' && $this->readerPayment($visit)) throw new Problem('Das Terminal ist belegt. Du kannst den anderen Vorgang unten prüfen oder abbrechen.', 409);
            $payment = $this->db->transaction(function () use ($fresh, $request, $hash, $ids, $manual, $groups, $reasons, $date, $input, $method): array {
                $visit = $fresh;
                $items = []; $amount = 0; $max = $this->config->get('app', 'max_amount_cents');
                $tax = Tax::settings($this->config);
                foreach ($groups as $groupId) {
                    $group = $this->db->one('SELECT service_ids_json FROM service_groups WHERE id=? AND active=1', [$groupId]);
                    if (!$group) throw new Problem('Eine ausgewählte Kombination wurde gelöscht. Bitte Auswahl aktualisieren.');
                    $ids = [...$ids, ...json_decode($group['service_ids_json'], true, 32, JSON_THROW_ON_ERROR)];
                }
                $ids = array_values(array_unique($ids)); sort($ids);
                if (count($ids) > 100) throw new Problem('Bitte höchstens 100 Einzelleistungen auswählen.');
                foreach ($reasons as $id => $_) if (!in_array($id, $ids, true)) throw new Problem('Eine Begründung gehört nicht zur Auswahl.');
                foreach ($ids as $id) {
                    $service = $this->db->one('SELECT id,label,price_cents,goae_code,factor,fee_type,on_request,vat FROM services WHERE id=? AND active=1', [$id]);
                    if (!$service) throw new Problem('Eine ausgewählte Leistung wurde gelöscht. Bitte Auswahl aktualisieren.');
                    // Steuersatz zum Zeitpunkt der Zahlung festhalten; spätere Änderungen betreffen alte Rechnungen nicht.
                    $service['vat_rate'] = Tax::rateFor($tax, (bool)$service['vat']); unset($service['vat']);
                    $service['reason'] = $reasons[$id] ?? '';
                    // Fest hinterlegte Begründung der Ziffer, wenn keine eigene eingegeben wurde.
                    if ($service['reason'] === '' && ServiceCatalog::needsReason($service)) $service['reason'] = (string)($this->db->one('SELECT default_reason FROM services WHERE id=?', [$id])['default_reason'] ?? '');
                    if (ServiceCatalog::needsReason($service) && $service['reason'] === '') throw new Problem('Bitte die Faktorsteigerung für „' . $service['label'] . '“ begründen.');
                    $items[] = $service; $amount += $service['price_cents'];
                }
                usort($items, static fn($a, $b) => strnatcasecmp($a['goae_code'] ?: $a['label'], $b['goae_code'] ?: $b['label']));
                if ($manual !== '') {
                    $cents = Money::cents($manual, $max); $amount += $cents;
                    $items[] = ['id' => null, 'label' => 'Sonstiger Betrag', 'price_cents' => $cents, 'vat_rate' => Tax::rateFor($tax, $tax['manual_vat'])];
                }
                if ($amount <= 0 || $amount > $max) throw new Problem('Bitte Leistungen oder einen zulässigen Betrag auswählen.');
                $hasGoae = count(array_filter($items, static fn($s) => ($s['goae_code'] ?? '') !== ''));
                if ($hasGoae && !$this->config->practiceReady()) throw new Problem('Für GOÄ-Belege bitte zuerst Praxisname und Anschrift in den Einstellungen eintragen.');
                $invoice = null;
                if ($method === 'cash' && !$this->config->practiceReady()) throw new Problem('Für Barzahlungen wird eine Rechnung erstellt. Bitte zuerst Praxisname und Anschrift in den Einstellungen eintragen.', 409);
                if ($this->config->practiceReady()) {
                    $practice = [];
                    foreach (['name','street','city','contact'] as $key) $practice[$key] = $this->config->get('practice', $key, '');
                    $invoice = json_encode(['title' => $hasGoae === count($items) ? 'PRIVATÄRZTLICHE RECHNUNG' : 'LEISTUNGSBELEG',
                        'timezone' => $this->config->get('app', 'timezone'), 'mock' => $this->config->get('sumup', 'mode') === 'mock',
                        'practice' => $practice, 'patient' => ['id' => $visit['patient_id'], 'name' => $visit['patient_name'], 'birthdate' => $visit['patient_birthdate'], 'address' => json_decode($visit['patient_address'], true, 32, JSON_THROW_ON_ERROR)],
                        'service_date' => $date, 'items' => $items, 'total_cents' => $amount,
                        'tax' => ['small_business' => $tax['small_business'], 'tax_id' => $tax['tax_id'], 'exempt_note' => $tax['exempt_note'], 'small_business_note' => $tax['small_business_note']]],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
                $id = self::id();
                if ($method === 'cash') {
                    // Bar: sofort bezahlt, eigene fortlaufende Rechnungsnummer je Jahr (Testmodus getrennt).
                    $this->db->query('INSERT INTO payments(id,visit_id,request_id,request_hash,reference,reader_id,amount_cents,services_json,payment_status,created_at,paid_at,transaction_id,invoice_json,payment_method) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$id, $visit['id'], $request, $hash, 'BAR-' . strtoupper($id), 'cash', $amount, json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'successful', time(), time(), $this->cashNumber(), $invoice, 'cash']);
                } else {
                    $this->db->query('INSERT INTO payments(id,visit_id,request_id,request_hash,reference,reader_id,amount_cents,services_json,payment_status,created_at,invoice_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                        [$id, $visit['id'], $request, $hash, 'POS-' . strtoupper($id), $this->config->get('sumup', 'reader_id') ?: 'mock-reader', $amount, json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'starting', time(), $invoice]);
                }
                $this->markAutoPrint($id, $input);
                return $this->db->one('SELECT * FROM payments WHERE id=?', [$id]);
            });
            return $payment['payment_method'] === 'cash' ? $this->publicPayment($payment) : $this->begin($payment);
        });
    }
    /** Nächste Rechnungsnummer für Barzahlungen: BAR-2026-0001 (im Testmodus TEST-BAR-…). Aufruf in der Transaktion. */
    private function cashNumber(): string {
        $zone = new \DateTimeZone($this->config->get('app', 'timezone'));
        $prefix = ($this->config->get('sumup', 'mode') === 'mock' ? 'TEST-BAR-' : 'BAR-') . (new \DateTimeImmutable('now', $zone))->format('Y') . '-';
        $last = 0;
        foreach ($this->db->query("SELECT transaction_id FROM payments WHERE payment_method='cash' AND transaction_id LIKE ?", [$prefix . '%'])->fetchAll(\PDO::FETCH_COLUMN) as $number) {
            $last = max($last, (int)substr($number, strlen($prefix)));
        }
        return $prefix . str_pad((string)($last + 1), 4, '0', STR_PAD_LEFT);
    }
    // Aufrufer hält die Terminalsperre; die Zahlung ist bereits als 'starting' gespeichert.
    private function begin(array $payment): array {
        try {
            $result = $this->sumup->start($payment);
            // Ein zweites Browserfenster könnte während des Starts bereits einen
            // endgültigen Transaktionsstatus erhalten haben. Diesen nicht zurücksetzen.
            $this->db->query("UPDATE payments SET payment_status=CASE WHEN payment_status IN ('successful','failed','cancelled') THEN payment_status ELSE 'pending' END,checkout_id=?,client_transaction_id=?,error_message='' WHERE id=?", [$result['checkout_id'], $result['client_transaction_id'], $payment['id']]);
        } catch (TransportError $e) {
            $this->db->query("UPDATE payments SET payment_status=?,error_message=? WHERE id=? AND payment_status NOT IN ('successful','failed','cancelled')", [$e->ambiguous ? 'unknown' : 'failed', $e->getMessage(), $payment['id']]);
        }
        return $this->publicPayment($this->db->one('SELECT * FROM payments WHERE id=?', [$payment['id']]));
    }

    /** Vom Druckserver: T2med-Rechnung (PDF) als offene Rechnung ablegen. */
    public function inboxReceive(string $pdf): array {
        if (!$this->config->get('invoice_inbox', 'enabled', false)) throw new Problem('Rechnungseingang ist deaktiviert.', 404);
        try {
            $invoice = T2medInvoice::fromPdf($pdf, $this->config->get('app', 'max_amount_cents'), $this->inboxLabels());
        } catch (Problem $e) {
            // Grund und maskierte Beschriftungszeilen merken (ohne Namen, Daten, PDF), damit neue Vorlagen einstellbar sind.
            (new InboxLog($this->config->get('app', 'state_dir')))->add($e->getMessage(), T2medInvoice::$lastSource, T2medInvoice::overview(T2medInvoice::$lastText));
            T2medInvoice::$lastText = '';
            throw $e;
        }
        T2medInvoice::$lastText = '';
        try {
            return $this->storeInvoice($invoice, $pdf);
        } catch (Problem $e) {
            (new InboxLog($this->config->get('app', 'state_dir')))->add($e->getMessage(), 'Rechnung ' . $invoice['number'], []);
            throw $e;
        }
    }

    /** Eigene Textschnipsel für die Rechnungserkennung. */
    private function inboxLabels(): array {
        $labels = [];
        foreach (T2medInvoice::LABEL_KEYS as $key) $labels[$key] = (array)$this->config->get('invoice_inbox', $key, []);
        return $labels;
    }

    /** Gelesene Rechnung speichern (getrennt vom PDF-Lesen, damit testbar). */
    public function storeInvoice(array $invoice, ?string $pdf = null): array {
        if (!$this->config->get('invoice_inbox', 'enabled', false)) throw new Problem('Rechnungseingang ist deaktiviert.', 404);
        // Die Original-PDF nur aufbewahren, wenn sie in den Netzwerkordner, zum Praxisdrucker oder per Post (Letterxpress) gehen soll.
        $keep = ($this->export->enabled() || $this->office->enabled() || $this->letter->enabled()) && $pdf !== null ? $pdf : null;
        $result = $this->db->transaction(function () use ($invoice, $keep): array {
            $this->purgeInbox();
            $number = $invoice['number'];
            // Eine bereits bezahlte oder gerade laufende Rechnung nie erneut anbieten.
            $paid = $this->db->one("SELECT payment_status FROM payments WHERE t2med_invoice=? AND payment_status IN ('starting','pending','unknown','cancel_requested','successful') LIMIT 1", [$number]);
            if ($paid) throw new Problem($paid['payment_status'] === 'successful' ? 'Rechnung ' . $number . ' ist bereits per Karte bezahlt.' : 'Rechnung ' . $number . ' wird gerade am Terminal kassiert.', 409);
            // Erneuter Druck derselben Rechnungsnummer ersetzt die ältere Fassung.
            $this->db->query('UPDATE invoice_inbox SET superseded_at=? WHERE invoice_number=? AND superseded_at IS NULL', [time(), $number]);
            $id = self::id();
            $stmt = $this->db->pdo->prepare('INSERT INTO invoice_inbox(id,invoice_number,invoice_date,amount_cents,patient_name,patient_birthdate,received_at,pdf) VALUES(?,?,?,?,?,?,?,?)');
            foreach ([$id, $number, $invoice['invoice_date'], $invoice['amount_cents'], $invoice['patient_name'], $invoice['patient_birthdate'], time()] as $i => $value) $stmt->bindValue($i + 1, $value);
            $stmt->bindValue(8, $keep, $keep === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
            $stmt->execute();
            // Antwort an den Druckserver ohne Patientendaten.
            return ['ok' => true, 'id' => $id, 'number' => $number, 'amount_cents' => $invoice['amount_cents'],
                'message' => 'Rechnung ' . $number . ' über ' . Money::format($invoice['amount_cents']) . ' liegt zum Kassieren bereit.'];
        });
        if ($keep !== null && $this->export->enabled() && $this->export->when() === 'received') {
            $status = $this->exportRow($result['id']);
            $result['message'] .= $status['status'] === 'done' ? ' PDF im Netzwerkordner abgelegt.' : ' PDF NICHT im Netzwerkordner abgelegt: ' . $status['error'];
        }
        unset($result['id']);
        return $result;
    }
    /** Original-PDF einer Rechnung ablegen; idempotent, Fehler werden gespeichert statt geworfen. */
    private function exportRow(string $inboxId): array {
        return $this->db->locked('invoice-export-' . $inboxId, function () use ($inboxId): array {
            $row = $this->db->one('SELECT * FROM invoice_inbox WHERE id=?', [$inboxId]);
            if (!$row) return ['status' => 'failed', 'error' => 'Rechnung nicht mehr vorhanden.', 'path' => ''];
            if ($row['export_status'] === 'done') return ['status' => 'done', 'error' => '', 'path' => $row['export_path']];
            if ($row['pdf'] === null) return ['status' => 'failed', 'error' => 'Original-PDF liegt nicht mehr vor. Bitte die Rechnung in T2med erneut drucken.', 'path' => ''];
            try {
                // Bei „nach Zahlung“ trägt der Dateiname die Zahlungsart (Karte oder Bar).
                $method = $this->db->one("SELECT payment_method FROM payments WHERE inbox_id=? AND payment_status='successful' ORDER BY paid_at DESC LIMIT 1", [$inboxId])['payment_method'] ?? 'card';
                $path = $this->export->export($row, $row['pdf'], $this->export->when() === 'paid' ? ($method === 'cash' ? 'Bar' : true) : false);
                // Nach erfolgreicher Ablage keine Kopie der PDF auf dem Anwendungsserver behalten.
                $this->db->query("UPDATE invoice_inbox SET export_status='done',export_error='',export_path=? WHERE id=?", [$path, $inboxId]);
                $this->releaseInboxPdf($inboxId);
                return ['status' => 'done', 'error' => '', 'path' => $path];
            } catch (Problem $e) {
                error_log('kienzle-sumup: Rechnungsablage: ' . $e->getMessage());
                $this->db->query("UPDATE invoice_inbox SET export_status='failed',export_error=? WHERE id=?", [$e->getMessage(), $inboxId]);
                return ['status' => 'failed', 'error' => $e->getMessage(), 'path' => ''];
            }
        });
    }
    /** Nach erfolgreicher Kartenzahlung: Rechnungs-PDF in den Netzwerkordner kopieren. */
    public function invoiceExport(array $visit, string $id): array {
        if (!$this->export->enabled()) throw new Problem('Ablage im Netzwerkordner ist deaktiviert.', 409);
        $payment = $this->payment($visit, $id);
        if ($payment['payment_status'] !== 'successful') throw new Problem('Ablage erst nach erfolgreicher Zahlung.', 409);
        if (!$this->exportPayment($payment, true)) throw new Problem('Für diese Zahlung ist keine Ablage vorgesehen.', 409);
        return $this->publicPayment($this->payment($visit, $id));
    }
    /** Legt die zur Zahlung gehörende Rechnung ab; false, wenn für diese Zahlung keine Ablage vorgesehen ist. */
    private function exportPayment(array $payment, bool $manual = false): bool {
        if (!$this->export->enabled() || $payment['payment_status'] !== 'successful') return false;
        if (!empty($payment['inbox_id'])) {
            // Im Modus received wird beim Eingang abgelegt; danach nur noch auf ausdrücklichen Wunsch wiederholen.
            if ($this->export->when() !== 'paid' && !$manual) return false;
            $this->exportRow($payment['inbox_id']); return true;
        }
        if (empty($payment['invoice_json']) || !$this->export->appInvoices()) return false;
        $this->exportAppInvoice($payment['id']); return true;
    }
    /** In der App erzeugte Rechnung/Leistungsbeleg ablegen; idempotent, Fehler werden gespeichert. */
    private function exportAppInvoice(string $paymentId): void {
        $this->db->locked('payment-export-' . $paymentId, function () use ($paymentId): void {
            $payment = $this->db->one('SELECT * FROM payments WHERE id=?', [$paymentId]);
            if (!$payment || $payment['export_status'] === 'done' || $payment['payment_status'] !== 'successful') return;
            try {
                $invoice = json_decode($payment['invoice_json'], true, 32, JSON_THROW_ON_ERROR);
                $paid = (new \DateTimeImmutable('@' . $payment['paid_at']))->setTimezone(new \DateTimeZone($invoice['timezone']));
                // Dieselbe Belegvariante wie PDF-Download, E-Mail und Direktdruck.
                $pdf = ReceiptPdf::convert($this->receiptSource($payment));
                $path = $this->export->export(['invoice_number' => (string)$payment['transaction_id'], 'invoice_date' => $paid->format('Y-m-d'),
                    'amount_cents' => $payment['amount_cents']], $pdf, ($payment['payment_method'] ?? 'card') === 'cash' ? 'Bar' : true);
                $this->db->query("UPDATE payments SET export_status='done',export_error='',export_path=? WHERE id=?", [$path, $paymentId]);
            } catch (Problem $e) {
                error_log('kienzle-sumup: Rechnungsablage: ' . $e->getMessage());
                $this->db->query("UPDATE payments SET export_status='failed',export_error=? WHERE id=?", [$e->getMessage(), $paymentId]);
            }
        });
    }
    /** Für die Kommandozeile: alle noch nicht abgelegten bezahlten Rechnungen nachholen. */
    public function exportPending(): array {
        if (!$this->export->enabled()) throw new Problem('Ablage im Netzwerkordner ist deaktiviert.', 409);
        $rows = $this->db->query("SELECT p.* FROM payments p LEFT JOIN invoice_inbox i ON i.id=p.inbox_id WHERE p.payment_status='successful'
            AND ((p.inbox_id IS NOT NULL AND i.export_status<>'done' AND i.pdf IS NOT NULL) OR (p.inbox_id IS NULL AND p.invoice_json IS NOT NULL AND p.export_status<>'done'))
            ORDER BY p.paid_at")->fetchAll();
        $result = ['done' => 0, 'failed' => 0, 'errors' => []];
        foreach ($rows as $payment) {
            if (!$this->exportPayment($payment, true)) continue;
            $state = $this->exportState($this->db->one('SELECT * FROM payments WHERE id=?', [$payment['id']]));
            if (($state['status'] ?? '') === 'done') $result['done']++;
            else { $result['failed']++; $result['errors'][] = ($payment['t2med_invoice'] ?: $payment['transaction_id']) . ': ' . ($state['error'] ?? ''); }
        }
        return $result;
    }
    private function purgeInbox(): void {
        $limit = time() - $this->config->get('invoice_inbox', 'max_age_days', 14) * 86400;
        // Nur nie kassierte Einträge löschen; Zahlungen behalten ihre Zuordnung.
        $this->db->query('DELETE FROM invoice_inbox WHERE (received_at < ? OR superseded_at < ?) AND id NOT IN (SELECT inbox_id FROM payments WHERE inbox_id IS NOT NULL)', [$limit, $limit]);
        // Bezahlte, aber nie abgeschlossene Vorgänge: Original-PDF nach Ablauf der Frist ebenfalls löschen.
        $this->db->query("UPDATE invoice_inbox SET pdf=NULL WHERE pdf IS NOT NULL AND received_at < ? AND id IN (SELECT inbox_id FROM payments WHERE inbox_id IS NOT NULL AND payment_status='successful')", [$limit]);
    }
    /** Offene Rechnungen genau dieses Patienten (Name und Geburtsdatum aus dem T2med-Kontext). */
    private function openInvoices(array $visit): array {
        if (!$this->config->get('invoice_inbox', 'enabled', false) || $visit['patient_birthdate'] === '') return [];
        $limit = time() - $this->config->get('invoice_inbox', 'max_age_days', 14) * 86400;
        $rows = $this->db->query("SELECT i.id,i.invoice_number,i.invoice_date,i.amount_cents,i.patient_name,i.patient_birthdate,i.lxp_status,i.lxp_sent_at,i.lxp_mode,i.lxp_job_id,i.lxp_error,i.printed_at,(i.pdf IS NOT NULL) AS has_pdf FROM invoice_inbox i WHERE i.superseded_at IS NULL AND i.received_at >= ? AND i.patient_birthdate=?
            AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.t2med_invoice=i.invoice_number AND p.payment_status IN ('starting','pending','unknown','cancel_requested','successful'))
            ORDER BY i.received_at DESC", [$limit, $visit['patient_birthdate']])->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            if (!T2medInvoice::matches($row, $visit['patient_name'], $visit['patient_birthdate'])) continue;
            $result[] = ['id' => $row['id'], 'number' => $row['invoice_number'], 'invoice_date' => $row['invoice_date'], 'amount_cents' => $row['amount_cents'], 'printed_at' => $row['printed_at'] !== null ? (int)$row['printed_at'] : null, 'letter' => Letterxpress::state($row) + ['postable' => (bool)$row['has_pdf']]];
        }
        return $result;
    }
    public function startInvoice(array $visit, array $input): array {
        if (!$this->config->get('invoice_inbox', 'enabled', false)) throw new Problem('Rechnungseingang ist deaktiviert.', 409);
        $request = self::string($input, 'request_id', 64);
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/D', $request)) throw new Problem('Ungültiger Vorgangsschlüssel.');
        $invoiceId = self::string($input, 'invoice_id', 32);
        if (!preg_match('/^[a-f0-9]{32}$/D', $invoiceId)) throw new Problem('Ungültige Rechnung.');
        $method = self::string($input, 'method', 8, false) ?: 'card';
        if (!in_array($method, ['card', 'cash'], true)) throw new Problem('Ungültige Zahlungsart.');
        $hash = hash('sha256', json_encode($method === 'cash' ? ['t2med_invoice', $invoiceId, 'cash'] : ['t2med_invoice', $invoiceId], JSON_THROW_ON_ERROR));
        return $this->db->locked('reader', function () use ($visit, $request, $hash, $invoiceId, $input, $method): array {
            $existing = $this->db->one('SELECT * FROM payments WHERE request_id=?', [$request]);
            if ($existing) {
                if ($existing['visit_id'] !== $visit['id'] || $existing['request_hash'] !== $hash) throw new Problem('Der Vorgangsschlüssel gehört zu einer anderen Zahlung.', 409);
                return $this->publicPayment($existing);
            }
            $fresh = $this->db->one('SELECT * FROM visits WHERE id=?', [$visit['id']]);
            $this->editable($fresh);
            $pending = $this->db->one("SELECT * FROM payments WHERE visit_id=? AND payment_status IN ('starting','pending','unknown','cancel_requested','successful')", [$visit['id']]);
            if ($pending) return $this->publicPayment($pending);
            if ($method === 'card' && $this->readerPayment($visit)) throw new Problem('Das Terminal ist belegt. Du kannst den anderen Vorgang unten prüfen oder abbrechen.', 409);
            $payment = $this->db->transaction(function () use ($fresh, $request, $hash, $invoiceId, $input, $method): array {
                // Erneut innerhalb der Transaktion prüfen: offen, aktuell und zum Patienten passend.
                $open = array_column($this->openInvoices($fresh), null, 'id');
                if (!isset($open[$invoiceId])) throw new Problem('Diese Rechnung ist nicht mehr offen oder gehört nicht zu diesem Patienten. Bitte Seite neu laden.', 409);
                $invoice = $this->db->one('SELECT * FROM invoice_inbox WHERE id=?', [$invoiceId]);
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $invoice['invoice_date'])->format('d.m.Y');
                $items = [['id' => null, 'label' => 'T2med-Rechnung ' . $invoice['invoice_number'] . ' vom ' . $date, 'price_cents' => $invoice['amount_cents']]];
                $id = self::id();
                $json = json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if ($method === 'cash') {
                    // Bar bezahlt: sofort erfasst (kein Terminal). Die T2med-Rechnung bleibt die Rechnung; es gibt keinen eigenen Beleg.
                    $this->db->query('INSERT INTO payments(id,visit_id,request_id,request_hash,reference,reader_id,amount_cents,services_json,payment_status,created_at,paid_at,transaction_id,invoice_json,inbox_id,t2med_invoice,payment_method) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$id, $fresh['id'], $request, $hash, 'BAR-' . strtoupper($id), $this->config->get('sumup', 'mode') === 'mock' ? 'mock-reader' : 'cash', $invoice['amount_cents'], $json,
                        'successful', time(), time(), $this->cashNumber(), null, $invoice['id'], $invoice['invoice_number'], 'cash']);
                } else {
                    $this->db->query('INSERT INTO payments(id,visit_id,request_id,request_hash,reference,reader_id,amount_cents,services_json,payment_status,created_at,invoice_json,inbox_id,t2med_invoice) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$id, $fresh['id'], $request, $hash, 'POS-' . strtoupper($id), $this->config->get('sumup', 'reader_id') ?: 'mock-reader', $invoice['amount_cents'],
                        $json, 'starting', time(), null, $invoice['id'], $invoice['invoice_number']]);
                }
                $this->markAutoPrint($id, $input);
                return $this->db->one('SELECT * FROM payments WHERE id=?', [$id]);
            });
            if ($method !== 'cash') return $this->begin($payment);
            $result = $this->publicPayment($payment);
            // Quittung automatisch auf dem Bondrucker; ein Druckfehler blockiert die Barzahlung nicht.
            if ($this->config->get('printing', 'enabled', false) && $this->config->get('printing', 'cash_receipt_auto', false)) {
                try { $r = $this->receiptPrint($fresh ?? $visit, ['payment_id' => $payment['id'], 'request_id' => 'quittung-' . $payment['id']]); $result['quittung'] = $r['status']; $result['quittung_message'] = $r['message']; }
                catch (\Throwable $e) { $result['quittung'] = 'failed'; $result['quittung_message'] = $e instanceof Problem ? $e->getMessage() : 'Quittung konnte nicht gedruckt werden.'; }
            }
            return $result;
        });
    }
    /** Offene T2med-Rechnung dieses Patienten per Post über Letterxpress versenden (nach Bestätigung an der Kasse). */
    public function invoiceLetter(array $visit, array $input): array {
        if (!$this->letter->enabled()) throw new Problem('Der Versand per Post ist nicht eingeschaltet (Einstellungen → Letterxpress).', 409);
        $invoiceId = self::string($input, 'invoice_id', 32);
        if (!preg_match('/^[a-f0-9]{32}$/D', $invoiceId)) throw new Problem('Ungültige Rechnung.');
        $fresh = $this->db->one('SELECT * FROM visits WHERE id=?', [$visit['id']]);
        $this->editable($fresh);
        $open = array_column($this->openInvoices($fresh), null, 'id');
        if (!isset($open[$invoiceId])) throw new Problem('Diese Rechnung ist nicht mehr offen oder gehört nicht zu diesem Patienten. Bitte Seite neu laden.', 409);
        return $this->letter->send($invoiceId);
    }
    /** „Später (Überweisung)“: Original-Rechnung einmal auf dem Praxisdrucker ausgeben (ohne Zahlung). */
    public function invoiceLater(array $visit, array $input): array {
        $invoiceId = self::string($input, 'invoice_id', 32);
        if (!preg_match('/^[a-f0-9]{32}$/D', $invoiceId)) throw new Problem('Ungültige Rechnung.');
        $fresh = $this->db->one('SELECT * FROM visits WHERE id=?', [$visit['id']]);
        $this->editable($fresh);
        $open = array_column($this->openInvoices($fresh), null, 'id');
        if (!isset($open[$invoiceId])) throw new Problem('Diese Rechnung ist nicht mehr offen oder gehört nicht zu diesem Patienten. Bitte Seite neu laden.', 409);
        $settings = $this->printSettings();
        if (!$settings['t2med_auto']) return ['printed' => false, 'printed_at' => null, 'message' => ''];
        return $this->db->locked('invoice-later-' . $invoiceId, function () use ($invoiceId, $settings): array {
            $row = $this->db->one('SELECT invoice_number,pdf,printed_at FROM invoice_inbox WHERE id=?', [$invoiceId]);
            if (!$row) throw new Problem('Diese Rechnung gibt es nicht (mehr).', 404);
            if ($row['printed_at'] !== null) return ['printed' => true, 'printed_at' => (int)$row['printed_at'], 'message' => 'Diese Rechnung wurde schon gedruckt.'];
            if ($row['pdf'] === null) throw new Problem('Original-PDF liegt nicht mehr vor. Bitte die Rechnung in T2med erneut auf „SumUp-Rechnung“ drucken.', 409);
            if ($this->config->get('sumup', 'mode') !== 'live') $info = 'Test: ' . $settings['office_label'] . ' (nichts gesendet)';
            else $info = $settings['office_label'] . ', Auftrag ' . $this->office->send($row['pdf'], 'Rechnung ' . $row['invoice_number']);
            $now = time();
            $this->db->query('UPDATE invoice_inbox SET printed_at=? WHERE id=?', [$now, $invoiceId]);
            return ['printed' => true, 'printed_at' => $now, 'message' => 'Rechnung ' . $row['invoice_number'] . ' gedruckt: ' . $info . '.'];
        });
    }
    private function payment(array $visit, string $id): array {
        return $this->db->one('SELECT * FROM payments WHERE id=? AND visit_id=?', [$id, $visit['id']]) ?? throw new Problem('Zahlung nicht gefunden.', 404);
    }
    public function receipt(array $visit, string $id): string {
        $payment = $this->payment($visit, $id);
        if ($payment['payment_status'] !== 'successful') throw new Problem('Ein Beleg ist erst nach bestätigter erfolgreicher Zahlung verfügbar.', 409);
        return ReceiptPdf::convert($this->receiptSource($payment));
    }
    private function receiptSource(array $payment): string {
        if (!$payment['invoice_json'] && ($payment['payment_method'] ?? 'card') === 'cash') return $this->cashQuittung($payment);
        if (!$payment['invoice_json']) return $this->sumup->receiptSource($payment);
        $invoice = json_decode($payment['invoice_json'], true, 32, JSON_THROW_ON_ERROR);
        $number = $payment['transaction_id'] ?? '';
        if ($number === '') throw new Problem('Die SumUp-Transaktions-ID für die Rechnungsnummer fehlt. Bitte den Zahlungsstatus prüfen.', 409);
        $paid = (new \DateTimeImmutable('@' . $payment['paid_at']))->setTimezone(new \DateTimeZone($invoice['timezone']));
        $invoice['number'] = $number; $invoice['issued_date'] = $paid->format('Y-m-d');
        $cash = ($payment['payment_method'] ?? 'card') === 'cash';
        return InvoiceReceipt::source($invoice, ['status' => $payment['payment_status'], 'paid_date' => $paid->format('d.m.Y H:i'),
            'reference' => $payment['reference'], 'transaction_id' => $number, 'mock' => $invoice['mock'], 'method' => $cash ? 'cash' : 'card'],
            !$cash && $this->config->get('practice', 'append_sumup_receipt', false) ? $this->sumup->receiptSource($payment) : null);
    }
    /** Quittung für eine bar bezahlte T2med-Rechnung (Betrag, Name, Datum, Rechnungsnummer, „bar erhalten“). */
    private function cashQuittung(array $payment): string {
        $zone = new \DateTimeZone($this->config->get('app', 'timezone'));
        $visit = $this->db->one('SELECT patient_name FROM visits WHERE id=?', [$payment['visit_id']]);
        $inbox = !empty($payment['inbox_id']) ? $this->db->one('SELECT invoice_date FROM invoice_inbox WHERE id=?', [$payment['inbox_id']]) : null;
        $date = $inbox && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', (string)$inbox['invoice_date'], $m) ? "$m[3].$m[2].$m[1]" : '';
        $practice = [];
        if ($this->config->practiceReady()) foreach (['name', 'street', 'city', 'contact'] as $key) $practice[$key] = $this->config->get('practice', $key, '');
        return InvoiceReceipt::quittung(['mock' => $this->config->get('sumup', 'mode') === 'mock', 'practice' => $practice, 'number' => (string)$payment['t2med_invoice'],
            'invoice_date' => $date, 'patient' => (string)($visit['patient_name'] ?? ''), 'amount_cents' => (int)$payment['amount_cents'],
            'paid_date' => (new \DateTimeImmutable('@' . $payment['paid_at']))->setTimezone($zone)->format('d.m.Y H:i')]);
    }
    public function receiptShare(array $visit, string $id): array {
        $payment = $this->payment($visit, $id);
        if (!$payment['invoice_json'] && ($payment['payment_method'] ?? 'card') === 'cash') throw new Problem('Für die Quittung gibt es keinen SumUp-Link.', 409);
        if ($payment['payment_status'] !== 'successful') throw new Problem('Ein Beleg ist erst nach bestätigter erfolgreicher Zahlung verfügbar.', 409);
        $url = $this->sumup->receiptLink($payment);
        $message = $url === '' ? ($this->config->get('sumup', 'mode') === 'mock'
            ? 'Im Testmodus werden Beleg und E-Mail-Versand nur simuliert.' : 'SumUp liefert für diese Zahlung keinen Originalbeleg-Link.') : '';
        $recipient = $this->receiptRecipient($visit, $id);
        return ['url' => $url, 'email' => $recipient['email'], 'message' => $recipient['message'] ?: $message];
    }
    public function receiptPrint(array $visit, array $input): array {
        if (!$this->config->get('printing', 'enabled', false)) throw new Problem('Direktdruck ist deaktiviert.', 409);
        $id = self::string($input, 'payment_id', 32);
        $request = self::string($input, 'request_id', 64);
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/D', $request)) throw new Problem('Ungültiger Druckschlüssel.');
        // Druckaufträge auf diesem Server nacheinander übergeben.
        return $this->db->locked('receipt-print', function () use ($visit, $id, $request): array {
            $payment = $this->payment($visit, $id);
            if ($payment['payment_status'] !== 'successful') throw new Problem('Drucken erst nach erfolgreicher Zahlung möglich.', 409);
            $old = $this->db->one('SELECT * FROM receipt_prints WHERE request_id=?', [$request]);
            if ($old && $old['payment_id'] !== $id) throw new Problem('Druckschlüssel gehört zu einer anderen Zahlung.', 409);
            if ($old) return $this->printResult($old['status']);
            $data = $this->printer->prepare($this->receiptSource($payment));
            $this->db->query("INSERT INTO receipt_prints(request_id,payment_id,status,created_at) VALUES(?,?,'submitting',?)", [$request, $id, time()]);
            $status = 'simulated';
            if ($this->config->get('sumup', 'mode') === 'live') {
                try { $this->printer->send($data); $status = 'submitted'; }
                catch (Problem $e) {
                    $this->db->query("UPDATE receipt_prints SET status='unknown' WHERE request_id=?", [$request]);
                    return ['status' => 'unknown', 'message' => $e->getMessage()];
                }
            }
            $this->db->query('UPDATE receipt_prints SET status=? WHERE request_id=?', [$status, $request]);
            return $this->printResult($status);
        });
    }
    private function printResult(string $status): array {
        return ['status' => $status, 'message' => match ($status) {
            'submitted' => 'Druckauftrag an die Druckerfreigabe übergeben.',
            'simulated' => 'Testdruck erfolgreich simuliert. Es wurde nichts an den Drucker gesendet.',
            default => 'Druckübergabe nicht bestätigt. Drucker und Warteschlange prüfen, bevor erneut gedruckt wird.',
        }];
    }
    public function receiptRecipient(array $visit, string $id): array {
        $payment = $this->payment($visit, $id);
        if ($payment['payment_status'] !== 'successful') throw new Problem('E-Mail-Adresse erst nach erfolgreicher Zahlung verfügbar.', 409);
        $email = ''; $message = '';
        if ($visit['oauth_cipher'] !== '') {
            try {
                $patient = $this->fhir->patient($visit['context_id'], $this->decrypt($visit['oauth_cipher']));
                if ($patient['id'] === $visit['patient_id']) $email = $patient['email'] ?? '';
                else $message = 'Die E-Mail-Adresse konnte nicht eindeutig dem Patienten zugeordnet werden. Bitte selbst eintragen.';
            } catch (Problem) { $message = 'Die E-Mail-Adresse konnte nicht aus t2med geladen werden. Bitte selbst eintragen.'; }
        }
        return ['email' => $email, 'message' => $message];
    }
    public function receiptEmail(array $visit, array $input): array {
        if (!$this->config->get('mail', 'enabled', false)) throw new Problem('E-Mail-Versand ist noch nicht eingerichtet (Einstellungen → E-Mail-Versand).', 409);
        $id = self::string($input, 'payment_id', 32);
        $email = self::string($input, 'email', 254);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Problem('Bitte eine gültige E-Mail-Adresse eingeben.');
        $request = self::string($input, 'request_id', 64);
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/D', $request)) throw new Problem('Ungültiger Versandschlüssel.');
        return $this->db->locked('receipt-email-' . $id, function () use ($visit, $id, $email, $request): array {
            $payment = $this->payment($visit, $id);
            if ($payment['payment_status'] !== 'successful') throw new Problem('E-Mail erst nach erfolgreicher Zahlung möglich.', 409);
            $old = $this->db->one('SELECT * FROM receipt_emails WHERE request_id=?', [$request]);
            if ($old && ($old['payment_id'] !== $id || $old['recipient'] !== $email)) throw new Problem('Versandschlüssel gehört zu einer anderen Nachricht.', 409);
            if ($old) return $this->mailResult($old['status']);
            $pdf = $this->receipt($visit, $id);
            $this->db->query("INSERT INTO receipt_emails(request_id,payment_id,recipient,status,created_at) VALUES(?,?,?,'sending',?)", [$request, $id, $email, time()]);
            $status = 'simulated';
            if ($this->config->get('sumup', 'mode') === 'live') {
                try { $this->mailer->send($email, $pdf, $payment['reference']); $status = 'sent'; }
                catch (Problem $e) {
                    $this->db->query("UPDATE receipt_emails SET status='unknown' WHERE request_id=?", [$request]);
                    return ['status' => 'unknown', 'message' => $e->getMessage()];
                }
            }
            $this->db->query('UPDATE receipt_emails SET status=? WHERE request_id=?', [$status, $request]);
            return $this->mailResult($status);
        });
    }
    private function mailResult(string $status): array {
        return ['status' => $status, 'message' => match ($status) {
            'sent' => 'E-Mail mit PDF-Anhang wurde an den Mailserver übergeben.',
            'simulated' => 'Testversand erfolgreich simuliert. Es wurde keine E-Mail versendet.',
            default => 'Der Versand wurde noch nicht bestätigt. Empfängerpostfach prüfen, bevor erneut gesendet wird.',
        }];
    }
    public function poll(array $visit, string $id): array {
        return $this->db->locked('payment-' . $id, function () use ($visit, $id): array {
            $payment = $this->payment($visit, $id);
            try { $this->refreshPayment($payment); } catch (TransportError) {}
            return $this->publicPayment($this->payment($visit, $id));
        });
    }
    // Aufrufer hält die Zahlungssperre. Vor einem Abbruch immer frisch abgleichen.
    private function refreshPayment(array $payment, bool $force = false): array {
        if (in_array($payment['payment_status'], ['successful', 'failed', 'cancelled'], true) || (!$force && time() - $payment['checked_at'] < 2)) return $payment;
        $id = $payment['id'];
        $this->db->query('UPDATE payments SET checked_at=? WHERE id=?', [time(), $id]);
        try {
            $result = $this->sumup->status($payment);
            $status = $result['status'];
            if ($payment['payment_status'] === 'cancel_requested' && $status === 'pending') $status = 'cancel_requested';
            $error = match ($status) {
                'unknown' => 'Zahlungsausgang noch ungeklärt. Bitte am Terminal prüfen; keinen neuen Vorgang starten.',
                'cancel_requested' => 'Abbruch angefragt; Ergebnis wird geprüft.',
                default => '',
            };
            $this->db->query('UPDATE payments SET payment_status=?,paid_at=COALESCE(paid_at,?),transaction_id=COALESCE(?,transaction_id),error_message=? WHERE id=?',
                [$status, $status === 'successful' ? ($result['paid_at'] ?? time()) : null, $result['transaction_id'] ?? null, $error, $id]);
        } catch (TransportError $e) {
            $this->db->query('UPDATE payments SET error_message=? WHERE id=?', [$e->getMessage(), $id]);
            throw $e;
        }
        return $this->db->one('SELECT * FROM payments WHERE id=?', [$id]);
    }
    private function cancelPayment(array $payment): array {
        try {
            $payment = $this->refreshPayment($payment, true);
            if (in_array($payment['payment_status'], ['starting', 'pending', 'unknown', 'cancel_requested'], true)) {
                $this->sumup->cancel($payment);
                $mock = $this->config->get('sumup', 'mode') === 'mock';
                $this->db->query('UPDATE payments SET payment_status=?,error_message=? WHERE id=?',
                    [$mock ? 'cancelled' : 'cancel_requested', $mock ? '' : 'Abbruch angefragt; Ergebnis wird geprüft.', $payment['id']]);
            }
        } catch (TransportError $e) {
            $this->db->query('UPDATE payments SET error_message=? WHERE id=?', [$e->getMessage(), $payment['id']]);
        }
        return $this->db->one('SELECT * FROM payments WHERE id=?', [$payment['id']]);
    }
    public function cancel(array $visit, string $id): array {
        return $this->db->locked('reader', fn() => $this->db->locked('payment-' . $id, function () use ($visit, $id): array {
            return $this->publicPayment($this->cancelPayment($this->payment($visit, $id)));
        }));
    }
    private function readerPayment(array $visit): ?array {
        // Nur neutrale Zahlungsdaten des blockierenden Vorgangs, keine Patientendaten.
        return $this->db->one("SELECT id,amount_cents,reference,payment_status,error_message FROM payments WHERE reader_id=? AND visit_id<>? AND payment_status IN ('starting','pending','unknown','cancel_requested')",
            [$this->config->get('sumup', 'reader_id') ?: 'mock-reader', $visit['id']]);
    }
    public function readerAction(array $visit, string $id, bool $cancel = false): array {
        return $this->db->locked('reader', fn() => $this->db->locked('payment-' . $id, function () use ($visit, $id, $cancel): array {
            $this->editable($this->db->one('SELECT * FROM visits WHERE id=?', [$visit['id']]));
            $payment = $this->db->one('SELECT * FROM payments WHERE id=? AND visit_id<>? AND reader_id=?',
                [$id, $visit['id'], $this->config->get('sumup', 'reader_id') ?: 'mock-reader']);
            if (!$payment) throw new Problem('Dieser Terminalvorgang ist nicht verfügbar.', 404);
            // Niemals einen neueren Vorgang abbrechen, wenn die angezeigte ID veraltet ist.
            if ($cancel) $payment = $this->cancelPayment($payment);
            else {
                try { $payment = $this->refreshPayment($payment); }
                catch (TransportError) { $payment = $this->db->one('SELECT * FROM payments WHERE id=?', [$id]); }
            }
            $current = $this->readerPayment($visit);
            $message = match ($payment['payment_status']) {
                'successful' => 'Die andere Zahlung war bereits erfolgreich. Bitte im zugehörigen Vorgang in der Akte dokumentieren.',
                'cancelled' => 'Der andere Vorgang wurde abgebrochen.',
                'failed' => 'Die andere Zahlung ist nicht erfolgt.',
                default => $payment['error_message'] ?: 'Am Terminal läuft noch der andere Vorgang.',
            };
            if (!$current) $message .= ' Du kannst jetzt die neue Zahlung starten.';
            elseif ($current['id'] !== $id) $message .= ' Inzwischen ist ein weiterer Vorgang am Terminal aktiv.';
            return ['reader_payment' => $current, 'message' => $message];
        }));
    }
    public function document(array $visit, string $id): array {
        return $this->db->locked('payment-' . $id, function () use ($visit, $id): array {
            $p = $this->payment($visit, $id);
            if ($p['doc_status'] === 'written') return $this->publicPayment($p);
            if ($p['payment_status'] !== 'successful') throw new Problem('Die Zahlung ist noch nicht als erfolgreich bestätigt.', 409);
            if ($this->config->get('fhir', 'mode') === 'live' && $p['invoice_json'] && json_decode($p['invoice_json'], true, 32, JSON_THROW_ON_ERROR)['mock']) throw new Problem('Ein Testbeleg darf nicht in eine echte Patientenakte geschrieben werden.', 409);
            $token = $this->decrypt($visit['oauth_cipher']);
            $conditional = false;
            if ($this->config->get('fhir', 'mode') === 'live') {
                $conditional = $this->fhir->supportsConditionalCreate($token);
                if (in_array($p['doc_status'], ['writing', 'unknown'], true)) {
                    try {
                        $found = $this->fhir->findPayment($visit, $p, $token);
                        if ($found) return $this->finish($p, $found);
                    } catch (TransportError) { /* Nur ein garantiert bedingtes POST darf noch schreiben. */ }
                    if (!$conditional) {
                        $this->db->query("UPDATE payments SET doc_status='unknown',error_message=? WHERE id=?", ['Der letzte Schreibversuch ist ungeklärt. Bitte Akte prüfen. Erneutes Klicken prüft zunächst auf den vorhandenen Eintrag.', $id]);
                        return $this->publicPayment($this->payment($visit, $id));
                    }
                }
            }
            $this->db->query("UPDATE payments SET doc_status='writing',error_message='' WHERE id=?", [$id]);
            try {
                if ($this->config->get('fhir', 'mode') === 'mock') {
                    if ($visit['mock_document_fail']) throw new TransportError('Simulierter t2med-Fehler. Testfehler ausschalten und erneut klicken.', false, 503);
                    $this->db->query('INSERT OR IGNORE INTO mock_records(payment_id,resource_json,created_at) VALUES(?,?,?)', [$id, json_encode($this->fhir->resource($visit, $p), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), time()]);
                    $resource = 'MockObservation/' . $id;
                } else $resource = $this->fhir->write($visit, $p, $token, $conditional);
                return $this->finish($p, $resource);
            } catch (TransportError $e) {
                $this->db->query('UPDATE payments SET doc_status=?,error_message=? WHERE id=?', [$e->ambiguous ? 'unknown' : 'failed', $e->getMessage(), $id]);
                return $this->publicPayment($this->payment($visit, $id));
            }
        });
    }
    private function finish(array $payment, string $resource): array {
        $this->db->transaction(function () use ($payment, $resource): void {
            $this->db->query("UPDATE payments SET doc_status='written',fhir_written_at=?,fhir_resource_id=?,error_message='' WHERE id=?", [time(), $resource, $payment['id']]);
            $this->db->query("UPDATE visits SET completed_at=?,oauth_cipher='' WHERE id=?", [time(), $payment['visit_id']]);
        });
        // Spätestens beim Abschluss die Rechnungs-PDF ablegen; ein Fehler blockiert die Dokumentation nicht.
        $this->exportPayment($this->db->one('SELECT * FROM payments WHERE id=?', [$payment['id']]));
        $this->autoPrint($payment['id']);
        if (!empty($payment['inbox_id'])) $this->releaseInboxPdf($payment['inbox_id']);
        return $this->publicPayment($this->db->one('SELECT * FROM payments WHERE id=?', [$payment['id']]));
    }
    public function mock(array $visit, array $input): void {
        if ($this->config->get('sumup', 'mode') !== 'mock') throw new Problem('Testmodus ist deaktiviert.', 403);
        $this->editable($visit);
        if (array_key_exists('document_fail', $input)) {
            if (!is_bool($input['document_fail'])) throw new Problem('Ungültige Testoption.');
            $this->db->query('UPDATE visits SET mock_document_fail=? WHERE id=?', [(int)$input['document_fail'], $visit['id']]); return;
        }
        $id = self::string($input, 'payment_id', 32);
        $status = self::string($input, 'status', 20);
        if (!in_array($status, ['successful', 'failed', 'cancelled'], true)) throw new Problem('Ungültiger Teststatus.');
        $this->db->locked('payment-' . $id, function () use ($visit, $id, $status): void {
            $p = $this->payment($visit, $id);
            if (!in_array($p['payment_status'], ['pending', 'starting', 'unknown', 'cancel_requested'], true)) throw new Problem('Testzahlung ist bereits abgeschlossen.', 409);
            $this->db->query("UPDATE payments SET payment_status=?,paid_at=?,transaction_id=?,error_message='' WHERE id=?", [$status, $status === 'successful' ? time() : null, $status === 'successful' ? 'TEST-' . $id : null, $id]);
        });
    }
    // --- Rechnung automatisch drucken ----------------------------------------------------
    /** Praxisweite Einstellung und verfügbare Drucker. */
    public function printSettings(): array {
        $office = $this->office->enabled(); $receipt = (bool)$this->config->get('printing', 'enabled', false);
        $rows = array_column($this->db->query("SELECT key,value FROM settings WHERE key IN ('auto_print','app_print_target')")->fetchAll(), 'value', 'key');
        $target = $rows['app_print_target'] ?? ($receipt ? 'receipt' : 'office');
        if ($target === 'receipt' && !$receipt) $target = 'office';
        if ($target === 'office' && !$office) $target = $receipt ? 'receipt' : 'office';
        $model = $this->config->get('printing', 'model', 'tm-m10');
        return ['office' => $office, 'receipt' => $receipt, 'auto_print' => ($rows['auto_print'] ?? '0') === '1' && ($office || $receipt),
            'app_target' => $target, 'office_label' => $office ? 'Praxisdrucker „' . $this->office->label() . '“' : '',
            'receipt_label' => $receipt ? (Config::PRINTER_MODELS[$model]['label'] ?? 'Bondrucker') : '',
            't2med_possible' => $office, 't2med_auto' => $office && (bool)$this->config->get('office_printing', 'invoice_auto', true), 'app_possible' => $this->config->practiceReady() && ($target === 'office' ? $office : $receipt)];
    }
    public function savePrintSettings(array $visit, array $input): array {
        $auto = $input['auto_print'] ?? null; $target = $input['app_target'] ?? null;
        if (!is_bool($auto) || !in_array($target, ['office', 'receipt'], true)) throw new Problem('Ungültige Druckeinstellung.');
        $settings = $this->printSettings();
        if (!$settings[$target]) throw new Problem($target === 'office' ? 'Der Praxisdrucker ist nicht eingerichtet.' : 'Der Bondrucker ist nicht eingerichtet.', 409);
        foreach (['auto_print' => $auto ? '1' : '0', 'app_print_target' => $target] as $key => $value) {
            $this->db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', [$key, $value, time()]);
        }
        return $this->printSettings();
    }
    /** Ziel für diese Zahlung: T2med-Rechnung immer Praxisdrucker, App-Rechnung laut Einstellung. */
    private function printTarget(array $payment): ?string {
        $settings = $this->printSettings();
        if (!empty($payment['inbox_id'])) return $settings['office'] ? 'office' : null;
        if (empty($payment['invoice_json'])) return null;   // ohne lokale Rechnung nur der SumUp-Beleg
        return $settings[$settings['app_target']] ? $settings['app_target'] : null;
    }
    /** Häkchen der Zahlungsseite; ohne Angabe gilt die praxisweite Einstellung. */
    private function markAutoPrint(string $paymentId, array $input): void {
        $payment = $this->db->one('SELECT * FROM payments WHERE id=?', [$paymentId]);
        // T2med-Rechnungen: Einstellung der Praxis (Einrichtung), keine Abfrage bei der Zahlung.
        if (!empty($payment['inbox_id'])) $wanted = $this->printSettings()['t2med_auto'];
        else $wanted = array_key_exists('auto_print', $input) ? $input['auto_print'] === true : $this->printSettings()['auto_print'];
        $this->db->query('UPDATE payments SET auto_print=? WHERE id=?', [$wanted && $this->printTarget($payment) ? 1 : 0, $paymentId]);
    }
    /** Druckt die Rechnung höchstens einmal automatisch; Fehler werden gespeichert, nicht geworfen. */
    private function autoPrint(string $paymentId, bool $again = false): void {
        $this->db->locked('invoice-print-' . $paymentId, function () use ($paymentId, $again): void {
            $payment = $this->db->one('SELECT * FROM payments WHERE id=?', [$paymentId]);
            if (!$payment || $payment['payment_status'] !== 'successful' || !$payment['auto_print']) return;
            if (!$again && in_array($payment['print_status'], ['done', 'simulated'], true)) return;
            // Bei „Später (Überweisung)“ schon gedruckte T2med-Rechnung nicht noch einmal drucken.
            if (!$again && !empty($payment['inbox_id']) && ($this->db->one('SELECT printed_at FROM invoice_inbox WHERE id=?', [$payment['inbox_id']])['printed_at'] ?? null) !== null) {
                $this->db->query("UPDATE payments SET print_status='done',print_error='',print_info=? WHERE id=?", ['Schon vor der Zahlung gedruckt.', $paymentId]);
                return;
            }
            $target = $this->printTarget($payment);
            try {
                if (!$target) throw new Problem('Kein passender Drucker eingerichtet.', 409);
                $title = 'Rechnung ' . ($payment['t2med_invoice'] ?: $payment['transaction_id']);
                if ($target === 'office') {
                    if (!empty($payment['inbox_id'])) {
                        $row = $this->db->one('SELECT pdf FROM invoice_inbox WHERE id=?', [$payment['inbox_id']]);
                        if (!$row || $row['pdf'] === null) throw new Problem('Original-PDF liegt nicht mehr vor. Bitte die Rechnung in T2med drucken.', 409);
                        $pdf = $row['pdf'];
                    } else {
                        $pdf = ReceiptPdf::convert(InvoiceReceipt::a4($this->receiptSource($payment)));
                    }
                    $data = null;
                } else {
                    $data = $this->printer->prepare($this->receiptSource($payment));
                }
                $label = $target === 'office' ? $this->printSettings()['office_label'] : $this->printSettings()['receipt_label'];
                if ($this->config->get('sumup', 'mode') !== 'live') {
                    // Testmodus: aufbereiten, aber nichts an den Drucker senden (wie beim Direktdruck).
                    $this->db->query("UPDATE payments SET print_status='simulated',print_error='',print_info=? WHERE id=?", ['Test: ' . $label, $paymentId]);
                } else {
                    $info = $target === 'office' ? $label . ', Auftrag ' . $this->office->send($pdf, $title) : $label;
                    // Bondrucker: wie „Beleg drucken“ nacheinander übergeben.
                    if ($data !== null) $this->db->locked('receipt-print', fn() => $this->printer->send($data));
                    $this->db->query("UPDATE payments SET print_status='done',print_error='',print_info=? WHERE id=?", [$info, $paymentId]);
                }
            } catch (Problem $e) {
                error_log('kienzle-sumup: Rechnungsdruck: ' . $e->getMessage());
                $this->db->query("UPDATE payments SET print_status='failed',print_error=? WHERE id=?", [$e->getMessage(), $paymentId]);
            }
            if (!empty($payment['inbox_id'])) $this->releaseInboxPdf($payment['inbox_id']);
        });
    }
    /** Nach erfolgreicher Zahlung: automatisch bzw. auf Wunsch erneut drucken. */
    public function invoicePrint(array $visit, array $input): array {
        $payment = $this->payment($visit, self::string($input, 'payment_id', 32));
        if ($payment['payment_status'] !== 'successful') throw new Problem('Drucken erst nach erfolgreicher Zahlung.', 409);
        if (!$this->printTarget($payment)) throw new Problem('Für diese Zahlung ist kein Rechnungsdrucker eingerichtet.', 409);
        $again = ($input['again'] ?? false) === true;
        if (!$payment['auto_print']) { $this->db->query('UPDATE payments SET auto_print=1 WHERE id=?', [$payment['id']]); $again = true; }
        $this->autoPrint($payment['id'], $again);
        return $this->publicPayment($this->payment($visit, $payment['id']));
    }
    private function printState(array $p): ?array {
        if (empty($p['auto_print'])) return null;
        $target = $this->printTarget($p);
        return ['status' => ($p['print_status'] ?? '') ?: 'pending', 'error' => $p['print_error'] ?? '', 'info' => $p['print_info'] ?? '',
            'target' => $target, 'label' => $target ? $this->printSettings()[$target . '_label'] : ''];
    }
    /** Original-PDF löschen, sobald Ablage und Druck erledigt sind (bzw. nicht vorgesehen). */
    private function releaseInboxPdf(string $inboxId): void {
        $row = $this->db->one('SELECT export_status FROM invoice_inbox WHERE id=?', [$inboxId]);
        // Bis zum Abschluss (Akteneintrag) bleibt die PDF für Nachdrucke erhalten.
        $paid = $this->db->one("SELECT p.auto_print,p.print_status FROM payments p JOIN visits v ON v.id=p.visit_id WHERE p.inbox_id=? AND p.payment_status='successful' AND v.completed_at IS NOT NULL LIMIT 1", [$inboxId]);
        if (!$row || !$paid) return;
        $exportDone = !$this->export->enabled() || $row['export_status'] === 'done';
        $printDone = !$paid['auto_print'] || in_array($paid['print_status'], ['done', 'simulated'], true);
        if ($exportDone && $printDone) $this->db->query('UPDATE invoice_inbox SET pdf=NULL WHERE id=?', [$inboxId]);
    }
    private function publicPayment(array $p): array {
        return array_intersect_key($p, array_flip(['id', 'amount_cents', 'currency', 'reference', 'payment_status', 'doc_status', 'paid_at', 'error_message'])) +
            ['method' => $p['payment_method'] ?? 'card', 'local_receipt' => !empty($p['invoice_json']), 'invoice_number' => !empty($p['invoice_json']) && $p['payment_status'] === 'successful' ? $p['transaction_id'] : null,
             't2med_invoice' => $p['t2med_invoice'] ?? null, 'invoice_export' => $this->exportState($p), 'invoice_print' => $this->printState($p)];
    }
    private function exportState(array $p): ?array {
        if (!$this->export->enabled()) return null;
        if (!empty($p['inbox_id'])) $row = $this->db->one('SELECT export_status,export_error,export_path FROM invoice_inbox WHERE id=?', [$p['inbox_id']]);
        elseif (!empty($p['invoice_json']) && $this->export->appInvoices()) $row = $p;
        else return null;
        return $row ? ['status' => ($row['export_status'] ?? '') ?: 'pending', 'error' => $row['export_error'] ?? '', 'path' => $row['export_path'] ?? ''] : null;
    }
}
