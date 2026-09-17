<?php
declare(strict_types=1);
namespace KienzleSumup;

/** Begrenzter Prozessaufruf ohne Shell; Ein-/Ausgabe nur in privaten temporären Dateien. */
final class Command {
    public static function run(array $arguments, string $input, int $seconds = 15, int $maxOutput = 8388608): array {
        $in = tmpfile(); $out = tmpfile(); $err = tmpfile(); $process = null;
        try {
            if (!$in || !$out || !$err) throw new Problem('Aufruf konnte nicht vorbereitet werden.', 500);
            if (fwrite($in, $input) !== strlen($input)) throw new Problem('Eingabedaten konnten nicht vorbereitet werden.', 500);
            rewind($in);
            $process = proc_open($arguments, [0 => $in, 1 => $out, 2 => $err], $pipes);
            if (!is_resource($process)) throw new Problem('Aufruf konnte nicht gestartet werden.', 500);
            $deadline = microtime(true) + $seconds;
            do {
                $state = proc_get_status($process);
                if (fstat($out)['size'] > $maxOutput || fstat($err)['size'] > 65536 || microtime(true) > $deadline) {
                    throw new Problem('Aufruf wurde nicht rechtzeitig bestätigt oder die Ausgabe ist zu groß.', 502);
                }
                if (!$state['running']) break;
                usleep(50000);
            } while (true);
            rewind($out); rewind($err);
            return ['code' => $state['exitcode'], 'output' => stream_get_contents($out, $maxOutput + 1),
                'stderr' => stream_get_contents($err, 65537)];
        } finally {
            if (is_resource($process)) { if (proc_get_status($process)['running']) proc_terminate($process, 9); proc_close($process); }
            foreach ([$in, $out, $err] as $file) if (is_resource($file)) fclose($file);
        }
    }
}
