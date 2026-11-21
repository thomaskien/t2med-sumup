<?php
declare(strict_types=1);
namespace KienzleSumup;

/** Netzwerkstruktur dieses Servers abfragen (Schnittstellen, Netze, Gateway, bekannte Geräte, lauschende Ports) und Netzadressen prüfen. */
final class Network {
    /** Private Bereiche, aus denen Freigaben stammen dürfen (wie im root-Dienst). */
    private const PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '169.254.0.0/16', 'fc00::/7', 'fe80::/10'];

    private bool $injected;

    /** @param callable(array): ?string $run Befehl → Ausgabe oder null (nur für Tests ersetzbar) */
    public function __construct(private $run = null) {
        $this->injected = $run !== null;
        $this->run ??= static function (array $command): ?string {
            if (!is_executable($command[0])) return null;
            try { $result = Command::run($command, '', 5, 1048576); } catch (Problem) { return null; }
            return $result['code'] === 0 ? $result['output'] : null;
        };
    }

    public function detect(): array {
        $ip = $this->tool(['/usr/sbin/ip', '/sbin/ip', '/usr/bin/ip', '/bin/ip']);
        $ss = $this->tool(['/usr/bin/ss', '/usr/sbin/ss', '/bin/ss', '/sbin/ss']);
        $interfaces = [];
        foreach ($this->json($ip, ['-j', 'addr', 'show']) as $row) {
            $name = (string)($row['ifname'] ?? '');
            if ($name === '' || $name === 'lo' || !preg_match('/^[A-Za-z0-9_.:@-]{1,32}$/D', $name)) continue;
            $addresses = [];
            foreach ($row['addr_info'] ?? [] as $a) {
                $local = (string)($a['local'] ?? ''); $prefix = (int)($a['prefixlen'] ?? -1);
                if (!filter_var($local, FILTER_VALIDATE_IP) || ($a['scope'] ?? '') === 'link' && str_contains($local, ':')) continue;
                $net = self::normalize($local . '/' . $prefix);
                if ($net === null) continue;
                $addresses[] = ['ip' => $local, 'prefix' => $prefix, 'network' => $net, 'private' => self::isPrivate($net), 'family' => str_contains($local, ':') ? 6 : 4];
            }
            if ($addresses) $interfaces[] = ['name' => $name, 'state' => strtolower((string)($row['operstate'] ?? 'unknown')), 'addresses' => $addresses];
        }
        $gateway = null;
        foreach ($this->json($ip, ['-j', 'route', 'show', 'default']) as $route) {
            if (filter_var($route['gateway'] ?? '', FILTER_VALIDATE_IP)) { $gateway = ['ip' => $route['gateway'], 'dev' => (string)($route['dev'] ?? '')]; break; }
        }
        $neighbors = [];
        foreach ($this->json($ip, ['-j', 'neigh', 'show']) as $n) {
            $dst = (string)($n['dst'] ?? ''); $states = (array)($n['state'] ?? []);
            if (!filter_var($dst, FILTER_VALIDATE_IP) || str_starts_with(strtolower($dst), 'fe80') || array_intersect($states, ['FAILED', 'INCOMPLETE'])) continue;
            $neighbors[$dst] = ['ip' => $dst, 'dev' => (string)($n['dev'] ?? '')];
        }
        $listening = [];
        $out = $ss ? ($this->run)([$ss, '-H', '-ltn']) : null;
        foreach (preg_split('/\R/', (string)$out) as $line) {
            if (!preg_match('/^LISTEN\s+\d+\s+\d+\s+(\S+):(\d{1,5})\s/', trim($line) . ' ', $m)) continue;
            $local = in_array($m[1], ['127.0.0.1', '[::1]', '127.0.0.53%lo', '127.0.0.54'], true) || str_starts_with($m[1], '127.');
            $port = (int)$m[2];
            if (!isset($listening[$port])) $listening[$port] = ['port' => $port, 'local_only' => $local];
            else $listening[$port]['local_only'] = $listening[$port]['local_only'] && $local;
        }
        ksort($listening);
        return ['interfaces' => $interfaces, 'gateway' => $gateway, 'neighbors' => array_values($neighbors), 'listening' => array_values($listening), 'tools' => (bool)$ip];
    }

    private function tool(array $paths): ?string { if ($this->injected) return $paths[0]; foreach ($paths as $p) if (is_executable($p)) return $p; return null; }
    private function json(?string $tool, array $args): array {
        if (!$tool) return [];
        $out = ($this->run)([$tool, ...$args]);
        $data = $out === null ? null : json_decode($out, true);
        return is_array($data) ? $data : [];
    }

    /** IPv4-in-IPv6-Schreibweise (::ffff:1.2.3.4) auf IPv4 zurückführen. */
    public static function clean(string $ip): string {
        return preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) ? $m[1] : $ip;
    }

    /** „192.168.1.20/24“ → „192.168.1.0/24“; null bei ungültiger Angabe. */
    public static function normalize(string $cidr): ?string {
        $parts = explode('/', trim($cidr));
        if (count($parts) > 2) return null;
        $packed = @inet_pton($parts[0]);
        if ($packed === false) return null;
        $bits = strlen($packed) * 8;
        $prefix = isset($parts[1]) ? $parts[1] : (string)$bits;
        if (!ctype_digit($prefix) || (int)$prefix > $bits) return null;
        $mask = self::mask((int)$prefix, strlen($packed));
        return inet_ntop($packed & $mask) . '/' . (int)$prefix;
    }
    private static function mask(int $prefix, int $bytes): string {
        $mask = '';
        for ($i = 0; $i < $bytes; $i++) { $n = max(0, min(8, $prefix - $i * 8)); $mask .= chr((0xFF << (8 - $n)) & 0xFF); }
        return $mask;
    }
    public static function contains(string $cidr, string $ip): bool {
        $net = self::normalize($cidr); $addr = @inet_pton(self::clean($ip));
        if ($net === null || $addr === false) return false;
        [$base, $prefix] = explode('/', $net);
        $b = inet_pton($base);
        return strlen($b) === strlen($addr) && ($addr & self::mask((int)$prefix, strlen($b))) === $b;
    }
    public static function isPrivate(string $cidr): bool {
        $net = self::normalize($cidr);
        if ($net === null) return false;
        [$base, $prefix] = explode('/', $net);
        foreach (self::PRIVATE as $range) {
            [, $rp] = explode('/', $range);
            if (str_contains($base, ':') === str_contains($range, ':') && (int)$prefix >= (int)$rp && self::contains($range, $base)) return true;
        }
        return false;
    }
    public static function isLoopback(string $ip): bool { $ip = self::clean($ip); return $ip === '::1' || str_starts_with($ip, '127.'); }
}
