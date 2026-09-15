<?php
declare(strict_types=1);
namespace KienzleSumup;

final class ReceiptPdf {
    public static function convert(string $source): string {
        if (strlen($source) > 2 * 1024 * 1024) throw new Problem('Der SumUp-Beleg ist zu groß.', 502);
        // PNG-Belege ebenfalls als eingebettetes Bild rendern. SVG bleibt vektorbasiert.
        if (str_starts_with($source, "\x89PNG\r\n\x1a\n")) {
            $size = @getimagesizefromstring($source);
            if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] > 8000 || $size[1] > 20000) throw new Problem('Ungültige Belegabmessungen.', 502);
            $source = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="'.$size[0].'" height="'.$size[1].'"><image width="100%" height="100%" xlink:href="data:image/png;base64,'.base64_encode($source).'"/></svg>';
        }
        if (!preg_match('/<svg\b/i', $source)) throw new Problem('SumUp hat keinen lesbaren SVG- oder PNG-Beleg geliefert.', 502);
        $binary = null;
        foreach (['/usr/bin/rsvg-convert', '/opt/homebrew/bin/rsvg-convert', '/usr/local/bin/rsvg-convert'] as $path) {
            if (is_executable($path)) { $binary = $path; break; }
        }
        if (!$binary) throw new Problem('PDF-Konverter fehlt. Bitte den aktualisierten Server-Installer ausführen (librsvg2-bin).', 503);
        $input = tmpfile(); $output = tmpfile(); $errors = tmpfile(); $process = null;
        try {
            if (!$input || !$output || !$errors) throw new Problem('PDF konnte nicht vorbereitet werden.', 500);
            fwrite($input, $source); rewind($input);
            // stdin hat keine Basis-URL: librsvg lädt keine lokalen/externen Dateien.
            $process = proc_open([$binary, '--format=pdf'], [0 => $input, 1 => $output, 2 => $errors], $pipes);
            if (!is_resource($process)) throw new Problem('PDF-Konverter konnte nicht gestartet werden.', 500);
            $deadline = microtime(true) + 15;
            do {
                $state = proc_get_status($process);
                if (!$state['running']) break;
                if (microtime(true) > $deadline || fstat($output)['size'] > 8 * 1024 * 1024) {
                    throw new Problem('PDF-Umwandlung konnte nicht abgeschlossen werden. Bitte erneut versuchen.', 502);
                }
                usleep(50000);
            } while (true);
            rewind($output); $pdf = stream_get_contents($output, 8 * 1024 * 1024 + 1);
            if ($state['exitcode'] !== 0 || !is_string($pdf) || !str_starts_with($pdf, '%PDF-') || strlen($pdf) > 8 * 1024 * 1024) {
                throw new Problem('Der SumUp-Originalbeleg konnte nicht in PDF umgewandelt werden.', 502);
            }
            return $pdf;
        } finally {
            if (is_resource($process)) { if (proc_get_status($process)['running']) proc_terminate($process, 9); proc_close($process); }
            foreach ([$input, $output, $errors] as $file) if (is_resource($file)) fclose($file);
        }
    }
}
