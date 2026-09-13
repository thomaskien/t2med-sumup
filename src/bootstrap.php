<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KienzleSumup\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('KienzleSumup\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

function ks_app(): KienzleSumup\App {
    static $app;
    return $app ??= new KienzleSumup\App(KienzleSumup\Config::load(
        getenv('KIENZLE_SUMUP_CONFIG') ?: '/etc/kienzle-sumup/kienzle-sumup.toml'
    ));
}
