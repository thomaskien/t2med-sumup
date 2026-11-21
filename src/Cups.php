<?php
declare(strict_types=1);
namespace KienzleSumup;

/** Gemeinsamer Aufruf von lp für Praxis- und Bondrucker. */
final class Cups {
    public static function run(array $args, string $data, int $timeout): array {
        if (!is_executable('/usr/bin/lp')) throw new Problem('CUPS-Client fehlt. Bitte den Server-Installer ausführen (cups-client).', 503);
        return Command::run($args, $data, $timeout, 65536);
    }
    public static function submit(string $server, string $queue, string $title, array $options, string $data, callable $run): string {
        $args = ['/usr/bin/lp'];
        if ($server !== '') array_push($args, '-h', $server);
        array_push($args, '-d', $queue, '-t', $title ?: 'Kienzle-SumUp', ...$options);
        $result = $run($args, $data);
        // Ausgabe von lp: "request id is Praxisdrucker-42 (0 file(s))"
        if ($result['code'] !== 0 || !preg_match('/request id is (\S+)/', $result['output'], $m)) {
            $reason = trim(preg_replace('/\s+/', ' ', substr($result['stderr'] ?: $result['output'], 0, 200)));
            error_log('kienzle-sumup: CUPS: ' . $reason);
            throw new Problem('Drucker „' . $queue . '“: ' . ($reason !== '' ? $reason : 'Exit-Code ' . (int)$result['code']) . '.', 502);
        }
        return $m[1];
    }
}
