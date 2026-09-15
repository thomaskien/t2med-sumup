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
    public function receipt(array $payment): array {
        if ($this->config->get('sumup', 'mode') === 'mock') {
            return ['mock' => true, 'amount_cents' => $payment['amount_cents'], 'reference' => $payment['reference'],
                'merchant_name' => 'Musterpraxis', 'merchant_address' => "Musterstraße 1\n12345 Musterstadt", 'merchant_code' => 'TEST',
                'date' => $this->receiptDate('@' . $payment['paid_at']), 'transaction_id' => 'mock-' . $payment['id'],
                'transaction_code' => 'TESTBELEG', 'receipt_no' => '', 'card_type' => 'Testkarte', 'card_last4' => '0000'];
        }
        $id = $payment['transaction_id'] ?? '';
        if (!is_string($id) || $id === '') throw new Problem('Für diese Zahlung fehlt die SumUp-Transaktions-ID.', 409);
        $merchant = $this->config->get('sumup', 'merchant_code');
        try {
            $response = $this->request('GET', '/v1.1/receipts/' . rawurlencode($id) . '?' . http_build_query(['mid' => $merchant]));
        } catch (TransportError) {
            throw new Problem('Der Zahlungsbeleg konnte nicht von SumUp geladen werden. Bitte erneut versuchen.', 502);
        }
        $status = $response['status'];
        if ($status === 404) throw new Problem('SumUp stellt den Beleg noch nicht bereit. Bitte kurz warten und erneut versuchen.', 502);
        if ($status === 401 || $status === 403) throw new Problem('Der SumUp-API-Key darf keine Belege abrufen. Bitte die Berechtigung receipts.read oder transactions.history prüfen.', 502);
        if ($status !== 200) throw new Problem('Der Zahlungsbeleg konnte nicht geladen werden. Bitte erneut versuchen. (HTTP ' . $status . ')', 502);
        $body = $response['body'];
        $data = is_array($body) ? ($body['transaction_data'] ?? null) : null;
        $amount = is_array($data) ? ($data['amount'] ?? null) : null;
        if (!is_array($data) || ($data['transaction_id'] ?? null) !== $id ||
            ($data['merchant_code'] ?? null) !== $merchant || ($data['currency'] ?? null) !== 'EUR' ||
            ($data['status'] ?? null) !== 'SUCCESSFUL' ||
            (!is_string($amount) && !is_int($amount) && !is_float($amount)) ||
            !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', (string)$amount) ||
            abs((float)$amount * 100 - $payment['amount_cents']) > 0.001) {
            throw new Problem('Der SumUp-Beleg passt nicht eindeutig zur erfolgreichen Zahlung.', 502);
        }
        $profile = $body['merchant_data']['merchant_profile'] ?? [];
        if (!is_array($profile)) $profile = [];
        if (isset($profile['merchant_code']) && $profile['merchant_code'] !== $merchant) throw new Problem('Der Händler im SumUp-Beleg passt nicht zur Zahlung.', 502);
        $address = is_array($profile['address'] ?? null) ? $profile['address'] : [];
        $card = is_array($data['card'] ?? null) ? $data['card'] : [];
        $last4 = self::receiptText($card, 'last_4_digits');
        // Nur die für den Beleg benötigten Felder weitergeben, keine Rohantwort.
        return ['mock' => false, 'amount_cents' => $payment['amount_cents'], 'reference' => $payment['reference'],
            'merchant_name' => self::receiptText($profile, 'business_name') ?: $merchant,
            'merchant_code' => $merchant,
            'merchant_address' => implode("\n", array_filter([self::receiptText($address, 'address_line1'),
                self::receiptText($address, 'address_line2'), trim(self::receiptText($address, 'post_code') . ' ' . self::receiptText($address, 'city')),
                self::receiptText($address, 'country_native_name') ?: self::receiptText($address, 'country')])),
            'date' => $this->receiptDate(self::receiptText($data, 'timestamp')),
            'transaction_id' => $id, 'transaction_code' => self::receiptText($data, 'transaction_code'),
            'receipt_no' => self::receiptText($data, 'receipt_no'), 'card_type' => self::receiptText($card, 'type'),
            'card_last4' => preg_match('/^\d{4}$/D', $last4) ? $last4 : ''];
    }
    private static function receiptText(array $data, string $key): string {
        $value = $data[$key] ?? '';
        return is_string($value) ? mb_substr(trim($value), 0, 300) : '';
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
    private function receiptDate(string $value): string {
        if ($value === '') return '';
        try { return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone($this->config->get('app', 'timezone')))->format('d.m.Y H:i T'); }
        catch (\Exception) { return ''; }
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
