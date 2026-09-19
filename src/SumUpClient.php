<?php
declare(strict_types=1);
namespace KienzleSumup;

final class SumUpClient {
    public function __construct(private Config $config, private HttpClient $http) {}
    private function request(string $method, string $path, ?array $body = null): array {
        return $this->http->request($method, 'https://api.sumup.com' . $path, [
            'Authorization: Bearer ' . $this->config->get('sumup', 'api_key'),
            'Accept: application/json', 'Content-Type: application/json',
        ], $body);
    }
    private function readerPath(array $payment): string {
        return '/v0.1/merchants/' . rawurlencode($this->config->get('sumup', 'merchant_code')) . '/readers/' . rawurlencode($payment['reader_id']);
    }
    public function start(array $payment): array {
        if ($this->config->get('sumup', 'mode') === 'mock') return ['checkout_id' => 'mock-' . $payment['id'], 'client_transaction_id' => 'mock-' . $payment['id']];
        $response = $this->request('POST', $this->readerPath($payment) . '/checkout', [
            'total_amount' => ['currency' => 'EUR', 'minor_unit' => 2, 'value' => $payment['amount_cents']],
            'description' => $payment['reference'],
            'affiliate' => ['app_id' => $this->config->get('sumup', 'app_id'),
                'key' => $this->config->get('sumup', 'affiliate_key'), 'foreign_transaction_id' => $payment['reference']],
        ]);
        $this->accepted($response, 'Zahlung konnte nicht am Terminal gestartet werden.');
        $data = $response['body']['data'] ?? null;
        if (!is_array($data) || !is_string($data['client_transaction_id'] ?? null) || $data['client_transaction_id'] === '') {
            throw new TransportError('Die Startantwort von SumUp ist unvollständig. Der Zahlungsstatus wird geprüft.');
        }
        return ['checkout_id' => $data['checkout_id'] ?? null, 'client_transaction_id' => $data['client_transaction_id']];
    }
    private function accepted(array $response, string $message): void {
        $code = $response['status'];
        if ($code < 200 || $code >= 300) throw new TransportError($message . " (HTTP $code)", $code >= 500 || $code === 408 || $code < 400, $code);
    }
    public function receipt(array $payment): string {
        return ReceiptPdf::convert($this->receiptSource($payment));
    }
    public function receiptSource(array $payment): string {
        if ($this->config->get('sumup', 'mode') === 'mock') {
            $amount = htmlspecialchars(Money::format($payment['amount_cents']), ENT_XML1, 'UTF-8');
            $reference = htmlspecialchars($payment['reference'], ENT_XML1, 'UTF-8');
            return '<svg xmlns="http://www.w3.org/2000/svg" width="380" height="240"><rect width="100%" height="100%" fill="white"/><g font-family="sans-serif" fill="black"><text x="20" y="35" font-size="19">TESTBELEG - keine echte Zahlung</text><text x="20" y="90" font-size="28">'.$amount.'</text><text x="20" y="130" font-size="14">Simulierte Kartenzahlung</text><text x="20" y="170" font-size="10">'.$reference.'</text></g></svg>';
        }
        $url = $this->receiptLink($payment);
        if ($url === '') throw new Problem('SumUp liefert für diese Zahlung keinen Originalbeleg-Link.', 502);
        try { $response = $this->http->download($url); }
        catch (TransportError) { throw new Problem('Der SumUp-Originalbeleg konnte nicht geladen werden. Bitte erneut versuchen.', 502); }
        if ($response['status'] !== 200) throw new Problem('Der SumUp-Originalbeleg ist noch nicht verfügbar. Bitte erneut versuchen.', 502);
        return $response['body'];
    }
    public function receiptLink(array $payment): string {
        if ($this->config->get('sumup', 'mode') === 'mock') return '';
        $id = $payment['transaction_id'] ?? '';
        if (!is_string($id) || $id === '') throw new Problem('Für diese Zahlung fehlt die SumUp-Transaktions-ID.', 409);
        $merchant = $this->config->get('sumup', 'merchant_code');
        try {
            $response = $this->request('GET', '/v2.1/merchants/' . rawurlencode($merchant) . '/transactions?' . http_build_query(['id' => $id]));
        } catch (TransportError) { throw new Problem('Der SumUp-Beleglink konnte nicht geladen werden. Bitte erneut versuchen.', 502); }
        $data = $response['body'];
        if ($response['status'] !== 200 || !is_array($data) || ($data['id'] ?? null) !== $id ||
            ($data['merchant_code'] ?? null) !== $merchant || ($data['status'] ?? null) !== 'SUCCESSFUL' ||
            ($data['currency'] ?? null) !== 'EUR' || (!is_int($data['amount'] ?? null) && !is_float($data['amount'] ?? null)) ||
            abs((float)$data['amount'] * 100 - $payment['amount_cents']) > 0.001) {
            throw new Problem('Der SumUp-Beleglink konnte nicht eindeutig der Zahlung zugeordnet werden. Bitte erneut versuchen.', 502);
        }
        foreach (is_array($data['links'] ?? null) ? $data['links'] : [] as $link) {
            if (!is_array($link) || ($link['rel'] ?? null) !== 'receipt') continue;
            $url = $link['href'] ?? null;
            if (!is_string($url) || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) continue;
            $parts = parse_url($url);
            // Nur unveränderte Beleglinks aus der geprüften Transaktion. Keine
            // API-URL mit Zugriffsschlüssel und kein vom Browser vorgegebenes Ziel.
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' ||
                !preg_match('/^receipts(?:-[a-z0-9]+)?\.sumup\.com$/D', strtolower($parts['host'] ?? '')) ||
                isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) ||
                (isset($parts['port']) && $parts['port'] !== 443)) continue;
            return $url;
        }
        return '';
    }
    public function cancel(array $payment): void {
        if ($this->config->get('sumup', 'mode') === 'mock') return;
        $response = $this->request('POST', $this->readerPath($payment) . '/terminate');
        $this->accepted($response, 'Der Abbruch konnte nicht bestätigt werden.');
        // Eine angenommene Terminate-Anfrage bestätigt noch keinen Zahlungsabbruch.
    }
    public function status(array $payment): array {
        if ($this->config->get('sumup', 'mode') === 'mock') return ['status' => $payment['payment_status']];
        $key = $payment['client_transaction_id'] ? 'client_transaction_id' : 'foreign_transaction_id';
        $value = $payment['client_transaction_id'] ?: $payment['reference'];
        $path = '/v2.1/merchants/' . rawurlencode($this->config->get('sumup', 'merchant_code')) . '/transactions?' . http_build_query([$key => $value]);
        $response = $this->request('GET', $path);
        if ($response['status'] >= 200 && $response['status'] < 300) {
            $data = $response['body'];
            if (!is_array($data) || ($data[$key] ?? null) !== $value ||
                ($data['merchant_code'] ?? null) !== $this->config->get('sumup', 'merchant_code') ||
                ($data['currency'] ?? null) !== 'EUR' ||
                (!is_int($data['amount'] ?? null) && !is_float($data['amount'] ?? null)) ||
                abs((float)$data['amount'] * 100 - $payment['amount_cents']) > 0.001) {
                throw new TransportError('SumUp-Zahlung konnte nicht eindeutig zugeordnet werden.');
            }
            $status = match ($data['status'] ?? '') {
                'SUCCESSFUL' => 'successful', 'FAILED' => 'failed', 'CANCELLED' => 'cancelled',
                'PENDING' => 'pending', default => 'unknown',
            };
            if ($status === 'successful' && (!is_string($data['id'] ?? null) || $data['id'] === '')) throw new TransportError('SumUp hat den Zahlungserfolg ohne Transaktions-ID geliefert. Der Status wird erneut geprüft.');
            $paidAt = null;
            if ($status === 'successful' && is_string($data['timestamp'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $data['timestamp'])) {
                try { $paidAt = (new \DateTimeImmutable($data['timestamp']))->getTimestamp(); } catch (\Exception) {}
                if ($paidAt !== null && ($paidAt <= 0 || $paidAt > time() + 300)) $paidAt = null;
            }
            return ['status' => $status, 'transaction_id' => $data['id'] ?? null, 'paid_at' => $paidAt];
        }
        if ($response['status'] !== 404) throw new TransportError('SumUp-Status derzeit nicht erreichbar.');
        // Noch kein Transaktionsdatensatz: Reader-Checkout kann bereits einen
        // endgültigen Fehler/Abbruch melden. Erfolg wird stets über Transactions bestätigt.
        if ($payment['checkout_id']) {
            $reader = $this->request('GET', $this->readerPath($payment) . '/checkout/' . rawurlencode($payment['checkout_id']));
            $data = $reader['body']['data'] ?? [];
            if ($reader['status'] === 200 && is_array($data) &&
                ($data['checkout_id'] ?? null) === $payment['checkout_id'] &&
                ($data['client_transaction_id'] ?? null) === $payment['client_transaction_id'] &&
                ($data['total_amount']['currency'] ?? null) === 'EUR' &&
                ($data['total_amount']['minor_unit'] ?? null) === 2 &&
                ($data['total_amount']['value'] ?? null) === $payment['amount_cents'] &&
                in_array($data['status'] ?? '', ['failed', 'cancelled'], true)) {
                return ['status' => $data['status']];
            }
        }
        return ['status' => in_array($payment['payment_status'], ['starting', 'unknown'], true) ? 'unknown' : $payment['payment_status']];
    }
}
