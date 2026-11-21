<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Rechnung (Original-PDF aus T2med) per Post über Letterxpress versenden (REST-API v3).
 * Die Empfängeradresse liest Letterxpress aus dem Adressfenster des PDFs; an die API gehen nur
 * das PDF, die Druckoptionen und eine Notiz mit der Rechnungsnummer (kein Patientenname).
 */
final class Letterxpress {
    public const BASE = 'https://api.letterxpress.de/v3';
    public function __construct(private Config $config, private HttpClient $http, private Database $db) {}

    public function enabled(): bool { return (bool)$this->config->get('letterxpress', 'enabled', false); }
    public function mode(): string { return (string)$this->config->get('letterxpress', 'mode', 'test') === 'live' ? 'live' : 'test'; }

    private function auth(): array {
        return ['username' => (string)$this->config->get('letterxpress', 'username', ''), 'apikey' => (string)$this->config->get('letterxpress', 'api_key', ''), 'mode' => $this->mode()];
    }
    private function request(string $method, string $path, array $body): array {
        return $this->http->request($method, self::BASE . $path, ['Accept: application/json', 'Content-Type: application/json'], ['auth' => $this->auth()] + $body);
    }
    /** Fehlermeldung der API ohne Zugangsdaten, kurz und ohne Steuerzeichen. */
    /** T2med-PDFs tragen teils eine Rechte-Verschlüsselung (ohne Kennwort), die Letterxpress ablehnt: mit qpdf entfernen. */
    private static function unprotected(string $pdf): string {
        $binary = null;
        foreach (['/usr/bin/qpdf', '/usr/local/bin/qpdf', '/opt/homebrew/bin/qpdf'] as $path) if (is_executable($path)) { $binary = $path; break; }
        $file = $binary ? tempnam(sys_get_temp_dir(), 'ks-lxp-') : false;
        if (!$binary || $file === false) return $pdf;
        try {
            chmod($file, 0600);
            if (file_put_contents($file, $pdf) !== strlen($pdf)) return $pdf;
            $result = Command::run([$binary, '--decrypt', '--warning-exit-0', $file, '-'], '', 30);
            return $result['code'] === 0 && str_starts_with($result['output'], '%PDF-') ? $result['output'] : $pdf;
        } catch (\Throwable) { return $pdf; }
        finally { @unlink($file); }
    }

    private function reason(array $response): string {
        if ($response['status'] === 401 || $response['status'] === 403) return 'Letterxpress lehnt Benutzername oder API-Key ab.';
        $message = is_array($response['body']) && is_string($response['body']['message'] ?? null) ? preg_replace('/[\x00-\x1f\x7f]/u', ' ', $response['body']['message']) : '';
        if (stripos($message, 'could not be determined') !== false) return 'Letterxpress findet im PDF keine lesbare Anschrift (Adressfenster der Rechnung) und kann Inland/Ausland nicht bestimmen. Anschrift im T2med-Rechnungsformular im Adressfenster prüfen oder unter Einstellungen die Versandart fest auf „Inland“ stellen. (HTTP ' . $response['status'] . ')';
        if (stripos($message, 'verschl') !== false) return 'Letterxpress lehnt das PDF ab (verschlüsselt oder nicht A4-Hochformat). Das PDF muss A4 hoch und ohne Kennwortschutz sein (Papierformat des Windows-Druckers „SumUp-Rechnung“ prüfen). (HTTP ' . $response['status'] . ')';
        return 'Letterxpress antwortet mit HTTP ' . $response['status'] . ($message !== '' ? ': ' . mb_substr($message, 0, 160) : '.');
    }

    /** Verbindungstest für die Einstellungsseite: Zugangsdaten und Guthaben. */
    public function check(): string {
        if (!$this->enabled()) throw new Problem('Letterxpress ist nicht eingeschaltet.', 409);
        try { $response = $this->request('GET', '/balance', []); }
        catch (TransportError $e) { throw new Problem($e->getMessage(), 502); }
        if ($response['status'] !== 200) throw new Problem($this->reason($response), 502);
        $data = is_array($response['body']['data'] ?? null) ? $response['body']['data'] : [];
        $balance = isset($data['balance']) && is_numeric($data['balance']) ? number_format((float)$data['balance'], 2, ',', '.') . ' ' . (($data['currency'] ?? 'EUR') === 'EUR' ? '€' : preg_replace('/[^A-Za-z]/', '', (string)$data['currency'])) : 'unbekannt';
        return 'Verbunden. Guthaben: ' . $balance . '. Betrieb: ' . ($this->mode() === 'live' ? 'Live (echter Postversand)' : 'Test (nichts wird verschickt)') . '.';
    }

    /** Status einer Rechnung für Anzeige: sent_at, mode, status, error. */
    /** Live gesendet oder unklar: kein zweiter Versand. */
    public static function locked(array $row): bool { return ($row['lxp_status'] ?? '') !== '' && ($row['lxp_mode'] ?? '') === 'live'; }

