<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Anbindung des T2med-Terminzettels (Ordner terminzettel/): Der virtuelle Drucker „Terminzettel“
 * liest beim Drucken /etc/terminzettel/web/layout.json. Diese Datei schreibt die
 * Einstellungsseite: Zielwarteschlange und Modell vom Bondrucker, dazu Texte, Schriftgrößen
 * und Kalender-QR. Nur ausdrücklich gespeicherte Layoutwerte werden übertragen, damit eine
 * vorhandene /etc/terminzettel/config.toml sonst unverändert gilt.
 */
final class Terminzettel {
    public static string $dir = '/etc/terminzettel/web';
    public static string $toml = '/etc/terminzettel/config.toml';
    public static string $backend = '/usr/lib/cups/backend/terminzettel';
    public const QR_SIZES = ['small' => 160, 'medium' => 256, 'large' => 320];

    public static function installed(): bool { return is_file(self::$backend); }

    /** Bondrucker für den Terminzettel: nur eine CUPS-Warteschlange auf diesem Server. */
    public static function target(Config $config): ?array {
        $queue = (string)$config->get('printing', 'queue', '');
        if ($config->get('printing', 'transport', 'samba') !== 'cups' || $config->get('printing', 'cups_server', '') !== '' || $queue === '') return null;
        return ['queue' => $queue, 'model' => (string)$config->get('printing', 'model', 'tm-m10')];
    }

    /** Layout aus Formularwerten (Abschnitt terminzettel) in das Format von terminzettel.py. */
    public static function overlay(Config $config, array $values): array {
        $target = self::target($config);
        $overlay = ['enabled' => ($values['enabled'] ?? true) !== false, 'queue' => $target['queue'] ?? null, 'model' => $target['model'] ?? 'tm-m10'];
        if (array_key_exists('cut', $values)) $overlay['cut'] = (bool)$values['cut'];
        $map = [
            'header_text' => ['header', 'text'], 'header_size' => ['header', 'size'], 'header_bold' => ['header', 'bold'],
            'heading' => ['heading', 'text'], 'heading_size' => ['heading', 'size'],
            'show_patient' => ['appointments', 'show_patient'], 'appointment_size' => ['appointments', 'size'],
            'footer_text' => ['footer', 'text'], 'footer_size' => ['footer', 'size'],
            'qr_enabled' => ['qr', 'enabled'], 'qr_position' => ['qr', 'position'], 'qr_summary' => ['qr', 'summary'],
        ];
        foreach ($map as $key => [$block, $field]) {
            if (array_key_exists($key, $values)) $overlay[$block][$field] = $values[$key];
        }
        // Erste Zeile des Starttexts abweichend (nur wenn gewählt; „same“ = wie die übrigen Zeilen).
        if (($values['header_first_size'] ?? 'same') !== 'same') $overlay['header']['first_size'] = $values['header_first_size'];
        if (in_array($values['header_first_bold'] ?? 'same', ['yes', 'no'], true)) $overlay['header']['first_bold'] = $values['header_first_bold'] === 'yes';
        if (array_key_exists('header_text', $values)) $overlay['header']['enabled'] = trim((string)$values['header_text']) !== '';
        if (array_key_exists('footer_text', $values)) $overlay['footer']['enabled'] = trim((string)$values['footer_text']) !== '';
        if (isset($values['qr_size'], self::QR_SIZES[$values['qr_size']])) $overlay['qr']['width_dots'] = self::QR_SIZES[$values['qr_size']];
        return $overlay;
    }

