<?php
declare(strict_types=1);
namespace KienzleSumup;

class HttpClient {
    /** Keine Weiterleitungen: Zugangsdaten verlassen nie das konfigurierte Ziel. */
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array {
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
        if ($pinCertificate) {
            // Nur für explizit konfigurierte FHIR-Zertifikate ohne passenden SAN.
            // Die Schlüsselbindung ersetzt den Namensvergleich; CA und Gültigkeit
            // bleiben geprüft. cURL prüft den Pin vor dem Senden von HTTP-Daten.
            $options[CURLOPT_PINNEDPUBLICKEY] = self::certificatePin($caFile);
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if (!curl_setopt_array($curl, $options)) throw new TransportError('Die sicheren Verbindungsoptionen konnten nicht gesetzt werden.', false);
        curl_exec($curl);
        $errno = curl_errno($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        // PHP 8.5 gibt das Handle automatisch frei; kein deprecated curl_close().
        $curl = null;
        if ($errno) {
            // PHP stellt libcurl-Fehler 60 als CURLE_SSL_CACERT bereit.
            $safe = in_array($errno, [CURLE_UNSUPPORTED_PROTOCOL, CURLE_URL_MALFORMAT,
                CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR,
                CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE, CURLE_SSL_PINNEDPUBKEYNOTMATCH], true);
            $message = match ($errno) {
                CURLE_UNSUPPORTED_PROTOCOL => 'Die Schnittstellenadresse muss HTTPS verwenden.',
                CURLE_URL_MALFORMAT => 'Die Schnittstellenadresse ist ungültig.',
                CURLE_COULDNT_RESOLVE_HOST => 'Der Servername der Schnittstelle konnte nicht aufgelöst werden.',
                CURLE_COULDNT_CONNECT => 'Die Schnittstelle ist unter der konfigurierten Adresse und dem Port nicht erreichbar.',
                CURLE_SSL_CONNECT_ERROR => 'Die TLS-Verbindung zur Schnittstelle konnte nicht aufgebaut werden.',
                CURLE_SSL_CACERT => 'Das Zertifikat der Schnittstelle wird nicht vertraut oder passt nicht zum Hostnamen bzw. zur IP-Adresse.',
                CURLE_SSL_CACERT_BADFILE => 'Die hinterlegte CA-Zertifikatsdatei fehlt, ist nicht lesbar oder ungültig.',
                CURLE_SSL_PINNEDPUBKEYNOTMATCH => 'Der öffentliche Schlüssel des Servers stimmt nicht mit dem hinterlegten t2med-Zertifikat überein.',
                CURLE_OPERATION_TIMEDOUT => 'Die Schnittstelle hat nicht rechtzeitig geantwortet. Der Ausgang eines Schreibversuchs kann noch offen sein.',
                default => 'Schnittstelle nicht erreichbar oder Antwort unterbrochen.',
            };
            // Keine curl_error()-Rohmeldung: diese kann URLs und Zugangsdaten enthalten.
            throw new TransportError($message . " (cURL $errno)", !$safe, $status);
        }
        try { $json = $raw === '' ? null : json_decode($raw, true, 128, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $json = null; }
        return ['status' => $status, 'body' => $json];
    }
    private static function certificatePin(string $path): string {
        $error = 'Zertifikatsbindung benötigt genau ein lesbares öffentliches t2med-Serverzertifikat in ca_file.';
        if ($path === '' || !is_file($path) || !is_readable($path)) throw new TransportError($error, false);
        $pem = @file_get_contents($path, false, null, 0, 65537);
        if (!is_string($pem) || strlen($pem) > 65536 ||
            !preg_match('~\\A\\s*-----BEGIN CERTIFICATE-----[A-Za-z0-9+/=\\s]+-----END CERTIFICATE-----\\s*\\z~D', $pem)) {
            throw new TransportError($error, false);
        }
        $cert = @openssl_x509_read($pem);
        $key = $cert === false ? false : @openssl_pkey_get_public($cert);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        $public = is_array($details) ? ($details['key'] ?? '') : '';
        $der = base64_decode(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n"], '', $public), true);
        if (!$der) throw new TransportError($error, false);
        return 'sha256//' . base64_encode(hash('sha256', $der, true));
    }
}
