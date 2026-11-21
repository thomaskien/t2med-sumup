<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Drucker auf diesem Server einrichten, ohne Terminal: Netzwerk/USB durchsuchen, CUPS-
 * Warteschlange anlegen (Bondrucker als RAW, A4-Drucker über IPP Everywhere) und entfernen.
 * Der PHP-Dienst ist dafür Mitglied der Gruppe lpadmin (vom Server-Installer eingerichtet).
 * Entfernt werden nur selbst angelegte Warteschlangen (Beschreibung „Kienzle-SumUp …“).
 */
class PrinterAdmin {
    public const KINDS = [
        'tm-m10' => ['label' => 'Bondrucker Epson TM-m10 (58 mm)', 'port' => 9100, 'name' => 'TM-m10'],
        'tm-m30iii' => ['label' => 'Bondrucker Epson TM-m30III (80 mm)', 'port' => 9100, 'name' => 'TM-m30III'],
        'office' => ['label' => 'A4-Drucker im Netzwerk (IPP / AirPrint)', 'port' => 631, 'name' => 'Praxisdrucker'],
    ];
    private const MARK = 'Kienzle-SumUp';
    private const URI = '#^(socket|ipp|ipps|dnssd|usb|lpd)://[A-Za-z0-9%._~:/?&=@+\-]{1,300}$#D';

    /** @var callable */
    private $run;
    /** @var callable */
    private $probe;

    public function __construct(?callable $run = null, ?callable $probe = null) {
        $this->run = $run ?? fn(array $args, int $timeout) => Command::run($args, '', $timeout, 262144);
        $this->probe = $probe ?? static function (string $host, int $port): bool {
            $socket = @stream_socket_client('tcp://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port, $code, $error, 3);
            if (!$socket) return false;
            fclose($socket); return true;
        };
    }

    public function available(): bool { return is_executable('/usr/sbin/lpadmin') && is_executable('/usr/sbin/lpinfo'); }

    /** Vorhandene Warteschlangen mit Adresse, Zustand und ob sie hier angelegt wurden. */
    public function queues(): array {
        $devices = $this->call(['/usr/bin/lpstat', '-v'], 15, true);
        $queues = [];
        foreach (preg_split('/\R/', $devices) as $line) {
            // „device for TM-m30III: socket://192.168.1.80:9100“ (deutsch: „Gerät für …“)
            if (preg_match('/^\S+ (?:for|für) ([^:\s]+): (\S+)$/u', trim($line), $m)) $queues[$m[1]] = ['name' => $m[1], 'uri' => $m[2], 'state' => '', 'description' => '', 'managed' => false];
        }
        $details = $this->call(['/usr/bin/lpstat', '-l', '-p'], 15, true);
        $current = null;
        foreach (preg_split('/\R/', $details) as $line) {
            if (preg_match('/^(?:printer|Drucker) (\S+) (.*)$/u', $line, $m)) {
                $current = $m[1];
                if (isset($queues[$current])) $queues[$current]['state'] = str_contains($m[2], 'disabled') || str_contains($m[2], 'deaktiviert') ? 'gestoppt' : (str_contains($m[2], 'printing') || str_contains($m[2], 'druckt') ? 'druckt' : 'bereit');
            } elseif ($current && isset($queues[$current]) && preg_match('/^\s+(?:Description|Beschreibung): ?(.*)$/u', $line, $m)) {
                $queues[$current]['description'] = trim($m[1]);
                $queues[$current]['managed'] = str_starts_with(trim($m[1]), self::MARK);
            }
        }
        foreach ($queues as &$queue) $queue['address'] = self::address($queue['uri']);
        return array_values($queues);
    }

    /** Netzwerk und USB nach Druckern durchsuchen (dauert einige Sekunden). */
    public function discover(): array {
        $output = $this->call(['/usr/sbin/lpinfo', '--timeout', '8', '-v'], 40);
        $found = [];
        foreach (preg_split('/\R/', $output) as $line) {
            if (!preg_match('/^(network|direct) (\S+)$/', trim($line), $m) || !preg_match(self::URI, $m[2])) continue;
            $uri = $m[2];
            $name = self::friendly($uri);
            $kind = preg_match('/TM-?m30/i', $name) ? 'tm-m30iii' : (preg_match('/TM-?m10/i', $name) ? 'tm-m10'
                : (preg_match('~^(dnssd://.*_ipps?\._tcp|ipps?://)~', $uri) ? 'office' : null));
            $found[$uri] = ['uri' => $uri, 'name' => $name, 'kind' => $kind, 'connection' => str_starts_with($uri, 'usb:') ? 'USB' : 'Netzwerk'];
        }
        // Gleicher Drucker über mehrere Wege: dnssd vor ipp/socket mit gleichem Namen zuerst listen.
        uasort($found, fn($a, $b) => [$a['kind'] === null, $a['name']] <=> [$b['kind'] === null, $b['name']]);
        return array_values($found);
    }

    /** Warteschlange anlegen; liefert Name und Adresse. Adresse ist IP/Rechnername oder eine gefundene URI. */
    public function add(string $kind, string $name, string $address): array {
        $spec = self::KINDS[$kind] ?? throw new Problem('Bitte die Art des Druckers wählen.', 422);
        $name = trim($name) !== '' ? trim($name) : $spec['name'];
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,40}$/D', $name)) throw new Problem('Name: nur Buchstaben, Ziffern, - und _ (ohne Leerzeichen), z. B. ' . $spec['name'] . '.', 422);
        $address = trim($address);
        if (preg_match('~^[a-z]+://~', $address)) {
            if (!preg_match(self::URI, $address)) throw new Problem('Diese Druckeradresse wird nicht unterstützt.', 422);
            $uri = $address;
        } else {
            if (!self::host($address)) throw new Problem('Bitte die IP-Adresse des Druckers eintragen, z. B. 192.168.1.80 (steht auf dem Statusbon bzw. im Druckermenü).', 422);
            if (!($this->probe)($address, $spec['port'])) {
                throw new Problem('Unter ' . $address . ' antwortet kein ' . ($kind === 'office' ? 'IPP-Drucker (Port 631)' : 'Bondrucker (Port 9100)')
                    . '. Ist der Drucker eingeschaltet, im Netzwerk und die IP-Adresse richtig?', 422);
            }
            $uri = $kind === 'office' ? 'ipp://' . self::bracket($address) . '/ipp/print' : 'socket://' . self::bracket($address) . ':9100';
        }
        $args = ['/usr/sbin/lpadmin', '-p', $name, '-v', $uri, '-m', $kind === 'office' ? 'everywhere' : 'raw',
            '-D', self::MARK . ' ' . ($kind === 'office' ? 'Praxisdrucker' : 'Bondrucker'), '-L', $spec['label'], '-o', 'printer-is-shared=false', '-E'];
        if ($kind === 'office') array_push($args, '-o', 'media-default=iso_a4_210x297mm');
        $existing = array_column($this->queues(), null, 'name');
        if (isset($existing[$name]) && !$existing[$name]['managed']) {
            throw new Problem('Es gibt bereits einen Drucker „' . $name . '“, der nicht hier angelegt wurde. Bitte einen anderen Namen wählen oder ihn direkt übernehmen.', 409);
        }
        $this->call($args, 60);
        return ['name' => $name, 'uri' => $uri, 'address' => self::address($uri)];
    }

