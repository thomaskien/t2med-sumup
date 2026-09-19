<?php
declare(strict_types=1);
namespace KienzleSumup;

final class Config {
    public function __construct(public readonly array $values) {
        foreach (['app', 'sumup', 'fhir'] as $section) {
            if (!isset($values[$section])) throw new \RuntimeException("Konfiguration: Abschnitt $section fehlt.");
        }
        foreach (['launcher_key', 'encryption_key'] as $key) {
            if (!preg_match('/^[a-f0-9]{64}$/D', (string)$this->get('app', $key))) {
                throw new \RuntimeException("Konfiguration: $key muss 32 zufällige Bytes als Hex enthalten.");
            }
        }
        foreach (['sumup', 'fhir'] as $section) {
            if (!in_array($this->get($section, 'mode'), ['mock', 'live'], true)) throw new \RuntimeException('Ungültiger Betriebsmodus.');
        }
        if ($this->get('sumup', 'mode') === 'mock' && $this->get('fhir', 'mode') === 'live') {
            throw new \RuntimeException('Testzahlungen dürfen nicht in eine echte Akte geschrieben werden.');
        }
        $url = (string)$this->get('app', 'base_url');
        $parts = parse_url($url);
        $dev = $this->get('app', 'development', false) === true;
        if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            !in_array($parts['path'] ?? '', ['', '/'], true) ||
            (($parts['scheme'] ?? '') !== 'https' && !($dev && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true)))) {
            throw new \RuntimeException('base_url muss eine HTTPS-Adresse ohne Pfad sein.');
        }
        if ($dev && ($this->get('sumup', 'mode') !== 'mock' || $this->get('fhir', 'mode') !== 'mock')) throw new \RuntimeException('Entwicklungsmodus benötigt beide Mocks.');
        foreach (['port' => [1, 65535], 'max_amount_cents' => [1, 10000000], 'session_hours' => [1, 24]] as $k => [$min, $max]) {
            $n = $this->get('app', $k);
            if (!is_int($n) || $n < $min || $n > $max) throw new \RuntimeException("Konfiguration: ungültiges $k.");
        }
        if ((int)($parts['port'] ?? 443) !== $this->get('app', 'port')) throw new \RuntimeException('Port und base_url widersprechen sich.');
        if (!str_starts_with((string)$this->get('app', 'state_dir'), '/')) throw new \RuntimeException('state_dir muss absolut sein.');
        new \DateTimeZone((string)$this->get('app', 'timezone'));
        foreach (['name', 'street', 'city', 'contact'] as $field) {
            $value = $this->get('practice', $field, '');
            if (!is_string($value) || mb_strlen($value) > 200 || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new \RuntimeException('Ungültige Praxisangabe: ' . $field);
        }
        if ($this->get('sumup', 'mode') === 'live') {
            foreach (['api_key', 'affiliate_key', 'app_id', 'merchant_code', 'reader_id'] as $key) {
                if (!is_string($this->get('sumup', $key)) || $this->get('sumup', $key) === '') throw new \RuntimeException("SumUp-Konfiguration: $key fehlt.");
            }
        }
        if ($this->get('fhir', 'mode') === 'live') {
            $fhir = parse_url((string)$this->get('fhir', 'base_url'));
            if (!$fhir || ($fhir['scheme'] ?? '') !== 'https' || empty($fhir['host']) || isset($fhir['user']) || isset($fhir['query']) || isset($fhir['fragment'])) throw new \RuntimeException('FHIR benötigt eine feste HTTPS-Basisadresse.');
            if (!$this->get('fhir', 'api_key') || !is_array($this->get('fhir', 'launch_urls')) || !$this->get('fhir', 'launch_urls')) throw new \RuntimeException('FHIR-Schlüssel oder erlaubte Aufrufadressen fehlen.');
        }
        $pin = $this->get('fhir', 'pin_certificate', false);
        if (!is_bool($pin)) throw new \RuntimeException('fhir.pin_certificate muss true oder false sein.');
        if ($pin && (!is_string($this->get('fhir', 'ca_file')) || !str_starts_with($this->get('fhir', 'ca_file'), '/'))) {
            throw new \RuntimeException('Zertifikatsbindung benötigt einen absoluten ca_file-Pfad zum t2med-Serverzertifikat.');
        }
        $entry = $this->get('fhir', 'entry_code', 'ZAHLUNG');
        if (!is_string($entry) || $entry === '' || mb_strlen($entry) > 20) throw new \RuntimeException('Aktenkürzel muss 1–20 Zeichen lang sein.');
        foreach (['enabled' => false, 'cut' => true] as $key => $default) {
            if (!is_bool($this->get('printing', $key, $default))) throw new \RuntimeException('printing.' . $key . ' muss true oder false sein.');
        }
        if ($this->get('printing', 'enabled', false)) {
            $share = $this->get('printing', 'share');
            if (!is_string($share) || !preg_match('~^//[A-Za-z0-9][A-Za-z0-9.-]{0,252}/[A-Za-z0-9][A-Za-z0-9 ._-]{0,79}$~D', $share)) throw new \RuntimeException('Druckerfreigabe als //server/drucker angeben.');
            $width = $this->get('printing', 'width_dots', 420);
            if (!is_int($width) || $width < 128 || $width > 420) throw new \RuntimeException('TM-m10-Druckbreite muss 128 bis 420 Punkte betragen.');
        }
        if (!is_bool($this->get('mail', 'enabled', false))) throw new \RuntimeException('mail.enabled muss true oder false sein.');
        if ($this->get('mail', 'enabled', false)) {
            if (!is_string($this->get('mail', 'host')) || !preg_match('/^[A-Za-z0-9.-]+$/D', $this->get('mail', 'host'))) throw new \RuntimeException('SMTP-Hostname ist ungültig.');
            if (!is_int($this->get('mail', 'port')) || $this->get('mail', 'port') < 1 || $this->get('mail', 'port') > 65535) throw new \RuntimeException('SMTP-Port ist ungültig.');
            if (!in_array($this->get('mail', 'encryption'), ['starttls', 'smtps'], true)) throw new \RuntimeException('SMTP-Verschlüsselung muss starttls oder smtps sein.');
            foreach (['from_address', 'from_name', 'username', 'password'] as $key) {
                if (!is_string($this->get('mail', $key, '')) || preg_match('/[\r\n]/', $this->get('mail', $key, ''))) throw new \RuntimeException('SMTP-Konfiguration ist ungültig: ' . $key);
            }
            if (!filter_var($this->get('mail', 'from_address'), FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('SMTP-Absenderadresse ist ungültig.');
            if ($this->get('mail', 'username', '') !== '' && $this->get('mail', 'password', '') === '') throw new \RuntimeException('SMTP-Passwort fehlt.');
        }
    }

    public function get(string $section, string $key, mixed $default = null): mixed {
        return $this->values[$section][$key] ?? $default;
    }
    public function practiceReady(): bool {
        foreach (['name', 'street', 'city'] as $field) if (trim($this->get('practice', $field, '')) === '') return false;
        return true;
    }

    public static function load(string $path): self {
        if (!is_readable($path)) throw new \RuntimeException('Konfiguration fehlt. Bitte Installer ausführen.');
        return new self(self::parse((string)file_get_contents($path)));
    }

    /** Bewusst kleiner, strikt geprüfter TOML-Teilumfang für unsere Konfiguration.
     * Unterstützt Tabellen, Basic-/Literal-Strings, bool, Dezimal-Integer, String-Arrays.
     * Andere TOML-Typen werden abgelehnt, niemals als INI fehlinterpretiert.
     */
    public static function parse(string $text): array {
        $result = []; $section = '';
        foreach (preg_split('/\R/u', $text) as $index => $line) {
            $line = trim(self::uncomment($line));
            if ($line === '') continue;
            if (preg_match('/^\[([a-z][a-z0-9_]*)\]$/D', $line, $m)) {
                $section = $m[1];
                if (isset($result[$section])) throw new \RuntimeException('Doppelte TOML-Tabelle.');
                $result[$section] = []; continue;
            }
            if (!$section || !preg_match('/^([a-z][a-z0-9_]*)\s*=\s*(.+)$/D', $line, $m) || array_key_exists($m[1], $result[$section])) {
                throw new \RuntimeException('Ungültige TOML-Zeile ' . ($index + 1));
            }
            $raw = trim($m[2]);
            if (in_array($raw, ['true', 'false'], true)) $value = $raw === 'true';
            elseif (preg_match('/^(0|[1-9][0-9]*)$/D', $raw)) {
                $value = filter_var($raw, FILTER_VALIDATE_INT);
                if ($value === false) throw new \RuntimeException('TOML-Ganzzahl zu groß.');
            } elseif (str_starts_with($raw, '[')) {
                // JSON-String-Arrays sind zugleich gültiges TOML. Literal-Strings in Arrays
                // und mehrzeilige Arrays sind nicht Teil unserer Konfigurationssyntax.
                $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($value) || !array_is_list($value)) throw new \RuntimeException('String-Array erwartet.');
                foreach ($value as $item) if (!is_string($item)) throw new \RuntimeException('String-Array erwartet.');
            } elseif (preg_match("/^'([^']*)'$/D", $raw, $s)) $value = $s[1];
            else {
                $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                if (!is_string($value)) throw new \RuntimeException('TOML-String erwartet.');
            }
            if (is_string($value) && preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) throw new \RuntimeException('Steuerzeichen in Konfiguration.');
            $result[$section][$m[1]] = $value;
        }
        return $result;
    }

    private static function uncomment(string $line): string {
        $quote = ''; $escape = false;
        for ($i = 0; $i < strlen($line); $i++) {
            $c = $line[$i];
            if ($escape) { $escape = false; continue; }
            if ($quote === '"' && $c === '\\') { $escape = true; continue; }
            if ($quote !== '') { if ($c === $quote) $quote = ''; }
            elseif ($c === '"' || $c === "'") $quote = $c;
            elseif ($c === '#') return substr($line, 0, $i);
        }
        return $line;
    }
}
