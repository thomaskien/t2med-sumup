<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Abgelehnte Rechnungsdrucke: Zeit, Grund und eine maskierte Zeilenübersicht (nur Beschriftungen und
 * Beträge, nie Namen, Geburtsdaten oder Rechnungs-PDFs). Dient der Einrichtung neuer T2med-Vorlagen.
 */
final class InboxLog {
    private const MAX_ENTRIES = 30;

    public function __construct(private string $stateDir) {}

    public function path(): string { return rtrim($this->stateDir, '/') . '/invoice-rejected.json'; }

    public function add(string $reason, string $source, array $lines): void {
        $entries = $this->recent(self::MAX_ENTRIES - 1);
        array_unshift($entries, ['at' => time(), 'reason' => mb_substr($reason, 0, 200), 'source' => mb_substr($source, 0, 40),
            'lines' => array_map(fn($l) => mb_substr((string)$l, 0, 140), array_slice($lines, 0, 14))]);
        @file_put_contents($this->path(), json_encode($entries, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($this->path(), 0600);
    }

    /** Neueste zuerst. */
    public function recent(int $count = 10): array {
        $data = is_file($this->path()) ? json_decode((string)@file_get_contents($this->path()), true) : [];
        return is_array($data) ? array_slice($data, 0, $count) : [];
    }

    public function clear(): void { @unlink($this->path()); }
}
