<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Protokoll sicherheitsrelevanter Vorgänge der Einstellungsseite (Anmeldung, Passwort, Änderungen, Update).
 * Enthält nie Passwörter, Schlüssel oder Patientendaten, nur Zeit, Absenderadresse und Vorgang.
 */
final class AuditLog {
    private const MAX_BYTES = 262144;

    public function __construct(private string $stateDir) {}

    public function path(): string { return rtrim($this->stateDir, '/') . '/admin-audit.log'; }

    public function write(string $event, string $detail = ''): void {
        $line = date('Y-m-d H:i:s') . ' ' . Throttle::clientAddress() . ' ' . preg_replace('/[^A-Za-z0-9_.:-]/', '', $event)
            . ($detail === '' ? '' : ' ' . preg_replace('/[^A-Za-z0-9_.:, -]/', '', mb_substr($detail, 0, 80))) . "\n";
        $path = $this->path();
        // Ältere Einträge kürzen, damit die Datei klein bleibt.
        if (is_file($path) && (int)@filesize($path) > self::MAX_BYTES) {
            $tail = (string)@file_get_contents($path, false, null, (int)(self::MAX_BYTES / 2));
            $tail = substr($tail, (int)strpos($tail, "\n") + 1);
            @file_put_contents($path, $tail, LOCK_EX);
        }
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        @chmod($path, 0600);
    }

    /** Die letzten Einträge, neueste zuerst. */
    public function recent(int $count = 20): array {
        $lines = is_file($this->path()) ? (array)@file($this->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        return array_reverse(array_slice($lines, -$count));
    }
}
