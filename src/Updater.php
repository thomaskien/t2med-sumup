<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Aktualisierung aus dem Webinterface: neue Version auf GitHub erkennen, Änderungen anzeigen und
 * die Aktualisierung anfordern. Ausgeführt wird sie vom root-Dienst kienzle-sumup-update
 * (scripts/self-update.sh); die Anwendung legt dafür nur eine Anfragedatei ab.
 */
final class Updater {
    public static string $conf = '/etc/kienzle-sumup/update.conf';
    public static string $state = '/var/lib/kienzle-sumup-update';
    public static string $service = '/usr/local/sbin/kienzle-sumup-update';
    /** Feste Quellen der Kanäle: Stabil = Hauptzweig des Projekts, Beta = Vorabversion dieses Forks. */
    public const STABLE = ['repo' => 'thomaskien/t2med-sumup', 'branch' => 'main'];
    public const BETA = ['repo' => 'hau-si/kienzle-suite-beta', 'branch' => 'rechnungseingang'];
    private const CACHE = 'update_check';
    private const CACHE_SECONDS = 21600;   // 6 Stunden

    /** @var callable(string): ?string  URL → Inhalt oder null */
    private $fetch;

    public function __construct(private Database $db, private string $root, ?callable $fetch = null) {
        $this->fetch = $fetch ?? static function (string $url): ?string {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'kienzle-sumup-update', CURLOPT_MAXFILESIZE => 262144]);
            $body = curl_exec($curl);
            $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            return is_string($body) && $code === 200 ? $body : null;
        };
    }

    /** Quelle aus der root-eigenen Konfigurationsdatei (null, wenn keine eingerichtet ist). */
    private function configured(): ?array {
        $text = @file_get_contents(self::$conf);
        if (!is_string($text) || !preg_match('/^repo=([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)$/m', $text, $r) || !preg_match('/^branch=([A-Za-z0-9_.\/-]+)$/m', $text, $b)) return null;
        return ['repo' => $r[1], 'branch' => $b[1]];
    }

    /** Gewählter Kanal: stable (Hauptzweig), beta (dieser Fork) oder custom (eigene Quelle in update.conf). */
    public function channel(): string {
        $stored = (string)($this->db->one('SELECT value FROM settings WHERE key=?', ['update_channel'])['value'] ?? '');
        if (in_array($stored, ['stable', 'beta'], true)) return $stored;
        $conf = $this->configured();   // noch nichts gewählt: bisherige Quelle beibehalten
        return $conf === null || $conf === self::STABLE ? 'stable' : ($conf === self::BETA ? 'beta' : 'custom');
    }
    public function setChannel(string $channel): void {
        if (!in_array($channel, ['stable', 'beta'], true)) throw new Problem('Unbekannter Kanal.', 422);
        $this->db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', ['update_channel', $channel, time()]);
        $this->db->query('DELETE FROM settings WHERE key=?', [self::CACHE]);   // Prüfergebnis gilt nur für die alte Quelle
    }

    public function source(): ?array {
        $conf = $this->configured();
        if ($conf === null) return null;
        return match ($this->channel()) { 'stable' => self::STABLE, 'beta' => self::BETA, default => $conf };
    }

    public function current(): string { return trim((string)@file_get_contents($this->root . '/VERSION')); }

    /** Versionsvergleich über alle Zahlen: 1.4.2 > 1.4.1-rechnung.12 > 1.4.1-rechnung.9. */
    public static function newer(string $remote, string $local): bool {
        preg_match_all('/\d+/', $remote, $a); preg_match_all('/\d+/', $local, $b);
        $a = array_map('intval', $a[0]); $b = array_map('intval', $b[0]);
        for ($i = 0; $i < max(count($a), count($b)); $i++) {
            if (($a[$i] ?? -1) !== ($b[$i] ?? -1)) return ($a[$i] ?? -1) > ($b[$i] ?? -1);
        }
        return false;
    }

    /** Neueste Version auf GitHub (zwischengespeichert); $force prüft sofort. */
    public function check(bool $force = false): array {
        $source = $this->source();
        $cached = json_decode((string)($this->db->one('SELECT value FROM settings WHERE key=?', [self::CACHE])['value'] ?? ''), true);
        $local = $this->current();
        if (!$source) return ['available' => false, 'configured' => false, 'current' => $local];
        if ($force || !is_array($cached) || ($cached['checked_at'] ?? 0) < time() - self::CACHE_SECONDS || ($cached['source'] ?? null) !== $source) {
            $raw = 'https://raw.githubusercontent.com/' . $source['repo'] . '/' . $source['branch'] . '/';
            $version = ($this->fetch)($raw . 'VERSION');
            $version = is_string($version) ? trim($version) : '';
            if (!preg_match('/^\d+(\.\d+)+([.+-][0-9A-Za-z.-]+)?$/D', $version)) {
                $cached = ['checked_at' => time(), 'source' => $source, 'remote' => null, 'notes' => [], 'error' => 'GitHub nicht erreichbar oder keine Versionsangabe gefunden.'];
            } else {
                $cached = ['checked_at' => time(), 'source' => $source, 'remote' => $version, 'notes' => self::notes((string)($this->fetch)($raw . 'docs/RELEASE.md'), $local), 'error' => null];
            }
            // Beta-Abonnenten sehen zusätzlich, ob im Hauptzweig (Stabil) eine neuere Version liegt.
            if ($source === self::BETA) {
                $main = ($this->fetch)('https://raw.githubusercontent.com/' . self::STABLE['repo'] . '/' . self::STABLE['branch'] . '/VERSION');
                $main = is_string($main) ? trim($main) : '';
                $cached['main_remote'] = preg_match('/^\d+(\.\d+)+([.+-][0-9A-Za-z.-]+)?$/D', $main) ? $main : null;
            }
            $this->db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at',
                [self::CACHE, json_encode($cached, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), time()]);
        }
        $remote = $cached['remote'] ?? null;
        return ['configured' => true, 'channel' => $this->channel(), 'source' => $source, 'current' => $local, 'remote' => $remote, 'checked_at' => $cached['checked_at'],
            'available' => is_string($remote) && self::newer($remote, $local),
            'main_available' => $source === self::STABLE ? (is_string($remote) && self::newer($remote, $local)) : (is_string($cached['main_remote'] ?? null) && self::newer($cached['main_remote'], $local)),
            'main_remote' => $source === self::STABLE ? $remote : ($cached['main_remote'] ?? null), 'notes' => $cached['notes'] ?? [], 'error' => $cached['error'] ?? null,
            'service' => is_file(self::$service)];
    }

    /** Einträge „Neu in …“ aus docs/RELEASE.md, die neuer als die installierte Version sind (höchstens 8). */
    public static function notes(string $release, string $local): array {
        $items = []; $current = null;
        foreach (preg_split('/\R/', $release) as $line) {
            if (preg_match('/^- (.*)$/', $line, $m)) { if ($current !== null) $items[] = $current; $current = $m[1]; }
            elseif ($current !== null && preg_match('/^\s+(\S.*)$/', $line, $m)) $current .= ' ' . $m[1];
            elseif ($current !== null && trim($line) === '') { $items[] = $current; $current = null; }
        }
        if ($current !== null) $items[] = $current;
        $localNumber = preg_match('/(\d+)$/', $local, $m) ? (int)$m[1] : null;
        $out = [];
        foreach ($items as $item) {
            if (!preg_match('/^Neu in [^\d]*?(\d+)(?:\.\d+)*:\s*(.*)$/u', $item, $m)) continue;
            if ($localNumber !== null && (int)$m[1] <= $localNumber) continue;
            $out[] = mb_substr(strip_tags($item), 0, 600);
            if (count($out) >= 8) break;
        }
        return $out;
    }

    public function status(): array {
        $status = json_decode((string)@file_get_contents(self::$state . '/status.json'), true);
        $log = @file_get_contents(self::$state . '/update.log');
        $lines = is_string($log) ? array_slice(preg_split('/\R/', rtrim($log)), -25) : [];
        $requested = is_file($this->db->directory . '/update-request');
        return ['state' => $requested && ($status['state'] ?? '') !== 'running' ? 'requested' : ($status['state'] ?? 'idle'),
            'message' => $status['message'] ?? '', 'from' => $status['from'] ?? '', 'to' => $status['to'] ?? '',
            'finished' => $status['finished'] ?? null, 'log' => array_map(static fn($l) => mb_substr((string)preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $l), 0, 300), $lines)];
    }

    /** Aktualisierung anfordern: nur ohne laufende Kartenzahlung und wenn eine neuere Version vorliegt. */
    public function request(): array {
        if (!is_file(self::$service)) throw new Problem('Der Aktualisierungsdienst fehlt. Bitte einmal den aktuellen Server-Installer ausführen.', 503);
        $check = $this->check(true);
        if (!$check['available']) throw new Problem($check['error'] ?? 'Es ist bereits die neueste Version installiert.', 409);
        if (in_array($this->status()['state'], ['running', 'requested'], true)) throw new Problem('Die Aktualisierung läuft bereits.', 409);
        if ($this->db->one("SELECT id FROM payments WHERE payment_status IN ('starting','pending','unknown','cancel_requested') LIMIT 1")) {
            throw new Problem('Gerade läuft eine Kartenzahlung. Bitte erst abschließen und dann aktualisieren.', 409);
        }
        $path = $this->db->directory . '/update-request';
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $this->channel()) === false || !rename($tmp, $path)) { @unlink($tmp); throw new Problem('Aktualisierung konnte nicht angefordert werden.', 500); }
        return ['message' => 'Aktualisierung auf ' . $check['remote'] . ' gestartet. Das dauert einige Minuten; die Seite ist zwischendurch kurz nicht erreichbar.'];
    }
}
