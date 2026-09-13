<?php
declare(strict_types=1);
namespace KienzleSumup;

final class App {
    public readonly Database $db;
    private SumUpClient $sumup;
    private FhirClient $fhir;
    public function __construct(public readonly Config $config, ?HttpClient $http = null) {
        umask(0077);
        $this->db = new Database($config->get('app', 'state_dir'));
        $http ??= new HttpClient();
        $this->sumup = new SumUpClient($config, $http);
        $this->fhir = new FhirClient($config, $http);
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
            if (!in_array(rtrim($url, '/'), $allowed, true)) throw new Problem('Diese t2med-Aufrufadresse ist nicht im Server-Installer freigegeben.');
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
                $this->db->query('UPDATE visits SET oauth_cipher=?, expires_at=?, patient_name=?, patient_birthdate=? WHERE id=?', [$this->encrypt($token), $expires, $patient['name'], $patient['birthdate'], $id]);
            } else {
                $this->db->query('INSERT INTO visits(id,context_id,patient_id,patient_name,patient_birthdate,oauth_cipher,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?)', [$id, $context, $patient['id'], $patient['name'], $patient['birthdate'], $this->encrypt($token), time(), $expires]);
            }
            $ticket = bin2hex(random_bytes(32));
            $this->db->query('INSERT INTO launch_tickets(hash,visit_id,expires_at) VALUES(?,?,?)', [hash('sha256', $ticket), $id, time() + 90]);
            // Fragment wird weder an Apache übertragen noch in Zugriffslogs gespeichert.
            return ['url' => rtrim($this->config->get('app', 'base_url'), '/') . '/#launch=' . $ticket];
        });
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
            'services' => $this->db->query('SELECT id,label,price_cents FROM services WHERE active=1 ORDER BY label COLLATE NOCASE')->fetchAll(),
            'payment' => $payment ? $this->publicPayment($payment) : null,
            'mock' => $this->config->get('sumup', 'mode') === 'mock',
            'fhir_mock' => $this->config->get('fhir', 'mode') === 'mock',
            'mock_document_fail' => (bool)$visit['mock_document_fail'],
            'max_amount_cents' => $this->config->get('app', 'max_amount_cents')];
    }
    private function editable(array $visit): void {
        if ($visit['completed_at'] !== null) throw new Problem('Dieser Vorgang ist bereits abgeschlossen.', 409);
    }
    public function saveService(array $visit, array $input): void {
        $this->editable($visit);
        $id = self::string($input, 'id', 32, false);
        $label = self::string($input, 'label', 160);
        $cents = Money::cents($input['price'] ?? '', $this->config->get('app', 'max_amount_cents'));
        if ($id === '') {
            $this->db->query('INSERT INTO services(id,label,price_cents,updated_at) VALUES(?,?,?,?)', [self::id(), $label, $cents, time()]);
        } else {
            if ($this->db->query('UPDATE services SET label=?,price_cents=?,updated_at=? WHERE id=? AND active=1', [$label, $cents, time(), $id])->rowCount() !== 1) throw new Problem('Leistung nicht gefunden.', 404);
        }
    }
    public function deleteService(array $visit, string $id): void {
        $this->editable($visit);
        $this->db->query('UPDATE services SET active=0,updated_at=? WHERE id=?', [time(), $id]);
    }
    public function start(array $visit, array $input): array {
        $request = self::string($input, 'request_id', 64);
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/D', $request)) throw new Problem('Ungültiger Vorgangsschlüssel.');
        $ids = $input['services'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100) throw new Problem('Ungültige Leistungsauswahl.');
        foreach ($ids as $id) if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new Problem('Ungültige Leistung.');
        if (count(array_unique($ids)) !== count($ids)) throw new Problem('Eine Leistung wurde mehrfach übergeben.');
        sort($ids);
        $manual = self::string($input, 'manual_amount', 16, false);
        $hash = hash('sha256', json_encode([$ids, $manual], JSON_THROW_ON_ERROR));
        return $this->db->locked('reader', function () use ($visit, $request, $hash, $ids, $manual): array {
            $existing = $this->db->one('SELECT * FROM payments WHERE request_id=?', [$request]);
            if ($existing) {
                if ($existing['visit_id'] !== $visit['id'] || $existing['request_hash'] !== $hash) throw new Problem('Der Vorgangsschlüssel gehört zu einer anderen Zahlung.', 409);
                return $this->publicPayment($existing);
            }
            $fresh = $this->db->one('SELECT * FROM visits WHERE id=?', [$visit['id']]);
            $this->editable($fresh);
            $pending = $this->db->one("SELECT * FROM payments WHERE visit_id=? AND payment_status IN ('starting','pending','unknown','cancel_requested','successful')", [$visit['id']]);
            if ($pending) return $this->publicPayment($pending);
            if ($this->db->one("SELECT id FROM payments WHERE payment_status IN ('starting','pending','unknown','cancel_requested')")) throw new Problem('Das Terminal ist noch mit einem anderen Vorgang belegt.', 409);
            $payment = $this->db->transaction(function () use ($visit, $request, $hash, $ids, $manual): array {
                $items = []; $amount = 0; $max = $this->config->get('app', 'max_amount_cents');
                foreach ($ids as $id) {
                    $service = $this->db->one('SELECT id,label,price_cents FROM services WHERE id=? AND active=1', [$id]);
                    if (!$service) throw new Problem('Eine ausgewählte Leistung wurde gelöscht. Bitte Auswahl aktualisieren.');
                    $items[] = $service; $amount += $service['price_cents'];
                }
                if ($manual !== '') {
                    $cents = Money::cents($manual, $max); $amount += $cents;
                    $items[] = ['id' => null, 'label' => 'Sonstiger Betrag', 'price_cents' => $cents];
                }
                if ($amount <= 0 || $amount > $max) throw new Problem('Bitte Leistungen oder einen zulässigen Betrag auswählen.');
                $id = self::id();
                $this->db->query('INSERT INTO payments(id,visit_id,request_id,request_hash,reference,reader_id,amount_cents,services_json,payment_status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
                    [$id, $visit['id'], $request, $hash, 'POS-' . strtoupper($id), $this->config->get('sumup', 'reader_id') ?: 'mock-reader', $amount, json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'starting', time()]);
                return $this->db->one('SELECT * FROM payments WHERE id=?', [$id]);
            });
            try {
                $result = $this->sumup->start($payment);
                // Ein zweites Browserfenster könnte während des Starts bereits einen
                // endgültigen Transaktionsstatus erhalten haben. Diesen nicht zurücksetzen.
                $this->db->query("UPDATE payments SET payment_status=CASE WHEN payment_status IN ('successful','failed','cancelled') THEN payment_status ELSE 'pending' END,checkout_id=?,client_transaction_id=?,error_message='' WHERE id=?", [$result['checkout_id'], $result['client_transaction_id'], $payment['id']]);
            } catch (TransportError $e) {
                $this->db->query("UPDATE payments SET payment_status=?,error_message=? WHERE id=? AND payment_status NOT IN ('successful','failed','cancelled')", [$e->ambiguous ? 'unknown' : 'failed', $e->getMessage(), $payment['id']]);
            }
            return $this->publicPayment($this->db->one('SELECT * FROM payments WHERE id=?', [$payment['id']]));
        });
    }
    private function payment(array $visit, string $id): array {
        return $this->db->one('SELECT * FROM payments WHERE id=? AND visit_id=?', [$id, $visit['id']]) ?? throw new Problem('Zahlung nicht gefunden.', 404);
    }
    public function poll(array $visit, string $id): array {
        return $this->db->locked('payment-' . $id, function () use ($visit, $id): array {
            $payment = $this->payment($visit, $id);
            if (!in_array($payment['payment_status'], ['successful', 'failed', 'cancelled'], true) && time() - $payment['checked_at'] >= 2) {
                $this->db->query('UPDATE payments SET checked_at=? WHERE id=?', [time(), $id]);
                try {
                    $result = $this->sumup->status($payment);
                    $status = $result['status'];
                    $this->db->query('UPDATE payments SET payment_status=?,paid_at=COALESCE(paid_at,?),transaction_id=COALESCE(?,transaction_id),error_message=? WHERE id=?',
                        [$status, $status === 'successful' ? time() : null, $result['transaction_id'] ?? null, $status === 'unknown' ? 'Zahlungsausgang noch ungeklärt. Bitte am Terminal prüfen; keinen neuen Vorgang starten.' : '', $id]);
                } catch (TransportError $e) {
                    $this->db->query('UPDATE payments SET error_message=? WHERE id=?', [$e->getMessage(), $id]);
                }
            }
            return $this->publicPayment($this->payment($visit, $id));
        });
    }
    public function cancel(array $visit, string $id): array {
        return $this->db->locked('reader', fn() => $this->db->locked('payment-' . $id, function () use ($visit, $id): array {
            $payment = $this->payment($visit, $id);
            if (in_array($payment['payment_status'], ['pending', 'starting', 'unknown', 'cancel_requested'], true)) {
                try {
                    $this->sumup->cancel($payment);
                    $this->db->query('UPDATE payments SET payment_status=?,error_message=? WHERE id=?', [$this->config->get('sumup', 'mode') === 'mock' ? 'cancelled' : 'cancel_requested', 'Abbruch angefragt; Ergebnis wird geprüft.', $id]);
                } catch (TransportError $e) {
                    $this->db->query('UPDATE payments SET error_message=? WHERE id=?', [$e->getMessage(), $id]);
                }
            }
            return $this->publicPayment($this->payment($visit, $id));
        }));
    }
    public function document(array $visit, string $id): array {
        return $this->db->locked('payment-' . $id, function () use ($visit, $id): array {
            $p = $this->payment($visit, $id);
            if ($p['doc_status'] === 'written') return $this->publicPayment($p);
            if ($p['payment_status'] !== 'successful') throw new Problem('Die Zahlung ist noch nicht als erfolgreich bestätigt.', 409);
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
            $this->db->query("UPDATE payments SET payment_status=?,paid_at=?,error_message='' WHERE id=?", [$status, $status === 'successful' ? time() : null, $id]);
        });
    }
    private function publicPayment(array $p): array {
        return array_intersect_key($p, array_flip(['id', 'amount_cents', 'currency', 'reference', 'payment_status', 'doc_status', 'paid_at', 'error_message']));
    }
}