    /** Datei für den Druckdienst schreiben (nach jedem Speichern). Ohne Terminzettel-Installation ohne Wirkung. */
    public static function sync(Config $config, array $explicit): bool {
        if (!is_dir(self::$dir) || !is_writable(self::$dir)) return false;
        $json = json_encode(self::overlay($config, $explicit), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        $path = rtrim(self::$dir, '/') . '/layout.json';
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        // Gruppe terminzettel (gesetztes Gruppen-Bit des Ordners) darf lesen, sonst niemand.
        if (file_put_contents($tmp, $json) === false || !chmod($tmp, 0640) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new Problem('Terminzettel-Einstellungen konnten nicht an den Druckdienst übergeben werden.', 500);
        }
        return true;
    }

    /** Vorgaben aus einer vorhandenen /etc/terminzettel/config.toml (z. B. bisheriger Praxiskopf). */
    public static function existing(): array {
        static $cache = [];
        if (array_key_exists(self::$toml, $cache)) return $cache[self::$toml];
        return $cache[self::$toml] = self::readExisting();
    }
    private static function readExisting(): array {
        $text = @file_get_contents(self::$toml);
        if (!is_string($text)) return [];
        $toml = self::parseToml($text);
        $out = [];
        if (isset($toml['header']['text']) && is_string($toml['header']['text'])) $out['header_text'] = ($toml['header']['enabled'] ?? true) ? $toml['header']['text'] : '';
        if (isset($toml['footer']['text']) && is_string($toml['footer']['text'])) $out['footer_text'] = ($toml['footer']['enabled'] ?? true) ? $toml['footer']['text'] : '';
        if (isset($toml['layout']['heading']) && is_string($toml['layout']['heading'])) $out['heading'] = $toml['layout']['heading'];
        if (isset($toml['layout']['show_patient']) && is_bool($toml['layout']['show_patient'])) $out['show_patient'] = $toml['layout']['show_patient'];
        if (isset($toml['calendar_qr']['enabled']) && is_bool($toml['calendar_qr']['enabled'])) $out['qr_enabled'] = $toml['calendar_qr']['enabled'];
        if (isset($toml['calendar_qr']['summary']) && is_string($toml['calendar_qr']['summary'])) $out['qr_summary'] = $toml['calendar_qr']['summary'];
        return $out;
    }

    /** Liest die vom Terminzettel-Installer geschriebene TOML-Datei (Werte in JSON-Schreibweise). */
    public static function parseToml(string $text): array {
        $result = []; $section = '';
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^\[([A-Za-z0-9_-]+)\]$/', $line, $m)) { $section = $m[1]; continue; }
            if ($section === '' || !preg_match('/^([A-Za-z0-9_-]+)\s*=\s*(.+)$/', $line, $m)) continue;
            $value = json_decode($m[2], true);   // "Text", true/false, Zahlen; Mehrzeiliges „"""“ wird übersprungen
            if ($value !== null && (is_string($value) || is_bool($value) || is_int($value))) $result[$section][$m[1]] = $value;
        }
        return $result;
    }

    /** Bon mit Beispieldaten erzeugen: als PNG (Vorschau) oder als ESC/POS (Testdruck). */
    public static function sample(array $overlay, string $format = 'png', ?callable $run = null): array {
        $script = dirname(__DIR__) . '/terminzettel/preview.py';
        if (!is_file($script)) throw new Problem('Terminzettel fehlt in dieser Installation.', 503);
        $run ??= fn(array $args, string $input) => Command::run($args, $input, 30, 16777216);
        $result = $run(['/usr/bin/python3', '-B', $script], json_encode(['overlay' => $overlay, 'format' => $format], JSON_THROW_ON_ERROR));
        if ($result['code'] !== 0) {
            $error = trim((string)$result['stderr']);
            if (str_contains($error, 'No module named')) $error = 'Für die Vorschau fehlen Python-Pakete. Bitte einmal sudo bash scripts/install-server.sh ausführen.';
            throw new Problem($error !== '' ? substr($error, 0, 300) : 'Vorschau fehlgeschlagen.', $result['code'] === 2 ? 422 : 502);
        }
        $split = strpos($result['output'], "\n");
        if ($split === false) throw new Problem('Vorschau fehlgeschlagen.', 502);
        $meta = json_decode(substr($result['output'], 0, $split), true);
        return ['notes' => is_array($meta['notes'] ?? null) ? $meta['notes'] : [], 'data' => substr($result['output'], $split + 1)];
    }
}
