<?php
declare(strict_types=1);
namespace KienzleSumup;

final class FhirClient {
    public const CONTEXT = 'https://fhir.t2med.de/identifier/kontext';
    public const PAYMENT = 'https://github.com/thomaskien/t2med-sumup/identifier/payment';
    public function __construct(private Config $config, private HttpClient $http) {}

    private function request(string $method, string $path, string $token, ?array $body = null, bool $patient = false): array {
        $headers = ['Accept: application/fhir+json', 'Content-Type: application/fhir+json',
            'Authorization: Bearer ' . $token, 'X-API-Key: ' . $this->config->get('fhir', 'api_key'),
            'X-TreatWarningAsError: true', 'Prefer: return=OperationOutcome', 'User-Agent: kienzle-sumup/' . trim(file_get_contents(dirname(__DIR__) . '/VERSION'))];
        if ($patient) $headers[] = 'X-FHIR-Profile: https://fhir.t2med.de/StructureDefinition/FhirApiPatient|1.0.0';
        return $this->http->request($method, rtrim($this->config->get('fhir', 'base_url'), '/') . $path, $headers, $body, $this->config->get('fhir', 'ca_file', ''));
    }
    public function patient(string $context, string $token): array {
        if ($this->config->get('fhir', 'mode') === 'mock') return ['id' => 'demo-' . $context, 'name' => 'Erika Musterfrau', 'birthdate' => '1980-04-12'];
        try {
            $result = $this->request('GET', '/Patient?' . http_build_query(['identifier' => self::CONTEXT . '|' . $context]), $token, null, true);
        } catch (TransportError $e) {
            throw new Problem('Patient konnte nicht aus t2med geladen werden: ' . $e->getMessage(), 502);
        }
        if ($result['status'] !== 200) throw new Problem('Patientenkontext konnte nicht aus t2med geladen werden. Bitte erneut aus t2med öffnen.', 502);
        $body = $result['body']; $patients = [];
        if (($body['resourceType'] ?? '') === 'Patient') $patients[] = $body;
        elseif (($body['resourceType'] ?? '') === 'Bundle') {
            foreach ($body['entry'] ?? [] as $entry) if (($entry['resource']['resourceType'] ?? '') === 'Patient') $patients[] = $entry['resource'];
        }
        if (count($patients) !== 1 || empty($patients[0]['id'])) throw new Problem('Der Patientenkontext ist nicht eindeutig.', 502);
        $p = $patients[0]; $name = $p['name'][0] ?? [];
        $label = trim(implode(' ', $name['given'] ?? []) . ' ' . ($name['family'] ?? ''));
        return ['id' => (string)$p['id'], 'name' => $label ?: 'Patient ' . $p['id'], 'birthdate' => $p['birthDate'] ?? ''];
    }
    public function resource(array $visit, array $payment): array {
        $zone = new \DateTimeZone($this->config->get('app', 'timezone'));
        $paid = (new \DateTimeImmutable('@' . $payment['paid_at']))->setTimezone($zone);
        $services = json_decode($payment['services_json'], true, 32, JSON_THROW_ON_ERROR);
        $lines = array_map(static fn (array $s): string => $s['label'] . ' ' . Money::format($s['price_cents']), $services);
        $text = 'Kartenzahlung – ' . Money::format($payment['amount_cents']) . "\n" . implode('; ', $lines) . "\n" .
            'Zahlung am ' . $paid->format('d.m.Y') . ' um ' . $paid->format('H:i') . ' Uhr über SumUp erfolgreich.' . "\n" . 'Transaktionsreferenz: ' . $payment['reference'];
        return ['resourceType' => 'Observation',
            'meta' => ['profile' => ['https://fhir.t2med.de/StructureDefinition/FhirApiObservationFreitext|1.0.0']],
            'identifier' => [['system' => self::CONTEXT, 'value' => $visit['context_id']], ['system' => self::PAYMENT, 'value' => $payment['id']]],
            'extension' => [['url' => 'https://fhir.t2med.de/StructureDefinition/FhirApiFreitextKuerzel', 'valueString' => $this->config->get('fhir', 'entry_code', 'ZAHLUNG')]],
            'status' => 'final', 'effectiveDateTime' => $paid->format(DATE_ATOM), 'valueString' => $text];
    }
    public function supportsConditionalCreate(string $token): bool {
        try {
            $result = $this->request('GET', '/metadata', $token);
            if ($result['status'] !== 200 || ($result['body']['resourceType'] ?? '') !== 'CapabilityStatement') return false;
            foreach ($result['body']['rest'] ?? [] as $rest) {
                if (($rest['mode'] ?? '') !== 'server') continue;
                foreach ($rest['resource'] ?? [] as $resource) {
                    if (($resource['type'] ?? '') === 'Observation' && ($resource['conditionalCreate'] ?? false) === true) return true;
                }
            }
        } catch (TransportError) { /* Gewöhnliches POST bleibt nutzbar. Unklare Wiederholungen bleiben gesperrt. */ }
        return false;
    }
    public function findPayment(array $visit, array $payment, string $token): ?string {
        $result = $this->request('GET', '/Observation?' . http_build_query(['identifier' => self::PAYMENT . '|' . $payment['id']]), $token);
        if ($result['status'] !== 200 || ($result['body']['resourceType'] ?? '') !== 'Bundle') throw new TransportError('Der vorhandene Akteneintrag konnte nicht geprüft werden.');
        $found = [];
        foreach ($result['body']['entry'] ?? [] as $entry) {
            $resource = $entry['resource'] ?? [];
            if (($resource['resourceType'] ?? '') !== 'Observation') continue;
            $matching = false; $context = false;
            foreach ($resource['identifier'] ?? [] as $id) {
                if (($id['system'] ?? '') === self::PAYMENT && ($id['value'] ?? '') === $payment['id']) $matching = true;
                if (($id['system'] ?? '') === self::CONTEXT && ($id['value'] ?? '') === $visit['context_id']) $context = true;
            }
            if ($matching && $context && !empty($resource['id']) && ($resource['valueString'] ?? null) === $this->resource($visit, $payment)['valueString']) $found[] = 'Observation/' . $resource['id'];
        }
        if (count($found) > 1) throw new TransportError('Mehrere passende Akteneinträge gefunden. Bitte in t2med prüfen.');
        // Ein leeres Ergebnis ist KEIN Beweis, dass ein vorheriger POST nicht noch verarbeitet wird.
        return $found[0] ?? null;
    }
    public function write(array $visit, array $payment, string $token, bool $conditional): string {
        $request = ['method' => 'POST', 'url' => 'Observation'];
        if ($conditional) $request['ifNoneExist'] = http_build_query(['identifier' => self::PAYMENT . '|' . $payment['id']]);
        $result = $this->request('POST', '', $token, ['resourceType' => 'Bundle', 'type' => 'transaction',
            'entry' => [['request' => $request, 'resource' => $this->resource($visit, $payment)]]]);
        $code = $result['status'];
        if ($code < 200 || $code >= 300) throw new TransportError('t2med hat die Dokumentation nicht bestätigt. (HTTP ' . $code . ')', $code >= 500 || $code === 408 || $code < 400, $code);
        $body = $result['body'];
        if (($body['resourceType'] ?? '') !== 'Bundle' || ($body['type'] ?? '') !== 'transaction-response' || count($body['entry'] ?? []) !== 1) throw new TransportError('t2med hat keine eindeutige Bestätigung geliefert.');
        $response = $body['entry'][0]['response'] ?? []; $status = (int)($response['status'] ?? 0);
        $error = false;
        foreach ($response['outcome']['issue'] ?? [] as $issue) if (in_array($issue['severity'] ?? '', ['fatal', 'error'], true)) $error = true;
        if ($status < 200 || $status >= 300 || $error) throw new TransportError('t2med hat den Akteneintrag abgelehnt. Bitte erneut versuchen.', !($status >= 400 && $status < 500 && $status !== 408), $status);
        $location = (string)($response['location'] ?? '');
        return preg_match('~(?:^|/)(Observation/[A-Za-z0-9.-]+)(?:/|$)~', $location, $m) ? $m[1] : '';
    }
}