    public static function state(array $row): array {
        return ['locked' => self::locked($row), 'status' => (string)($row['lxp_status'] ?? ''), 'sent_at' => isset($row['lxp_sent_at']) ? (int)$row['lxp_sent_at'] : null,
            'mode' => (string)($row['lxp_mode'] ?? ''), 'job_id' => (string)($row['lxp_job_id'] ?? ''), 'error' => (string)($row['lxp_error'] ?? '')];
    }

    /**
     * Rechnung versenden. Eine live gesendete (oder in ihrem Ausgang unklare) Rechnung wird nie ein zweites Mal gesendet;
     * Testbriefe sperren nicht.
     * Bei unklarem Ausgang (Zeitüberschreitung) wird nichts wiederholt; der Postausgang bei Letterxpress zeigt den Stand.
     */
    public function send(string $inboxId): array {
        if (!$this->enabled()) throw new Problem('Der Versand per Post ist nicht eingeschaltet (Einstellungen → Letterxpress).', 409);
        if (!preg_match('/^[a-f0-9]{32}$/D', $inboxId)) throw new Problem('Ungültige Rechnung.', 422);
        return $this->db->locked('letterxpress-' . $inboxId, function () use ($inboxId): array {
            $row = $this->db->one('SELECT * FROM invoice_inbox WHERE id=?', [$inboxId]);
            if (!$row) throw new Problem('Diese Rechnung gibt es nicht (mehr).', 404);
            if ($this->db->one("SELECT 1 AS x FROM payments WHERE (inbox_id=? OR t2med_invoice=?) AND payment_status IN ('starting','pending','unknown','cancel_requested','successful') LIMIT 1", [$inboxId, $row['invoice_number']]))
                throw new Problem('Diese Rechnung ist bezahlt oder wird gerade kassiert und wird nicht mehr per Post verschickt.', 409);
            if (self::locked($row)) throw new Problem($row['lxp_status'] === 'unknown'
                ? 'Der Versand dieser Rechnung ist unklar. Bitte zuerst den Postausgang bei Letterxpress prüfen.' : 'Diese Rechnung wurde bereits per Post gesendet.', 409);
            if ($row['pdf'] === null || !str_starts_with((string)$row['pdf'], '%PDF-')) throw new Problem('Original-PDF liegt nicht mehr vor. Bitte die Rechnung in T2med erneut auf „SumUp-Rechnung“ drucken.', 409);
            $file = base64_encode(self::unprotected((string)$row['pdf']));
            $letter = ['base64_file' => $file, 'base64_file_checksum' => md5($file),
                'filename_original' => 'Rechnung-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $row['invoice_number']) . '.pdf',
                'specification' => ['color' => (string)$this->config->get('letterxpress', 'color', '1'),
                    'mode' => $this->config->get('letterxpress', 'duplex', false) ? 'duplex' : 'simplex',
                    'shipping' => (string)$this->config->get('letterxpress', 'shipping', 'auto')],
                'notice' => 'Rechnung ' . $row['invoice_number']];
            $registered = (string)$this->config->get('letterxpress', 'registered', 'none');
            if ($registered !== 'none') $letter['registered'] = $registered;
            try {
                $response = $this->request('POST', '/printjobs', ['letter' => $letter]);
            } catch (TransportError $e) {
                if (!$e->ambiguous) throw new Problem($e->getMessage(), 502);
                $this->db->query("UPDATE invoice_inbox SET lxp_status='unknown',lxp_mode=?,lxp_error=? WHERE id=?", [$this->mode(), 'Antwort blieb aus', $inboxId]);
                throw new Problem('Letterxpress hat nicht rechtzeitig geantwortet. Ob der Brief angenommen wurde, ist unklar: bitte den Postausgang bei Letterxpress prüfen und nicht erneut senden.', 502);
            }
            if ($response['status'] !== 200) throw new Problem($this->reason($response), 502);
            $id = $response['body']['data']['id'] ?? '';
            $job = is_scalar($id) ? preg_replace('/[^0-9A-Za-z_-]/', '', (string)$id) : '';
            $this->db->query("UPDATE invoice_inbox SET lxp_status='sent',lxp_job_id=?,lxp_sent_at=?,lxp_mode=?,lxp_error='' WHERE id=?", [$job, time(), $this->mode(), $inboxId]);
            return ['status' => 'sent', 'job_id' => $job, 'mode' => $this->mode(), 'message' => $this->mode() === 'live'
                ? 'Rechnung ' . $row['invoice_number'] . ' wurde an Letterxpress übergeben' . ($job !== '' ? ' (Auftrag ' . $job . ')' : '') . ' und wird per Post verschickt. Bei Letterxpress lässt sich der Auftrag noch etwa 15 Minuten im Postausgang ändern oder löschen.'
                : 'Testmodus: Rechnung ' . $row['invoice_number'] . ' liegt im Postausgang bei Letterxpress' . ($job !== '' ? ' (Auftrag ' . $job . ')' : '') . '. Es wird nichts gedruckt oder verschickt.'];
        });
    }
}