    public function remove(string $name): void {
        $queue = array_column($this->queues(), null, 'name')[$name] ?? throw new Problem('Drucker nicht gefunden.', 404);
        if (!$queue['managed']) throw new Problem('Dieser Drucker wurde nicht hier angelegt und wird deshalb nicht entfernt.', 409);
        $this->call(['/usr/sbin/lpadmin', '-x', $name], 30);
    }

    public static function host(string $value): bool {
        return filter_var($value, FILTER_VALIDATE_IP) !== false
            || (bool)preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,62})(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}))*$/D', $value);
    }
    private static function bracket(string $host): string { return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host; }

    /** Lesbare Adresse für die Anzeige, z. B. „192.168.1.80“ oder „USB“. */
    public static function address(string $uri): string {
        if (str_starts_with($uri, 'usb:')) return 'USB';
        if (preg_match('/^(kienzle-sumup|terminzettel):/', $uri)) return 'auf diesem Server';
        if (str_starts_with($uri, 'dnssd:')) return 'Netzwerk (automatisch gefunden)';
        $host = parse_url($uri, PHP_URL_HOST);
        return is_string($host) ? trim($host, '[]') : $uri;
    }
    private static function friendly(string $uri): string {
        if (str_starts_with($uri, 'dnssd://')) return rawurldecode(explode('._', substr($uri, 8))[0]);
        if (str_starts_with($uri, 'usb://')) return trim(str_replace('/', ' ', rawurldecode(explode('?', substr($uri, 6))[0])));
        return 'Gerät unter ' . self::address($uri);
    }

    /** lpadmin-Aufruf für eigene virtuelle Drucker (Fehler im Klartext). */
    public function command(array $args): string { return $this->call($args, 60); }

    private function call(array $args, int $timeout, bool $allowEmpty = false): string {
        $result = ($this->run)($args, $timeout);
        if ($result['code'] === 0) return (string)$result['output'];
        $error = trim((string)($result['stderr'] ?: $result['output']));
        // lpstat ohne Drucker: „No destinations added.“ bzw. Exit-Code 1 ohne Ausgabe.
        if ($allowEmpty && ($error === '' || stripos($error, 'no destinations') !== false || stripos($error, 'keine ziele') !== false)) return '';
        error_log('kienzle-sumup: ' . basename($args[0]) . ': ' . substr($error, 0, 300));
        throw new Problem(self::explain($error), 502);
    }
    private static function explain(string $error): string {
        $e = strtolower($error);
        if (str_contains($e, 'forbidden') || str_contains($e, 'not authorized') || str_contains($e, 'verboten') || str_contains($e, 'nicht berechtigt')) {
            return 'Keine Berechtigung zur Druckereinrichtung. Bitte auf dem Server einmal sudo bash scripts/install-server.sh ausführen.';
        }
        if (str_contains($e, 'unable to connect') || str_contains($e, 'connection refused') || str_contains($e, 'scheduler is not running') || str_contains($e, 'keine verbindung')) {
            return 'Der Druckdienst (CUPS) auf diesem Server läuft nicht. Bitte auf dem Server einmal sudo bash scripts/install-server.sh ausführen.';
        }
        if (str_contains($e, 'everywhere') || str_contains($e, 'unable to query') || str_contains($e, 'ipp everywhere')) {
            return 'Der Drucker hat die automatische Einrichtung (IPP Everywhere/AirPrint) abgelehnt. Im Webmenü des Druckers „IPP“ bzw. „AirPrint“ einschalten und erneut versuchen.';
        }
        return 'Druckereinrichtung fehlgeschlagen: ' . ($error !== '' ? substr(preg_replace('/\s+/', ' ', $error), 0, 200) : 'unbekannter Fehler') . '.';
    }
}
