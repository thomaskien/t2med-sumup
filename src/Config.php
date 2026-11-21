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
        if (!is_bool($this->get('practice', 'append_sumup_receipt', false))) throw new \RuntimeException('practice.append_sumup_receipt muss true oder false sein.');
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
        if ($this->get('letterxpress', 'enabled', false)) {
            foreach (['username' => 'Benutzername', 'api_key' => 'API-Key'] as $key => $label) {
                $value = $this->get('letterxpress', $key, '');
                if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 200 || preg_match('/[\x00-\x1f]/', $value)) throw new \RuntimeException('Bitte den Letterxpress ' . $label . ' eintragen.');
            }
        }
        foreach (['mode' => ['test', 'live'], 'color' => ['1', '4'], 'shipping' => ['auto', 'national', 'international'], 'registered' => ['none', 'r1', 'r2']] as $key => $allowed) {
            $default = ['mode' => 'test', 'color' => '1', 'shipping' => 'auto', 'registered' => 'none'][$key];
            if (!in_array((string)$this->get('letterxpress', $key, $default), $allowed, true)) throw new \RuntimeException('letterxpress.' . $key . ' hat einen ungültigen Wert.');
        }
        if ((string)$this->get('letterxpress', 'registered', 'none') !== 'none' && (string)$this->get('letterxpress', 'shipping', 'auto') === 'international') throw new \RuntimeException('Einschreiben ist bei Letterxpress nur für Inlandsbriefe möglich.');
        if (!is_bool($this->get('letterxpress', 'duplex', false)) || !is_bool($this->get('letterxpress', 'enabled', false))) throw new \RuntimeException('letterxpress.enabled und letterxpress.duplex müssen true oder false sein.');
        $entry = $this->get('fhir', 'entry_code', 'ZAHLUNG');
        if (!is_string($entry) || $entry === '' || mb_strlen($entry) > 20) throw new \RuntimeException('Aktenkürzel muss 1–20 Zeichen lang sein.');
        foreach (['enabled' => false, 'cut' => true, 'cash_receipt_auto' => false] as $key => $default) {
            if (!is_bool($this->get('printing', $key, $default))) throw new \RuntimeException('printing.' . $key . ' muss true oder false sein.');
        }
        if ($this->get('printing', 'enabled', false)) {
            $model = $this->get('printing', 'model', 'tm-m10');
            if (!isset(self::PRINTER_MODELS[$model])) throw new \RuntimeException('printing.model muss tm-m10 oder tm-m30iii sein.');
            $transport = $this->get('printing', 'transport', 'samba');
            if ($transport === 'samba') {
                $share = $this->get('printing', 'share');
                if (!is_string($share) || !preg_match('~^//[A-Za-z0-9][A-Za-z0-9.-]{0,252}/[A-Za-z0-9][A-Za-z0-9 ._-]{0,79}$~D', $share)) throw new \RuntimeException('Druckerfreigabe als //server/drucker angeben.');
            } elseif ($transport === 'cups') {
                $this->cupsTarget('printing');
            } else throw new \RuntimeException('printing.transport muss samba oder cups sein.');
            $max = self::PRINTER_MODELS[$model]['max_dots'];
            $width = $this->get('printing', 'width_dots', self::PRINTER_MODELS[$model]['width_dots']);
            if (!is_int($width) || $width < 128 || $width > $max) throw new \RuntimeException('Druckbreite für ' . self::PRINTER_MODELS[$model]['label'] . ' muss 128 bis ' . $max . ' Punkte betragen.');
        }
        if (!is_bool($this->get('office_printing', 'enabled', false)) || !is_bool($this->get('office_printing', 'duplex', false)) || !is_bool($this->get('office_printing', 'invoice_auto', true))) throw new \RuntimeException('office_printing.enabled/duplex muss true oder false sein.');
        if ($this->get('office_printing', 'enabled', false)) {
            $this->cupsTarget('office_printing');
            if (!in_array($this->get('office_printing', 'media', 'A4'), ['A4', 'Letter'], true)) throw new \RuntimeException('office_printing.media muss A4 oder Letter sein.');
        }
        if (!is_bool($this->get('invoice_inbox', 'enabled', false))) throw new \RuntimeException('invoice_inbox.enabled muss true oder false sein.');
        if ($this->get('invoice_inbox', 'enabled', false)) {
            if (!preg_match('/^[a-f0-9]{64}$/D', (string)$this->get('invoice_inbox', 'key'))) throw new \RuntimeException('invoice_inbox.key muss 32 zufällige Bytes als Hex enthalten.');
            if ($this->get('invoice_inbox', 'key') === $this->get('app', 'launcher_key')) throw new \RuntimeException('invoice_inbox.key darf nicht dem Starter-Schlüssel entsprechen.');
        }
        foreach (T2medInvoice::LABEL_KEYS as $key) {
            $list = $this->get('invoice_inbox', $key, []);
            if (!is_array($list) || count($list) > 10) throw new \RuntimeException('invoice_inbox.' . $key . ': höchstens 10 Textschnipsel.');
            foreach ($list as $item) if (!is_string($item) || mb_strlen($item) > 60 || preg_match('/[\x00-\x1f]/', $item)) throw new \RuntimeException('invoice_inbox.' . $key . ': Textschnipsel bis 60 Zeichen ohne Steuerzeichen.');
        }
        $days = $this->get('invoice_inbox', 'max_age_days', 14);
        if (!is_int($days) || $days < 1 || $days > 90) throw new \RuntimeException('invoice_inbox.max_age_days muss 1 bis 90 betragen.');
        if (!is_bool($this->get('invoice_export', 'enabled', false))) throw new \RuntimeException('invoice_export.enabled muss true oder false sein.');
        if ($this->get('invoice_export', 'enabled', false)) {
            if (!is_bool($this->get('invoice_export', 'app_invoices', false))) throw new \RuntimeException('invoice_export.app_invoices muss true oder false sein.');
            if (!$this->get('invoice_inbox', 'enabled', false) && !$this->get('invoice_export', 'app_invoices', false)) throw new \RuntimeException('Ablage der Rechnungs-PDF benötigt den Rechnungseingang oder invoice_export.app_invoices = true.');
            $share = $this->get('invoice_export', 'share');
            if (!is_string($share) || !preg_match('~^//[A-Za-z0-9][A-Za-z0-9.-]{0,252}/[A-Za-z0-9][A-Za-z0-9 $._-]{0,79}$~D', $share)) throw new \RuntimeException('Netzwerkordner als //server/freigabe angeben.');
            $dir = $this->get('invoice_export', 'directory', '');
            if (!is_string($dir) || !preg_match('~^[A-Za-z0-9 _.{}/-]{0,200}$~D', $dir) || preg_match('~(^|/)\.\.?(/|$)~', $dir)) throw new \RuntimeException('invoice_export.directory: nur Buchstaben, Ziffern, Leerzeichen, _ . - / sowie {jahr} und {monat}.');
            foreach (['username', 'password', 'domain'] as $key) {
                $value = $this->get('invoice_export', $key, '');
                if (!is_string($value) || strlen($value) > 256 || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new \RuntimeException('invoice_export.' . $key . ' ist ungültig.');
            }
            if (!in_array($this->get('invoice_export', 'when', 'paid'), ['paid', 'received'], true)) throw new \RuntimeException('invoice_export.when muss paid oder received sein.');
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

    /** Bondrucker: Breite in Druckpunkten (203 dpi). TM-m30III: 80 mm Papier, 72 mm bedruckbar. */
    public const PRINTER_MODELS = [
        'tm-m10' => ['label' => 'Epson TM-m10 (58 mm)', 'width_dots' => 420, 'max_dots' => 420],
        'tm-m30iii' => ['label' => 'Epson TM-m30III (80 mm)', 'width_dots' => 576, 'max_dots' => 576],
    ];
    private function cupsTarget(string $section): void {
        $queue = $this->get($section, 'queue', '');
        if (!is_string($queue) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,126}$/D', $queue)) throw new \RuntimeException($section . '.queue: Name der CUPS-Warteschlange angeben.');
        $server = $this->get($section, 'cups_server', '');
        if (!is_string($server) || ($server !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}(:[0-9]{1,5})?$/D', $server))) throw new \RuntimeException($section . '.cups_server: leer (lokal) oder host[:port].');
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
