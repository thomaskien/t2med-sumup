<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Virtueller Drucker „SumUp-Rechnung“ auf diesem Server, ohne Terminal eingerichtet:
 * Der Server-Installer legt einmalig das CUPS-Backend und die PPD ab. Die Einstellungsseite
 * schreibt Adresse, Schlüssel und Zertifikat nach /etc/kienzle-sumup-printer/web und legt die
 * Warteschlange per lpadmin an (Gruppe lpadmin) bzw. entfernt sie beim Ausschalten wieder.
 */
final class InvoicePrinter {
    public const QUEUE = 'SumUp-Rechnung';
    public const URI = 'kienzle-sumup:/';
    public static string $dir = '/etc/kienzle-sumup-printer/web';
    public static string $backend = '/usr/lib/cups/backend/kienzle-sumup';
    public static string $ppd = '/usr/share/ppd/kienzle-sumup/sumup-rechnung.ppd';
    public static string $certificate = '/etc/kienzle-sumup/server.crt';

    public static function installed(): bool { return is_file(self::$backend) && is_file(self::$ppd); }

    /** Verbindungsdaten für das Backend schreiben; ohne Web-Ordner (z. B. Entwicklung) ohne Wirkung. */
    public static function sync(Config $config): bool {
        if (!is_dir(self::$dir) || !is_writable(self::$dir)) return false;
        $json = self::$dir . '/printer.json'; $crt = self::$dir . '/server.crt';
        if (!$config->get('invoice_inbox', 'enabled', false)) {
            @unlink($json); @unlink($crt);
            return true;
        }
        $cert = @file_get_contents(self::$certificate);
        if (!is_string($cert) || !str_contains($cert, 'BEGIN CERTIFICATE') || str_contains($cert, 'PRIVATE KEY')) throw new Problem('Serverzertifikat nicht lesbar. Bitte den Server-Installer ausführen.', 503);
        self::write($crt, $cert, 0644);
        self::write($json, json_encode(['server_url' => $config->get('app', 'base_url'), 'inbox_key' => $config->get('invoice_inbox', 'key')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        return true;
    }
    private static function write(string $path, string $data, int $mode): void {
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $data) === false || !chmod($tmp, $mode) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new Problem('Druckereinrichtung konnte nicht gespeichert werden.', 500);
        }
    }

    /** Warteschlange anlegen (an) oder entfernen (aus). Liefert den neuen Zustand oder null ohne Druckdienst. */
    public static function apply(PrinterAdmin $printers, bool $enabled): ?bool {
        if (!$printers->available() || !self::installed()) return null;
        $queue = array_column($printers->queues(), null, 'name')[self::QUEUE] ?? null;
        if ($queue && $queue['uri'] !== self::URI) {
            throw new Problem('Es gibt bereits einen anderen Drucker „' . self::QUEUE . '“. Bitte diesen zuerst umbenennen oder entfernen.', 409);
        }
        if ($enabled && !$queue) {
            $printers->command(['/usr/sbin/lpadmin', '-p', self::QUEUE, '-v', self::URI, '-P', self::$ppd, '-D', self::QUEUE,
                '-L', 'T2med-Rechnung an SumUp-Terminal', '-o', 'printer-is-shared=true', '-o', 'printer-error-policy=abort-job',
                '-o', 'job-sheets-default=none,none', '-E']);
        } elseif (!$enabled && $queue) {
            $printers->command(['/usr/sbin/lpadmin', '-x', self::QUEUE]);
        }
        return $enabled;
    }

    public static function status(PrinterAdmin $printers, Config $config): array {
        $host = parse_url((string)$config->get('app', 'base_url'), PHP_URL_HOST) ?: 'server';
        $queue = $printers->available() ? (array_column($printers->queues(), null, 'name')[self::QUEUE] ?? null) : null;
        return ['enabled' => (bool)$config->get('invoice_inbox', 'enabled', false), 'installed' => self::installed() && $printers->available(),
            'queue' => $queue ? ['state' => $queue['state'], 'ours' => $queue['uri'] === self::URI] : null,
            'url' => 'http://' . $host . ':631/printers/' . self::QUEUE];
    }
}
