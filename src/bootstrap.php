<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KienzleSumup\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('KienzleSumup\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

// Zusätzliche Schutz-Header für jede Web-Antwort (zusätzlich zu denen von Apache).
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server' && !headers_sent()) {
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header_remove('X-Powered-By');
}

/** Anfragen anderer Websites ablehnen: Browser melden Sec-Fetch-Site; Starter und Druckserver senden den Header nicht. */
function ks_require_same_site(): void {
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site !== '' && $site !== 'same-origin' && $site !== 'none') throw new KienzleSumup\Problem('Unzulässiger Ursprung.', 403);
}

function ks_app(): KienzleSumup\App {
    static $app;
    return $app ??= new KienzleSumup\App(KienzleSumup\Settings::effective(ks_base_config()));
}

/** Konfigurationsdatei des Installers (Grundwerte); Weboberflächen-Einstellungen liegen darüber. */
function ks_base_config(): KienzleSumup\Config {
    static $config;
    return $config ??= KienzleSumup\Config::load(getenv('KIENZLE_SUMUP_CONFIG') ?: '/etc/kienzle-sumup/kienzle-sumup.toml');
}
