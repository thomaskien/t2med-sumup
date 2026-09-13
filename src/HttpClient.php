<?php
declare(strict_types=1);
namespace KienzleSumup;

class HttpClient {
    /** Keine Weiterleitungen: Zugangsdaten verlassen nie das konfigurierte Ziel. */
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = ''): array {
        $curl = curl_init($url); $raw = ''; $tooLarge = false;
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$raw, &$tooLarge): int {
                if (strlen($raw) + strlen($chunk) > 2 * 1024 * 1024) { $tooLarge = true; return 0; }
                $raw .= $chunk; return strlen($chunk);
            }];
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($caFile !== '') $options[CURLOPT_CAINFO] = $caFile;
        curl_setopt_array($curl, $options);
        curl_exec($curl);
        $errno = curl_errno($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        // PHP 8.5 gibt das Handle automatisch frei; kein deprecated curl_close().
        $curl = null;
        if ($errno) {
            $safe = in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION], true);
            throw new TransportError('Schnittstelle nicht erreichbar oder Antwort unterbrochen.', !$safe, $status);
        }
        try { $json = $raw === '' ? null : json_decode($raw, true, 128, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $json = null; }
        return ['status' => $status, 'body' => $json];
    }
}
