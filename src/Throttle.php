<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Bremst Rate-Angriffe (Passwort raten, Schlüssel raten) pro Absender. Der Zähler liegt in der Datenbank,
 * überlebt also Neustarts. Gesperrt wird nur der Absender, nicht die ganze Praxis: ein Angreifer im Netz
 * kann so den Zugang der Praxis nicht blockieren. Es zählt ausschließlich die TCP-Adresse (REMOTE_ADDR);
 * Proxy-Header wie X-Forwarded-For lassen sich fälschen und werden bewusst ignoriert.
 */
final class Throttle {
    public function __construct(private Database $db, private string $client = '') {
        if ($this->client === '') $this->client = self::clientAddress();
    }

    public static function clientAddress(): string {
        $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'lokal');
        // IPv6 auf das /64-Netz kürzen: ein Angreifer hat dort Milliarden Adressen.
        if (str_contains($address, ':') && ($packed = @inet_pton($address)) !== false && strlen($packed) === 16) {
            return bin2hex(substr($packed, 0, 8)) . '::/64';
        }
        return $address;
    }

    /** Wirft HTTP 429, solange der Absender gesperrt ist. */
    public function check(string $scope): void {
        $state = $this->state($scope);
        if ($state['until'] > time()) {
            throw new Problem('Zu viele Fehlversuche. Bitte ' . max(1, (int)ceil(($state['until'] - time()) / 60)) . ' Minuten warten.', 429);
        }
    }

    /** Fehlversuch zählen. Ab $limit Fehlern wird gesperrt, jede weitere Sperre doppelt so lang (höchstens 1 Stunde). */
    public function fail(string $scope, int $limit = 5, int $lockSeconds = 300): void {
        $state = $this->state($scope);
        if ($state['until'] > time()) return;
        $state['count']++;
        if ($state['count'] >= $limit) {
            $state['lock'] = min(3600, max($lockSeconds, $state['lock'] * 2));
            $state['until'] = time() + $state['lock'];
            $state['count'] = 0;
        }
        $this->store($scope, $state);
        $this->purge();
    }

    public function clear(string $scope): void {
        $this->db->query('DELETE FROM settings WHERE key=?', [$this->key($scope)]);
    }

    /** Für --admin-reset: alle Sperren löschen. */
    public static function clearAll(Database $db): void {
        $db->query("DELETE FROM settings WHERE key LIKE 'throttle:%'");
    }

    private function key(string $scope): string { return 'throttle:' . $scope . ':' . substr(hash('sha256', $this->client), 0, 24); }

    private function state(string $scope): array {
        $row = $this->db->one('SELECT value FROM settings WHERE key=?', [$this->key($scope)]);
        $state = $row ? json_decode($row['value'], true) : null;
        return is_array($state) ? $state + ['count' => 0, 'until' => 0, 'lock' => 0] : ['count' => 0, 'until' => 0, 'lock' => 0];
    }

    private function store(string $scope, array $state): void {
        $this->db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at',
            [$this->key($scope), json_encode($state), time()]);
    }

    /** Alte Zähler nach einem Tag löschen, damit die Tabelle nicht wächst. */
    private function purge(): void {
        $this->db->query("DELETE FROM settings WHERE key LIKE 'throttle:%' AND updated_at<?", [time() - 86400]);
    }
}
