<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Einrichtungspaket für einen Arbeitsplatz (ZIP): Starter-Konfiguration, Serverzertifikat,
 * Installationsskript und – soweit auf dem Server vorhanden – die Starterprogramme mit
 * Prüfsummen. Enthält den Starter-Schlüssel, daher nur nach Admin-Anmeldung.
 */
final class ClientPackage {
    public const SYSTEMS = [
        'windows' => ['label' => 'Windows', 'scripts' => ['install-windows.cmd', 'install-windows.ps1'], 'assets' => ['kienzle-sumup-windows-amd64.exe', 'kienzle-sumup-windows-arm64.exe']],
        'macos' => ['label' => 'macOS', 'scripts' => ['install-macos.command'], 'assets' => ['kienzle-sumup-darwin-amd64', 'kienzle-sumup-darwin-arm64']],
        'linux' => ['label' => 'Linux', 'scripts' => ['install-linux.sh'], 'assets' => ['kienzle-sumup-linux-amd64', 'kienzle-sumup-linux-arm64', 'kienzle-sumup-linux-arm']],
    ];

    public function __construct(private Config $config, private string $root, private string $certificate = '/etc/kienzle-sumup/server.crt') {}

    /** Liste der Dateien: Name => ['data' => string] oder ['path' => Datei], jeweils mit Unix-Rechten. */
    public function files(string $system): array {
        $spec = self::SYSTEMS[$system] ?? throw new Problem('Unbekanntes Betriebssystem.', 404);
        $pem = @file_get_contents($this->certificate);
        if (!$pem || !preg_match('/-----BEGIN CERTIFICATE-----\s*(.+?)\s*-----END CERTIFICATE-----/s', $pem, $m) || str_contains($pem, 'PRIVATE KEY')) {
            throw new Problem('Serverzertifikat nicht lesbar. Bitte den Server-Installer ausführen.', 503);
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        $client = ['server_url' => $this->config->get('app', 'base_url'), 'launcher_key' => $this->config->get('app', 'launcher_key'),
            'certificate_sha256' => hash('sha256', (string)$der)];
        $files = [
            'client.json' => ['data' => json_encode($client, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 'mode' => 0600],
            'server.crt' => ['data' => $pem, 'mode' => 0644],
            'LIESMICH.txt' => ['data' => self::readme($system, (string)$client['server_url']), 'mode' => 0644],
        ];
        foreach ($spec['scripts'] as $script) {
            $path = $this->root . '/client/' . $script;
            if (!is_file($path)) throw new Problem('Installationsskript fehlt auf dem Server: ' . $script, 503);
            $files[$script] = ['path' => $path, 'mode' => 0755];
        }
        // Starterprogramme beilegen, wenn der Server-Installer sie geladen und geprüft hat.
        $dist = $this->root . '/client-dist';
        $sums = is_file($dist . '/SHA256SUMS') ? (string)file_get_contents($dist . '/SHA256SUMS') : '';
        $lines = [];
        foreach ($spec['assets'] as $asset) {
            if (is_file($dist . '/' . $asset) && preg_match('/^([0-9a-f]{64})\s+\*?' . preg_quote($asset, '/') . '$/m', $sums, $hit)) {
                $files[$asset] = ['path' => $dist . '/' . $asset, 'mode' => 0755];
                $lines[] = $hit[1] . '  ' . $asset;
            }
        }
        if ($lines) $files['SHA256SUMS'] = ['data' => implode("\n", $lines) . "\n", 'mode' => 0644];
        return $files;
    }

    public static function filename(string $system): string { return 'kienzle-sumup-' . $system . '.zip'; }

    /** ZIP ohne Kompression schreiben (Starter sind bereits kompakt); liefert die Gesamtgröße. */
    public static function zip(array $files, callable $write): int {
        $offset = 0; $central = ''; $time = getdate();
        $dosTime = ($time['hours'] << 11) | ($time['minutes'] << 5) | intdiv($time['seconds'], 2);
        $dosDate = (($time['year'] - 1980) << 9) | ($time['mon'] << 5) | $time['mday'];
        $emit = function (string $bytes) use ($write, &$offset) { $write($bytes); $offset += strlen($bytes); };
        foreach ($files as $name => $file) {
            if (isset($file['data'])) { $size = strlen($file['data']); $crc = crc32($file['data']); }
            else { $size = (int)filesize($file['path']); $crc = (int)hexdec(hash_file('crc32b', $file['path'])); }
            $start = $offset;
            // Bit 11: Dateiname in UTF-8.
            $emit(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0) . $name);
            if (isset($file['data'])) $emit($file['data']);
            else {
                $handle = fopen($file['path'], 'rb');
                if (!$handle) throw new Problem('Datei nicht lesbar: ' . $name, 500);
                while (!feof($handle)) $emit((string)fread($handle, 1 << 20));
                fclose($handle);
            }
            // „Erstellt unter Unix“ (3), damit Linux/macOS die Ausführungsrechte übernehmen.
            $attributes = ((0100000 | ($file['mode'] ?? 0644)) << 16);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, (3 << 8) | 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size,
                strlen($name), 0, 0, 0, 0, $attributes, $start) . $name;
        }
        $centralStart = $offset;
        $emit($central);
        $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $centralStart, 0));
        return $offset;
    }

    private static function readme(string $system, string $url): string {
        $steps = match ($system) {
            'windows' => "1. Diese ZIP-Datei mit Rechtsklick → „Alle extrahieren …“ entpacken (nicht direkt aus der ZIP starten).\r\n"
                . "2. Im entpackten Ordner „install-windows.cmd“ doppelklicken.\r\n"
                . "   Meldet Windows „Der Computer wurde durch Windows geschützt“: „Weitere Informationen“ → „Trotzdem ausführen“.\r\n"
                . "3. Die Sicherheitswarnung zum Zertifikat mit „Ja“ bestätigen. Das Fenster kann hinter anderen Fenstern liegen –\r\n"
                . "   wenn scheinbar nichts passiert, in der Taskleiste nachsehen.\r\n"
                . "4. „Kienzle-SumUp eingerichtet“ erscheint. Fenster mit Enter schließen. Der Ordner kann danach gelöscht werden.\r\n",
            'macos' => "1. Die ZIP-Datei doppelklicken (entpackt in den Ordner „Downloads“).\n"
                . "2. Im entpackten Ordner „install-macos.command“ mit Rechtsklick → „Öffnen“ starten und „Öffnen“ bestätigen.\n"
                . "3. Für das Zertifikat Ihr Mac-Anmeldepasswort eingeben.\n"
                . "4. „Kienzle-SumUp eingerichtet“ erscheint. Der Ordner kann danach gelöscht werden.\n",
            default => "1. Die ZIP-Datei entpacken (Dateimanager oder: unzip kienzle-sumup-linux.zip -d kienzle-sumup).\n"
                . "2. Im Terminal im entpackten Ordner ausführen:  bash install-linux.sh\n"
                . "3. Das Zertifikat server.crt im Browser als vertrauenswürdig importieren.\n",
        };
        $nl = $system === 'windows' ? "\r\n" : "\n";
        return 'Kienzle-SumUp – Einrichtung des Arbeitsplatzes' . $nl . $nl . $steps . $nl
            . 'Server: ' . $url . $nl
            . 'Diese Dateien enthalten den Starter-Schlüssel der Praxis. Nicht weitergeben, nach der Einrichtung löschen.' . $nl;
    }
}
