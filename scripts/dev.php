#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$root = dirname(__DIR__);
umask(0077);
if (!is_dir($root . '/var')) mkdir($root . '/var', 0700);
$path = $root . '/config/local.toml';
if (!is_file($path)) {
    $text = file_get_contents($root . '/config/kienzle-sumup.example.toml');
    $text = str_replace(['https://praxisserver:7868','port = 7868','/var/lib/kienzle-sumup','development = false'], ['http://127.0.0.1:7868','port = 7868',$root . '/var','development = true'], $text);
    foreach (['launcher_key','encryption_key'] as $key) $text = preg_replace('/^' . $key . ' = .*$/m', $key . ' = "' . bin2hex(random_bytes(32)) . '"', $text);
    file_put_contents($path, $text);
}
putenv('KIENZLE_SUMUP_CONFIG=' . $path);
ks_app()->db->migrate();
echo "Testmodus: http://127.0.0.1:7868 · mit Strg+C beenden\n";
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:7868', '-t', $root . '/public'], [STDIN, STDOUT, STDERR], $pipes);
exit(proc_close($process));
