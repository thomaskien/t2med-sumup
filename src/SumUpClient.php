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
            return ['status' => $status, 'transaction_id' => $data['id'] ?? null];
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
